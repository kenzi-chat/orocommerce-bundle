<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Controller;

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

        $appUrl = (string) $this->configManager->get('oro_ui.application_url');
        $instanceKey = parse_url($appUrl, PHP_URL_HOST) ?: '';

        if ($instanceKey === '') {
            return new JsonResponse(['error' => 'Could not determine application hostname'], 422);
        }

        $this->setConfig(Configuration::PARAM_NAME_WORKSPACE_ID, $workspaceId);
        $this->setConfig(Configuration::PARAM_NAME_SHARED_SECRET, $sharedSecret);
        $this->setConfig(Configuration::PARAM_NAME_INSTANCE_KEY, $instanceKey);
        $this->setConfig(Configuration::PARAM_NAME_CONNECTED_AT, (new \DateTimeImmutable())->format('c'));
        $this->setConfig(Configuration::PARAM_NAME_SYNC_ENABLED, true);
        $this->configManager->flush();

        return new JsonResponse(['status' => 'connected']);
    }

    /**
     * Disconnects the Kenzi integration.
     */
    #[Route(path: '/disconnect', name: 'kenzi_orocommerce_disconnect', methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    public function disconnect(): JsonResponse
    {
        $this->setConfig(Configuration::PARAM_NAME_SYNC_ENABLED, false);
        $this->setConfig(Configuration::PARAM_NAME_SHARED_SECRET, '');
        $this->setConfig(Configuration::PARAM_NAME_WORKSPACE_ID, '');
        $this->setConfig(Configuration::PARAM_NAME_INSTANCE_KEY, '');
        $this->setConfig(Configuration::PARAM_NAME_CONNECTED_AT, '');
        $this->configManager->flush();

        return new JsonResponse(['status' => 'disconnected']);
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
