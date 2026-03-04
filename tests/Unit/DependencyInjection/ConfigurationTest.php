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
            Configuration::PARAM_NAME_WEBHOOK_URL,
            Configuration::PARAM_NAME_SECRET,
            Configuration::PARAM_NAME_WORKSPACE_ID,
            Configuration::PARAM_NAME_STORE_KEY,
            Configuration::PARAM_NAME_WIDGET_BASE_URL,
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
        $this->assertSame(
            'https://app.kenzi.chat/orocommerce/webhooks',
            $settings[Configuration::PARAM_NAME_WEBHOOK_URL]['value']
        );
        $this->assertSame('', $settings[Configuration::PARAM_NAME_SECRET]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_WORKSPACE_ID]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_STORE_KEY]['value']);
        $this->assertSame('', $settings[Configuration::PARAM_NAME_WIDGET_BASE_URL]['value']);
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
        yield 'webhook_url' => [
            Configuration::PARAM_NAME_WEBHOOK_URL,
            'kenzi_oro_commerce.webhook_url',
        ];
        yield 'secret' => [
            Configuration::PARAM_NAME_SECRET,
            'kenzi_oro_commerce.secret',
        ];
        yield 'workspace_id' => [
            Configuration::PARAM_NAME_WORKSPACE_ID,
            'kenzi_oro_commerce.workspace_id',
        ];
        yield 'store_key' => [
            Configuration::PARAM_NAME_STORE_KEY,
            'kenzi_oro_commerce.store_key',
        ];
        yield 'widget_base_url' => [
            Configuration::PARAM_NAME_WIDGET_BASE_URL,
            'kenzi_oro_commerce.widget_base_url',
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
