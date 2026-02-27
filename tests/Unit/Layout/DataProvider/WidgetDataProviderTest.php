<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Layout\DataProvider;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Kenzi\OroCommerceBundle\Layout\DataProvider\WidgetDataProvider;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WidgetDataProviderTest extends TestCase
{
    private ConfigManager&MockObject $configManager;
    private WidgetDataProvider $provider;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->provider = new WidgetDataProvider($this->configManager);
    }

    public function testIsWidgetEnabledReturnsTrueWhenEnabledAndHasWorkspaceId(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_WIDGET_ENABLED => true,
            Configuration::PARAM_NAME_WORKSPACE_ID => 'ws_abc123',
        ]);

        $this->assertTrue($this->provider->isWidgetEnabled());
    }

    public function testIsWidgetEnabledReturnsFalseWhenDisabled(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_WIDGET_ENABLED => false,
            Configuration::PARAM_NAME_WORKSPACE_ID => 'ws_abc123',
        ]);

        $this->assertFalse($this->provider->isWidgetEnabled());
    }

    public function testIsWidgetEnabledReturnsFalseWhenEnabledButNoWorkspaceId(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_WIDGET_ENABLED => true,
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
        ]);

        $this->assertFalse($this->provider->isWidgetEnabled());
    }

    public function testIsWidgetEnabledReturnsFalseWhenBothDisabledAndNoWorkspaceId(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_WIDGET_ENABLED => false,
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
        ]);

        $this->assertFalse($this->provider->isWidgetEnabled());
    }

    public function testGetWorkspaceIdReturnsConfiguredValue(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_WORKSPACE_ID => 'ws_xyz789',
        ]);

        $this->assertSame('ws_xyz789', $this->provider->getWorkspaceId());
    }

    public function testGetWorkspaceIdReturnsEmptyStringWhenNotConfigured(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
        ]);

        $this->assertSame('', $this->provider->getWorkspaceId());
    }

    public function testGetWidgetBaseUrlReturnsConfiguredValue(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_WIDGET_BASE_URL => 'https://cdn.kenzi.chat/widget/loader.js',
        ]);

        $this->assertSame('https://cdn.kenzi.chat/widget/loader.js', $this->provider->getWidgetBaseUrl());
    }

    public function testGetWidgetBaseUrlReturnsEmptyWhenNotOverridden(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_WIDGET_BASE_URL => '',
        ]);

        $this->assertSame('', $this->provider->getWidgetBaseUrl());
    }

    /**
     * Stub ConfigManager::get() for the given parameter name → value pairs.
     *
     * @param array<string, mixed> $values
     */
    private function stubConfig(array $values): void
    {
        $this->configManager->method('get')
            ->willReturnCallback(function (string $key) use ($values): mixed {
                // Strip the root node prefix to match against param names
                $paramName = str_replace(Configuration::ROOT_NODE . '.', '', $key);

                return $values[$paramName] ?? null;
            });
    }
}
