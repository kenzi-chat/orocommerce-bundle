<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Form\Type;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Kenzi\OroCommerceBundle\Form\Type\KenziWidgetToggleType;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class KenziWidgetToggleTypeTest extends TestCase
{
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;
    private KenziWidgetToggleType $type;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->type = new KenziWidgetToggleType($this->configManager);
    }

    public function testGetParentReturnsCheckboxType(): void
    {
        $this->assertSame(CheckboxType::class, $this->type->getParent());
    }

    public function testConfigureOptionsDisabledWhenNotConnected(): void
    {
        $this->stubSecret('');

        $resolver = new OptionsResolver();
        $resolver->setDefined(['disabled']);
        $this->type->configureOptions($resolver);

        $this->assertTrue($resolver->resolve([])['disabled']);
    }

    public function testConfigureOptionsEnabledWhenConnected(): void
    {
        $this->stubSecret('ss_abc123');

        $resolver = new OptionsResolver();
        $resolver->setDefined(['disabled']);
        $this->type->configureOptions($resolver);

        $this->assertFalse($resolver->resolve([])['disabled']);
    }

    private function stubSecret(string $secret): void
    {
        $this->configManager->method('get')
            ->with(Configuration::getConfigKeyByName(Configuration::PARAM_NAME_SHARED_SECRET))
            ->willReturn($secret);
    }
}
