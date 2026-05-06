<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Application;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ApplicationUrlResolverTest extends TestCase
{
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
    }

    /**
     * @dataProvider instanceKeyProvider
     */
    public function testInstanceKey(string $applicationUrl, string $expected): void
    {
        $this->stubAppUrl($applicationUrl);

        $resolver = new ApplicationUrlResolver($this->configManager, '/admin');

        $this->assertSame($expected, $resolver->instanceKey());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function instanceKeyProvider(): iterable
    {
        yield 'lowercase host stays lowercase' => [
            'https://oro.acme.com',
            'oro.acme.com',
        ];
        yield 'uppercase host gets lowercased' => [
            'https://Oro.Acme.COM',
            'oro.acme.com',
        ];
        yield 'mixed-case host gets lowercased' => [
            'https://Oro.ACME.com',
            'oro.acme.com',
        ];
        yield 'port is dropped' => [
            'https://oro.acme.com:8443',
            'oro.acme.com',
        ];
        yield 'trailing slash is stripped' => [
            'https://oro.acme.com/',
            'oro.acme.com',
        ];
        yield 'path is preserved without trailing slash' => [
            'https://oro.acme.com/store',
            'oro.acme.com/store',
        ];
        yield 'path with trailing slash gets stripped' => [
            'https://oro.acme.com/store/',
            'oro.acme.com/store',
        ];
        yield 'localhost with port' => [
            'http://localhost:8000',
            'localhost',
        ];
        yield 'empty url returns empty key' => [
            '',
            '',
        ];
    }

    public function testBaseOriginIncludesPort(): void
    {
        $this->stubAppUrl('https://oro.acme.com:8443/store/');

        $resolver = new ApplicationUrlResolver($this->configManager, '/admin');

        $this->assertSame('https://oro.acme.com:8443', $resolver->baseOrigin());
    }

    public function testAdminUrlAppendsBackendPrefix(): void
    {
        $this->stubAppUrl('https://oro.acme.com');

        $resolver = new ApplicationUrlResolver($this->configManager, '/admin');

        $this->assertSame('https://oro.acme.com/admin', $resolver->adminUrl());
    }

    public function testAdminUrlHandlesEmptyBackendPrefix(): void
    {
        $this->stubAppUrl('https://oro.acme.com');

        $resolver = new ApplicationUrlResolver($this->configManager, '');

        $this->assertSame('https://oro.acme.com', $resolver->adminUrl());
    }

    public function testApiUrlAppendsApiPath(): void
    {
        $this->stubAppUrl('https://oro.acme.com');

        $resolver = new ApplicationUrlResolver($this->configManager, '/admin');

        $this->assertSame('https://oro.acme.com/admin/api', $resolver->apiUrl());
    }

    private function stubAppUrl(string $url): void
    {
        $this->configManager->method('get')
            ->with('oro_ui.application_url')
            ->willReturn($url);
    }
}
