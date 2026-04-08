<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Webhook;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Signs and dispatches webhook payloads to the Kenzi endpoint.
 *
 * All configuration (sync_enabled, shared_secret, instance_key) is read
 * from global scope. Computes HMAC-SHA256 over the raw JSON body and sets
 * all required Kenzi headers.
 */
class WebhookDispatcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigManager $configManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether webhook sync is enabled and fully configured.
     *
     * Checks that sync_enabled is true AND all required config
     * (app_base_url, shared_secret, instance_key) are present. Listeners
     * use this to bail out early before serializing payloads.
     */
    public function isEnabled(): bool
    {
        if (!$this->getConfig(Configuration::PARAM_NAME_SYNC_ENABLED)) {
            return false;
        }

        $appBaseUrl = $this->getConfig(Configuration::PARAM_NAME_APP_BASE_URL);
        $webhookSecret = $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET);
        $instanceKey = $this->getConfig(Configuration::PARAM_NAME_INSTANCE_KEY);

        return \is_string($appBaseUrl) && $appBaseUrl !== ''
            && \is_string($webhookSecret) && $webhookSecret !== ''
            && \is_string($instanceKey) && $instanceKey !== '';
    }

    /**
     * Dispatch a webhook payload to the Kenzi endpoint.
     *
     * Callers must check isEnabled() before calling this method.
     * This method handles signing, sending, and logging — it does not gate
     * on configuration.
     *
     * Throws JsonException on encoding failure (permanent — not retryable).
     * Throws TransportExceptionInterface on network failure (transient — retryable).
     * Throws RuntimeException on non-2xx response (transient — retryable).
     * Callers (OrderWebhookProcessor) use these to decide retry vs reject.
     *
     * @param array<string, mixed> $payload  Pre-serialized payload from OrderPayloadSerializer
     * @param non-empty-string     $event    Event name (e.g. "order.created")
     *
     * @throws \JsonException
     * @throws TransportExceptionInterface
     */
    public function dispatch(array $payload, string $event): void
    {
        $appBaseUrl = (string) $this->getConfig(Configuration::PARAM_NAME_APP_BASE_URL);
        $webhookUrl = $appBaseUrl . '/webhooks/oro-commerce';
        $webhookSecret = (string) $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET);
        $instanceKey = (string) $this->getConfig(Configuration::PARAM_NAME_INSTANCE_KEY);

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $signature = $this->sign($rawBody, $webhookSecret);
        $deliveryId = Uuid::v4()->toRfc4122();
        $timestamp = (string) time();

        $response = $this->httpClient->request('POST', $webhookUrl, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-kenzi-signature' => $signature,
                'x-kenzi-delivery-id' => $deliveryId,
                'x-kenzi-timestamp' => $timestamp,
                'x-kenzi-integration' => $instanceKey,
                'x-kenzi-event' => $event,
            ],
            'body' => $rawBody,
            'timeout' => 10,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->logger->info('Webhook dispatched successfully', [
                'event' => $event,
                'delivery_id' => $deliveryId,
                'status_code' => $statusCode,
            ]);

            return;
        }

        throw new \RuntimeException(sprintf(
            'Webhook endpoint returned HTTP %d (delivery: %s)',
            $statusCode,
            $deliveryId,
        ));
    }

    /**
     * Compute HMAC-SHA256 signature: base64(HMAC-SHA256(sharedSecret, body)).
     *
     * NOTE: The timestamp is NOT included in the signed payload. Including it
     * (e.g. HMAC(sharedSecret, timestamp + "." + body)) would prevent replay attacks
     * but requires a coordinated change on the Kenzi webhook receiver side.
     * Track via KZP-208 follow-up before going to production.
     */
    private function sign(string $rawBody, string $sharedSecret): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $sharedSecret, true));
    }

    /**
     * Reads a Kenzi config value from global scope.
     */
    private function getConfig(string $paramName): mixed
    {
        return $this->configManager->get(
            Configuration::getConfigKeyByName($paramName)
        );
    }
}
