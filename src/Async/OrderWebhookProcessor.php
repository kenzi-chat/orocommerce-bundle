<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Async;

use Doctrine\Persistence\ManagerRegistry;
use Kenzi\OroCommerceBundle\Serializer\OrderPayloadSerializer;
use Kenzi\OroCommerceBundle\Webhook\WebhookDispatcher;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\MessageQueue\Client\Message;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes queued order webhook messages by loading the Order,
 * serializing it, and dispatching the webhook to Kenzi.
 *
 * On transient failure (network error, non-2xx response), schedules
 * a retry with exponential backoff (10s → 60s → 300s). After 3 failed
 * retries, permanently rejects the message and logs the failure.
 */
class OrderWebhookProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    /**
     * Maximum number of retry attempts before permanently rejecting the message.
     */
    private const MAX_RETRIES = 3;

    /**
     * Delay in seconds before each retry attempt (indexed by retry_count).
     *
     * The delays give Kenzi time to recover from transient issues:
     *   - 10s:  covers brief network blips, load balancer hiccups
     *   - 60s:  covers short deployments, service restarts
     *   - 300s: covers longer outages, DNS propagation, scaling events
     *
     * Total retry window is ~6 minutes. If Kenzi is still down after that,
     * the message is permanently rejected.
     */
    private const RETRY_DELAYS = [10, 60, 300];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly WebhookDispatcher $dispatcher,
        private readonly OrderPayloadSerializer $serializer,
        private readonly MessageProducerInterface $messageProducer,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function process(MessageInterface $message, SessionInterface $session): string
    {
        $body = $message->getBody();

        if (!is_array($body) || !isset($body['order_id'], $body['event'])) {
            $this->logger->error('Kenzi: malformed message body, rejecting', ['body' => $body]);

            return self::REJECT;
        }

        $orderId = $body['order_id'];
        $event = $body['event'];

        $order = $this->doctrine->getRepository(Order::class)->find($orderId);

        if ($order === null) {
            $this->logger->warning('Kenzi: order not found for webhook dispatch, skipping', [
                'order_id' => $orderId,
                'event' => $event,
            ]);

            return self::REJECT;
        }

        $website = $order->getWebsite();

        if (!$this->dispatcher->isEnabled()) {
            $this->logger->debug('Kenzi: webhook skipped, sync not enabled', [
                'order_id' => $orderId,
                'website_id' => $website?->getId(),
                'event' => $event,
            ]);

            return self::ACK;
        }

        try {
            $payload = $this->serializer->serialize($order, $event);
            $this->dispatcher->dispatch($payload, $event, $website);
        } catch (\JsonException $e) {
            $this->logger->error('Kenzi: webhook payload encoding failed (permanent)', [
                'order_id' => $orderId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return self::REJECT;
        } catch (\Throwable $e) {
            return $this->scheduleRetryOrReject($body, $e);
        }

        return self::ACK;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function scheduleRetryOrReject(array $body, \Throwable $e): string
    {
        $retryCount = $body['retry_count'] ?? 0;
        $orderId = $body['order_id'];
        $event = $body['event'];

        if ($retryCount >= self::MAX_RETRIES) {
            $this->logger->critical('Kenzi: webhook permanently failed after max retries', [
                'order_id' => $orderId,
                'event' => $event,
                'retry_count' => $retryCount,
                'error' => $e->getMessage(),
            ]);

            return self::REJECT;
        }

        $delay = self::RETRY_DELAYS[$retryCount] ?? self::RETRY_DELAYS[array_key_last(self::RETRY_DELAYS)];

        $retryMessage = new Message();
        $retryMessage->setBody([
            'order_id' => $orderId,
            'event' => $event,
            'retry_count' => $retryCount + 1,
        ]);
        $retryMessage->setDelay($delay);

        $this->messageProducer->send(OrderWebhookTopic::getName(), $retryMessage);

        $this->logger->warning('Kenzi: webhook dispatch failed, scheduling retry', [
            'order_id' => $orderId,
            'event' => $event,
            'retry_count' => $retryCount + 1,
            'delay_seconds' => $delay,
            'error' => $e->getMessage(),
        ]);

        return self::ACK;
    }

    /**
     * @return array<string>
     */
    #[\Override]
    public static function getSubscribedTopics(): array
    {
        return [OrderWebhookTopic::getName()];
    }
}
