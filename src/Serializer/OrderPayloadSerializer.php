<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Serializer;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Oro\Bundle\AttachmentBundle\Manager\AttachmentManager;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderAddress;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\OrderBundle\Entity\OrderShippingTracking;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Converts an Order entity into the JSON payload structure
 * defined by the Kenzi webhook contract.
 */
class OrderPayloadSerializer
{
    public function __construct(
        private readonly AttachmentManager $attachmentManager,
        private readonly ApplicationUrlResolver $urlResolver,
    ) {
    }

    /**
     * Build the top-level webhook payload for an order event.
     *
     * @param non-empty-string $event
     * @param int|null $timestamp Unix timestamp override; defaults to time(). Accepts an
     *                            explicit value so tests can assert against a fixed timestamp.
     * @return array{event: string, timestamp: int, data: array<string, mixed>}
     */
    public function serialize(Order $order, string $event, ?int $timestamp = null): array
    {
        return [
            'event' => $event,
            'timestamp' => $timestamp ?? time(),
            'data' => $this->serializeOrder($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrder(Order $order): array
    {
        $data = [
            'id' => $order->getId(),
            'identifier' => $order->getIdentifier(),
            'status' => $this->resolveStatus($order),
            'email' => $order->getEmail(),
            'currency' => $order->getCurrency(),
            'subtotal' => $this->formatMoney($order->getSubtotal()),
            'total' => $this->formatMoney($order->getTotal()),
            'total_discounts' => $this->formatMoney($order->getTotalDiscounts()?->getValue()),
            'shipping_method' => $order->getShippingMethod(),
            'shipping_method_type' => $order->getShippingMethodType(),
            'shipping_cost' => $this->formatMoney($order->getShippingCost()?->getValue()),
            'estimated_shipping_cost_amount' => $this->formatMoney($order->getEstimatedShippingCostAmount()),
            'overridden_shipping_cost_amount' => $this->formatMoney($order->getOverriddenShippingCostAmount()),
            'po_number' => $order->getPoNumber(),
            'customer_notes' => $order->getCustomerNotes(),
            'ship_until' => $order->getShipUntil()?->format('c'),
            'source_entity_class' => $order->getSourceEntityClass(),
            'source_entity_id' => $order->getSourceEntityId(),
            'source_entity_identifier' => $order->getSourceEntityIdentifier(),
            'created_at' => $order->getCreatedAt()?->format('c'), /** @phpstan-ignore nullsafe.neverNull */
            'updated_at' => $order->getUpdatedAt()?->format('c'), /** @phpstan-ignore nullsafe.neverNull */
        ];

        $customer = $order->getCustomer();
        if ($customer !== null) {
            $data['customer'] = $this->serializeCustomer($customer);
        }

        $customerUser = $order->getCustomerUser();
        if ($customerUser !== null) {
            $data['customer_user'] = $this->serializeCustomerUser($customerUser);
        }

        $billingAddress = $order->getBillingAddress();
        if ($billingAddress !== null) {
            $data['billing_address'] = $this->serializeAddress($billingAddress);
        }

        $shippingAddress = $order->getShippingAddress();
        if ($shippingAddress !== null) {
            $data['shipping_address'] = $this->serializeAddress($shippingAddress);
        }

        $website = $order->getWebsite();
        if ($website !== null) {
            $data['website'] = [
                'id' => $website->getId(),
                'name' => $website->getName(),
            ];
        }

        $lineItems = [];
        foreach ($order->getLineItems() as $lineItem) {
            $lineItems[] = $this->serializeLineItem($lineItem);
        }
        $data['line_items'] = $lineItems;

        $shippingTrackings = [];
        foreach ($order->getShippingTrackings() as $tracking) {
            $shippingTrackings[] = $this->serializeShippingTracking($tracking);
        }
        $data['shipping_trackings'] = $shippingTrackings;

        return $data;
    }

    /**
     * @return array{id: int|null, name: string|null}
     */
    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
        ];
    }

    /**
     * @return array{id: int|null, email: ?string, first_name: ?string, last_name: ?string}
     */
    private function serializeCustomerUser(CustomerUser $customerUser): array
    {
        return [
            'id' => $customerUser->getId(),
            'email' => $customerUser->getEmail(),
            'first_name' => $customerUser->getFirstName(),
            'last_name' => $customerUser->getLastName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAddress(OrderAddress $address): array
    {
        return [
            'name_prefix' => $address->getNamePrefix(),
            'first_name' => $address->getFirstName(),
            'middle_name' => $address->getMiddleName(),
            'last_name' => $address->getLastName(),
            'name_suffix' => $address->getNameSuffix(),
            'organization' => $address->getOrganization(),
            'street' => $address->getStreet(),
            'street2' => $address->getStreet2(),
            'city' => $address->getCity(),
            'region' => $address->getRegionName(),
            'region_code' => $address->getRegionCode(),
            'postal_code' => $address->getPostalCode(),
            'country' => $address->getCountryName(),
            'country_iso2' => $address->getCountryIso2(),
            'country_iso3' => $address->getCountryIso3(),
            'phone' => $address->getPhone(),
        ];
    }

    /**
     * @return array{method: string|null, number: string|null}
     */
    private function serializeShippingTracking(OrderShippingTracking $tracking): array
    {
        return [
            'method' => $tracking->getMethod(),
            'number' => $tracking->getNumber(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLineItem(OrderLineItem $lineItem): array
    {
        return [
            'id' => $lineItem->getId(),
            'product_id' => $lineItem->getProduct()?->getId(), /** @phpstan-ignore nullsafe.neverNull */
            'product_sku' => $lineItem->getProductSku(),
            'product_name' => $lineItem->getProductName(),
            'product_image_url' => $this->resolveProductImageUrl($lineItem),
            'free_form_product' => $lineItem->getFreeFormProduct(),
            'quantity' => $lineItem->getQuantity(),
            'unit' => $lineItem->getProductUnitCode(),
            'price' => $this->formatMoney($lineItem->getValue()),
            'currency' => $lineItem->getCurrency(),
            'price_type' => $lineItem->getPriceType(),
            'comment' => $lineItem->getComment(),
            'ship_by' => $lineItem->getShipBy()?->format('c'), /** @phpstan-ignore nullsafe.neverNull */
            'shipping_method' => $lineItem->getShippingMethod(),
            'shipping_method_type' => $lineItem->getShippingMethodType(),
            'shipping_estimate_amount' => $this->formatMoney($lineItem->getShippingEstimateAmount()),
        ];
    }

    private function resolveProductImageUrl(OrderLineItem $lineItem): ?string
    {
        $product = $lineItem->getProduct();
        if ($product === null) {
            return null;
        }

        $images = $product->getImagesByType('listing');
        if ($images === null || $images->isEmpty()) {
            return null;
        }

        $productImage = $images->first();
        if ($productImage === false) {
            return null;
        }

        $file = $productImage->getImage();
        if ($file === null) {
            return null;
        }

        $path = $this->attachmentManager->getFilteredImageUrl(
            $file,
            'product_small',
            '',
            UrlGeneratorInterface::ABSOLUTE_PATH
        );

        return $this->urlResolver->baseOrigin() . $path;
    }

    private function resolveStatus(Order $order): string
    {
        $internalStatus = $order->getInternalStatus();

        /** @phpstan-ignore identical.alwaysFalse (Oro PHPDoc says non-null, but null is possible for new orders) */
        if ($internalStatus === null) {
            return 'unknown';
        }

        return (string) $internalStatus->getId();
    }

    private function formatMoney(float|int|string|null $value): ?string
    {
        if ($value === null || !is_finite((float) $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
