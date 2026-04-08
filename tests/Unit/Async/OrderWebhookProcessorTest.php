<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Async;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Kenzi\OroCommerceBundle\Async\OrderWebhookProcessor;
use Kenzi\OroCommerceBundle\Async\OrderWebhookTopic;
use Kenzi\OroCommerceBundle\Serializer\OrderPayloadSerializer;
use Kenzi\OroCommerceBundle\Webhook\WebhookDispatcher;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrderWebhookProcessorTest extends TestCase
{
    /** @var ManagerRegistry&MockObject */
    private MockObject $doctrine;
    /** @var WebhookDispatcher&MockObject */
    private MockObject $dispatcher;
    /** @var OrderPayloadSerializer&MockObject */
    private MockObject $serializer;
    /** @var MessageProducerInterface&MockObject */
    private MockObject $messageProducer;
    private OrderWebhookProcessor $processor;

    protected function setUp(): void
    {
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->dispatcher = $this->createMock(WebhookDispatcher::class);
        $this->serializer = $this->createMock(OrderPayloadSerializer::class);
        $this->messageProducer = $this->createMock(MessageProducerInterface::class);

        $this->processor = new OrderWebhookProcessor(
            $this->doctrine,
            $this->dispatcher,
            $this->serializer,
            $this->messageProducer,
            new NullLogger(),
        );
    }

    // -- Successful dispatch ──────────────────────────────────────────

    public function testDispatchesWebhookAndAcks(): void
    {
        $order = $this->createMock(Order::class);

        $this->stubOrderLookup($order);
        $this->dispatcher->method('isEnabled')->willReturn(true);

        $payload = ['event' => 'order.created', 'timestamp' => 1700000000, 'data' => ['id' => 42]];
        $this->serializer->expects($this->once())
            ->method('serialize')
            ->with($order, 'order.created')
            ->willReturn($payload);

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($payload, 'order.created');

        $this->messageProducer->expects($this->never())->method('send');

        $result = $this->processor->process(
            $this->createMessage(['order_id' => 42, 'event' => 'order.created']),
            $this->createMock(SessionInterface::class)
        );

        $this->assertSame(MessageProcessorInterface::ACK, $result);
    }

    // -- Order not found ──────────────────────────────────────────────

    public function testRejectsWhenOrderNotFound(): void
    {
        $this->stubOrderLookup(null);

        $this->serializer->expects($this->never())->method('serialize');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $result = $this->processor->process(
            $this->createMessage(['order_id' => 999, 'event' => 'order.created']),
            $this->createMock(SessionInterface::class)
        );

        $this->assertSame(MessageProcessorInterface::REJECT, $result);
    }

    // -- Sync disabled ────────────────────────────────────────────────

    public function testAcksWhenSyncDisabledForWebsite(): void
    {
        $order = $this->createMock(Order::class);

        $this->stubOrderLookup($order);
        $this->dispatcher->method('isEnabled')->willReturn(false);

        $this->serializer->expects($this->never())->method('serialize');

        $result = $this->processor->process(
            $this->createMessage(['order_id' => 42, 'event' => 'order.created']),
            $this->createMock(SessionInterface::class)
        );

        $this->assertSame(MessageProcessorInterface::ACK, $result);
    }

    // -- Retry on transient failure ───────────────────────────────────

    public function testSchedulesRetryOnFirstFailure(): void
    {
        $order = $this->createMock(Order::class);

        $this->stubOrderLookup($order);
        $this->dispatcher->method('isEnabled')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);
        $this->dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('HTTP 500'));

        $this->messageProducer->expects($this->once())
            ->method('send')
            ->with(
                OrderWebhookTopic::getName(),
                $this->callback(function ($message) {
                    $body = $message->getBody();

                    return $body['order_id'] === 42
                        && $body['event'] === 'order.created'
                        && $body['retry_count'] === 1;
                })
            );

        $result = $this->processor->process(
            $this->createMessage(['order_id' => 42, 'event' => 'order.created']),
            $this->createMock(SessionInterface::class)
        );

        $this->assertSame(MessageProcessorInterface::ACK, $result);
    }

    public function testSchedulesRetryWithIncreasingDelay(): void
    {
        $order = $this->createMock(Order::class);

        $this->stubOrderLookup($order);
        $this->dispatcher->method('isEnabled')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);
        $this->dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('HTTP 503'));

        $capturedDelay = null;
        $this->messageProducer->expects($this->once())
            ->method('send')
            ->with(
                $this->anything(),
                $this->callback(function ($message) use (&$capturedDelay) {
                    $capturedDelay = $message->getDelay();
                    $body = $message->getBody();

                    return $body['retry_count'] === 3;
                })
            );

        // Simulate retry_count=2 (third attempt), expect delay=300s
        $result = $this->processor->process(
            $this->createMessage(['order_id' => 42, 'event' => 'order.created', 'retry_count' => 2]),
            $this->createMock(SessionInterface::class)
        );

        $this->assertSame(MessageProcessorInterface::ACK, $result);
        $this->assertSame(300, $capturedDelay);
    }

    public function testRetryDelaysMatchExpectedSchedule(): void
    {
        $order = $this->createMock(Order::class);

        $this->stubOrderLookup($order);
        $this->dispatcher->method('isEnabled')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);
        $this->dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('timeout'));

        $expectedDelays = [10, 60, 300];

        foreach ($expectedDelays as $retryCount => $expectedDelay) {
            $capturedDelay = null;
            $this->messageProducer->expects($this->once())
                ->method('send')
                ->with(
                    $this->anything(),
                    $this->callback(function ($message) use (&$capturedDelay) {
                        $capturedDelay = $message->getDelay();

                        return true;
                    })
                );

            $this->processor->process(
                $this->createMessage(['order_id' => 42, 'event' => 'order.created', 'retry_count' => $retryCount]),
                $this->createMock(SessionInterface::class)
            );

            $this->assertSame($expectedDelay, $capturedDelay, "Delay for retry_count={$retryCount}");

            // Reset mock for next iteration
            $this->messageProducer = $this->createMock(MessageProducerInterface::class);
            $this->processor = new OrderWebhookProcessor(
                $this->doctrine,
                $this->dispatcher,
                $this->serializer,
                $this->messageProducer,
                new NullLogger(),
            );
        }
    }

    // -- Permanent rejection after max retries ────────────────────────

    public function testRejectsAfterMaxRetries(): void
    {
        $order = $this->createMock(Order::class);

        $this->stubOrderLookup($order);
        $this->dispatcher->method('isEnabled')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);
        $this->dispatcher->method('dispatch')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->messageProducer->expects($this->never())->method('send');

        $result = $this->processor->process(
            $this->createMessage(['order_id' => 42, 'event' => 'order.created', 'retry_count' => 3]),
            $this->createMock(SessionInterface::class)
        );

        $this->assertSame(MessageProcessorInterface::REJECT, $result);
    }

    // -- Permanent failure (JsonException) ────────────────────────────

    public function testRejectsOnJsonExceptionWithoutRetry(): void
    {
        $order = $this->createMock(Order::class);

        $this->stubOrderLookup($order);
        $this->dispatcher->method('isEnabled')->willReturn(true);
        $this->serializer->method('serialize')->willReturn(['data' => []]);
        $this->dispatcher->method('dispatch')
            ->willThrowException(new \JsonException('Malformed UTF-8'));

        $this->messageProducer->expects($this->never())->method('send');

        $result = $this->processor->process(
            $this->createMessage(['order_id' => 42, 'event' => 'order.created']),
            $this->createMock(SessionInterface::class)
        );

        $this->assertSame(MessageProcessorInterface::REJECT, $result);
    }

    // -- Topic subscription ───────────────────────────────────────────

    public function testSubscribesToOrderWebhookTopic(): void
    {
        $this->assertSame(
            [OrderWebhookTopic::getName()],
            OrderWebhookProcessor::getSubscribedTopics()
        );
    }

    // -- Helpers ──────────────────────────────────────────────────────

    private function stubOrderLookup(?Order $order): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->willReturn($order);

        $this->doctrine->method('getRepository')
            ->with(Order::class)
            ->willReturn($repository);
    }

    /** @param array<string, mixed> $body */
    private function createMessage(array $body): MessageInterface
    {
        $message = $this->createMock(MessageInterface::class);
        $message->method('getBody')->willReturn($body);

        return $message;
    }
}
