<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\EventListener;

use Kenzi\OroCommerceBundle\EventListener\OrderCheckoutListener;
use Kenzi\OroCommerceBundle\Serializer\OrderPayloadSerializer;
use Kenzi\OroCommerceBundle\Webhook\WebhookDispatcher;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use Oro\Component\Action\Event\ExtendableActionEvent;
use Oro\Component\Action\Model\AbstractStorage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrderCheckoutListenerTest extends TestCase
{
    /** @var WebhookDispatcher&MockObject */
    private MockObject $dispatcher;
    /** @var OrderPayloadSerializer&MockObject */
    private MockObject $serializer;
    private OrderCheckoutListener $listener;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(WebhookDispatcher::class);
        $this->serializer = $this->createMock(OrderPayloadSerializer::class);

        $this->listener = new OrderCheckoutListener(
            $this->dispatcher,
            $this->serializer,
            new NullLogger(),
        );
    }

    public function testDispatchesOrderCreatedWebhook(): void
    {
        $website = $this->createMock(Website::class);
        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn($website);

        $this->dispatcher->method('isEnabledForWebsite')->with($website)->willReturn(true);

        $payload = ['event' => 'order.created', 'timestamp' => 1700000000, 'data' => ['id' => 42]];
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($order, 'order.created')
            ->willReturn($payload);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($payload, 'order.created', $website);

        $event = $this->createEventWithOrder($order);
        $this->listener->onFinishCheckout($event);
    }

    public function testSkipsWhenDataIsNull(): void
    {
        $event = new ExtendableActionEvent(null);

        $this->serializer->expects($this->never())->method('serialize');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->onFinishCheckout($event);
    }

    public function testSkipsWhenOrderKeyIsMissing(): void
    {
        $data = $this->createMock(AbstractStorage::class);
        $data->method('get')->with('order')->willReturn(null);

        $event = new ExtendableActionEvent($data);

        $this->serializer->expects($this->never())->method('serialize');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->onFinishCheckout($event);
    }

    public function testSkipsWhenOrderIsNotOrderInstance(): void
    {
        $data = $this->createMock(AbstractStorage::class);
        $data->method('get')->with('order')->willReturn('not-an-order');

        $event = new ExtendableActionEvent($data);

        $this->serializer->expects($this->never())->method('serialize');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->listener->onFinishCheckout($event);
    }

    public function testSkipsWhenWebsiteNotEnabledForKenzi(): void
    {
        $website = $this->createMock(Website::class);
        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn($website);

        $this->dispatcher->method('isEnabledForWebsite')
            ->with($website)
            ->willReturn(false);

        $this->serializer->expects($this->never())->method('serialize');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $event = $this->createEventWithOrder($order);
        $this->listener->onFinishCheckout($event);
    }

    public function testPassesOrderWebsiteToDispatcher(): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(7);

        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn($website);

        $this->dispatcher->method('isEnabledForWebsite')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->anything(), 'order.created', $this->identicalTo($website));

        $event = $this->createEventWithOrder($order);
        $this->listener->onFinishCheckout($event);
    }

    public function testPassesNullWebsiteWhenOrderHasNoWebsite(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn(null);

        $this->dispatcher->method('isEnabledForWebsite')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->anything(), 'order.created', null);

        $event = $this->createEventWithOrder($order);
        $this->listener->onFinishCheckout($event);
    }

    public function testCatchesSerializerException(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn(null);
        $order->method('getId')->willReturn(42);

        $this->dispatcher->method('isEnabledForWebsite')->willReturn(true);
        $this->serializer->method('serialize')
            ->willThrowException(new \RuntimeException('Serialization failed'));

        $this->dispatcher->expects($this->never())->method('dispatch');

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
