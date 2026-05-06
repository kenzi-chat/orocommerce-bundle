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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class KenziConnectButtonTypeTest extends TestCase
{
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;
    /** @var ApplicationUrlResolver&MockObject */
    private MockObject $urlResolver;
    /** @var UrlGeneratorInterface&MockObject */
    private MockObject $router;
    private KenziConnectButtonType $type;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->urlResolver = $this->createMock(ApplicationUrlResolver::class);
        $this->router = $this->createMock(UrlGeneratorInterface::class);

        $this->type = new KenziConnectButtonType(
            $this->configManager,
            $this->urlResolver,
            $this->router,
        );
    }

    public function testConfigureOptionsSetsUnmapped(): void
    {
        $resolver = new OptionsResolver();
        $this->type->configureOptions($resolver);

        $resolved = $resolver->resolve([]);
        $this->assertFalse($resolved['mapped']);
    }

    public function testGetBlockPrefix(): void
    {
        $this->assertSame('kenzi_connect_button', $this->type->getBlockPrefix());
    }

    public function testBuildViewEmitsBootstrapDict(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.chat',
            Configuration::PARAM_NAME_SHARED_SECRET => 'sec_abc',
        ]);
        $this->urlResolver->method('instanceKey')->willReturn('store.example.com');
        $this->stubRoutes();

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertArrayHasKey('kenzi_bootstrap', $view->vars);
        $bootstrap = $view->vars['kenzi_bootstrap'];

        $this->assertSame('https://app.kenzi.chat', $bootstrap['kenzi_app_origin']);
        $this->assertSame('store.example.com', $bootstrap['instance_key']);
        $this->assertSame(['commerce'], $bootstrap['supported_grants']);
        $this->assertTrue($bootstrap['secret_exists']);

        $this->assertSame([
            'connect' => '/admin/kenzi/connect',
            'configure' => '/admin/kenzi/configure',
            'integration' => '/admin/kenzi/integration',
            'disconnect' => '/admin/kenzi/disconnect',
        ], $bootstrap['endpoints']);
    }

    public function testBuildViewSecretExistsFalseWhenSecretEmpty(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.chat',
            Configuration::PARAM_NAME_SHARED_SECRET => '',
        ]);
        $this->urlResolver->method('instanceKey')->willReturn('store.example.com');
        $this->stubRoutes();

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertFalse($view->vars['kenzi_bootstrap']['secret_exists']);
    }

    public function testBuildViewDoesNotReadDroppedKeys(): void
    {
        // The dropped keys (connected_at, credentials_delivered, instance_key,
        // sync_enabled, oauth_client_id) no longer exist in Configuration. If
        // buildView() tries to read them, the test setup wouldn't catch it
        // directly — instead we assert the new bootstrap dict is the ONLY
        // surface, which by structure can't include the dropped keys.
        $this->stubConfig([
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.chat',
            Configuration::PARAM_NAME_SHARED_SECRET => '',
        ]);
        $this->urlResolver->method('instanceKey')->willReturn('store.example.com');
        $this->stubRoutes();

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $bootstrapKeys = array_keys($view->vars['kenzi_bootstrap']);
        sort($bootstrapKeys);
        $this->assertSame([
            'endpoints',
            'instance_key',
            'kenzi_app_origin',
            'secret_exists',
            'supported_grants',
        ], $bootstrapKeys);

        // Specifically: no `is_connected`, `credentials_delivered`,
        // `connected_at` keys at the top level either.
        $this->assertArrayNotHasKey('is_connected', $view->vars);
        $this->assertArrayNotHasKey('credentials_delivered', $view->vars);
        $this->assertArrayNotHasKey('connected_at', $view->vars);
    }

    public function testBuildViewPassesAppBaseUrlAsKenziOriginVerbatim(): void
    {
        // Trust-the-contract: app_base_url is documented as already being a
        // clean origin. The form type passes it through without normalization.
        $this->stubConfig([
            Configuration::PARAM_NAME_APP_BASE_URL => 'http://localhost:4000',
            Configuration::PARAM_NAME_SHARED_SECRET => '',
        ]);
        $this->urlResolver->method('instanceKey')->willReturn('localhost');
        $this->stubRoutes();

        $view = new FormView();
        $form = $this->createMock(FormInterface::class);

        $this->type->buildView($view, $form, []);

        $this->assertSame('http://localhost:4000', $view->vars['kenzi_bootstrap']['kenzi_app_origin']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stubConfig(array $values): void
    {
        $this->configManager->method('get')
            ->willReturnCallback(function (string $key) use ($values): mixed {
                $paramName = str_replace(Configuration::ROOT_NODE . '.', '', $key);
                return $values[$paramName] ?? null;
            });
    }

    private function stubRoutes(): void
    {
        $this->router->method('generate')
            ->willReturnCallback(function (string $name): string {
                return match ($name) {
                    'kenzi_connect' => '/admin/kenzi/connect',
                    'kenzi_configure' => '/admin/kenzi/configure',
                    'kenzi_integration' => '/admin/kenzi/integration',
                    'kenzi_disconnect' => '/admin/kenzi/disconnect',
                    default => throw new \LogicException("Unexpected route: {$name}"),
                };
            });
    }

}
