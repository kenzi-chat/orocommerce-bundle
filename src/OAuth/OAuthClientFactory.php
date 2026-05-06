<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\OAuth;

use Oro\Bundle\OAuth2ServerBundle\Entity\Client;
use Oro\Bundle\OAuth2ServerBundle\Entity\Manager\ClientManager;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\UserBundle\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Mints OAuth2 clients (client_credentials grant) for the Kenzi integration.
 *
 * Stateless — each call to create() generates a fresh client. No idempotency,
 * no lookup of prior clients. Operators clean up orphaned clients manually
 * via Oro admin > OAuth Applications (filter by name "Kenzi OroCommerce").
 *
 * The plain client secret only lives in memory on the returned tuple — Oro
 * hashes it before persisting, so callers must hand it off immediately.
 */
class OAuthClientFactory
{
    public function __construct(
        private readonly ClientManager $clientManager,
        private readonly TokenAccessorInterface $tokenAccessor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Mint a fresh OAuth2 client.
     *
     * @param string       $name   Display name (e.g. "Kenzi OroCommerce")
     * @param list<string> $grants OAuth2 grant types
     *
     * @return array{0: string, 1: string} [clientId, clientSecret]
     */
    public function create(string $name, array $grants): array
    {
        if (!$this->clientManager->isCreationGranted()) {
            throw new \RuntimeException('Insufficient permission to create OAuth2 client');
        }

        $userId = $this->tokenAccessor->getUserId();

        if ($userId === null) {
            throw new \RuntimeException('No authenticated user — cannot set OAuth2 client owner');
        }

        $client = new Client();
        $client->setName($name);
        $client->setGrants($grants);
        $client->setActive(true);
        $client->setOwnerEntity(User::class, $userId);

        $this->clientManager->updateClient($client);

        $clientId = $client->getIdentifier();
        // Oro's Client::getPlainSecret() is declared @return string, but the
        // underlying property is untyped and nullable. The plain secret only
        // exists in memory immediately after updateClient() — Oro hashes it
        // before persisting. If we don't see a value here, something in Oro
        // changed and the integration would silently break.
        /** @var string|null $clientSecret */
        $clientSecret = $client->getPlainSecret();

        if ($clientSecret === null || $clientSecret === '') {
            throw new \RuntimeException('OAuth2 client created but plain secret is unavailable');
        }

        $this->logger->info('OAuth2 client created', [
            'oauth_client_identifier' => $clientId,
            'client_name' => $name,
        ]);

        return [$clientId, $clientSecret];
    }

    /**
     * Delete an OAuth2 client by its identifier.
     *
     * Best-effort delete via ClientManager::deleteClient, which writes to
     * the DB on its own. The caller's ConfigManager write that clears the
     * stored identifier is a separate DB write. If the two split (one
     * succeeds, one fails), the user can re-run Disconnect to finish the
     * job — every step is safe to repeat. No-op when the identifier is
     * empty or the client cannot be found (operator may have deleted it
     * manually).
     */
    public function revoke(string $clientIdentifier): void
    {
        if ($clientIdentifier === '') {
            return;
        }

        $client = $this->clientManager->getClient($clientIdentifier);

        if ($client === null) {
            $this->logger->info('OAuth2 client not found during revoke (already gone)', [
                'oauth_client_identifier' => $clientIdentifier,
            ]);
            return;
        }

        $this->clientManager->deleteClient($client);

        $this->logger->info('OAuth2 client revoked', [
            'oauth_client_identifier' => $clientIdentifier,
        ]);
    }
}
