<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Serializer;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Kenzi\OroCommerceBundle\Serializer\OrderPayloadSerializer;
use Oro\Bundle\AttachmentBundle\Entity\File;
use Oro\Bundle\AttachmentBundle\Manager\AttachmentManager;
use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\CustomerBundle\Entity\CustomerUser;
use Oro\Bundle\EntityExtendBundle\Entity\EnumOptionInterface;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\OrderBundle\Entity\OrderAddress;
use Oro\Bundle\OrderBundle\Entity\OrderLineItem;
use Oro\Bundle\OrderBundle\Entity\OrderShippingTracking;
use Oro\Bundle\ProductBundle\Entity\Product;
use Oro\Bundle\ProductBundle\Entity\ProductImage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OrderPayloadSerializerTest extends TestCase
{
    private OrderPayloadSerializer $serializer;
    private AttachmentManager&MockObject $attachmentManager;
    private ApplicationUrlResolver&MockObject $urlResolver;

    protected function setUp(): void
    {
        $this->attachmentManager = $this->createMock(AttachmentManager::class);
        $this->urlResolver = $this->createMock(ApplicationUrlResolver::class);
        $this->urlResolver->method('baseOrigin')->willReturn('https://oro.example.com');
        $this->serializer = new OrderPayloadSerializer($this->attachmentManager, $this->urlResolver);
    }

    public function testSerializeReturnsCorrectEnvelopeStructure(): void
    {
        $order = $this->createOrderMock();
        $timestamp = 1700000000;

        $result = $this->serializer->serialize($order, 'order.created', $timestamp);

        $this->assertSame('order.created', $result['event']);
        $this->assertSame(1700000000, $result['timestamp']);
        $this->assertArrayHasKey('data', $result);
    }

    public function testSerializeUsesCurrentTimeWhenTimestampIsNull(): void
    {
        $order = $this->createOrderMock();

        $before = time();
        $result = $this->serializer->serialize($order, 'order.updated');
        $after = time();

        $this->assertGreaterThanOrEqual($before, $result['timestamp']);
        $this->assertLessThanOrEqual($after, $result['timestamp']);
    }

    public function testSerializePreservesEventType(): void
    {
        $order = $this->createOrderMock();

        $created = $this->serializer->serialize($order, 'order.created', 1);
        $updated = $this->serializer->serialize($order, 'order.updated', 1);

        $this->assertSame('order.created', $created['event']);
        $this->assertSame('order.updated', $updated['event']);
    }

    public function testSerializeOrderScalarFields(): void
    {
        $createdAt = new \DateTime('2024-01-15T10:30:00+00:00');
        $updatedAt = new \DateTime('2024-01-16T14:00:00+00:00');
        $shipUntil = new \DateTime('2024-02-01T00:00:00+00:00');

        $totalDiscounts = $this->createValueObject(5.50);
        $shippingCost = $this->createValueObject(12.00);

        $order = $this->createOrderMock([
            'getId' => 42,
            'getIdentifier' => 'ORD-2024-001',
            'getEmail' => 'buyer@example.com',
            'getCurrency' => 'USD',
            'getSubtotal' => 99.99,
            'getTotal' => 109.99,
            'getTotalDiscounts' => $totalDiscounts,
            'getShippingMethod' => 'flat_rate',
            'getShippingMethodType' => 'primary',
            'getShippingCost' => $shippingCost,
            'getPoNumber' => 'PO-5678',
            'getCustomerNotes' => 'Leave at door',
            'getEstimatedShippingCostAmount' => 10.00,
            'getOverriddenShippingCostAmount' => null,
            'getSourceEntityClass' => 'Oro\\Bundle\\ShoppingListBundle\\Entity\\ShoppingList',
            'getSourceEntityId' => 7,
            'getSourceEntityIdentifier' => 'SL-7',
            'getCreatedAt' => $createdAt,
            'getUpdatedAt' => $updatedAt,
            'getShipUntil' => $shipUntil,
        ]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame(42, $data['id']);
        $this->assertSame('ORD-2024-001', $data['identifier']);
        $this->assertSame('buyer@example.com', $data['email']);
        $this->assertSame('USD', $data['currency']);
        $this->assertSame('99.99', $data['subtotal']);
        $this->assertSame('109.99', $data['total']);
        $this->assertSame('5.50', $data['total_discounts']);
        $this->assertSame('flat_rate', $data['shipping_method']);
        $this->assertSame('primary', $data['shipping_method_type']);
        $this->assertSame('12.00', $data['shipping_cost']);
        $this->assertSame('PO-5678', $data['po_number']);
        $this->assertSame('Leave at door', $data['customer_notes']);
        $this->assertSame('10.00', $data['estimated_shipping_cost_amount']);
        $this->assertNull($data['overridden_shipping_cost_amount']);
        $this->assertSame('Oro\\Bundle\\ShoppingListBundle\\Entity\\ShoppingList', $data['source_entity_class']);
        $this->assertSame(7, $data['source_entity_id']);
        $this->assertSame('SL-7', $data['source_entity_identifier']);
        $this->assertSame('2024-01-15T10:30:00+00:00', $data['created_at']);
        $this->assertSame('2024-01-16T14:00:00+00:00', $data['updated_at']);
        $this->assertSame('2024-02-01T00:00:00+00:00', $data['ship_until']);
    }

    public function testMoneyValuesAreFormattedAsTwoDecimalStrings(): void
    {
        $order = $this->createOrderMock([
            'getSubtotal' => 100,
            'getTotal' => 100.1,
            'getEstimatedShippingCostAmount' => 0,
        ]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame('100.00', $data['subtotal']);
        $this->assertSame('100.10', $data['total']);
        $this->assertSame('0.00', $data['estimated_shipping_cost_amount']);
    }

    public function testNullMoneyValuesReturnNull(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertNull($data['subtotal']);
        $this->assertNull($data['total']);
        $this->assertNull($data['total_discounts']);
        $this->assertNull($data['shipping_cost']);
        $this->assertNull($data['estimated_shipping_cost_amount']);
        $this->assertNull($data['overridden_shipping_cost_amount']);
    }

    public function testResolveStatusReturnsInternalStatusId(): void
    {
        $status = $this->createMock(EnumOptionInterface::class);
        $status->method('getId')->willReturn('open');

        $order = $this->createOrderMock(['getInternalStatus' => $status]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame('open', $data['status']);
    }

    public function testResolveStatusReturnsUnknownWhenNull(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame('unknown', $data['status']);
    }

    public function testCustomerIsIncludedWhenPresent(): void
    {
        $customer = $this->createMock(Customer::class);
        $customer->method('getId')->willReturn(10);
        $customer->method('getName')->willReturn('Acme Corp');

        $order = $this->createOrderMock(['getCustomer' => $customer]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame(['id' => 10, 'name' => 'Acme Corp'], $data['customer']);
    }

    public function testCustomerIsOmittedWhenNull(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertArrayNotHasKey('customer', $data);
    }

    public function testCustomerUserIsIncludedWhenPresent(): void
    {
        $customerUser = $this->createMock(CustomerUser::class);
        $customerUser->method('getId')->willReturn(20);
        $customerUser->method('getEmail')->willReturn('john@acme.com');
        $customerUser->method('getFirstName')->willReturn('John');
        $customerUser->method('getLastName')->willReturn('Doe');

        $order = $this->createOrderMock(['getCustomerUser' => $customerUser]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame([
            'id' => 20,
            'email' => 'john@acme.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ], $data['customer_user']);
    }

    public function testCustomerUserIsOmittedWhenNull(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertArrayNotHasKey('customer_user', $data);
    }

    public function testBillingAddressIsSerializedWhenPresent(): void
    {
        $address = $this->createAddressMock([
            'getNamePrefix' => 'Mr.',
            'getFirstName' => 'John',
            'getMiddleName' => 'Q',
            'getLastName' => 'Doe',
            'getNameSuffix' => 'Jr.',
            'getOrganization' => 'Acme Corp',
            'getStreet' => '123 Main St',
            'getStreet2' => 'Suite 100',
            'getCity' => 'Springfield',
            'getRegionName' => 'Illinois',
            'getRegionCode' => 'IL',
            'getPostalCode' => '62701',
            'getCountryName' => 'United States',
            'getCountryIso2' => 'US',
            'getCountryIso3' => 'USA',
            'getPhone' => '+1-555-0100',
        ]);

        $order = $this->createOrderMock(['getBillingAddress' => $address]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame('John', $data['billing_address']['first_name']);
        $this->assertSame('Doe', $data['billing_address']['last_name']);
        $this->assertSame('Springfield', $data['billing_address']['city']);
        $this->assertSame('US', $data['billing_address']['country_iso2']);
        $this->assertSame('+1-555-0100', $data['billing_address']['phone']);
    }

    public function testShippingAddressIsSerializedWhenPresent(): void
    {
        $address = $this->createAddressMock([
            'getFirstName' => 'Jane',
            'getLastName' => 'Smith',
            'getStreet' => '456 Oak Ave',
            'getCity' => 'Portland',
            'getRegionName' => 'Oregon',
            'getRegionCode' => 'OR',
            'getPostalCode' => '97201',
            'getCountryName' => 'United States',
            'getCountryIso2' => 'US',
            'getCountryIso3' => 'USA',
            'getPhone' => '+1-555-0200',
        ]);

        $order = $this->createOrderMock(['getShippingAddress' => $address]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame('Jane', $data['shipping_address']['first_name']);
        $this->assertSame('Smith', $data['shipping_address']['last_name']);
        $this->assertSame('Portland', $data['shipping_address']['city']);
        $this->assertSame('US', $data['shipping_address']['country_iso2']);
        $this->assertSame('+1-555-0200', $data['shipping_address']['phone']);
    }

    public function testAddressesAreOmittedWhenNull(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertArrayNotHasKey('billing_address', $data);
        $this->assertArrayNotHasKey('shipping_address', $data);
    }

    public function testWebsiteIsIncludedWhenPresent(): void
    {
        $website = $this->createMock(\Oro\Bundle\WebsiteBundle\Entity\Website::class);
        $website->method('getId')->willReturn(1);
        $website->method('getName')->willReturn('B2B Store');

        $order = $this->createOrderMock(['getWebsite' => $website]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame(['id' => 1, 'name' => 'B2B Store'], $data['website']);
    }

    public function testWebsiteIsOmittedWhenNull(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertArrayNotHasKey('website', $data);
    }

    public function testLineItemsAreSerializedAsArray(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(77);

        $lineItem = $this->createLineItemMock([
            'getId' => 1,
            'getProduct' => $product,
            'getProductSku' => 'SKU-001',
            'getProductName' => 'Widget',
            'getFreeFormProduct' => 'Custom Widget',
            'getQuantity' => 3.0,
            'getProductUnitCode' => 'item',
            'getValue' => 49.99,
            'getCurrency' => 'USD',
            'getPriceType' => 10,
            'getComment' => 'Rush order',
            'getShipBy' => new \DateTime('2024-03-01T00:00:00+00:00'),
            'getShippingMethod' => 'flat_rate',
            'getShippingMethodType' => 'primary',
            'getShippingEstimateAmount' => 7.50,
        ]);

        $order = $this->createOrderMock(['getLineItems' => new \ArrayIterator([$lineItem])]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertCount(1, $data['line_items']);
        $item = $data['line_items'][0];
        $this->assertSame(1, $item['id']);
        $this->assertSame(77, $item['product_id']);
        $this->assertSame('SKU-001', $item['product_sku']);
        $this->assertSame('Widget', $item['product_name']);
        $this->assertArrayHasKey('product_image_url', $item);
        $this->assertNull($item['product_image_url']);
        $this->assertSame('Custom Widget', $item['free_form_product']);
        $this->assertSame(3.0, $item['quantity']);
        $this->assertSame('item', $item['unit']);
        $this->assertSame('49.99', $item['price']);
        $this->assertSame('USD', $item['currency']);
        $this->assertSame(10, $item['price_type']);
        $this->assertSame('Rush order', $item['comment']);
        $this->assertSame('2024-03-01T00:00:00+00:00', $item['ship_by']);
        $this->assertSame('flat_rate', $item['shipping_method']);
        $this->assertSame('primary', $item['shipping_method_type']);
        $this->assertSame('7.50', $item['shipping_estimate_amount']);
    }

    public function testEmptyLineItemsReturnsEmptyArray(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame([], $data['line_items']);
    }

    public function testShippingTrackingsAreSerializedAsArray(): void
    {
        $tracking = $this->createMock(OrderShippingTracking::class);
        $tracking->method('getMethod')->willReturn('UPS');
        $tracking->method('getNumber')->willReturn('1Z999AA10123456784');

        $order = $this->createOrderMock(['getShippingTrackings' => new \ArrayIterator([$tracking])]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertCount(1, $data['shipping_trackings']);
        $this->assertSame('UPS', $data['shipping_trackings'][0]['method']);
        $this->assertSame('1Z999AA10123456784', $data['shipping_trackings'][0]['number']);
    }

    public function testEmptyShippingTrackingsReturnsEmptyArray(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame([], $data['shipping_trackings']);
    }

    public function testTimestampsAreIso8601(): void
    {
        $order = $this->createOrderMock([
            'getCreatedAt' => new \DateTime('2024-06-15T08:30:00+02:00'),
            'getUpdatedAt' => new \DateTime('2024-06-15T10:00:00+00:00'),
            'getShipUntil' => new \DateTime('2024-07-01T00:00:00+00:00'),
        ]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame('2024-06-15T08:30:00+02:00', $data['created_at']);
        $this->assertSame('2024-06-15T10:00:00+00:00', $data['updated_at']);
        $this->assertSame('2024-07-01T00:00:00+00:00', $data['ship_until']);
    }

    public function testNullTimestampsReturnNull(): void
    {
        $order = $this->createOrderMock();

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertNull($data['created_at']);
        $this->assertNull($data['updated_at']);
        $this->assertNull($data['ship_until']);
    }

    public function testLineItemShipByDateIsIso8601(): void
    {
        $lineItem = $this->createLineItemMock([
            'getId' => 1,
            'getProductSku' => 'SKU-001',
            'getProductName' => 'Widget',
            'getQuantity' => 1.0,
            'getProductUnitCode' => 'item',
            'getValue' => 10.0,
            'getCurrency' => 'USD',
            'getPriceType' => 10,
            'getShipBy' => new \DateTime('2024-03-01T00:00:00+00:00'),
            'getShippingEstimateAmount' => 5.50,
        ]);

        $order = $this->createOrderMock(['getLineItems' => new \ArrayIterator([$lineItem])]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertNull($data['line_items'][0]['product_id']);
        $this->assertSame('2024-03-01T00:00:00+00:00', $data['line_items'][0]['ship_by']);
        $this->assertSame('5.50', $data['line_items'][0]['shipping_estimate_amount']);
    }

    public function testLineItemProductImageUrlIsResolvedWhenPresent(): void
    {
        $file = $this->createMock(File::class);

        $productImage = $this->getMockBuilder(ProductImage::class)
            ->disableOriginalConstructor()
            ->addMethods(['getImage'])
            ->getMock();
        $productImage->method('getImage')->willReturn($file);

        $images = new \Doctrine\Common\Collections\ArrayCollection([$productImage]);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(77);
        $product->method('getImagesByType')->with('listing')->willReturn($images);

        $this->attachmentManager
            ->expects($this->once())
            ->method('getFilteredImageUrl')
            ->with($file, 'product_small', '', UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/media/cache/product_small/image.jpg');

        $lineItem = $this->createLineItemMock([
            'getId' => 1,
            'getProduct' => $product,
            'getProductSku' => 'SKU-001',
            'getProductName' => 'Widget',
            'getQuantity' => 1.0,
            'getProductUnitCode' => 'item',
            'getValue' => 49.99,
            'getCurrency' => 'USD',
            'getPriceType' => 10,
        ]);

        $order = $this->createOrderMock(['getLineItems' => new \ArrayIterator([$lineItem])]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertSame(
            'https://oro.example.com/media/cache/product_small/image.jpg',
            $data['line_items'][0]['product_image_url']
        );
    }

    public function testLineItemProductImageUrlIsNullWhenNoProduct(): void
    {
        $lineItem = $this->createLineItemMock([
            'getId' => 1,
            'getProductSku' => 'SKU-001',
            'getProductName' => 'Widget',
            'getQuantity' => 1.0,
            'getProductUnitCode' => 'item',
            'getValue' => 49.99,
            'getCurrency' => 'USD',
            'getPriceType' => 10,
        ]);

        $order = $this->createOrderMock(['getLineItems' => new \ArrayIterator([$lineItem])]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertNull($data['line_items'][0]['product_image_url']);
    }

    public function testLineItemProductImageUrlIsNullWhenImagesByTypeReturnsNull(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(77);
        $product->method('getImagesByType')->with('listing')->willReturn(null);

        $lineItem = $this->createLineItemMock([
            'getId' => 1,
            'getProduct' => $product,
            'getProductSku' => 'SKU-001',
            'getProductName' => 'Widget',
            'getQuantity' => 1.0,
            'getProductUnitCode' => 'item',
            'getValue' => 49.99,
            'getCurrency' => 'USD',
            'getPriceType' => 10,
        ]);

        $order = $this->createOrderMock(['getLineItems' => new \ArrayIterator([$lineItem])]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertNull($data['line_items'][0]['product_image_url']);
    }

    public function testLineItemProductImageUrlIsNullWhenNoImages(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(77);
        $product->method('getImagesByType')->with('listing')
            ->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $lineItem = $this->createLineItemMock([
            'getId' => 1,
            'getProduct' => $product,
            'getProductSku' => 'SKU-001',
            'getProductName' => 'Widget',
            'getQuantity' => 1.0,
            'getProductUnitCode' => 'item',
            'getValue' => 49.99,
            'getCurrency' => 'USD',
            'getPriceType' => 10,
        ]);

        $order = $this->createOrderMock(['getLineItems' => new \ArrayIterator([$lineItem])]);

        $data = $this->serializer->serialize($order, 'order.created', 1)['data'];

        $this->assertNull($data['line_items'][0]['product_image_url']);
    }

    /**
     * Create an Order mock with configurable return values.
     *
     * getInternalStatus is a @method magic method (Oro entity extend),
     * so we declare it via addMethods. All real methods we stub must be
     * listed in onlyMethods — PHPUnit 9 requires both when combining them.
     *
     * Uses willReturnCallback to allow a single configuration point
     * per method rather than trying to reconfigure stubs.
     *
     * @param array<string, mixed> $values Method name => return value
     */
    private function createOrderMock(array $values = []): Order&MockObject
    {
        $defaults = [
            'getId' => null,
            'getIdentifier' => null,
            'getInternalStatus' => null,
            'getEmail' => null,
            'getCurrency' => null,
            'getSubtotal' => null,
            'getTotal' => null,
            'getTotalDiscounts' => null,
            'getShippingMethod' => null,
            'getShippingMethodType' => null,
            'getShippingCost' => null,
            'getEstimatedShippingCostAmount' => null,
            'getOverriddenShippingCostAmount' => null,
            'getPoNumber' => null,
            'getCustomerNotes' => null,
            'getShipUntil' => null,
            'getSourceEntityClass' => null,
            'getSourceEntityId' => null,
            'getSourceEntityIdentifier' => null,
            'getCreatedAt' => null,
            'getUpdatedAt' => null,
            'getCustomer' => null,
            'getCustomerUser' => null,
            'getBillingAddress' => null,
            'getShippingAddress' => null,
            'getWebsite' => null,
            'getLineItems' => new \ArrayIterator([]),
            'getShippingTrackings' => new \ArrayIterator([]),
        ];

        $merged = array_merge($defaults, $values);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId', 'getIdentifier', 'getEmail', 'getCurrency',
                'getSubtotal', 'getTotal', 'getTotalDiscounts',
                'getShippingMethod', 'getShippingMethodType', 'getShippingCost',
                'getEstimatedShippingCostAmount', 'getOverriddenShippingCostAmount',
                'getPoNumber', 'getCustomerNotes', 'getShipUntil',
                'getSourceEntityClass', 'getSourceEntityId', 'getSourceEntityIdentifier',
                'getCreatedAt', 'getUpdatedAt',
                'getCustomer', 'getCustomerUser',
                'getBillingAddress', 'getShippingAddress', 'getWebsite',
                'getLineItems', 'getShippingTrackings',
            ])
            ->addMethods(['getInternalStatus'])
            ->getMock();

        foreach ($merged as $method => $returnValue) {
            $order->method($method)->willReturn($returnValue);
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $values Method name => return value
     */
    private function createAddressMock(array $values = []): OrderAddress&MockObject
    {
        $defaults = [
            'getNamePrefix' => null,
            'getFirstName' => null,
            'getMiddleName' => null,
            'getLastName' => null,
            'getNameSuffix' => null,
            'getOrganization' => null,
            'getStreet' => null,
            'getStreet2' => null,
            'getCity' => null,
            'getRegionName' => null,
            'getRegionCode' => null,
            'getPostalCode' => null,
            'getCountryName' => null,
            'getCountryIso2' => null,
            'getCountryIso3' => null,
            'getPhone' => null,
        ];

        $merged = array_merge($defaults, $values);
        $address = $this->createMock(OrderAddress::class);

        foreach ($merged as $method => $returnValue) {
            $address->method($method)->willReturn($returnValue);
        }

        return $address;
    }

    /**
     * @param array<string, mixed> $values Method name => return value
     */
    private function createLineItemMock(array $values = []): OrderLineItem&MockObject
    {
        $defaults = [
            'getId' => null,
            'getProduct' => null,
            'getProductSku' => null,
            'getProductName' => null,
            'getFreeFormProduct' => null,
            'getQuantity' => null,
            'getProductUnitCode' => null,
            'getValue' => null,
            'getCurrency' => null,
            'getPriceType' => null,
            'getComment' => null,
            'getShipBy' => null,
            'getShippingMethod' => null,
            'getShippingMethodType' => null,
            'getShippingEstimateAmount' => null,
        ];

        $merged = array_merge($defaults, $values);
        $lineItem = $this->createMock(OrderLineItem::class);

        foreach ($merged as $method => $returnValue) {
            $lineItem->method($method)->willReturn($returnValue);
        }

        return $lineItem;
    }

    /**
     * Create a simple object with a getValue() method, used to mock
     * Oro Price objects returned by getTotalDiscounts() / getShippingCost().
     */
    private function createValueObject(float $value): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getValue'])
            ->getMock();
        $mock->method('getValue')->willReturn($value);

        return $mock;
    }
}
