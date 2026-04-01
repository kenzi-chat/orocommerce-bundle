<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Form\Type;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
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
    private ApplicationUrlResolver&MockObject $urlResolver;
    private KenziConnectButtonType $type;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->urlResolver = $this->createMock(ApplicationUrlResolver::class);
        $this->type = new KenziConnectButtonType($this->configManager, $this->urlResolver);
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
            'oro_ui.application_url' => 'https://b2b.acme-corp.com',
            Configuration::PARAM_NAME_CONNECTED_AT => '2026-02-24T10:30:00+00:00',
            Configuration::PARAM_NAME_WORKSPACE_ID => 'ws_abc123',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'b2b.acme-corp.com',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.chat',
        ]);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertTrue($view->vars['is_connected']);
        $this->assertSame('ws_abc123', $view->vars['workspace_id']);
        $this->assertSame('b2b.acme-corp.com', $view->vars['instance_key']);
        $this->assertSame('2026-02-24T10:30:00+00:00', $view->vars['connected_at']);
        $this->assertSame('https://app.kenzi.chat', $view->vars['kenzi_origin']);
        $this->assertSame('https://b2b.acme-corp.com/admin', $view->vars['admin_url']);
        $this->assertSame('https://b2b.acme-corp.com/admin/api', $view->vars['api_url']);
        $this->assertSame('https://b2b.acme-corp.com/oauth2-token', $view->vars['token_url']);
    }

    public function testBuildViewWhenDisconnectedDerivesInstanceKeyFromAppUrl(): void
    {
        $this->stubConfig([
            'oro_ui.application_url' => 'https://b2b.acme-corp.com',
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => '',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://staging.kenzi.chat',
        ]);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertFalse($view->vars['is_connected']);
        $this->assertSame('', $view->vars['workspace_id']);
        $this->assertSame('b2b.acme-corp.com', $view->vars['instance_key']);
        $this->assertSame('', $view->vars['connected_at']);
        $this->assertSame('https://staging.kenzi.chat', $view->vars['kenzi_origin']);
        $this->assertSame('https://b2b.acme-corp.com/admin', $view->vars['admin_url']);
        $this->assertSame('https://b2b.acme-corp.com/admin/api', $view->vars['api_url']);
        $this->assertSame('https://b2b.acme-corp.com/oauth2-token', $view->vars['token_url']);
    }

    public function testBuildViewUsesStoredInstanceKeyWhenAvailable(): void
    {
        $this->stubConfig([
            'oro_ui.application_url' => 'https://example.com',
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'already-set.example.com',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.chat',
        ]);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        // Should use stored value, NOT derive from app URL
        $this->assertSame('already-set.example.com', $view->vars['instance_key']);
    }

    public function testBuildViewReadsAppBaseUrlDirectlyAsOrigin(): void
    {
        $this->stubConfig([
            'oro_ui.application_url' => 'http://localhost:8000',
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => '',
            Configuration::PARAM_NAME_APP_BASE_URL => 'http://localhost:4000',
        ]);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertSame('http://localhost:4000', $view->vars['kenzi_origin']);
        $this->assertSame('http://localhost:8000/admin/api', $view->vars['api_url']);
        $this->assertSame('http://localhost:8000/oauth2-token', $view->vars['token_url']);
    }

    public function testBuildViewApiUrlIncludesNonStandardPort(): void
    {
        $this->stubConfig([
            'oro_ui.application_url' => 'https://oro.example.com:8443',
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => '',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.chat',
        ]);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertSame('oro.example.com', $view->vars['instance_key']);
        $this->assertSame('https://oro.example.com:8443/admin/api', $view->vars['api_url']);
        $this->assertSame('https://oro.example.com:8443/admin', $view->vars['admin_url']);
        $this->assertSame('https://oro.example.com:8443/oauth2-token', $view->vars['token_url']);
    }

    public function testBuildViewHandlesEmptyAppBaseUrl(): void
    {
        $this->stubConfig([
            'oro_ui.application_url' => 'https://store.test',
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => '',
            Configuration::PARAM_NAME_APP_BASE_URL => '',
        ]);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertSame('', $view->vars['kenzi_origin']);
    }

    public function testBuildViewAdminUrlIsAppUrlPlusAdminPath(): void
    {
        $this->stubConfig([
            'oro_ui.application_url' => 'https://store.test',
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'store.test',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.chat',
        ]);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertSame('https://store.test/admin', $view->vars['admin_url']);
    }

    public function testBuildViewLocalDevWithPort(): void
    {
        $this->stubConfig([
            'oro_ui.application_url' => 'http://localhost:8000',
            Configuration::PARAM_NAME_CONNECTED_AT => '',
            Configuration::PARAM_NAME_WORKSPACE_ID => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => '',
            Configuration::PARAM_NAME_APP_BASE_URL => 'http://localhost:4000',
        ]);

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertSame('localhost', $view->vars['instance_key']);
        $this->assertSame('http://localhost:8000/admin', $view->vars['admin_url']);
        $this->assertSame('http://localhost:8000/admin/api', $view->vars['api_url']);
        $this->assertSame('http://localhost:8000/oauth2-token', $view->vars['token_url']);
    }

    /**
     * @param array<string, mixed> $values  Config keys (Kenzi params without ROOT_NODE prefix, or full keys like 'oro_ui.application_url')
     */
    private function stubConfig(array $values): void
    {
        $appUrl = (string) ($values['oro_ui.application_url'] ?? '');
        $parts = parse_url($appUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $baseOrigin = $host === '' ? '' : $scheme . '://' . $host . $port;
        $adminUrl = $baseOrigin === '' ? '/admin' : $baseOrigin . '/admin';

        $this->urlResolver->method('instanceKey')->willReturn($host);
        $this->urlResolver->method('adminUrl')->willReturn($adminUrl);
        $this->urlResolver->method('apiUrl')->willReturn($adminUrl . '/api');

        $this->configManager->method('get')
            ->willReturnCallback(function (string $key) use ($values): mixed {
                if (isset($values[$key])) {
                    return $values[$key];
                }

                $paramName = str_replace(Configuration::ROOT_NODE . '.', '', $key);

                return $values[$paramName] ?? null;
            });
    }
}
