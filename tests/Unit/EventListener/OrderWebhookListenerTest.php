<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Kenzi\OroCommerceBundle\Async\OrderWebhookTopic;
use Kenzi\OroCommerceBundle\EventListener\OrderWebhookListener;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OrderWebhookListenerTest extends TestCase
{
    /** @var MessageProducerInterface&MockObject */
    private MockObject $messageProducer;
    private OrderWebhookListener $listener;

    protected function setUp(): void
    {
        $this->messageProducer = $this->createMock(MessageProducerInterface::class);

        $this->listener = new OrderWebhookListener(
            $this->messageProducer,
        );
    }

    // == postPersist — order.created ══════════════════════════════════

    public function testPostPersistSendsOrderCreatedMessage(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())
            ->method('send')
            ->with(
                OrderWebhookTopic::getName(),
                ['order_id' => 42, 'event' => 'order.created']
            );

        $args = $this->createLifecycleArgs();
        $this->listener->postPersist($order, $args);
    }

    public function testPostPersistSkipsWhenOrderIdIsNull(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createLifecycleArgs();
        $this->listener->postPersist($order, $args);
    }

    public function testPostPersistSkipsSubOrders(): void
    {
        $parent = $this->createOrderMock(1);
        $child = $this->createOrderMock(2, $parent);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createLifecycleArgs();
        $this->listener->postPersist($child, $args);
    }

    // == postUpdate — order.updated ══════════════════════════════════

    // -- Sends message when watched fields change ─────────────────────

    public function testPostUpdateSendsMessageWhenInternalStatusChangesInSerializedData(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())
            ->method('send')
            ->with(
                OrderWebhookTopic::getName(),
                ['order_id' => 42, 'event' => 'order.updated']
            );

        $args = $this->createArgsWithChangeSet($order, [
            'serialized_data' => [
                ['internal_status' => 'open'],
                ['internal_status' => 'shipped'],
            ],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenShippingStatusChangesInSerializedData(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'serialized_data' => [
                ['shippingStatus' => 'not_shipped'],
                ['shippingStatus' => 'shipped'],
            ],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenSerializedDataOldSideIsNull(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'serialized_data' => [
                null,
                ['shippingStatus' => 'not_shipped'],
            ],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenSerializedDataNewSideIsNull(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'serialized_data' => [
                ['shippingStatus' => 'shipped'],
                null,
            ],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSkipsWhenSerializedDataBothSidesAreNull(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'serialized_data' => [null, null],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenTotalValueChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['totalValue' => [99.99, 109.99]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenSubtotalValueChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['subtotalValue' => [99.99, 109.99]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenTotalDiscountsAmountChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['totalDiscountsAmount' => [0.0, 5.50]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenEstimatedShippingCostAmountChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['estimatedShippingCostAmount' => [0.0, 7.50]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenOverriddenShippingCostAmountChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['overriddenShippingCostAmount' => [null, 12.00]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenCustomerChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['customer' => [null, 'customer_obj']]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSendsMessageWhenShippingAddressChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['shippingAddress' => ['old', 'new']]);
        $this->listener->postUpdate($order, $args);
    }

    // -- Skips when only non-watched fields change ────────────────────

    public function testPostUpdateSkipsWhenOnlyUpdatedAtChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['updatedAt' => ['2026-01-01', '2026-01-02']]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSkipsWhenOnlyCreatedAtChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['createdAt' => ['2026-01-01', '2026-01-02']]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSkipsWhenOnlyTimestampsChange(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'updatedAt' => ['2026-01-01', '2026-01-02'],
            'createdAt' => ['2026-01-01', '2026-01-02'],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSkipsWhenUnwatchedFieldChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['someInternalField' => ['old', 'new']]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSkipsWhenNoChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, []);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSkipsWhenSerializedDataOnlyHasNonWatchedKeys(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'serialized_data' => [
                ['some_other_enum' => 'a'],
                ['some_other_enum' => 'b'],
            ],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testPostUpdateSkipsWhenSerializedDataHasEqualOldAndNewValues(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'serialized_data' => [
                ['internal_status' => 'open', 'shippingStatus' => 'not_shipped'],
                ['internal_status' => 'open', 'shippingStatus' => 'not_shipped'],
            ],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    // -- Mixed changes ────────────────────────────────────────────────

    public function testPostUpdateSendsWhenWatchedFieldChangesAlongsideTimestamp(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'updatedAt' => ['2026-01-01', '2026-01-02'],
            'subtotalValue' => [99.99, 109.99],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    // -- Null order ID ────────────────────────────────────────────────

    public function testPostUpdateSkipsWhenOrderIdIsNull(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createMock(LifecycleEventArgs::class);
        $this->listener->postUpdate($order, $args);
    }

    // -- Sub-order guard ──────────────────────────────────────────────

    public function testPostUpdateSkipsSubOrders(): void
    {
        $parent = $this->createOrderMock(1);
        $child = $this->createOrderMock(2, $parent);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($child, ['totalValue' => [99.99, 109.99]]);
        $this->listener->postUpdate($child, $args);
    }

    // == Helpers ══════════════════════════════════════════════════════

    /** @return Order&MockObject */
    private function createOrderMock(int $id, ?Order $parent = null): MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getParent')->willReturn($parent);

        return $order;
    }

    /** @return LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface> */
    private function createLifecycleArgs(): LifecycleEventArgs
    {
        return $this->createMock(LifecycleEventArgs::class);
    }

    /**
     * @param array<string, array{mixed, mixed}> $changeSet
     * @return LifecycleEventArgs<\Doctrine\ORM\EntityManagerInterface>
     */
    private function createArgsWithChangeSet(Order $order, array $changeSet): LifecycleEventArgs
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')
            ->with($order)
            ->willReturn($changeSet);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $args = $this->createMock(LifecycleEventArgs::class);
        $args->method('getObjectManager')->willReturn($em);

        return $args;
    }
}
