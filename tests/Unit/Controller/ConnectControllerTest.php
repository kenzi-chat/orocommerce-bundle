<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Controller;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Kenzi\OroCommerceBundle\Controller\ConnectController;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\WebsiteBundle\Entity\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConnectControllerTest extends TestCase
{
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;
    /** @var ManagerRegistry&MockObject */
    private MockObject $doctrine;
    /** @var AclHelper&MockObject */
    private MockObject $aclHelper;
    private ConnectController $controller;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->aclHelper = $this->createMock(AclHelper::class);

        $this->controller = new ConnectController(
            $this->configManager,
            $this->doctrine,
            $this->aclHelper,
        );
    }

    // -- Connect: validation --

    public function testConnectReturns400WhenBodyIsEmpty(): void
    {
        $request = new Request([], [], [], [], [], [], '');

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testConnectReturns400WhenBodyIsInvalidJson(): void
    {
        $request = new Request([], [], [], [], [], [], 'not json');

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testConnectReturns400WhenMissingFields(): void
    {
        $request = $this->createJsonRequest(['workspace_id' => 'ws_123']);

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"error":"Missing required fields"}',
            $response->getContent()
        );
    }

    public function testConnectReturns400WhenCredentialsAreEmptyStrings(): void
    {
        $request = $this->createJsonRequest([
            'workspace_id' => '',
            'secret' => '',
            'website_id' => 1,
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testConnectReturns400WhenCredentialsAreWhitespaceOnly(): void
    {
        $request = $this->createJsonRequest([
            'workspace_id' => '   ',
            'secret' => '   ',
            'website_id' => 1,
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testConnectReturns400WhenMissingWebhookSecret(): void
    {
        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_123',
            'website_id' => 1,
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    // -- Connect: website lookup --

    public function testConnectReturns404WhenWebsiteNotFound(): void
    {
        $this->stubWebsiteLookup(null);

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_123',
            'secret' => 'secret_abc',
            'website_id' => 99,
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testConnectReturns422WhenWebsiteUrlIsEmpty(): void
    {
        $website = $this->createWebsiteMock(3);
        $this->stubWebsiteLookup($website);
        $this->stubWebsiteUrl($website, '');

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_1',
            'secret' => 'sec_1',
            'website_id' => 3,
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"error":"Website URL not configured"}',
            $response->getContent()
        );
    }

    // -- Connect: success --

    public function testConnectSuccessfully(): void
    {
        $website = $this->createWebsiteMock(1);
        $this->stubWebsiteLookup($website);
        $this->stubWebsiteUrl($website, 'https://b2b.acme-corp.com/store');

        $setCalls = [];
        $this->configManager->expects($this->exactly(5))
            ->method('set')
            ->willReturnCallback(function (string $key, $value, ?Website $scopeEntity) use (&$setCalls) {
                $setCalls[] = ['key' => $key, 'value' => $value, 'scope' => $scopeEntity];
            });
        $this->configManager->expects($this->once())->method('flush');

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_nano_42',
            'secret' => 'hmac_secret_xyz',
            'website_id' => 1,
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"status":"connected"}', $response->getContent());

        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_WORKSPACE_ID, 'ws_nano_42', $website);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SECRET, 'hmac_secret_xyz', $website);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_STORE_KEY, 'b2b.acme-corp.com', $website);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SYNC_ENABLED, true, $website);
    }

    public function testConnectTrimsWhitespaceFromCredentials(): void
    {
        $website = $this->createWebsiteMock(1);
        $this->stubWebsiteLookup($website);
        $this->stubWebsiteUrl($website, 'https://store.test');

        $setCalls = [];
        $this->configManager->method('set')
            ->willReturnCallback(function (string $key, $value, ?Website $scopeEntity) use (&$setCalls) {
                $setCalls[] = ['key' => $key, 'value' => $value, 'scope' => $scopeEntity];
            });
        $this->configManager->method('flush');

        $request = $this->createJsonRequest([
            'workspace_id' => '  ws_padded  ',
            'secret' => '  sec_padded  ',
            'website_id' => 1,
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_WORKSPACE_ID, 'ws_padded', $website);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SECRET, 'sec_padded', $website);
    }

    public function testConnectDerivesStoreKeyFromWebsiteUrl(): void
    {
        $website = $this->createWebsiteMock(2);
        $this->stubWebsiteLookup($website);
        $this->stubWebsiteUrl($website, 'https://shop.example.org/en');

        $capturedStoreKey = null;
        $this->configManager->method('set')
            ->willReturnCallback(function (string $key, $value) use (&$capturedStoreKey) {
                if ($key === Configuration::getConfigKeyByName(Configuration::PARAM_NAME_STORE_KEY)) {
                    $capturedStoreKey = $value;
                }
            });
        $this->configManager->method('flush');

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_1',
            'secret' => 'sec_1',
            'website_id' => 2,
        ]);

        $this->controller->connect($request);

        $this->assertSame('shop.example.org', $capturedStoreKey);
    }

    public function testConnectWritesConnectedAtTimestamp(): void
    {
        $website = $this->createWebsiteMock(1);
        $this->stubWebsiteLookup($website);
        $this->stubWebsiteUrl($website, 'https://store.test');

        $capturedConnectedAt = null;
        $this->configManager->method('set')
            ->willReturnCallback(function (string $key, $value) use (&$capturedConnectedAt) {
                if ($key === Configuration::getConfigKeyByName(Configuration::PARAM_NAME_CONNECTED_AT)) {
                    $capturedConnectedAt = $value;
                }
            });
        $this->configManager->method('flush');

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_1',
            'secret' => 'sec_1',
            'website_id' => 1,
        ]);

        $this->controller->connect($request);

        $this->assertNotEmpty($capturedConnectedAt);
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $capturedConnectedAt);
        $this->assertNotFalse($parsed, 'connected_at should be a valid ISO 8601 timestamp');

        $diff = abs((new \DateTimeImmutable())->getTimestamp() - $parsed->getTimestamp());
        $this->assertLessThan(5, $diff, 'connected_at should be within 5 seconds of now');
    }

    // -- Disconnect: validation --

    public function testDisconnectReturns400WhenBodyIsEmpty(): void
    {
        $request = new Request([], [], [], [], [], [], '');

        $response = $this->controller->disconnect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDisconnectReturns400WhenBodyIsInvalidJson(): void
    {
        $request = new Request([], [], [], [], [], [], 'not json');

        $response = $this->controller->disconnect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDisconnectReturns400WhenNoWebsiteId(): void
    {
        $request = $this->createJsonRequest([]);

        $response = $this->controller->disconnect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testDisconnectReturns404WhenWebsiteNotFound(): void
    {
        $this->stubWebsiteLookup(null);

        $request = $this->createJsonRequest(['website_id' => 99]);

        $response = $this->controller->disconnect($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    // -- Disconnect: success --

    public function testDisconnectClearsAllConfigFields(): void
    {
        $website = $this->createWebsiteMock(1);
        $this->stubWebsiteLookup($website);

        $setCalls = [];
        $this->configManager->expects($this->exactly(5))
            ->method('set')
            ->willReturnCallback(function (string $key, $value, ?Website $scopeEntity) use (&$setCalls) {
                $setCalls[] = ['key' => $key, 'value' => $value, 'scope' => $scopeEntity];
            });
        $this->configManager->expects($this->once())->method('flush');

        $request = $this->createJsonRequest(['website_id' => 1]);

        $response = $this->controller->disconnect($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"status":"disconnected"}', $response->getContent());

        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SYNC_ENABLED, false, $website);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SECRET, '', $website);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_WORKSPACE_ID, '', $website);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_STORE_KEY, '', $website);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_CONNECTED_AT, '', $website);
    }

    // -- Helpers --

    private function createJsonRequest(array $data): Request
    {
        return new Request([], [], [], [], [], [], json_encode($data));
    }

    /** @return Website&MockObject */
    private function createWebsiteMock(int $id): MockObject
    {
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn($id);
        return $website;
    }

    private function stubWebsiteLookup(?Website $website): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getOneOrNullResult')->willReturn($website);

        $this->aclHelper->method('apply')->willReturn($query);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->with('w')->willReturn($qb);

        $this->doctrine->method('getRepository')
            ->with(Website::class)
            ->willReturn($repository);
    }

    private function stubWebsiteUrl(?Website $website, string $url): void
    {
        $this->configManager->method('get')
            ->with('oro_website.url', false, false, $website)
            ->willReturn($url);
    }

    /**
     * @param array<array{key: string, value: mixed, scope: ?Website}> $setCalls
     * @param string|bool $expectedValue
     */
    private function assertConfigWasSet(array $setCalls, string $paramName, $expectedValue, Website $expectedScope): void
    {
        $expectedKey = Configuration::getConfigKeyByName($paramName);
        foreach ($setCalls as $call) {
            if ($call['key'] === $expectedKey) {
                $this->assertSame($expectedValue, $call['value'], "Config value mismatch for {$paramName}");
                $this->assertSame($expectedScope, $call['scope'], "Config scope mismatch for {$paramName}");
                return;
            }
        }
        $this->fail("Expected config set for {$paramName} was not called");
    }
}
