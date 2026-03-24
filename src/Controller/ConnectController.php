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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives credentials from the Kenzi connect popup and stores them
 * in Oro's system configuration, scoped to the selected Website.
 */
#[Route(path: '/kenzi/connect')]
#[CsrfProtection]
class ConnectController
{
    public function __construct(
        private readonly ConfigManager $globalConfigManager,
        private readonly ConfigManager $scopedConfigManager,
        private readonly ManagerRegistry $doctrine,
        private readonly AclHelper $aclHelper,
    ) {
    }

    /**
     * Receives credentials from the connect popup postMessage handler.
     *
     * The JavaScript sends: {workspace_id, shared_secret, website_id}
     * The store_key is auto-derived from the Website entity's URL hostname.
     * All values are stored scoped to the specified Website entity.
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

        if (!is_array($data) || $workspaceId === '' || $sharedSecret === '' || !isset($data['website_id'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // website_id=0 means global scope (CE or EE system configuration).
        // website_id>0 means website scope (EE website configuration).
        $websiteId = (int) $data['website_id'];
        $website = $websiteId > 0 ? $this->findWebsite($websiteId) : null;

        if ($websiteId > 0 && $website === null) {
            return new JsonResponse(['error' => 'Website not found'], 404);
        }

        $websiteUrl = (string) $this->getConfigManager($website)->get('oro_website.url', false, false, $website);
        $storeKey = parse_url($websiteUrl, PHP_URL_HOST) ?: '';

        if ($storeKey === '') {
            return new JsonResponse(['error' => 'Website URL not configured'], 422);
        }

        $this->setConfig(Configuration::PARAM_NAME_WORKSPACE_ID, $workspaceId, $website);
        $this->setConfig(Configuration::PARAM_NAME_SHARED_SECRET, $sharedSecret, $website);
        $this->setConfig(Configuration::PARAM_NAME_STORE_KEY, $storeKey, $website);
        $this->setConfig(Configuration::PARAM_NAME_CONNECTED_AT, (new \DateTimeImmutable())->format('c'), $website);
        $this->setConfig(Configuration::PARAM_NAME_SYNC_ENABLED, true, $website);
        $this->getConfigManager($website)->flush();

        return new JsonResponse(['status' => 'connected']);
    }

    /**
     * Disconnects the Kenzi integration for a specific Website.
     */
    #[Route(path: '/disconnect', name: 'kenzi_orocommerce_disconnect', methods: ['POST'])]
    #[AclAncestor('oro_config_system')]
    public function disconnect(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        if (!is_array($data) || !isset($data['website_id'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $websiteId = (int) $data['website_id'];
        $website = $websiteId > 0 ? $this->findWebsite($websiteId) : null;

        if ($websiteId > 0 && $website === null) {
            return new JsonResponse(['error' => 'Website not found'], 404);
        }

        $this->setConfig(Configuration::PARAM_NAME_SYNC_ENABLED, false, $website);
        $this->setConfig(Configuration::PARAM_NAME_SHARED_SECRET, '', $website);
        $this->setConfig(Configuration::PARAM_NAME_WORKSPACE_ID, '', $website);
        $this->setConfig(Configuration::PARAM_NAME_STORE_KEY, '', $website);
        $this->setConfig(Configuration::PARAM_NAME_CONNECTED_AT, '', $website);
        $this->getConfigManager($website)->flush();

        return new JsonResponse(['status' => 'disconnected']);
    }

    private function findWebsite(int $id): ?Website
    {
        /** @var \Doctrine\ORM\EntityRepository<Website> $repository */
        $repository = $this->doctrine->getRepository(Website::class);

        $qb = $repository->createQueryBuilder('w')
            ->where('w.id = :id')
            ->setParameter('id', $id);

        return $this->aclHelper->apply($qb)->getOneOrNullResult();
    }

    /**
     * Returns the appropriate ConfigManager based on whether a Website is specified.
     *
     * On CE (website_id=0, $website=null), oro_config.manager resolves to a
     * non-global scope (e.g. "customer") because the global scope has the lowest
     * priority (-255). Credentials stored under "customer" scope are invisible
     * to the storefront, which reads via the global scope cascade.
     *
     * On EE (website_id>0, $website set), oro_config.manager resolves to the
     * website scope manager, which correctly stores per-website config.
     */
    private function getConfigManager(?Website $website): ConfigManager
    {
        return $website !== null ? $this->scopedConfigManager : $this->globalConfigManager;
    }

    /**
     * @param string|bool $value
     */
    private function setConfig(string $paramName, $value, ?Website $website): void
    {
        $this->getConfigManager($website)->set(
            Configuration::getConfigKeyByName($paramName),
            $value,
            $website
        );
    }
}
