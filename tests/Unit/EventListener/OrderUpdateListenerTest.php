<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Kenzi\OroCommerceBundle\Async\OrderWebhookTopic;
use Kenzi\OroCommerceBundle\EventListener\OrderUpdateListener;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class OrderUpdateListenerTest extends TestCase
{
    /** @var MessageProducerInterface&MockObject */
    private MockObject $messageProducer;
    private OrderUpdateListener $listener;

    protected function setUp(): void
    {
        $this->messageProducer = $this->createMock(MessageProducerInterface::class);

        $this->listener = new OrderUpdateListener(
            $this->messageProducer,
        );
    }

    // -- Sends message when watched fields change ─────────────────────

    public function testSendsMessageWhenInternalStatusChangesInSerializedData(): void
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

    public function testSendsMessageWhenShippingStatusChangesInSerializedData(): void
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

    public function testSendsMessageWhenSerializedDataOldSideIsNull(): void
    {
        // Doctrine produces this shape on orders created before the
        // serialized-fields bundle installed — the column is nullable per
        // Oro migrations. Must not throw on null-side indexing.
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

    public function testSendsMessageWhenSerializedDataNewSideIsNull(): void
    {
        // Symmetric to the null-old-side case: the `??`-guarded comparison
        // must treat array-vs-null as a real change without throwing.
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

    public function testSkipsWhenSerializedDataBothSidesAreNull(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'serialized_data' => [null, null],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSendsMessageWhenTotalValueChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['totalValue' => [99.99, 109.99]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSendsMessageWhenSubtotalValueChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['subtotalValue' => [99.99, 109.99]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSendsMessageWhenTotalDiscountsAmountChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['totalDiscountsAmount' => [0.0, 5.50]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSendsMessageWhenEstimatedShippingCostAmountChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['estimatedShippingCostAmount' => [0.0, 7.50]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSendsMessageWhenOverriddenShippingCostAmountChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['overriddenShippingCostAmount' => [null, 12.00]]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSendsMessageWhenCustomerChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['customer' => [null, 'customer_obj']]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSendsMessageWhenShippingAddressChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->once())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['shippingAddress' => ['old', 'new']]);
        $this->listener->postUpdate($order, $args);
    }

    // -- Skips when only non-watched fields change ────────────────────

    public function testSkipsWhenOnlyUpdatedAtChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['updatedAt' => ['2026-01-01', '2026-01-02']]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSkipsWhenOnlyCreatedAtChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['createdAt' => ['2026-01-01', '2026-01-02']]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSkipsWhenOnlyTimestampsChange(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, [
            'updatedAt' => ['2026-01-01', '2026-01-02'],
            'createdAt' => ['2026-01-01', '2026-01-02'],
        ]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSkipsWhenUnwatchedFieldChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, ['someInternalField' => ['old', 'new']]);
        $this->listener->postUpdate($order, $args);
    }

    public function testSkipsWhenNoChanges(): void
    {
        $order = $this->createOrderMock(42);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createArgsWithChangeSet($order, []);
        $this->listener->postUpdate($order, $args);
    }

    public function testSkipsWhenSerializedDataOnlyHasNonWatchedKeys(): void
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

    public function testSkipsWhenSerializedDataHasEqualOldAndNewValues(): void
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

    public function testSendsWhenWatchedFieldChangesAlongsideTimestamp(): void
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

    public function testSkipsWhenOrderIdIsNull(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);

        $this->messageProducer->expects($this->never())->method('send');

        $args = $this->createMock(LifecycleEventArgs::class);
        $this->listener->postUpdate($order, $args);
    }

    // -- Event type ───────────────────────────────────────────────────

    public function testAlwaysSendsOrderUpdatedEventType(): void
    {
        $order = $this->createOrderMock(1);

        $this->messageProducer->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->callback(fn (array $body) => $body['event'] === 'order.updated')
            );

        $args = $this->createArgsWithChangeSet($order, ['email' => ['old@test.com', 'new@test.com']]);
        $this->listener->postUpdate($order, $args);
    }

    // -- Helpers ──────────────────────────────────────────────────────

    /** @return Order&MockObject */
    private function createOrderMock(int $id): MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);

        return $order;
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
