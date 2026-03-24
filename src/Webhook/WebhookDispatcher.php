<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Webhook;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Signs and dispatches webhook payloads to the Kenzi endpoint.
 *
 * Reads all configuration scoped to the given Website (or global scope when null).
 * Computes HMAC-SHA256 over the raw JSON body and sets all required Kenzi headers.
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
     * Whether webhook sync is enabled and fully configured for the given website.
     *
     * Checks that sync_enabled is true AND all required config (app_base_url,
     * shared_secret, store_key) are present. Listeners use this to bail out early
     * before serializing payloads for websites not connected to Kenzi.
     */
    public function isEnabledForWebsite(?Website $website): bool
    {
        if (!$this->getConfig(Configuration::PARAM_NAME_SYNC_ENABLED, $website)) {
            return false;
        }

        $appBaseUrl = $this->getConfig(Configuration::PARAM_NAME_APP_BASE_URL, null);
        $webhookSecret = $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET, $website);
        $storeKey = $this->getConfig(Configuration::PARAM_NAME_STORE_KEY, $website);

        return \is_string($appBaseUrl) && $appBaseUrl !== ''
            && \is_string($webhookSecret) && $webhookSecret !== ''
            && \is_string($storeKey) && $storeKey !== '';
    }

    /**
     * Dispatch a webhook payload to the Kenzi endpoint.
     *
     * Callers must check isEnabledForWebsite() before calling this method.
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
     * @param Website|null         $website  Website to scope config reads to (null = global)
     *
     * @throws \JsonException
     * @throws TransportExceptionInterface
     */
    public function dispatch(array $payload, string $event, ?Website $website = null): void
    {
        $websiteId = $website?->getId();

        $appBaseUrl = (string) $this->getConfig(Configuration::PARAM_NAME_APP_BASE_URL, null);
        $webhookUrl = $appBaseUrl . '/orocommerce/webhooks';
        $webhookSecret = (string) $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET, $website);
        $storeKey = (string) $this->getConfig(Configuration::PARAM_NAME_STORE_KEY, $website);

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
                'x-kenzi-store-key' => $storeKey,
                'x-kenzi-event' => $event,
            ],
            'body' => $rawBody,
            'timeout' => 10,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->logger->info('Webhook dispatched successfully', [
                'website_id' => $websiteId,
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
     * Reads a Kenzi config value, optionally scoped to a Website.
     *
     * Uses oro_config.manager (the scope-cascading manager), which is the
     * idiomatic Oro pattern for reading website-scoped settings. On EE, the
     * manager resolves to WebsiteScopeManager and returns per-website values.
     * On CE (no WebsiteScopeManager), Website is not a recognized scope entity
     * for any CE scope manager, so the cascade falls through to global scope
     * where ConnectController stores credentials via oro_config.global.
     */
    private function getConfig(string $paramName, ?Website $website): mixed
    {
        return $this->configManager->get(
            Configuration::getConfigKeyByName($paramName),
            false,
            false,
            $website
        );
    }
}
