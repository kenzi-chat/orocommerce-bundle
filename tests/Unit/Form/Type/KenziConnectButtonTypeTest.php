<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Form\Type;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Kenzi\OroCommerceBundle\Form\Type\KenziConnectButtonType;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

final class KenziConnectButtonTypeTest extends TestCase
{
    private ConfigManager&MockObject $configManager;
    private KenziConnectButtonType $type;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->type = new KenziConnectButtonType($this->configManager);
    }

    public function testConfigureOptionsSetsUnmapped(): void
    {
        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $this->type->configureOptions($resolver);

        $resolved = $resolver->resolve([]);
        $this->assertFalse($resolved['mapped']);
    }

    public function testGetBlockPrefix(): void
    {
        $this->assertSame('kenzi_connect_button', $this->type->getBlockPrefix());
    }

    public function testBuildViewWhenConnected(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_CONNECTED_AT => '2026-02-24T10:30:00+00:00',
            Configuration::PARAM_NAME_WORKSPACE_ID => 'ws_abc123',
            Configuration::PARAM_NAME_STORE_KEY => 'b2b.acme-corp.com',
            Configuration::PARAM_NAME_WEBHOOK_URL => 'https://app.kenzi.chat/orocommerce/webhooks',
        ]);
        $this->configManager->method('getScopeId')->willReturn(42);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertTrue($view->vars['is_connected']);
        $this->assertSame('ws_abc123', $view->vars['workspace_id']);
        $this->assertSame('b2b.acme-corp.com', $view->vars['store_key']);
        $this->assertSame('2026-02-24T10:30:00+00:00', $view->vars['connected_at']);
        $this->assertSame(42, $view->vars['website_id']);
        $this->assertSame('https://app.kenzi.chat', $view->vars['kenzi_origin']);
    }

    public function testBuildViewWhenDisconnectedDerivesStoreKeyFromWebsiteUrl(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_STORE_KEY => '',
            Configuration::PARAM_NAME_WEBHOOK_URL => 'https://staging.kenzi.chat/orocommerce/webhooks',
        ], [
            'oro_website.url' => 'https://b2b.acme-corp.com',
        ]);
        $this->configManager->method('getScopeId')->willReturn(7);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertFalse($view->vars['is_connected']);
        $this->assertSame('', $view->vars['workspace_id']);
        $this->assertSame('b2b.acme-corp.com', $view->vars['store_key']);
        $this->assertSame('', $view->vars['connected_at']);
        $this->assertSame(7, $view->vars['website_id']);
        $this->assertSame('https://staging.kenzi.chat', $view->vars['kenzi_origin']);
    }

    public function testBuildViewUsesStoredStoreKeyWhenAvailable(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_STORE_KEY => 'already-set.example.com',
            Configuration::PARAM_NAME_WEBHOOK_URL => 'https://app.kenzi.chat/orocommerce/webhooks',
        ]);
        $this->configManager->method('getScopeId')->willReturn(7);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        // Should use stored value, NOT derive from website URL
        $this->assertSame('already-set.example.com', $view->vars['store_key']);
    }

    public function testBuildViewWhenGlobalScopeDerivesStoreKey(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_STORE_KEY => '',
            Configuration::PARAM_NAME_WEBHOOK_URL => 'https://app.kenzi.chat/orocommerce/webhooks',
        ], [
            'oro_website.url' => 'https://b2b.default-store.com',
        ]);
        $this->configManager->method('getScopeId')->willReturn(0);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertFalse($view->vars['is_connected']);
        $this->assertSame(0, $view->vars['website_id']);
        // On global scope (CE), store_key is still derived from oro_website.url
        $this->assertSame('b2b.default-store.com', $view->vars['store_key']);
        $this->assertSame('https://app.kenzi.chat', $view->vars['kenzi_origin']);
    }

    public function testBuildViewDerivesOriginWithPort(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_STORE_KEY => '',
            Configuration::PARAM_NAME_WEBHOOK_URL => 'http://localhost:4000/orocommerce/webhooks',
        ]);
        $this->configManager->method('getScopeId')->willReturn(1);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertSame('http://localhost:4000', $view->vars['kenzi_origin']);
    }

    public function testBuildViewHandlesEmptyWebhookUrl(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_STORE_KEY => '',
            Configuration::PARAM_NAME_WEBHOOK_URL => '',
        ]);
        $this->configManager->method('getScopeId')->willReturn(1);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertSame('', $view->vars['kenzi_origin']);
    }

    public function testBuildViewHandlesEmptyWebsiteUrl(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_STORE_KEY => '',
            Configuration::PARAM_NAME_WEBHOOK_URL => 'https://app.kenzi.chat/orocommerce/webhooks',
        ], [
            'oro_website.url' => '',
        ]);
        // Works on both global (0) and website (5) scope
        $this->configManager->method('getScopeId')->willReturn(5);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        // Empty website URL → empty store_key (no hostname to derive)
        $this->assertSame('', $view->vars['store_key']);
        $this->assertSame(5, $view->vars['website_id']);
    }

    public function testBuildViewHandlesEmptyWebsiteUrlOnGlobalScope(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_STORE_KEY => '',
            Configuration::PARAM_NAME_WEBHOOK_URL => 'https://app.kenzi.chat/orocommerce/webhooks',
        ], [
            'oro_website.url' => '',
        ]);
        $this->configManager->method('getScopeId')->willReturn(0);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        // Global scope with empty URL → empty store_key
        $this->assertSame('', $view->vars['store_key']);
        $this->assertSame(0, $view->vars['website_id']);
    }

    /**
     * @param array<string, mixed> $values  Kenzi config params (without ROOT_NODE prefix)
     * @param array<string, mixed> $rawConfig  Arbitrary config keys (e.g. 'oro_website.url')
     */
    private function stubConfig(array $values, array $rawConfig = []): void
    {
        $this->configManager->method('get')
            ->willReturnCallback(function (string $key) use ($values, $rawConfig): mixed {
                if (isset($rawConfig[$key])) {
                    return $rawConfig[$key];
                }

                $paramName = str_replace(Configuration::ROOT_NODE . '.', '', $key);

                return $values[$paramName] ?? null;
            });
    }
}
