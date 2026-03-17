<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Migrations\Data\ORM;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Persistence\ObjectManager;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * Seeds the Kenzi base URLs into Oro system configuration.
 *
 * Runs automatically during `oro:install` and `oro:platform:update`.
 * Values come from environment variables when present (local dev),
 * otherwise fall back to the production defaults.
 *
 * Note: This migration unconditionally overwrites existing DB values on each run.
 * This is intentional — it ensures the config always reflects the current environment
 * variables, which is the expected behavior for dev/staging/CI where env vars change
 * between deployments. Operators who need custom values should set the env vars rather
 * than editing the DB directly.
 *
 * Environment variables:
 *   KENZI_APP_BASE    — Kenzi app base URL (default: https://app.kenzi.chat)
 *   KENZI_STATIC_BASE — Kenzi static asset base URL (default: https://static.kenzi.chat)
 */
class LoadKenziBaseUrls extends AbstractFixture implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    public function load(ObjectManager $manager): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->container->get('oro_config.global');

        $appBaseUrl = getenv('KENZI_APP_BASE') ?: 'https://app.kenzi.chat';
        $staticBaseUrl = getenv('KENZI_STATIC_BASE') ?: 'https://static.kenzi.chat';

        $configManager->set(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_APP_BASE_URL),
            $appBaseUrl
        );

        $configManager->set(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_STATIC_BASE_URL),
            $staticBaseUrl
        );

        $configManager->flush();
    }
}
