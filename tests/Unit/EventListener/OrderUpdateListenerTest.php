<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\EventListener;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Kenzi\OroCommerceBundle\EventListener\OrderUpdateListener;
use Kenzi\OroCommerceBundle\Serializer\OrderPayloadSerializer;
use Kenzi\OroCommerceBundle\Webhook\WebhookDispatcher;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrderUpdateListenerTest extends TestCase
{
    /** @var WebhookDispatcher&MockObject */
    private MockObject $dispatcher;
    /** @var OrderPayloadSerializer&MockObject */
    private MockObject $serializer;
    private OrderUpdateListener $listener;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(WebhookDispatcher::class);
        $this->serializer = $this->createMock(OrderPayloadSerializer::class);

        $this->listener = new OrderUpdateListener(
            $this->dispatcher,
            $this->serializer,
            new NullLogger(),
        );
    }

    public function testDispatchesOrderUpdatedWebhook(): void
    {
        $website = $this->createMock(Website::class);
        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn($website);

        $this->dispatcher->method('isEnabledForWebsite')->with($website)->willReturn(true);

        $payload = ['event' => 'order.updated', 'timestamp' => 1700000000, 'data' => ['id' => 42]];
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($order, 'order.updated')
            ->willReturn($payload);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($payload, 'order.updated', $website);

        $args = $this->createMock(LifecycleEventArgs::class);
        $this->listener->postUpdate($order, $args);
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

        $args = $this->createMock(LifecycleEventArgs::class);
        $this->listener->postUpdate($order, $args);
    }

    public function testPassesOrderWebsiteToDispatcher(): void
    {
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(3);

        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn($website);

        $this->dispatcher->method('isEnabledForWebsite')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->anything(), 'order.updated', $this->identicalTo($website));

        $args = $this->createMock(LifecycleEventArgs::class);
        $this->listener->postUpdate($order, $args);
    }

    public function testPassesNullWebsiteWhenOrderHasNoWebsite(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn(null);

        $this->dispatcher->method('isEnabledForWebsite')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->anything(), 'order.updated', null);

        $args = $this->createMock(LifecycleEventArgs::class);
        $this->listener->postUpdate($order, $args);
    }

    public function testUsesCorrectEventType(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn(null);

        $this->dispatcher->method('isEnabledForWebsite')->willReturn(true);

        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($order, 'order.updated')
            ->willReturn(['data' => []]);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->anything(), 'order.updated', $this->anything());

        $args = $this->createMock(LifecycleEventArgs::class);
        $this->listener->postUpdate($order, $args);
    }

    public function testCatchesSerializerException(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getWebsite')->willReturn(null);
        $order->method('getId')->willReturn(99);

        $this->dispatcher->method('isEnabledForWebsite')->willReturn(true);
        $this->serializer->method('serialize')
            ->willThrowException(new \RuntimeException('Serialization failed'));

        $this->dispatcher->expects($this->never())->method('dispatch');

        $args = $this->createMock(LifecycleEventArgs::class);
        $this->listener->postUpdate($order, $args);
    }
}
