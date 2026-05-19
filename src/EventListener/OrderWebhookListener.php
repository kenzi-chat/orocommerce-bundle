<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\EventListener;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Kenzi\OroCommerceBundle\Async\OrderWebhookTopic;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;

/**
 * Produces webhook messages for Order lifecycle events:
 *   - postPersist → order.created (any creation path: checkout, admin, API, import)
 *   - postUpdate  → order.updated (only when payload-relevant fields change)
 *
 * Uses the doctrine.orm.entity_listener tag (NOT doctrine.event_listener).
 * OroCommerce uses Doctrine\Persistence\Event\LifecycleEventArgs,
 * NOT Doctrine\ORM\Event\PostUpdateEventArgs.
 *
 * The Order entity is passed as the first parameter (type-hinted),
 * not accessed via $args->getObject().
 */
class OrderWebhookListener
{
    /**
     * Fields checked directly in the Doctrine changeset.
     * Includes scalar columns, ManyToOne/OneToOne associations,
     * and the underlying column properties for MultiCurrency/Price value objects.
     *
     * Maps to fields serialized by OrderPayloadSerializer::serializeOrder().
     * Excludes updatedAt/createdAt (byproduct timestamps) and id (immutable).
     */
    private const DIRECT_FIELDS = [
        // Scalar fields
        'identifier',
        'email',
        'currency',
        'shippingMethod',
        'shippingMethodType',
        'poNumber',
        'customerNotes',
        'shipUntil',
        'sourceEntityClass',
        'sourceEntityId',
        'sourceEntityIdentifier',
        // Oro 6.0 enum fields — real columns (AbstractEnumValue), direct changeset entries.
        // On 6.1 these moved to serialized_data; the direct check is a harmless no-op.
        'internal_status',
        // Associations — changeset uses property name.
        // Note: in-place address edits are tracked on OrderAddress, not Order.
        'customer',
        'customerUser',
        'billingAddress',
        'shippingAddress',
        // MultiCurrency / Price underlying columns (NOT the value-object properties).
        // See Order entity: "Changes to this value object won't affect entity change set"
        'subtotalValue',              // backs Order::$subtotal
        'totalValue',                 // backs Order::$total
        'totalDiscountsAmount',       // backs Order::$totalDiscounts
        'estimatedShippingCostAmount',
        'overriddenShippingCostAmount',
    ];

    /**
     * Serialized enum fields nested inside the `serialized_data` changeset entry.
     * Key naming is inconsistent in Oro: `internal_status` uses underscores,
     * `shippingStatus` uses camelCase. Use the exact key from the serialized data.
     *
     * Pattern follows ReindexProductOrderListener::isInternalStatusChanged().
     */
    private const SERIALIZED_ENUM_FIELDS = [
        'internal_status',
        'shippingStatus',
    ];

    public function __construct(
        private readonly MessageProducerInterface $messageProducer,
    ) {
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface> $args
     */
    public function postPersist(Order $order, LifecycleEventArgs $args): void
    {
        $orderId = $order->getId();

        if ($orderId === null) {
            return;
        }

        if ($this->isSubOrder($order)) {
            return;
        }

        $this->messageProducer->send(
            OrderWebhookTopic::getName(),
            ['order_id' => $orderId, 'event' => 'order.created']
        );
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface> $args
     */
    public function postUpdate(Order $order, LifecycleEventArgs $args): void
    {
        $orderId = $order->getId();

        if ($orderId === null) {
            return;
        }

        if ($this->isSubOrder($order)) {
            return;
        }

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = $args->getObjectManager();
        $changeSet = $em->getUnitOfWork()->getEntityChangeSet($order);

        if (!$this->hasRelevantChanges($changeSet)) {
            return;
        }

        $this->messageProducer->send(
            OrderWebhookTopic::getName(),
            ['order_id' => $orderId, 'event' => 'order.updated']
        );
    }

    /**
     * A webhook is only produced when at least one watched field changes —
     * either a direct changeset field (DIRECT_FIELDS) or a nested serialized
     * enum (SERIALIZED_ENUM_FIELDS inside the serialized_data entry).
     *
     * @param array<string, array{mixed, mixed}> $changeSet
     */
    private function hasRelevantChanges(array $changeSet): bool
    {
        return $this->hasDirectFieldChange($changeSet)
            || $this->hasSerializedEnumChange($changeSet);
    }

    /**
     * @param array<string, array{mixed, mixed}> $changeSet
     */
    private function hasDirectFieldChange(array $changeSet): bool
    {
        foreach (self::DIRECT_FIELDS as $field) {
            if (isset($changeSet[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Serialized enum fields live inside the `serialized_data` JSON column,
     * whose changeset shape is `['serialized_data' => [$old, $new]]`. Either
     * side may be null when orders predate the serialized-fields bundle
     * installing (column is nullable per Oro migrations), so guard against
     * null-indexing before reading nested keys.
     *
     * @param array<string, array{mixed, mixed}> $changeSet
     */
    private function hasSerializedEnumChange(array $changeSet): bool
    {
        if (!isset($changeSet['serialized_data'])) {
            return false;
        }

        [$old, $new] = $changeSet['serialized_data'];

        if (!is_array($old) && !is_array($new)) {
            return false;
        }

        foreach (self::SERIALIZED_ENUM_FIELDS as $field) {
            if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sub-orders are child orders created by Oro's line-item-grouping feature
     * (split checkout). The parent order already contains all line items, so
     * sending both would cause duplicate data and inflated totals on Kenzi's side.
     */
    private function isSubOrder(Order $order): bool
    {
        return $order->getParent() !== null;
    }
}
