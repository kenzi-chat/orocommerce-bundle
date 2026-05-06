<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Controller;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Kenzi\OroCommerceBundle\Controller\ConnectController;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Kenzi\OroCommerceBundle\OAuth\OAuthClientFactory;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\OAuth2ServerBundle\Entity\Client;
use Oro\Bundle\OAuth2ServerBundle\Security\EncryptionKeysExistenceChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ConnectControllerTest extends TestCase
{
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;
    /** @var HttpClientInterface&MockObject */
    private MockObject $httpClient;
    /** @var OAuthClientFactory&MockObject */
    private MockObject $oauthClientFactory;
    /** @var ApplicationUrlResolver&MockObject */
    private MockObject $urlResolver;
    /** @var EncryptionKeysExistenceChecker&MockObject */
    private MockObject $encryptionKeysChecker;
    private ConnectController $controller;

    /** @var array<int, array{key: string, value: mixed}> */
    private array $configSetCalls = [];

    /** @var array<int, string> */
    private array $configResetCalls = [];

    /** @var array<string, mixed> */
    private array $stubbedConfig = [];

    protected function setUp(): void
    {
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->oauthClientFactory = $this->createMock(OAuthClientFactory::class);
        $this->urlResolver = $this->createMock(ApplicationUrlResolver::class);
        $this->encryptionKeysChecker = $this->createMock(EncryptionKeysExistenceChecker::class);
        $this->encryptionKeysChecker->method('isPrivateKeyExist')->willReturn(true);
        $this->encryptionKeysChecker->method('isPublicKeyExist')->willReturn(true);

        $this->controller = new ConnectController(
            $this->configManager,
            $this->httpClient,
            $this->oauthClientFactory,
            $this->urlResolver,
            new NullLogger(),
            $this->encryptionKeysChecker,
        );

        $this->configSetCalls = [];
        $this->configResetCalls = [];
        $this->stubbedConfig = [];
        $this->configManager->method('set')
            ->willReturnCallback(function (string $key, $value) {
                $this->configSetCalls[] = ['key' => $key, 'value' => $value];
            });
        $this->configManager->method('reset')
            ->willReturnCallback(function (string $key) {
                $this->configResetCalls[] = $key;
            });
        $this->configManager->method('get')
            ->willReturnCallback(function (string $key): mixed {
                $paramName = str_replace(Configuration::ROOT_NODE . '.', '', $key);
                return $this->stubbedConfig[$paramName] ?? null;
            });
    }

    // ─── connect ─────────────────────────────────────────────────────

    public function testConnectReturns422WhenBodyInvalidJson(): void
    {
        $response = $this->controller->connect(new Request([], [], [], [], [], [], 'not json'));
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testConnectReturns422WhenRequiredFieldMissing(): void
    {
        $response = $this->controller->connect($this->jsonRequest([
            'workspace_id' => 'ws_1',
            'grants' => ['commerce'],
        ]));
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testConnectReturns422WhenSharedSecretLacksPrefix(): void
    {
        $response = $this->controller->connect($this->jsonRequest([
            'shared_secret' => 'bad_prefix_abc',
            'workspace_id' => 'ws_1',
            'grants' => ['commerce'],
        ]));
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testConnectWritesThreeKeysAndFlushesOnce(): void
    {
        $this->configManager->expects($this->once())->method('flush');
        $this->httpClient->expects($this->never())->method('request');

        $response = $this->controller->connect($this->jsonRequest([
            'shared_secret' => 'ss_xyz',
            'workspace_id' => 'ws_42',
            'grants' => ['commerce'],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"ok":true}', $response->getContent());

        $this->assertConfigWasSet(Configuration::PARAM_NAME_SHARED_SECRET, 'ss_xyz');
        $this->assertConfigWasSet(Configuration::PARAM_NAME_GRANTS, ['commerce']);
        $this->assertConfigWasSet(Configuration::PARAM_NAME_WORKSPACE_ID, 'ws_42');
    }

    public function testConnectTrimsWhitespaceFromCredentials(): void
    {
        $this->configManager->method('flush');

        $this->controller->connect($this->jsonRequest([
            'shared_secret' => '  ss_padded  ',
            'workspace_id' => '  ws_padded  ',
            'grants' => ['commerce'],
        ]));

        $this->assertConfigWasSet(Configuration::PARAM_NAME_SHARED_SECRET, 'ss_padded');
        $this->assertConfigWasSet(Configuration::PARAM_NAME_WORKSPACE_ID, 'ws_padded');
    }

    public function testConnectFiltersNonStringGrantEntries(): void
    {
        $this->configManager->method('flush');

        $this->controller->connect($this->jsonRequest([
            'shared_secret' => 'ss_test',
            'workspace_id' => 'ws',
            'grants' => ['commerce', 42, null, 'support'],
        ]));

        $this->assertConfigWasSet(Configuration::PARAM_NAME_GRANTS, ['commerce', 'support']);
    }

    // ─── configure ───────────────────────────────────────────────────

    public function testConfigureMintsOAuthAndPatchesWhenCommerceGrantPresent(): void
    {
        $this->stubGrants(['commerce']);
        $this->stubAppBaseUrl('https://app.kenzi.test');
        $this->stubSecret('sec_abc');
        $this->stubResolverUrls();

        $this->oauthClientFactory->expects($this->once())
            ->method('create')
            ->with('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS])
            ->willReturn(['oauth_id_42', 'oauth_secret_42']);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                'https://app.kenzi.test/api/integration',
                $this->callback(function (array $options) {
                    $this->assertSame('Bearer sec_abc', $options['headers']['Authorization']);
                    $this->assertSame('application/json', $options['headers']['Accept']);

                    $body = $options['json'];
                    $this->assertSame([
                        'config' => [
                            'api_url' => 'https://oro.acme.com/admin/api',
                            'admin_url' => 'https://oro.acme.com/admin',
                            'base_url' => 'https://oro.acme.com',
                            'client_id' => 'oauth_id_42',
                            'client_secret' => 'oauth_secret_42',
                        ],
                    ], $body);
                    return true;
                })
            )
            ->willReturn($this->responseMock(200, '{"configured":true,"claimed":true}'));

        $response = $this->controller->configure();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertConfigWasSet(Configuration::PARAM_NAME_OAUTH_CLIENT_ID, 'oauth_id_42');
    }

    public function testConfigureDoesNotStoreOAuthClientIdWhenCommerceGrantAbsent(): void
    {
        $this->stubGrants(['support']);
        $this->stubAppBaseUrl('https://app.kenzi.test');
        $this->stubSecret('sec_abc');
        $this->stubResolverUrls();

        $this->oauthClientFactory->expects($this->never())->method('create');

        $this->httpClient->method('request')
            ->willReturn($this->responseMock(200, '{"configured":true,"claimed":false}'));

        $this->controller->configure();

        $oauthClientIdKey = Configuration::getConfigKeyByName(Configuration::PARAM_NAME_OAUTH_CLIENT_ID);
        foreach ($this->configSetCalls as $call) {
            $this->assertNotSame(
                $oauthClientIdKey,
                $call['key'],
                'oauth_client_id must not be written when commerce grant is absent'
            );
        }
    }

    public function testConfigureSkipsOAuthMintWhenCommerceGrantAbsent(): void
    {
        $this->stubGrants(['support']);
        $this->stubAppBaseUrl('https://app.kenzi.test');
        $this->stubSecret('sec_abc');
        $this->stubResolverUrls();

        $this->oauthClientFactory->expects($this->never())->method('create');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                $this->anything(),
                $this->callback(function (array $options) {
                    $body = $options['json'];
                    $this->assertSame([
                        'config' => [
                            'api_url' => 'https://oro.acme.com/admin/api',
                            'admin_url' => 'https://oro.acme.com/admin',
                            'base_url' => 'https://oro.acme.com',
                        ],
                    ], $body);
                    $this->assertArrayNotHasKey('client_id', $body['config']);
                    $this->assertArrayNotHasKey('client_secret', $body['config']);
                    return true;
                })
            )
            ->willReturn($this->responseMock(200, '{"configured":true,"claimed":false}'));

        $response = $this->controller->configure();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testConfigureReturns500WhenOAuthMintThrows(): void
    {
        $this->stubGrants(['commerce']);
        $this->stubAppBaseUrl('https://app.kenzi.test');
        $this->stubSecret('sec_abc');

        $this->oauthClientFactory->expects($this->once())
            ->method('create')
            ->willThrowException(new \RuntimeException('Insufficient permission'));

        $this->httpClient->expects($this->never())->method('request');

        $response = $this->controller->configure();

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testConfigureReturns500WhenEncryptionKeysMissing(): void
    {
        $this->stubGrants(['commerce']);

        $this->encryptionKeysChecker = $this->createMock(EncryptionKeysExistenceChecker::class);
        $this->encryptionKeysChecker->method('isPrivateKeyExist')->willReturn(false);
        $this->encryptionKeysChecker->method('isPublicKeyExist')->willReturn(false);

        $controller = new ConnectController(
            $this->configManager,
            $this->httpClient,
            $this->oauthClientFactory,
            $this->urlResolver,
            new NullLogger(),
            $this->encryptionKeysChecker,
        );

        $this->oauthClientFactory->expects($this->never())->method('create');
        $this->httpClient->expects($this->never())->method('request');

        $response = $controller->configure();

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testConfigureForwardsKenziStatusVerbatim(): void
    {
        $this->stubGrants([]);
        $this->stubAppBaseUrl('https://app.kenzi.test');
        $this->stubSecret('sec_abc');
        $this->stubResolverUrls();

        $this->httpClient->method('request')
            ->willReturn($this->responseMock(422, '{"configured":false,"errors":["bad"]}'));

        $response = $this->controller->configure();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString(
            '{"configured":false,"errors":["bad"]}',
            $response->getContent()
        );
    }

    // ─── integration ─────────────────────────────────────────────────

    public function testIntegrationReturns404WhenNoSecret(): void
    {
        $this->stubSecret('');

        $this->httpClient->expects($this->never())->method('request');

        $response = $this->controller->integration();

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testIntegrationGetsAndForwardsBody(): void
    {
        $this->stubSecret('sec_abc');
        $this->stubAppBaseUrl('https://app.kenzi.test');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://app.kenzi.test/api/integration',
                $this->callback(function (array $options) {
                    $this->assertSame('Bearer sec_abc', $options['headers']['Authorization']);
                    $this->assertArrayNotHasKey('json', $options);
                    return true;
                })
            )
            ->willReturn($this->responseMock(200, '{"configured":true,"claimed":true,"claim":{"workspace":{"name":"Acme"}}}'));

        $response = $this->controller->integration();

        $this->assertSame(200, $response->getStatusCode());
        // Symfony's JsonResponse may augment Cache-Control with `private` —
        // we only care that `no-store` is present.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testIntegrationReturns502OnTransportFailure(): void
    {
        $this->stubSecret('sec_abc');
        $this->stubAppBaseUrl('https://app.kenzi.test');

        $exception = new class ('Connection refused') extends \RuntimeException implements \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface {};
        $this->httpClient->method('request')->willThrowException($exception);

        $response = $this->controller->integration();

        $this->assertSame(502, $response->getStatusCode());
    }

    // ─── disconnect ──────────────────────────────────────────────────

    public function testDisconnectResetsAllFiveLifecycleKeys(): void
    {
        $this->stubSecret('sec_abc');
        $this->stubAppBaseUrl('https://app.kenzi.test');

        $this->httpClient->method('request')
            ->willReturn($this->responseMock(200, ''));

        $this->configManager->expects($this->once())->method('flush');

        $response = $this->controller->disconnect();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertJsonStringEqualsJsonString('{"ok":true}', $response->getContent());

        $this->assertConfigWasReset(Configuration::PARAM_NAME_SHARED_SECRET);
        $this->assertConfigWasReset(Configuration::PARAM_NAME_GRANTS);
        $this->assertConfigWasReset(Configuration::PARAM_NAME_WORKSPACE_ID);
        $this->assertConfigWasReset(Configuration::PARAM_NAME_OAUTH_CLIENT_ID);
        $this->assertConfigWasReset(Configuration::PARAM_NAME_WIDGET_ENABLED);
    }

    public function testDisconnectStillResetsWhenKenziUnreachable(): void
    {
        $this->stubSecret('sec_abc');
        $this->stubAppBaseUrl('https://app.kenzi.test');

        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->configManager->expects($this->once())->method('flush');

        $response = $this->controller->disconnect();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertConfigWasReset(Configuration::PARAM_NAME_SHARED_SECRET);
        $this->assertConfigWasReset(Configuration::PARAM_NAME_GRANTS);
        $this->assertConfigWasReset(Configuration::PARAM_NAME_WORKSPACE_ID);
        $this->assertConfigWasReset(Configuration::PARAM_NAME_OAUTH_CLIENT_ID);
        $this->assertConfigWasReset(Configuration::PARAM_NAME_WIDGET_ENABLED);
    }

    public function testDisconnectSendsClaimNullPatch(): void
    {
        $this->stubSecret('sec_abc');
        $this->stubAppBaseUrl('https://app.kenzi.test');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                'https://app.kenzi.test/api/integration',
                $this->callback(function (array $options) {
                    $this->assertSame(['claim' => null], $options['json']);
                    $this->assertSame(5, $options['timeout']);
                    return true;
                })
            )
            ->willReturn($this->responseMock(200, ''));

        $this->configManager->method('flush');

        $this->controller->disconnect();
    }

    public function testDisconnectRevokesStoredOAuthClient(): void
    {
        $this->stubSecret('sec_abc');
        $this->stubAppBaseUrl('https://app.kenzi.test');
        $this->stubConfigGet(Configuration::PARAM_NAME_OAUTH_CLIENT_ID, 'oauth_id_42');

        $this->httpClient->method('request')->willReturn($this->responseMock(200, ''));
        $this->configManager->method('flush');

        $this->oauthClientFactory->expects($this->once())
            ->method('revoke')
            ->with('oauth_id_42');

        $this->controller->disconnect();
    }

    public function testDisconnectStillResetsWhenRevokeThrows(): void
    {
        $this->stubSecret('sec_abc');
        $this->stubAppBaseUrl('https://app.kenzi.test');
        $this->stubConfigGet(Configuration::PARAM_NAME_OAUTH_CLIENT_ID, 'oauth_id_42');

        $this->httpClient->method('request')->willReturn($this->responseMock(200, ''));
        $this->configManager->expects($this->once())->method('flush');

        $this->oauthClientFactory->method('revoke')
            ->willThrowException(new \RuntimeException('Doctrine error'));

        $response = $this->controller->disconnect();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertConfigWasReset(Configuration::PARAM_NAME_OAUTH_CLIENT_ID);
    }

    // ─── helpers ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function jsonRequest(array $data): Request
    {
        return new Request([], [], [], [], [], [], json_encode($data));
    }

    private function stubSecret(string $value): void
    {
        $this->stubConfigGet(Configuration::PARAM_NAME_SHARED_SECRET, $value);
    }

    private function stubAppBaseUrl(string $value): void
    {
        $this->stubConfigGet(Configuration::PARAM_NAME_APP_BASE_URL, $value);
    }

    /** @param list<string> $grants */
    private function stubGrants(array $grants): void
    {
        $this->stubConfigGet(Configuration::PARAM_NAME_GRANTS, $grants);
    }

    private function stubResolverUrls(): void
    {
        $this->urlResolver->method('apiUrl')->willReturn('https://oro.acme.com/admin/api');
        $this->urlResolver->method('adminUrl')->willReturn('https://oro.acme.com/admin');
        $this->urlResolver->method('baseOrigin')->willReturn('https://oro.acme.com');
    }

    private function stubConfigGet(string $paramName, mixed $value): void
    {
        $this->stubbedConfig[$paramName] = $value;
    }

    private function responseMock(int $statusCode, string $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->willReturn($body);
        return $response;
    }

    /** @param mixed $expectedValue */
    private function assertConfigWasSet(string $paramName, $expectedValue): void
    {
        $expectedKey = Configuration::getConfigKeyByName($paramName);
        foreach ($this->configSetCalls as $call) {
            if ($call['key'] === $expectedKey) {
                $this->assertSame($expectedValue, $call['value'], "Config value mismatch for {$paramName}");
                return;
            }
        }
        $this->fail("Expected config set for {$paramName} was not called");
    }

    private function assertConfigWasReset(string $paramName): void
    {
        $expectedKey = Configuration::getConfigKeyByName($paramName);
        $this->assertContains(
            $expectedKey,
            $this->configResetCalls,
            "Expected config reset for {$paramName} was not called"
        );
    }
}
