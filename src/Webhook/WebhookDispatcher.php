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
     * Checks that sync_enabled is true AND all required config (webhook_url,
     * secret, store_key) are present. Listeners use this to bail out early
     * before serializing payloads for websites not connected to Kenzi.
     */
    public function isEnabledForWebsite(?Website $website): bool
    {
        if (!$this->getConfig(Configuration::PARAM_NAME_SYNC_ENABLED, $website)) {
            return false;
        }

        $webhookUrl = $this->getConfig(Configuration::PARAM_NAME_WEBHOOK_URL, $website);
        $webhookSecret = $this->getConfig(Configuration::PARAM_NAME_SECRET, $website);
        $storeKey = $this->getConfig(Configuration::PARAM_NAME_STORE_KEY, $website);

        return \is_string($webhookUrl) && $webhookUrl !== ''
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
     * @param array<string, mixed> $payload  Pre-serialized payload from OrderPayloadSerializer
     * @param non-empty-string     $event    Event name (e.g. "order.created")
     * @param Website|null         $website  Website to scope config reads to (null = global)
     */
    public function dispatch(array $payload, string $event, ?Website $website = null): void
    {
        $websiteId = $website?->getId();

        $webhookUrl = $this->getConfig(Configuration::PARAM_NAME_WEBHOOK_URL, $website);
        $webhookSecret = $this->getConfig(Configuration::PARAM_NAME_SECRET, $website);
        $storeKey = $this->getConfig(Configuration::PARAM_NAME_STORE_KEY, $website);

        try {
            $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Webhook dispatch failed: JSON encoding error', [
                'website_id' => $websiteId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $signature = $this->sign($rawBody, $webhookSecret);
        $deliveryId = Uuid::v4()->toRfc4122();
        $timestamp = (string) time();

        try {
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

            $this->logger->warning('Webhook endpoint returned non-2xx response', [
                'website_id' => $websiteId,
                'event' => $event,
                'delivery_id' => $deliveryId,
                'status_code' => $statusCode,
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Webhook dispatch failed', [
                'website_id' => $websiteId,
                'event' => $event,
                'delivery_id' => $deliveryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compute HMAC-SHA256 signature: base64(HMAC-SHA256(secret, body)).
     *
     * NOTE: The timestamp is NOT included in the signed payload. Including it
     * (e.g. HMAC(secret, timestamp + "." + body)) would prevent replay attacks
     * but requires a coordinated change on the Kenzi webhook receiver side.
     * Track via KZP-208 follow-up before going to production.
     */
    private function sign(string $rawBody, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    }

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
