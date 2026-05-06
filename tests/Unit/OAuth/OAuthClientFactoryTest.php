<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\OAuth;

use Kenzi\OroCommerceBundle\OAuth\OAuthClientFactory;
use Oro\Bundle\OAuth2ServerBundle\Entity\Client;
use Oro\Bundle\OAuth2ServerBundle\Entity\Manager\ClientManager;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OAuthClientFactoryTest extends TestCase
{
    /** @var ClientManager&MockObject */
    private MockObject $clientManager;
    /** @var TokenAccessorInterface&MockObject */
    private MockObject $tokenAccessor;
    private OAuthClientFactory $factory;

    protected function setUp(): void
    {
        $this->clientManager = $this->createMock(ClientManager::class);
        $this->clientManager->method('isCreationGranted')->willReturn(true);

        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->tokenAccessor->method('getUserId')->willReturn(1);

        $this->factory = new OAuthClientFactory(
            $this->clientManager,
            $this->tokenAccessor,
            new NullLogger(),
        );
    }

    public function testCreateMintsClientWithGivenName(): void
    {
        $this->clientManager->expects($this->once())
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) {
                $this->assertSame('Kenzi OroCommerce', $client->getName());
                $this->setClientFields($client, 'gen_id', 'gen_secret');
            });

        $result = $this->factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);

        $this->assertSame(['gen_id', 'gen_secret'], $result);
    }

    public function testCreateMintsClientWithGivenGrants(): void
    {
        $this->clientManager->expects($this->once())
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) {
                $this->assertSame([Client::CLIENT_CREDENTIALS], $client->getGrants());
                $this->setClientFields($client, 'id', 'secret');
            });

        $this->factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);
    }

    public function testCreateMarksClientActive(): void
    {
        $this->clientManager->expects($this->once())
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) {
                $this->assertTrue($client->isActive());
                $this->setClientFields($client, 'id', 'secret');
            });

        $this->factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);
    }

    public function testCreateAlwaysMintsFresh(): void
    {
        // Two calls, two distinct clients — no idempotency, no lookup.
        $clients = [];
        $this->clientManager->expects($this->exactly(2))
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) use (&$clients) {
                $idx = count($clients);
                $this->setClientFields($client, "id_{$idx}", "secret_{$idx}");
                $clients[] = $client;
            });

        $first = $this->factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);
        $second = $this->factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);

        $this->assertNotSame($first, $second);
        $this->assertSame(['id_0', 'secret_0'], $first);
        $this->assertSame(['id_1', 'secret_1'], $second);
    }

    public function testCreateThrowsWhenCreationNotGranted(): void
    {
        $clientManager = $this->createMock(ClientManager::class);
        $clientManager->method('isCreationGranted')->willReturn(false);

        $factory = new OAuthClientFactory(
            $clientManager,
            $this->tokenAccessor,
            new NullLogger(),
        );

        $clientManager->expects($this->never())->method('updateClient');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient permission');

        $factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);
    }

    public function testCreateThrowsWhenNoAuthenticatedUser(): void
    {
        $tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $tokenAccessor->method('getUserId')->willReturn(null);

        $factory = new OAuthClientFactory(
            $this->clientManager,
            $tokenAccessor,
            new NullLogger(),
        );

        $this->clientManager->expects($this->never())->method('updateClient');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated user');

        $factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);
    }

    public function testCreateThrowsWhenPlainSecretIsNull(): void
    {
        $this->clientManager->expects($this->once())
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) {
                $this->setClientFields($client, 'id', null);
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plain secret is unavailable');

        $this->factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);
    }

    public function testCreateThrowsWhenPlainSecretIsEmpty(): void
    {
        $this->clientManager->expects($this->once())
            ->method('updateClient')
            ->willReturnCallback(function (Client $client) {
                $this->setClientFields($client, 'id', '');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plain secret is unavailable');

        $this->factory->create('Kenzi OroCommerce', [Client::CLIENT_CREDENTIALS]);
    }

    // -- revoke ──────────────────────────────────────────────────────────

    public function testRevokeDeletesClientByIdentifier(): void
    {
        $client = new Client();

        $this->clientManager->expects($this->once())
            ->method('getClient')
            ->with('oauth_id_42')
            ->willReturn($client);

        $this->clientManager->expects($this->once())
            ->method('deleteClient')
            ->with($client);

        $this->factory->revoke('oauth_id_42');
    }

    public function testRevokeNoOpsOnEmptyIdentifier(): void
    {
        $this->clientManager->expects($this->never())->method('getClient');
        $this->clientManager->expects($this->never())->method('deleteClient');

        $this->factory->revoke('');
    }

    public function testRevokeNoOpsWhenClientAlreadyGone(): void
    {
        $this->clientManager->method('getClient')
            ->with('oauth_id_42')
            ->willReturn(null);

        $this->clientManager->expects($this->never())->method('deleteClient');

        $this->factory->revoke('oauth_id_42');
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
}
