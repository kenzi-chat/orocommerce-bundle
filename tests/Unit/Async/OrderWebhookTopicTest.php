<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Async;

use Kenzi\OroCommerceBundle\Async\OrderWebhookTopic;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OrderWebhookTopicTest extends TestCase
{
    private OrderWebhookTopic $topic;

    protected function setUp(): void
    {
        $this->topic = new OrderWebhookTopic();
    }

    public function testGetName(): void
    {
        $this->assertSame('kenzi.webhook.order', OrderWebhookTopic::getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty(OrderWebhookTopic::getDescription());
    }

    public function testAcceptsValidMessageBody(): void
    {
        $resolver = new OptionsResolver();
        $this->topic->configureMessageBody($resolver);

        $resolved = $resolver->resolve(['order_id' => 42, 'event' => 'order.created']);

        $this->assertSame(42, $resolved['order_id']);
        $this->assertSame('order.created', $resolved['event']);
    }

    public function testRejectsMissingOrderId(): void
    {
        $resolver = new OptionsResolver();
        $this->topic->configureMessageBody($resolver);

        $this->expectException(MissingOptionsException::class);
        $resolver->resolve(['event' => 'order.created']);
    }

    public function testRejectsMissingEvent(): void
    {
        $resolver = new OptionsResolver();
        $this->topic->configureMessageBody($resolver);

        $this->expectException(MissingOptionsException::class);
        $resolver->resolve(['order_id' => 42]);
    }

    public function testRejectsNonIntOrderId(): void
    {
        $resolver = new OptionsResolver();
        $this->topic->configureMessageBody($resolver);

        $this->expectException(InvalidOptionsException::class);
        $resolver->resolve(['order_id' => 'not-an-int', 'event' => 'order.created']);
    }

    public function testRejectsNonStringEvent(): void
    {
        $resolver = new OptionsResolver();
        $this->topic->configureMessageBody($resolver);

        $this->expectException(InvalidOptionsException::class);
        $resolver->resolve(['order_id' => 42, 'event' => 123]);
    }

    public function testRetryCountDefaultsToZero(): void
    {
        $resolver = new OptionsResolver();
        $this->topic->configureMessageBody($resolver);

        $resolved = $resolver->resolve(['order_id' => 42, 'event' => 'order.created']);

        $this->assertSame(0, $resolved['retry_count']);
    }

    public function testAcceptsExplicitRetryCount(): void
    {
        $resolver = new OptionsResolver();
        $this->topic->configureMessageBody($resolver);

        $resolved = $resolver->resolve(['order_id' => 42, 'event' => 'order.created', 'retry_count' => 2]);

        $this->assertSame(2, $resolved['retry_count']);
    }

    public function testRejectsNonIntRetryCount(): void
    {
        $resolver = new OptionsResolver();
        $this->topic->configureMessageBody($resolver);

        $this->expectException(InvalidOptionsException::class);
        $resolver->resolve(['order_id' => 42, 'event' => 'order.created', 'retry_count' => 'not-int']);
    }
}
