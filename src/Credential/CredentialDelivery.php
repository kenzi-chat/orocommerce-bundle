<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Credential;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\OAuth2ServerBundle\Entity\Client;
use Oro\Bundle\OAuth2ServerBundle\Entity\Manager\ClientManager;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers OAuth2 client credentials to the Kenzi backend.
 *
 * After Kenzi Connect completes, the integration needs OAuth2 credentials
 * so Kenzi can authenticate against OroCommerce's JSON:API for backfill.
 * This class creates an OAuth2 Client (client_credentials grant), then
 * PATCHes the client_id and client_secret to Kenzi's credential delivery
 * endpoint.
 *
 * All config is read/written at global scope (one Oro instance = one
 * Kenzi integration).
 *
 * Two config values track state:
 * - `oauth_client_id` — the Oro database ID of the generated OAuth2 Client
 * - `credentials_delivered` — whether the PATCH to Kenzi succeeded
 *
 * If delivery fails, the generated OAuth2 Client is revoked and both
 * config values are cleared so the next attempt retries from scratch.
 */
class CredentialDelivery
{
    public function __construct(
        private readonly ClientManager $clientManager,
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigManager $configManager,
        private readonly TokenAccessorInterface $tokenAccessor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Deliver credentials if connected and not yet delivered.
     *
     * Safe to call multiple times — exits early when already delivered
     * or when prerequisites (shared_secret, integration_key) are not met.
     */
    public function deliver(): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        if ($this->isDelivered()) {
            return true;
        }

        $client = $this->findOrCreateOAuthClient();

        if ($client === null) {
            return false;
        }

        $clientIdentifier = $client->getIdentifier();
        // Oro's Client::getPlainSecret() is declared @return string, but the
        // underlying property is untyped and nullable. After a DB reload,
        // setPlainSecret() has never been called, so null is returned.
        /** @var string|null $clientSecret */
        $clientSecret = $client->getPlainSecret();

        // When we create an OAuth2 client, Oro generates a plainSecret and
        // then hashes it before saving to the database. The plain value only
        // lives in memory on the client object — it is never persisted.
        //
        // Normally we deliver it to Kenzi immediately in the same request.
        // But if a previous delivery failed (e.g. network error), the client
        // was already saved to the DB and its plainSecret is lost. Loading
        // it back via getClient() gives us the hash, not the original value.
        // Since we can't send a hashed secret to Kenzi, we revoke the
        // unusable client so the next deliver() call starts fresh.
        if ($clientSecret === '') {
            $this->logger->warning('OAuth2 client exists but plain secret is unavailable, revoking');
            $this->revokeAndClear($client);
            return false;
        }

        $delivered = $this->deliverToKenzi($clientIdentifier, $clientSecret);

        if (!$delivered) {
            $this->revokeAndClear($client);
            return false;
        }

        $this->setConfig(Configuration::PARAM_NAME_CREDENTIALS_DELIVERED, true);
        $this->configManager->flush();

        $this->logger->info('Credentials delivered to Kenzi');

        return true;
    }

    /**
     * Revoke the OAuth2 client referenced by the current connection.
     *
     * Doctrine-only — deletes the `Client` entity via `ClientManager` and
     * does NOT touch `ConfigManager`. The caller is responsible for
     * clearing `oauth_client_id` / `credentials_delivered` as part of a
     * single atomic disconnect flush (see `ConnectController::disconnect`).
     *
     * Splitting these writes apart matters: a previous version of this
     * method flushed the config twice during disconnect, opening a race
     * window where a concurrent `deliver()` call could observe a "credentials
     * not delivered yet, but shared_secret still valid" state and create a
     * fresh OAuth2 client that would then be orphaned by the second flush.
     */
    public function revokeOAuthClient(): void
    {
        $oauthClientId = $this->getConfig(Configuration::PARAM_NAME_OAUTH_CLIENT_ID);

        if ($oauthClientId === null || $oauthClientId === '') {
            return;
        }

        $client = $this->clientManager->getClient((string) $oauthClientId);

        if ($client === null) {
            return;
        }

        $this->clientManager->deleteClient($client);

        $this->logger->info('OAuth2 client revoked', [
            'oauth_client_identifier' => $client->getIdentifier(),
        ]);
    }

    /**
     * All prerequisites for delivery must be present before we create a
     * DB-backed OAuth2 client. `app_base_url` is part of the check because
     * `deliverToKenzi()` uses it to construct the PATCH URL — without it,
     * we would create a client just to immediately revoke it, leaving a
     * noisy audit trail for a state we can detect up front.
     */
    private function isConnected(): bool
    {
        $sharedSecret = $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET);
        $integrationKey = $this->getConfig(Configuration::PARAM_NAME_INSTANCE_KEY);
        $appBaseUrl = $this->getConfig(Configuration::PARAM_NAME_APP_BASE_URL);

        return \is_string($sharedSecret) && $sharedSecret !== ''
            && \is_string($integrationKey) && $integrationKey !== ''
            && \is_string($appBaseUrl) && $appBaseUrl !== '';
    }

    private function isDelivered(): bool
    {
        return (bool) $this->getConfig(Configuration::PARAM_NAME_CREDENTIALS_DELIVERED);
    }

    private function findOrCreateOAuthClient(): ?Client
    {
        // Check if we already have an OAuth2 client from a previous attempt
        $oauthClientId = $this->getConfig(Configuration::PARAM_NAME_OAUTH_CLIENT_ID);

        if (\is_string($oauthClientId) && $oauthClientId !== '') {
            $existing = $this->clientManager->getClient($oauthClientId);

            if ($existing !== null) {
                return $existing;
            }

            // Stale reference — client was deleted externally, clear and recreate
            $this->logger->warning('Stored OAuth2 client not found, recreating', [
                'stored_identifier' => $oauthClientId,
            ]);
        }

        return $this->createOAuthClient();
    }

    private function createOAuthClient(): ?Client
    {
        // Pre-flight: both checks represent "we can't create a client here,"
        // but they map to genuinely different ops scenarios — insufficient
        // role configuration vs. an expired/missing session. Keep the two
        // log messages distinct so the operator can diagnose without
        // re-running under a debugger.
        if (!$this->clientManager->isCreationGranted()) {
            $this->logger->error('Insufficient permission to create OAuth2 client');

            return null;
        }

        $userId = $this->tokenAccessor->getUserId();

        if ($userId === null) {
            $this->logger->error('No authenticated user — cannot set OAuth2 client owner');

            return null;
        }

        try {
            $integrationKey = (string) $this->getConfig(Configuration::PARAM_NAME_INSTANCE_KEY);

            $client = new Client();
            $client->setName('Kenzi Commerce (' . $integrationKey . ')');
            $client->setGrants([Client::CLIENT_CREDENTIALS]);
            $client->setActive(true);
            $client->setOwnerEntity(User::class, $userId);

            $this->clientManager->updateClient($client);

            // Store the client identifier (not the DB id) for later lookup/revocation
            $this->setConfig(Configuration::PARAM_NAME_OAUTH_CLIENT_ID, $client->getIdentifier());
            $this->configManager->flush();

            $this->logger->info('OAuth2 client created', [
                'oauth_client_identifier' => $client->getIdentifier(),
            ]);

            return $client;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create OAuth2 client', [
                'error_class' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function deliverToKenzi(string $clientIdentifier, string $clientSecret): bool
    {
        // `isConnected()` is the single gate for these three values; if we
        // got past it, they are guaranteed non-empty strings.
        $appBaseUrl = (string) $this->getConfig(Configuration::PARAM_NAME_APP_BASE_URL);
        $sharedSecret = (string) $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET);
        $integrationKey = (string) $this->getConfig(Configuration::PARAM_NAME_INSTANCE_KEY);

        $url = $appBaseUrl . '/api/integrations/oro_commerce/' . urlencode($integrationKey) . '/credentials';

        try {
            $response = $this->httpClient->request('PATCH', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $sharedSecret,
                ],
                'body' => json_encode([
                    'client_id' => $clientIdentifier,
                    'client_secret' => $clientSecret,
                ], JSON_THROW_ON_ERROR),
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(throw: false);

            if ($statusCode === 200) {
                return true;
            }

            $this->logger->error('Credential delivery failed', [
                'status_code' => $statusCode,
                'response_body' => substr($body, 0, 500),
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Credential delivery request failed', [
                'error_class' => $e::class,
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            return false;
        }
    }

    /**
     * Delete the OAuth2 client and clear its tracking config.
     *
     * This is a two-phase write across different storage systems: the
     * Doctrine `Client` entity is deleted first, then the `ConfigManager`
     * flush clears `oauth_client_id` + `credentials_delivered`. If the
     * process crashes between the two phases, the stored `oauth_client_id`
     * references a client that no longer exists in the database.
     *
     * That stale state is recoverable: on the next `deliver()` call,
     * `findOrCreateOAuthClient()` loads the stored id via
     * `ClientManager::getClient()`, gets `null`, logs "Stored OAuth2 client
     * not found, recreating" and falls through to `createOAuthClient()`.
     * So a crash between the phases degrades to "next delivery attempt
     * recreates the client," which is exactly what we want.
     *
     * Ordering matters: Doctrine delete must come first. Reversing the
     * order would produce the opposite failure — config cleared while the
     * orphaned client is still in the database — which is not
     * self-recovering on the next `deliver()` call because the stored id
     * would already be empty.
     */
    private function revokeAndClear(Client $client): void
    {
        $this->clientManager->deleteClient($client);

        $this->setConfig(Configuration::PARAM_NAME_OAUTH_CLIENT_ID, '');
        $this->setConfig(Configuration::PARAM_NAME_CREDENTIALS_DELIVERED, false);
        $this->configManager->flush();

        $this->logger->info('OAuth2 client revoked after delivery failure');
    }

    private function getConfig(string $paramName): mixed
    {
        return $this->configManager->get(
            Configuration::getConfigKeyByName($paramName)
        );
    }

    /**
     * @param string|bool $value
     */
    private function setConfig(string $paramName, $value): void
    {
        $this->configManager->set(
            Configuration::getConfigKeyByName($paramName),
            $value
        );
    }
}
