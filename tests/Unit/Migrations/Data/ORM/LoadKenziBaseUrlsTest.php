<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Migrations\Data\ORM;

use Doctrine\Persistence\ObjectManager;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Kenzi\OroCommerceBundle\Migrations\Data\ORM\LoadKenziBaseUrls;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class LoadKenziBaseUrlsTest extends TestCase
{
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;
    private LoadKenziBaseUrls $migration;

    /** @var array<string, string|false> Original env var values for cleanup */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('oro_config.global')
            ->willReturn($this->configManager);

        $this->migration = new LoadKenziBaseUrls();
        $this->migration->setContainer($container);

        // Capture original env values so we can restore after each test
        $this->originalEnv['KENZI_APP_BASE'] = getenv('KENZI_APP_BASE');
        $this->originalEnv['KENZI_STATIC_BASE'] = getenv('KENZI_STATIC_BASE');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
    }

    public function testLoadSetsProductionDefaultsWhenEnvVarsAbsent(): void
    {
        putenv('KENZI_APP_BASE');
        putenv('KENZI_STATIC_BASE');

        $setCalls = [];
        $this->configManager->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $key, string $value) use (&$setCalls) {
                $setCalls[$key] = $value;
            });
        $this->configManager->expects($this->once())->method('flush');

        $this->migration->load($this->createMock(ObjectManager::class));

        $this->assertSame(
            'https://app.kenzi.chat',
            $setCalls[Configuration::getConfigKeyByName(Configuration::PARAM_NAME_APP_BASE_URL)]
        );
        $this->assertSame(
            'https://static.kenzi.chat',
            $setCalls[Configuration::getConfigKeyByName(Configuration::PARAM_NAME_STATIC_BASE_URL)]
        );
    }

    public function testLoadUsesEnvVarsWhenPresent(): void
    {
        putenv('KENZI_APP_BASE=https://staging.kenzi.chat');
        putenv('KENZI_STATIC_BASE=https://staging-static.kenzi.chat');

        $setCalls = [];
        $this->configManager->expects($this->exactly(2))
            ->method('set')
            ->willReturnCallback(function (string $key, string $value) use (&$setCalls) {
                $setCalls[$key] = $value;
            });
        $this->configManager->expects($this->once())->method('flush');

        $this->migration->load($this->createMock(ObjectManager::class));

        $this->assertSame(
            'https://staging.kenzi.chat',
            $setCalls[Configuration::getConfigKeyByName(Configuration::PARAM_NAME_APP_BASE_URL)]
        );
        $this->assertSame(
            'https://staging-static.kenzi.chat',
            $setCalls[Configuration::getConfigKeyByName(Configuration::PARAM_NAME_STATIC_BASE_URL)]
        );
    }

    public function testLoadUsesPartialEnvVarsWithFallback(): void
    {
        putenv('KENZI_APP_BASE=http://localhost:4000');
        putenv('KENZI_STATIC_BASE');

        $setCalls = [];
        $this->configManager->method('set')
            ->willReturnCallback(function (string $key, string $value) use (&$setCalls) {
                $setCalls[$key] = $value;
            });
        $this->configManager->method('flush');

        $this->migration->load($this->createMock(ObjectManager::class));

        $this->assertSame(
            'http://localhost:4000',
            $setCalls[Configuration::getConfigKeyByName(Configuration::PARAM_NAME_APP_BASE_URL)]
        );
        $this->assertSame(
            'https://static.kenzi.chat',
            $setCalls[Configuration::getConfigKeyByName(Configuration::PARAM_NAME_STATIC_BASE_URL)]
        );
    }

    public function testLoadFlushesConfigAfterSettingBothValues(): void
    {
        putenv('KENZI_APP_BASE');
        putenv('KENZI_STATIC_BASE');

        $callOrder = [];
        $this->configManager->method('set')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'set';
            });
        $this->configManager->method('flush')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'flush';
            });

        $this->migration->load($this->createMock(ObjectManager::class));

        $this->assertSame(['set', 'set', 'flush'], $callOrder);
    }
}
