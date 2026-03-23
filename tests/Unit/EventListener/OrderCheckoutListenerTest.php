<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\EventListener;

use Kenzi\OroCommerceBundle\Async\OrderWebhookTopic;
use Kenzi\OroCommerceBundle\EventListener\OrderCheckoutListener;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\Action\Event\ExtendableActionEvent;
use Oro\Component\Action\Model\AbstractStorage;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrderCheckoutListenerTest extends TestCase
{
    /** @var MessageProducerInterface&MockObject */
    private MockObject $messageProducer;
    private OrderCheckoutListener $listener;

    protected function setUp(): void
    {
        $this->messageProducer = $this->createMock(MessageProducerInterface::class);

        $this->listener = new OrderCheckoutListener(
            $this->messageProducer,
            new NullLogger(),
        );
    }

    public function testSendsOrderCreatedMessage(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);

        $this->messageProducer->expects($this->once())
            ->method('send')
            ->with(
                OrderWebhookTopic::getName(),
                ['order_id' => 42, 'event' => 'order.created']
            );

        $event = $this->createEventWithOrder($order);
        $this->listener->onFinishCheckout($event);
    }

    public function testSkipsWhenDataIsNull(): void
    {
        $event = new ExtendableActionEvent(null);

        $this->messageProducer->expects($this->never())->method('send');

        $this->listener->onFinishCheckout($event);
    }

    public function testSkipsWhenOrderKeyIsMissing(): void
    {
        $data = $this->createMock(AbstractStorage::class);
        $data->method('get')->with('order')->willReturn(null);

        $event = new ExtendableActionEvent($data);

        $this->messageProducer->expects($this->never())->method('send');

        $this->listener->onFinishCheckout($event);
    }

    public function testSkipsWhenOrderIsNotOrderInstance(): void
    {
        $data = $this->createMock(AbstractStorage::class);
        $data->method('get')->with('order')->willReturn('not-an-order');

        $event = new ExtendableActionEvent($data);

        $this->messageProducer->expects($this->never())->method('send');

        $this->listener->onFinishCheckout($event);
    }

    public function testSkipsWhenOrderIdIsNull(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);

        $this->messageProducer->expects($this->never())->method('send');

        $event = $this->createEventWithOrder($order);
        $this->listener->onFinishCheckout($event);
    }

    public function testSendsCorrectOrderId(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(99);

        $this->messageProducer->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->callback(fn(array $body) => $body['order_id'] === 99)
            );

        $event = $this->createEventWithOrder($order);
        $this->listener->onFinishCheckout($event);
    }

    public function testAlwaysSendsOrderCreatedEventType(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(1);

        $this->messageProducer->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->callback(fn(array $body) => $body['event'] === 'order.created')
            );

        $event = $this->createEventWithOrder($order);
        $this->listener->onFinishCheckout($event);
    }

    private function createEventWithOrder(Order $order): ExtendableActionEvent
    {
        $data = $this->createMock(AbstractStorage::class);
        $data->method('get')->with('order')->willReturn($order);

        return new ExtendableActionEvent($data);
    }
}
