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

    public function testProcessedConfigContainsAllSettingsKeys(): void
    {
        $config = $this->processConfig([]);

        $expectedKeys = [
            Configuration::PARAM_NAME_WIDGET_ENABLED,
            Configuration::PARAM_NAME_SYNC_ENABLED,
            Configuration::PARAM_NAME_APP_BASE_URL,
            Configuration::PARAM_NAME_STATIC_BASE_URL,
            Configuration::PARAM_NAME_SHARED_SECRET,
            Configuration::PARAM_NAME_WORKSPACE_ID,
            Configuration::PARAM_NAME_INSTANCE_KEY,
            Configuration::PARAM_NAME_CONNECTED_AT,
        ];

        $settings = $config['settings'];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $settings, "Missing settings key: {$key}");
        }
    }

    public function testDefaultValues(): void
    {
        $settings = $this->processConfig([])['settings'];

        $this->assertSame(false, $settings[Configuration::PARAM_NAME_WIDGET_ENABLED]['value']);
        $this->assertSame(false, $settings[Configuration::PARAM_NAME_SYNC_ENABLED]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_APP_BASE_URL]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_STATIC_BASE_URL]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_SHARED_SECRET]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_WORKSPACE_ID]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_INSTANCE_KEY]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_CONNECTED_AT]['value']);
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
        yield 'widget_enabled' => [
            Configuration::PARAM_NAME_WIDGET_ENABLED,
            'kenzi_oro_commerce.widget_enabled',
        ];
        yield 'sync_enabled' => [
            Configuration::PARAM_NAME_SYNC_ENABLED,
            'kenzi_oro_commerce.sync_enabled',
        ];
        yield 'app_base_url' => [
            Configuration::PARAM_NAME_APP_BASE_URL,
            'kenzi_oro_commerce.app_base_url',
        ];
        yield 'static_base_url' => [
            Configuration::PARAM_NAME_STATIC_BASE_URL,
            'kenzi_oro_commerce.static_base_url',
        ];
        yield 'shared_secret' => [
            Configuration::PARAM_NAME_SHARED_SECRET,
            'kenzi_oro_commerce.shared_secret',
        ];
        yield 'workspace_id' => [
            Configuration::PARAM_NAME_WORKSPACE_ID,
            'kenzi_oro_commerce.workspace_id',
        ];
        yield 'instance_key' => [
            Configuration::PARAM_NAME_INSTANCE_KEY,
            'kenzi_oro_commerce.instance_key',
        ];
        yield 'connected_at' => [
            Configuration::PARAM_NAME_CONNECTED_AT,
            'kenzi_oro_commerce.connected_at',
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
