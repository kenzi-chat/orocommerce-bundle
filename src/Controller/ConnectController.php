<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives credentials from the Kenzi connect popup and stores them
 * in Oro's system configuration, scoped to the selected Website.
 */
#[Route(path: '/kenzi/connect', name: 'kenzi_orocommerce_connect_')]
#[CsrfProtection]
class ConnectController extends AbstractController
{
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly ManagerRegistry $doctrine,
        private readonly AclHelper $aclHelper,
    ) {
    }

    /**
     * Receives credentials from the connect popup postMessage handler.
     *
     * The JavaScript sends: {workspace_id, secret, website_id}
     * The store_key is auto-derived from the Website entity's URL hostname.
     * All values are stored scoped to the specified Website entity.
     */
    #[Route(path: '/connect', name: 'connect', methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    public function connect(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $workspaceId = is_array($data) ? trim((string) ($data['workspace_id'] ?? '')) : '';
        $secret = is_array($data) ? trim((string) ($data['secret'] ?? '')) : '';

        if (!is_array($data) || $workspaceId === '' || $secret === '' || !isset($data['website_id'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $website = $this->findWebsite((int) $data['website_id']);
        if ($website === null) {
            return new JsonResponse(['error' => 'Website not found'], 404);
        }

        $websiteUrl = (string) $this->configManager->get('oro_website.url', false, false, $website);
        $storeKey = parse_url($websiteUrl, PHP_URL_HOST) ?: '';

        if ($storeKey === '') {
            return new JsonResponse(['error' => 'Website URL not configured'], 422);
        }

        $this->setConfig(Configuration::PARAM_NAME_WORKSPACE_ID, $workspaceId, $website);
        $this->setConfig(Configuration::PARAM_NAME_SECRET, $secret, $website);
        $this->setConfig(Configuration::PARAM_NAME_STORE_KEY, $storeKey, $website);
        $this->setConfig(Configuration::PARAM_NAME_CONNECTED_AT, (new \DateTimeImmutable())->format('c'), $website);
        $this->setConfig(Configuration::PARAM_NAME_SYNC_ENABLED, true, $website);
        $this->configManager->flush();

        return new JsonResponse(['status' => 'connected']);
    }

    /**
     * Disconnects the Kenzi integration for a specific Website.
     */
    #[Route(path: '/disconnect', name: 'disconnect', methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    public function disconnect(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['website_id'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $website = $this->findWebsite((int) $data['website_id']);
        if ($website === null) {
            return new JsonResponse(['error' => 'Website not found'], 404);
        }

        $this->setConfig(Configuration::PARAM_NAME_SYNC_ENABLED, false, $website);
        $this->setConfig(Configuration::PARAM_NAME_SECRET, '', $website);
        $this->setConfig(Configuration::PARAM_NAME_WORKSPACE_ID, '', $website);
        $this->setConfig(Configuration::PARAM_NAME_STORE_KEY, '', $website);
        $this->setConfig(Configuration::PARAM_NAME_CONNECTED_AT, '', $website);
        $this->configManager->flush();

        return new JsonResponse(['status' => 'disconnected']);
    }

    private function findWebsite(int $id): ?Website
    {
        $qb = $this->doctrine->getRepository(Website::class)
            ->createQueryBuilder('w')
            ->where('w.id = :id')
            ->setParameter('id', $id);

        return $this->aclHelper->apply($qb)->getOneOrNullResult();
    }

    /**
     * @param string|bool $value
     */
    private function setConfig(string $paramName, $value, Website $website): void
    {
        $this->configManager->set(
            Configuration::getConfigKeyByName($paramName),
            $value,
            $website
        );
    }
}
