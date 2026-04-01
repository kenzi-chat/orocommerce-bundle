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
    public const PARAM_NAME_WIDGET_ENABLED = 'widget_enabled';
    public const PARAM_NAME_SYNC_ENABLED = 'sync_enabled';
    public const PARAM_NAME_APP_BASE_URL = 'app_base_url';
    public const PARAM_NAME_STATIC_BASE_URL = 'static_base_url';
    public const PARAM_NAME_SHARED_SECRET = 'shared_secret';
    public const PARAM_NAME_WORKSPACE_ID = 'workspace_id';
    public const PARAM_NAME_INSTANCE_KEY = 'instance_key';
    public const PARAM_NAME_CONNECTED_AT = 'connected_at';
    public const PARAM_NAME_OAUTH_CLIENT_ID = 'oauth_client_id';
    public const PARAM_NAME_CREDENTIALS_DELIVERED = 'credentials_delivered';

    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $rootNode = $treeBuilder->getRootNode();

        SettingsBuilder::append(
            $rootNode,
            [
                self::PARAM_NAME_WIDGET_ENABLED => [
                    'type' => 'boolean',
                    'value' => false,
                ],
                self::PARAM_NAME_SYNC_ENABLED => [
                    'type' => 'boolean',
                    'value' => false,
                ],
                self::PARAM_NAME_APP_BASE_URL => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_STATIC_BASE_URL => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_SHARED_SECRET => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_WORKSPACE_ID => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_INSTANCE_KEY => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_CONNECTED_AT => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_OAUTH_CLIENT_ID => [
                    'type' => 'scalar',
                    'value' => '',
                ],
                self::PARAM_NAME_CREDENTIALS_DELIVERED => [
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
