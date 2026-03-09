<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\DependencyInjection;

use Kenzi\OroCommerceBundle\DependencyInjection\KenziOroCommerceExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class KenziOroCommerceExtensionTest extends TestCase
{
    private KenziOroCommerceExtension $extension;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new KenziOroCommerceExtension();
        $this->container = new ContainerBuilder();
    }

    public function testLoadRegistersServicesFromYml(): void
    {
        $this->extension->load([], $this->container);

        $this->assertTrue(
            $this->container->has('kenzi_oro_commerce.layout.data_provider.widget'),
            'WidgetDataProvider service should be registered'
        );
    }

    public function testLoadPrependsSettingsConfig(): void
    {
        $this->extension->load([], $this->container);

        $prependedConfigs = $this->container->getExtensionConfig('kenzi_oro_commerce');

        $this->assertNotEmpty($prependedConfigs, 'Extension should prepend config');

        // The prepended config should contain the settings key from SettingsBuilder
        $prependedSettings = $prependedConfigs[0];
        $this->assertArrayHasKey('settings', $prependedSettings);
    }

    public function testLoadPrependsAllSettingsDefaults(): void
    {
        $this->extension->load([], $this->container);

        $prependedConfigs = $this->container->getExtensionConfig('kenzi_oro_commerce');
        $settings = $prependedConfigs[0]['settings'];

        $expectedKeys = [
            'widget_enabled',
            'sync_enabled',
            'webhook_url',
            'secret',
            'workspace_id',
            'store_key',
            'widget_base_url',
            'connected_at',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $settings, "Prepended settings missing key: {$key}");
        }
    }

    public function testWidgetDataProviderServiceDefinition(): void
    {
        $this->extension->load([], $this->container);

        $definition = $this->container->getDefinition('kenzi_oro_commerce.layout.data_provider.widget');

        $this->assertSame(
            'Kenzi\OroCommerceBundle\Layout\DataProvider\WidgetDataProvider',
            $definition->getClass()
        );

        $tags = $definition->getTag('layout.data_provider');
        $this->assertNotEmpty($tags, 'Service should have layout.data_provider tag');
        $this->assertSame('kenzi_widget', $tags[0]['alias']);
    }

    public function testExtensionAlias(): void
    {
        $this->assertSame('kenzi_oro_commerce', $this->extension->getAlias());
    }

    public function testLoadRegistersConnectButtonFormType(): void
    {
        $this->extension->load([], $this->container);

        $this->assertTrue(
            $this->container->has('kenzi_oro_commerce.form.type.connect_button'),
            'KenziConnectButtonType service should be registered'
        );

        $definition = $this->container->getDefinition('kenzi_oro_commerce.form.type.connect_button');
        $this->assertSame(
            'Kenzi\OroCommerceBundle\Form\Type\KenziConnectButtonType',
            $definition->getClass()
        );

        $tags = $definition->getTag('form.type');
        $this->assertNotEmpty($tags, 'Service should have form.type tag');
    }

    public function testPrependRegistersFormTheme(): void
    {
        $this->extension->prepend($this->container);

        $twigConfigs = $this->container->getExtensionConfig('twig');

        $formThemes = [];
        foreach ($twigConfigs as $config) {
            if (isset($config['form_themes'])) {
                $formThemes = array_merge($formThemes, $config['form_themes']);
            }
        }

        $this->assertContains(
            '@KenziOroCommerce/Form/fields.html.twig',
            $formThemes,
            'Bundle form theme must be registered'
        );
    }
}
