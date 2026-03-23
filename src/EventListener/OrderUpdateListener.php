<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\EventListener;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Kenzi\OroCommerceBundle\Async\OrderWebhookTopic;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;

/**
 * Listens for Order entity updates via Doctrine entity lifecycle events
 * and enqueues order.updated webhook messages for asynchronous dispatch to Kenzi.
 *
 * Only triggers when fields included in the webhook payload change.
 * Timestamp fields (updatedAt, createdAt) are excluded because they change
 * as a byproduct of any update — if only timestamps changed, Kenzi would
 * receive identical substantive data.
 *
 * Uses the doctrine.orm.entity_listener tag (NOT doctrine.event_listener).
 * OroCommerce uses Doctrine\Persistence\Event\LifecycleEventArgs,
 * NOT Doctrine\ORM\Event\PostUpdateEventArgs.
 *
 * The Order entity is passed as the first parameter (type-hinted),
 * not accessed via $args->getObject().
 */
class OrderUpdateListener
{
    /**
     * Order entity fields that appear in the webhook payload.
     * A webhook is only produced when at least one of these changes.
     *
     * Maps to the fields serialized by OrderPayloadSerializer::serializeOrder().
     * Excludes updatedAt/createdAt (byproduct timestamps) and id (immutable).
     */
    private const WATCHED_FIELDS = [
        'identifier',
        'internalStatus',
        'email',
        'currency',
        'subtotal',
        'total',
        'totalDiscounts',
        'shippingMethod',
        'shippingMethodType',
        'shippingCost',
        'estimatedShippingCostAmount',
        'overriddenShippingCostAmount',
        'poNumber',
        'customerNotes',
        'shipUntil',
        'sourceEntityClass',
        'sourceEntityId',
        'sourceEntityIdentifier',
        'customer',
        'customerUser',
        'billingAddress',
        'shippingAddress',
    ];

    public function __construct(
        private readonly MessageProducerInterface $messageProducer,
    ) {
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface> $args
     */
    public function postUpdate(Order $order, LifecycleEventArgs $args): void
    {
        $orderId = $order->getId();

        /** @phpstan-ignore identical.alwaysFalse (getId() returns null before persistence) */
        if ($orderId === null) {
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
     * @param array<string, array{mixed, mixed}> $changeSet
     */
    private function hasRelevantChanges(array $changeSet): bool
    {
        foreach (self::WATCHED_FIELDS as $field) {
            if (isset($changeSet[$field])) {
                return true;
            }
        }

        return false;
    }
}
