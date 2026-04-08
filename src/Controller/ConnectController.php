<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Controller;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Kenzi\OroCommerceBundle\Credential\CredentialDelivery;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives credentials from the Kenzi connect popup and stores them
 * in Oro's system configuration at global scope (one integration per
 * Oro instance).
 */
#[Route(path: '/kenzi/connect')]
#[CsrfProtection]
class ConnectController
{
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly ApplicationUrlResolver $urlResolver,
        private readonly CredentialDelivery $credentialDelivery,
    ) {
    }

    /**
     * Receives credentials from the connect popup postMessage handler.
     *
     * The JavaScript sends: {workspace_id, shared_secret}
     * The instance_key is auto-derived from the application hostname.
     * All values are stored at global scope.
     */
    #[Route(path: '/connect', name: 'kenzi_orocommerce_connect', methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    public function connect(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $workspaceId = is_array($data) ? trim((string) ($data['workspace_id'] ?? '')) : '';
        $sharedSecret = is_array($data) ? trim((string) ($data['shared_secret'] ?? '')) : '';

        if (!is_array($data) || $workspaceId === '' || $sharedSecret === '') {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $instanceKey = $this->urlResolver->instanceKey();

        if ($instanceKey === '') {
            return new JsonResponse(['error' => 'Could not determine application hostname'], 422);
        }

        $this->storeConnection([
            Configuration::PARAM_NAME_WORKSPACE_ID => $workspaceId,
            Configuration::PARAM_NAME_SHARED_SECRET => $sharedSecret,
            Configuration::PARAM_NAME_INSTANCE_KEY => $instanceKey,
            Configuration::PARAM_NAME_CONNECTED_AT => (new \DateTimeImmutable())->format('c'),
            Configuration::PARAM_NAME_SYNC_ENABLED => true,
        ]);

        $delivered = $this->credentialDelivery->deliver();

        return new JsonResponse([
            'status' => $delivered ? 'connected' : 'partially_connected',
            'credentials_delivered' => $delivered,
        ]);
    }

    /**
     * Retries OAuth2 credential delivery after a prior `connect` call
     * succeeded at storing shared credentials but failed to hand off the
     * OAuth2 client_id/secret to Kenzi. Called from the admin UI when the
     * user clicks "Retry delivery" on the partially-connected banner.
     *
     * `CredentialDelivery::deliver()` is idempotent — the shared_secret and
     * instance_key were already persisted by the original connect call, so
     * this is just "run the second half again."
     */
    #[Route(path: '/retry-delivery', name: 'kenzi_orocommerce_retry_delivery', methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    public function retryDelivery(): JsonResponse
    {
        $delivered = $this->credentialDelivery->deliver();

        return new JsonResponse([
            'status' => $delivered ? 'connected' : 'partially_connected',
            'credentials_delivered' => $delivered,
        ]);
    }

    /**
     * Disconnects the Kenzi integration.
     *
     * Order matters: the Doctrine-only OAuth2 client revocation runs before
     * the single `ConfigManager` flush. Both the connection keys and the
     * credential-delivery keys are written in one `storeConnection()` call
     * so concurrent requests cannot observe a "credentials cleared but
     * shared_secret still valid" state and trigger a fresh `deliver()` that
     * would create an orphaned OAuth2 client.
     */
    #[Route(path: '/disconnect', name: 'kenzi_orocommerce_disconnect', methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    public function disconnect(): JsonResponse
    {
        $this->credentialDelivery->revokeOAuthClient();

        $this->storeConnection([
            Configuration::PARAM_NAME_SYNC_ENABLED => false,
            Configuration::PARAM_NAME_SHARED_SECRET => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => '',
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_OAUTH_CLIENT_ID => '',
            Configuration::PARAM_NAME_CREDENTIALS_DELIVERED => false,
        ]);

        return new JsonResponse(['status' => 'disconnected']);
    }

    /**
     * Writes a set of connection parameters and flushes in a single operation.
     *
     * Both connect() and disconnect() evolve the connection record shape by
     * touching the same set of `PARAM_NAME_*` keys. Routing every write through
     * this helper keeps the "what fields make up a connection" list in one
     * place and prevents one action from drifting out of sync with the other.
     *
     * @param array<string, string|bool> $values  Map of Configuration::PARAM_NAME_* => value
     */
    private function storeConnection(array $values): void
    {
        foreach ($values as $paramName => $value) {
            $this->configManager->set(
                Configuration::getConfigKeyByName($paramName),
                $value
            );
        }

        $this->configManager->flush();
    }
}
