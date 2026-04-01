<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Controller;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Kenzi\OroCommerceBundle\Controller\ConnectController;
use Kenzi\OroCommerceBundle\Credential\CredentialDelivery;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConnectControllerTest extends TestCase
{
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;
    /** @var ApplicationUrlResolver&MockObject */
    private MockObject $urlResolver;
    /** @var CredentialDelivery&MockObject */
    private MockObject $credentialDelivery;
    private ConnectController $controller;

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->urlResolver = $this->createMock(ApplicationUrlResolver::class);
        $this->credentialDelivery = $this->createMock(CredentialDelivery::class);

        $this->controller = new ConnectController(
            $this->configManager,
            $this->urlResolver,
            $this->credentialDelivery,
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
            'shared_secret' => '',
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testConnectReturns400WhenCredentialsAreWhitespaceOnly(): void
    {
        $request = $this->createJsonRequest([
            'workspace_id' => '   ',
            'shared_secret' => '   ',
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testConnectReturns400WhenMissingSharedSecret(): void
    {
        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_123',
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testConnectReturns422WhenHostnameEmpty(): void
    {
        $this->stubAppUrl('');

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_1',
            'shared_secret' => 'sec_1',
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"error":"Could not determine application hostname"}',
            $response->getContent()
        );
    }

    // -- Connect: success --

    public function testConnectSuccessfully(): void
    {
        $this->stubAppUrl('https://b2b.acme-corp.com');
        $this->credentialDelivery->method('deliver')->willReturn(false);

        $setCalls = [];
        $this->configManager->expects($this->exactly(5))
            ->method('set')
            ->willReturnCallback(function (string $key, $value) use (&$setCalls) {
                $setCalls[] = ['key' => $key, 'value' => $value];
            });
        $this->configManager->expects($this->once())->method('flush');

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_nano_42',
            'shared_secret' => 'hmac_secret_xyz',
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"status":"connected","credentials_delivered":false}',
            $response->getContent()
        );

        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_WORKSPACE_ID, 'ws_nano_42');
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SHARED_SECRET, 'hmac_secret_xyz');
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_INSTANCE_KEY, 'b2b.acme-corp.com');
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SYNC_ENABLED, true);

        // connected_at is verified in detail by testConnectWritesConnectedAtTimestamp;
        // here we just confirm it was included in the 5 set() calls.
        $connectedAtKey = Configuration::getConfigKeyByName(Configuration::PARAM_NAME_CONNECTED_AT);
        $connectedAtValues = array_filter($setCalls, fn (array $c) => $c['key'] === $connectedAtKey);
        $this->assertCount(1, $connectedAtValues, 'connected_at should be set exactly once');
    }

    public function testConnectTrimsWhitespaceFromCredentials(): void
    {
        $this->stubAppUrl('https://store.test');
        $this->credentialDelivery->method('deliver')->willReturn(false);

        $setCalls = [];
        $this->configManager->method('set')
            ->willReturnCallback(function (string $key, $value) use (&$setCalls) {
                $setCalls[] = ['key' => $key, 'value' => $value];
            });
        $this->configManager->method('flush');

        $request = $this->createJsonRequest([
            'workspace_id' => '  ws_padded  ',
            'shared_secret' => '  sec_padded  ',
        ]);

        $response = $this->controller->connect($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_WORKSPACE_ID, 'ws_padded');
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SHARED_SECRET, 'sec_padded');
    }

    public function testConnectDerivesInstanceKeyFromAppUrl(): void
    {
        $this->stubAppUrl('https://shop.example.org');
        $this->credentialDelivery->method('deliver')->willReturn(false);

        $capturedKey = null;
        $this->configManager->method('set')
            ->willReturnCallback(function (string $key, $value) use (&$capturedKey) {
                if ($key === Configuration::getConfigKeyByName(Configuration::PARAM_NAME_INSTANCE_KEY)) {
                    $capturedKey = $value;
                }
            });
        $this->configManager->method('flush');

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_1',
            'shared_secret' => 'sec_1',
        ]);

        $this->controller->connect($request);

        $this->assertSame('shop.example.org', $capturedKey);
    }

    public function testConnectWritesConnectedAtTimestamp(): void
    {
        $this->stubAppUrl('https://store.test');
        $this->credentialDelivery->method('deliver')->willReturn(false);

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
            'shared_secret' => 'sec_1',
        ]);

        $this->controller->connect($request);

        $this->assertNotEmpty($capturedConnectedAt);
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $capturedConnectedAt);
        $this->assertNotFalse($parsed, 'connected_at should be a valid ISO 8601 timestamp');

        $diff = abs((new \DateTimeImmutable())->getTimestamp() - $parsed->getTimestamp());
        $this->assertLessThan(5, $diff, 'connected_at should be within 5 seconds of now');
    }

    public function testConnectDeliversCredentials(): void
    {
        $this->stubAppUrl('https://store.test');

        $this->credentialDelivery->expects($this->once())
            ->method('deliver')
            ->willReturn(true);

        $this->configManager->method('set');
        $this->configManager->method('flush');

        $request = $this->createJsonRequest([
            'workspace_id' => 'ws_1',
            'shared_secret' => 'sec_1',
        ]);

        $response = $this->controller->connect($request);

        $this->assertJsonStringEqualsJsonString(
            '{"status":"connected","credentials_delivered":true}',
            $response->getContent()
        );
    }

    // -- Disconnect --

    public function testDisconnectClearsAllConfigFields(): void
    {
        $this->credentialDelivery->expects($this->once())->method('cleanup');

        $setCalls = [];
        $this->configManager->expects($this->exactly(5))
            ->method('set')
            ->willReturnCallback(function (string $key, $value) use (&$setCalls) {
                $setCalls[] = ['key' => $key, 'value' => $value];
            });
        $this->configManager->expects($this->once())->method('flush');

        $response = $this->controller->disconnect();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"status":"disconnected"}', $response->getContent());

        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SYNC_ENABLED, false);
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_SHARED_SECRET, '');
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_WORKSPACE_ID, '');
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_INSTANCE_KEY, '');
        $this->assertConfigWasSet($setCalls, Configuration::PARAM_NAME_CONNECTED_AT, '');
    }

    // -- Helpers --

    /** @param array<string, mixed> $data */
    private function createJsonRequest(array $data): Request
    {
        return new Request([], [], [], [], [], [], json_encode($data));
    }

    private function stubAppUrl(string $appUrl): void
    {
        $host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '');
        $this->urlResolver->method('instanceKey')->willReturn($host);
    }

    /**
     * @param array<array{key: string, value: mixed}> $setCalls
     * @param string|bool $expectedValue
     */
    private function assertConfigWasSet(array $setCalls, string $paramName, $expectedValue): void
    {
        $expectedKey = Configuration::getConfigKeyByName($paramName);
        foreach ($setCalls as $call) {
            if ($call['key'] === $expectedKey) {
                $this->assertSame($expectedValue, $call['value'], "Config value mismatch for {$paramName}");
                return;
            }
        }
        $this->fail("Expected config set for {$paramName} was not called");
    }
}
