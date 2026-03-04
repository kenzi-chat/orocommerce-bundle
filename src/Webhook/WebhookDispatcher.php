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
     * Dispatch a webhook payload to the Kenzi endpoint.
     *
     * @param array<string, mixed> $payload  Pre-serialized payload from OrderPayloadSerializer
     * @param string               $event    Event name (e.g. "order.created") — empty string returns false
     * @param Website|null         $website  Website to scope config reads to (null = global)
     */
    public function dispatch(array $payload, string $event, ?Website $website = null): bool
    {
        if ($event === '') {
            return false;
        }

        $websiteId = $website?->getId();

        if (!$this->getConfig(Configuration::PARAM_NAME_SYNC_ENABLED, $website)) {
            $this->logger->debug('Webhook dispatch skipped: sync disabled', [
                'website_id' => $websiteId,
                'event' => $event,
            ]);

            return false;
        }

        $webhookUrl = $this->getConfig(Configuration::PARAM_NAME_WEBHOOK_URL, $website);
        $webhookSecret = $this->getConfig(Configuration::PARAM_NAME_SECRET, $website);
        $storeKey = $this->getConfig(Configuration::PARAM_NAME_STORE_KEY, $website);

        if (!\is_string($webhookUrl) || $webhookUrl === ''
            || !\is_string($webhookSecret) || $webhookSecret === ''
            || !\is_string($storeKey) || $storeKey === ''
        ) {
            $this->logger->warning('Webhook dispatch skipped: missing configuration', [
                'website_id' => $websiteId,
                'has_url' => \is_string($webhookUrl) && $webhookUrl !== '',
                'has_secret' => \is_string($webhookSecret) && $webhookSecret !== '',
                'has_store_key' => \is_string($storeKey) && $storeKey !== '',
            ]);

            return false;
        }

        try {
            $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Webhook dispatch failed: JSON encoding error', [
                'website_id' => $websiteId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
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

                return true;
            }

            $this->logger->warning('Webhook endpoint returned non-2xx response', [
                'website_id' => $websiteId,
                'event' => $event,
                'delivery_id' => $deliveryId,
                'status_code' => $statusCode,
            ]);

            return false;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Webhook dispatch failed', [
                'website_id' => $websiteId,
                'event' => $event,
                'delivery_id' => $deliveryId,
                'error' => $e->getMessage(),
            ]);

            return false;
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
