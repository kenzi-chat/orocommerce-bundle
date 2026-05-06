<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\DependencyInjection;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->configuration = new Configuration();
    }

    public function testGetConfigTreeBuilderReturnsCorrectRootNode(): void
    {
        $treeBuilder = $this->configuration->getConfigTreeBuilder();

        $this->assertSame(Configuration::ROOT_NODE, $treeBuilder->buildTree()->getName());
    }

    public function testProcessedConfigContainsExactlySevenKeys(): void
    {
        $config = $this->processConfig([]);

        $expectedKeys = [
            // Lifecycle
            Configuration::PARAM_NAME_SHARED_SECRET,
            Configuration::PARAM_NAME_GRANTS,
            Configuration::PARAM_NAME_WORKSPACE_ID,
            Configuration::PARAM_NAME_OAUTH_CLIENT_ID,
            // Non-lifecycle
            Configuration::PARAM_NAME_APP_BASE_URL,
            Configuration::PARAM_NAME_STATIC_BASE_URL,
            Configuration::PARAM_NAME_WIDGET_ENABLED,
        ];

        $settings = $config['settings'];
        unset($settings['resolved']);

        $this->assertEqualsCanonicalizing(
            $expectedKeys,
            array_keys($settings),
            'Settings must contain exactly the four lifecycle + three non-lifecycle keys'
        );
    }

    /**
     * @dataProvider droppedKeyProvider
     */
    public function testDroppedKeysAreAbsent(string $droppedKey): void
    {
        $config = $this->processConfig([]);
        $settings = $config['settings'];

        $this->assertArrayNotHasKey(
            $droppedKey,
            $settings,
            "Dropped key {$droppedKey} must not appear in settings"
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function droppedKeyProvider(): iterable
    {
        yield 'sync_enabled' => ['sync_enabled'];
        yield 'instance_key' => ['instance_key'];
        yield 'connected_at' => ['connected_at'];
        yield 'credentials_delivered' => ['credentials_delivered'];
    }

    public function testDefaultValues(): void
    {
        $settings = $this->processConfig([])['settings'];

        $this->assertSame('', $settings[Configuration::PARAM_NAME_SHARED_SECRET]['value']);
        $this->assertSame([], $settings[Configuration::PARAM_NAME_GRANTS]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_WORKSPACE_ID]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_OAUTH_CLIENT_ID]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_APP_BASE_URL]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_STATIC_BASE_URL]['value']);
        $this->assertSame(false, $settings[Configuration::PARAM_NAME_WIDGET_ENABLED]['value']);
    }

    /**
     * @dataProvider configKeyProvider
     */
    public function testGetConfigKeyByName(string $paramName, string $expectedKey): void
    {
        $this->assertSame($expectedKey, Configuration::getConfigKeyByName($paramName));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function configKeyProvider(): iterable
    {
        yield 'shared_secret' => [
            Configuration::PARAM_NAME_SHARED_SECRET,
            'kenzi_oro_commerce.shared_secret',
        ];
        yield 'grants' => [
            Configuration::PARAM_NAME_GRANTS,
            'kenzi_oro_commerce.grants',
        ];
        yield 'workspace_id' => [
            Configuration::PARAM_NAME_WORKSPACE_ID,
            'kenzi_oro_commerce.workspace_id',
        ];
        yield 'oauth_client_id' => [
            Configuration::PARAM_NAME_OAUTH_CLIENT_ID,
            'kenzi_oro_commerce.oauth_client_id',
        ];
        yield 'app_base_url' => [
            Configuration::PARAM_NAME_APP_BASE_URL,
            'kenzi_oro_commerce.app_base_url',
        ];
        yield 'static_base_url' => [
            Configuration::PARAM_NAME_STATIC_BASE_URL,
            'kenzi_oro_commerce.static_base_url',
        ];
        yield 'widget_enabled' => [
            Configuration::PARAM_NAME_WIDGET_ENABLED,
            'kenzi_oro_commerce.widget_enabled',
        ];
    }

    /**
     * Process the Configuration tree with Symfony's Processor.
     *
     * @param array<string, mixed> $configs
     * @return array<string, mixed>
     */
    private function processConfig(array $configs): array
    {
        $processor = new Processor();

        return $processor->processConfiguration($this->configuration, [$configs]);
    }
}
