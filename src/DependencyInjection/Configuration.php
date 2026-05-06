<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\DependencyInjection;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\ConfigBundle\DependencyInjection\SettingsBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const ROOT_NODE = 'kenzi_oro_commerce';

    // Lifecycle keys — cleared by /disconnect for a clean fresh start.
    // shared_secret/grants/workspace_id are written by /connect; oauth_client_id
    // is written by /configure; widget_enabled is operator-set via admin UI but
    // also reset on disconnect so a reconnect doesn't auto-show the widget.
    public const PARAM_NAME_SHARED_SECRET = 'shared_secret';
    public const PARAM_NAME_GRANTS = 'grants';
    public const PARAM_NAME_WORKSPACE_ID = 'workspace_id';
    public const PARAM_NAME_OAUTH_CLIENT_ID = 'oauth_client_id';
    public const PARAM_NAME_WIDGET_ENABLED = 'widget_enabled';

    // Environment keys — env-seeded operator config, preserved across cycles.
    public const PARAM_NAME_APP_BASE_URL = 'app_base_url';
    public const PARAM_NAME_STATIC_BASE_URL = 'static_base_url';

    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $rootNode = $treeBuilder->getRootNode();

        SettingsBuilder::append(
            $rootNode,
            [
                self::PARAM_NAME_SHARED_SECRET => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_GRANTS => [
                    'type' => 'array',
                    'value' => [],
                ],
                self::PARAM_NAME_WORKSPACE_ID => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_OAUTH_CLIENT_ID => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_APP_BASE_URL => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_STATIC_BASE_URL => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_WIDGET_ENABLED => [
                    'type' => 'boolean',
                    'value' => false,
                ],
            ]
        );

        return $treeBuilder;
    }

    public static function getConfigKeyByName(string $name): string
    {
        return self::ROOT_NODE . ConfigManager::SECTION_MODEL_SEPARATOR . $name;
    }
}
