<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Layout\DataProvider;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Layout data provider for Kenzi Chat widget configuration.
 *
 * Reads widget_enabled, workspace_id, and static_base_url from
 * Oro system configuration (website-scoped on EE, global on CE).
 */
class WidgetDataProvider
{
    public function __construct(
        private readonly ConfigManager $configManager,
    ) {
    }

    /**
     * Check if the widget is enabled AND has a workspace ID configured.
     */
    public function isWidgetEnabled(): bool
    {
        $enabled = (bool) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_WIDGET_ENABLED)
        );

        if (!$enabled) {
            return false;
        }

        $workspaceId = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_WORKSPACE_ID)
        );

        return $workspaceId !== '';
    }

    /**
     * Get the Kenzi workspace ID for widget script injection.
     */
    public function getWorkspaceId(): string
    {
        return (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_WORKSPACE_ID)
        );
    }

    /**
     * Get the full URL for the widget loader script.
     *
     * Derived from the global static_base_url config (e.g. https://static.kenzi.chat
     * in production, http://localhost:4000 in local dev).
     */
    public function getWidgetScriptUrl(): string
    {
        $staticBaseUrl = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_STATIC_BASE_URL)
        );

        return $staticBaseUrl !== '' ? $staticBaseUrl . '/widget/loader.js' : '';
    }
}
