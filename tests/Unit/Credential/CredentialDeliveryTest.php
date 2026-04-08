<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Credential;

use Kenzi\OroCommerceBundle\Credential\CredentialDelivery;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\OAuth2ServerBundle\Entity\Client;
use Oro\Bundle\OAuth2ServerBundle\Entity\Manager\ClientManager;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CredentialDeliveryTest extends TestCase
{
    /** @var ClientManager&MockObject */
    private MockObject $clientManager;
    /** @var HttpClientInterface&MockObject */
    private MockObject $httpClient;
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;
    /** @var TokenAccessorInterface&MockObject */
    private MockObject $tokenAccessor;
    private CredentialDelivery $delivery;

    protected function setUp(): void
    {
        $this->clientManager = $this->createMock(ClientManager::class);
        $this->clientManager->method('isCreationGranted')->willReturn(true);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->configManager = $this->createMock(ConfigManager::class);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->tokenAccessor->method('getUserId')->willReturn(1);

        $this->delivery = new CredentialDelivery(
            $this->clientManager,
            $this->httpClient,
            $this->configManager,
            $this->tokenAccessor,
            new NullLogger(),
        );
    }

    // -- deliver: prerequisites ──────────────────────────────────────────

    public function testDeliverReturnsFalseWhenNotConnected(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_SHARED_SECRET => '',
            Configuration::PARAM_NAME_INSTANCE_KEY => '',
        ]);

        $this->clientManager->expects($this->never())->method('updateClient');
        $this->httpClient->expects($this->never())->method('request');

        $this->assertFalse($this->delivery->deliver());
    }

    public function testDeliverReturnsFalseWhenAppBaseUrlMissing(): void
    {
        // Prerequisite guard: without app_base_url we cannot build the Kenzi
        // PATCH URL, so we must bail BEFORE creating a DB-backed OAuth client.
        // Otherwise we would persist a client just to immediately revoke it.
        $this->stubConfig([
            Configuration::PARAM_NAME_SHARED_SECRET => 'secret',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'store.com',
            Configuration::PARAM_NAME_APP_BASE_URL => '',
        ]);

        $this->clientManager->expects($this->never())->method('updateClient');
        $this->clientManager->expects($this->never())->method('deleteClient');
        $this->httpClient->expects($this->never())->method('request');

        $this->assertFalse($this->delivery->deliver());
    }

    public function testDeliverReturnsTrueWhenAlreadyDelivered(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_SHARED_SECRET => 'secret',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'store.com',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.test',
            Configuration::PARAM_NAME_CREDENTIALS_DELIVERED => true,
        ]);

        $this->clientManager->expects($this->never())->method('updateClient');
        $this->httpClient->expects($this->never())->method('request');

        $this->assertTrue($this->delivery->deliver());
    }

    // -- deliver: happy path ─────────────────────────────────────────────

    public function testDeliverCreatesOAuthClientAndPatchesToKenzi(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_SHARED_SECRET => 'secret_abc',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'store.com',
            Configuration::PARAM_NAME_CREDENTIALS_DELIVERED => false,
            Configuration::PARAM_NAME_OAUTH_CLIENT_ID => '',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.test',
        ]);

        $this->clientManager->expects($this->once())
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) {
                $this->assertSame([Client::CLIENT_CREDENTIALS], $client->getGrants());
                $this->assertStringContainsString('Kenzi Commerce', $client->getName());
                $this->setClientFields($client, 'gen_client_id', 'gen_client_secret');
            });

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'PATCH',
                'https://app.kenzi.test/api/integrations/oro_commerce/store.com/credentials',
                $this->callback(function (array $options) {
                    $this->assertSame('Bearer secret_abc', $options['headers']['Authorization']);
                    $this->assertSame('application/json', $options['headers']['Content-Type']);

                    $body = json_decode($options['body'], true);
                    $this->assertSame('gen_client_id', $body['client_id']);
                    $this->assertSame('gen_client_secret', $body['client_secret']);

                    return true;
                })
            )
            ->willReturn($this->createResponseMock(200));

        $setCalls = [];
        $this->configManager->method('set')
            ->willReturnCallback(function (string $key, $value) use (&$setCalls) {
                $setCalls[$key] = $value;
            });
        $this->configManager->expects($this->exactly(2))->method('flush');

        $this->assertTrue($this->delivery->deliver());

        $this->assertTrue(
            $setCalls[Configuration::getConfigKeyByName(Configuration::PARAM_NAME_CREDENTIALS_DELIVERED)]
        );
        $this->assertSame(
            'gen_client_id',
            $setCalls[Configuration::getConfigKeyByName(Configuration::PARAM_NAME_OAUTH_CLIENT_ID)]
        );
    }

    // -- deliver: idempotency ────────────────────────────────────────────

    public function testDeliverRevokesExistingClientWhenPlainSecretUnavailable(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_SHARED_SECRET => 'secret_abc',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'store.com',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.test',
            Configuration::PARAM_NAME_CREDENTIALS_DELIVERED => false,
            Configuration::PARAM_NAME_OAUTH_CLIENT_ID => 'stale_id',
        ]);

        $staleClient = new Client();
        $this->setClientFields($staleClient, 'stale_id', null);

        $this->clientManager->method('getClient')
            ->with('stale_id')
            ->willReturn($staleClient);

        $this->clientManager->expects($this->once())->method('deleteClient')->with($staleClient);
        $this->httpClient->expects($this->never())->method('request');

        $this->configManager->method('set');
        $this->configManager->method('flush');

        $this->assertFalse($this->delivery->deliver());
    }

    // -- deliver: failure recovery ───────────────────────────────────────

    public function testDeliverRevokesClientOnHttpFailure(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_SHARED_SECRET => 'secret_abc',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'store.com',
            Configuration::PARAM_NAME_CREDENTIALS_DELIVERED => false,
            Configuration::PARAM_NAME_OAUTH_CLIENT_ID => '',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.test',
        ]);

        $this->clientManager->expects($this->once())
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) {
                $this->setClientFields($client, 'fail_id', 'fail_secret');
            });

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($this->createResponseMock(500));

        $this->clientManager->expects($this->once())->method('deleteClient');

        $this->configManager->method('set');
        $this->configManager->method('flush');

        $this->assertFalse($this->delivery->deliver());
    }

    public function testDeliverRevokesClientOnNetworkError(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_SHARED_SECRET => 'secret_abc',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'store.com',
            Configuration::PARAM_NAME_CREDENTIALS_DELIVERED => false,
            Configuration::PARAM_NAME_OAUTH_CLIENT_ID => '',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.test',
        ]);

        $this->clientManager->expects($this->once())
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) {
                $this->setClientFields($client, 'net_id', 'net_secret');
            });

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->clientManager->expects($this->once())->method('deleteClient');

        $this->configManager->method('set');
        $this->configManager->method('flush');

        $this->assertFalse($this->delivery->deliver());
    }

    // -- deliver: ACL check ─────────────────────────────────────────────

    public function testDeliverReturnsFalseWhenCreationNotGranted(): void
    {
        $this->stubConfig([
            Configuration::PARAM_NAME_SHARED_SECRET => 'secret_abc',
            Configuration::PARAM_NAME_INSTANCE_KEY => 'store.com',
            Configuration::PARAM_NAME_APP_BASE_URL => 'https://app.kenzi.test',
            Configuration::PARAM_NAME_CREDENTIALS_DELIVERED => false,
            Configuration::PARAM_NAME_OAUTH_CLIENT_ID => '',
        ]);

        // Override the default isCreationGranted stub
        $clientManager = $this->createMock(ClientManager::class);
        $clientManager->method('isCreationGranted')->willReturn(false);

        $delivery = new CredentialDelivery(
            $clientManager,
            $this->httpClient,
            $this->configManager,
            $this->tokenAccessor,
            new NullLogger(),
        );

        $clientManager->expects($this->never())->method('updateClient');
        $this->httpClient->expects($this->never())->method('request');

        $this->assertFalse($delivery->deliver());
    }

    // -- revokeOAuthClient ───────────────────────────────────────────────

    public function testRevokeOAuthClientDeletesStoredClient(): void
    {
        $this->configManager->method('get')
            ->willReturnMap([
                [Configuration::getConfigKeyByName(Configuration::PARAM_NAME_OAUTH_CLIENT_ID), false, false, null, 'cleanup_id'],
            ]);

        $client = new Client();
        $this->setClientFields($client, 'cleanup_id', null);

        $this->clientManager->method('getClient')
            ->with('cleanup_id')
            ->willReturn($client);

        $this->clientManager->expects($this->once())->method('deleteClient')->with($client);

        // Revocation is Doctrine-only: the caller owns the ConfigManager
        // writes as part of a single atomic disconnect flush.
        $this->configManager->expects($this->never())->method('set');
        $this->configManager->expects($this->never())->method('flush');

        $this->delivery->revokeOAuthClient();
    }

    public function testRevokeOAuthClientHandlesMissingStoredClient(): void
    {
        $this->configManager->method('get')
            ->willReturnMap([
                [Configuration::getConfigKeyByName(Configuration::PARAM_NAME_OAUTH_CLIENT_ID), false, false, null, 'gone_id'],
            ]);

        $this->clientManager->method('getClient')
            ->with('gone_id')
            ->willReturn(null);

        $this->clientManager->expects($this->never())->method('deleteClient');
        $this->configManager->expects($this->never())->method('set');
        $this->configManager->expects($this->never())->method('flush');

        $this->delivery->revokeOAuthClient();
    }

    public function testRevokeOAuthClientNoOpsWhenNoStoredClientId(): void
    {
        $this->configManager->method('get')
            ->willReturnMap([
                [Configuration::getConfigKeyByName(Configuration::PARAM_NAME_OAUTH_CLIENT_ID), false, false, null, ''],
            ]);

        $this->clientManager->expects($this->never())->method('getClient');
        $this->clientManager->expects($this->never())->method('deleteClient');
        $this->configManager->expects($this->never())->method('set');
        $this->configManager->expects($this->never())->method('flush');

        $this->delivery->revokeOAuthClient();
    }

    // -- Helpers ──────────────────────────────────────────────────────────

    /** @return ResponseInterface&MockObject */
    private function createResponseMock(int $statusCode): MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        return $response;
    }

    /**
     * Set identifier and plainSecret on a Client via reflection.
     *
     * ClientManager::updateClient() auto-generates these in production,
     * but in unit tests we control them without a real database.
     */
    private function setClientFields(Client $client, string $identifier, ?string $plainSecret): void
    {
        $ref = new \ReflectionClass($client);

        $idProp = $ref->getProperty('identifier');
        $idProp->setValue($client, $identifier);

        $secretProp = $ref->getProperty('plainSecret');
        $secretProp->setValue($client, $plainSecret);
    }

    /**
     * @param array<string, mixed> $configValues
     */
    private function stubConfig(array $configValues): void
    {
        $map = [];
        foreach ($configValues as $param => $value) {
            $map[] = [Configuration::getConfigKeyByName($param), false, false, null, $value];
        }
        $this->configManager->method('get')->willReturnMap($map);
    }
}
