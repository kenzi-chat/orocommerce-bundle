<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\Webhook;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Kenzi\OroCommerceBundle\Webhook\WebhookDispatcher;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WebhookDispatcherTest extends TestCase
{
    /** @var HttpClientInterface&MockObject */
    private MockObject $httpClient;
    /** @var ConfigManager&MockObject */
    private MockObject $configManager;
    private WebhookDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->configManager = $this->createMock(ConfigManager::class);

        $this->dispatcher = new WebhookDispatcher(
            $this->httpClient,
            $this->configManager,
            new NullLogger(),
        );
    }

    // -- isEnabled ────────────────────────────────────────────────────

    public function testIsEnabledReturnsTrueWhenFullyConfigured(): void
    {
        $this->stubConfig(true, 'https://kenzi.test', 'secret', 'store.com');

        $this->assertTrue($this->dispatcher->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenSyncDisabled(): void
    {
        $this->stubConfig(false, 'https://kenzi.test', 'secret', 'store.com');

        $this->assertFalse($this->dispatcher->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenMissingSecret(): void
    {
        $this->stubConfig(true, 'https://kenzi.test', '', 'store.com');

        $this->assertFalse($this->dispatcher->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenMissingAppBaseUrl(): void
    {
        $this->stubConfig(true, '', 'secret', 'store.com');

        $this->assertFalse($this->dispatcher->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenMissingInstanceKey(): void
    {
        $this->stubConfig(true, 'https://kenzi.test', 'secret', '');

        $this->assertFalse($this->dispatcher->isEnabled());
    }

    // -- dispatch ─────────────────────────────────────────────────────

    public function testDispatchSendsCorrectHmacSignature(): void
    {
        $secret = 'test_secret_abc123';
        $this->stubConfig(true, 'https://kenzi.test', $secret, 'test.store.com');

        $payload = ['event' => 'order.created', 'timestamp' => 1700000000, 'data' => ['id' => 1]];

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://kenzi.test/webhooks/oro-commerce',
                $this->callback(function (array $options) use ($payload, $secret) {
                    $rawBody = $options['body'];
                    $expectedSignature = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

                    $this->assertSame('application/json', $options['headers']['Content-Type']);
                    $this->assertSame($expectedSignature, $options['headers']['x-kenzi-signature']);
                    $this->assertNotEmpty($options['headers']['x-kenzi-delivery-id']);
                    $this->assertNotEmpty($options['headers']['x-kenzi-timestamp']);
                    $this->assertSame('test.store.com', $options['headers']['x-kenzi-integration']);
                    $this->assertSame('order.created', $options['headers']['x-kenzi-event']);

                    // Verify the body round-trips to the same payload
                    $decoded = json_decode($rawBody, true);
                    $this->assertSame($payload, $decoded);

                    return true;
                })
            )
            ->willReturn($this->createResponseMock(200));

        $this->dispatcher->dispatch($payload, 'order.created');
    }

    /**
     * @dataProvider non2xxStatusCodeProvider
     */
    public function testDispatchThrowsOnNon2xxResponse(int $statusCode): void
    {
        $this->stubConfig(true, 'https://kenzi.test', 'secret', 'store.com');
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($this->createResponseMock($statusCode));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP ' . $statusCode . '/');

        $this->dispatcher->dispatch(['data' => []], 'order.created');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function non2xxStatusCodeProvider(): iterable
    {
        yield 'below range (199)' => [199];
        yield 'client error (401)' => [401];
        yield 'redirect (300)' => [300];
        yield 'server error (500)' => [500];
    }

    public function testDispatchSucceedsOnUpperBound2xx(): void
    {
        $this->stubConfig(true, 'https://kenzi.test', 'secret', 'store.com');
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($this->createResponseMock(299));

        $this->dispatcher->dispatch(['data' => []], 'order.created');
    }

    public function testDispatchThrowsTransportExceptionFromRequest(): void
    {
        $this->stubConfig(true, 'https://kenzi.test', 'secret', 'store.com');

        $exception = new class ('Connection timed out') extends \RuntimeException implements TransportExceptionInterface {};
        $this->httpClient->method('request')->willThrowException($exception);

        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessage('Connection timed out');

        $this->dispatcher->dispatch(['data' => []], 'order.created');
    }

    public function testDispatchThrowsTransportExceptionFromGetStatusCode(): void
    {
        $this->stubConfig(true, 'https://kenzi.test', 'secret', 'store.com');

        $exception = new class ('DNS resolution failed') extends \RuntimeException implements TransportExceptionInterface {};
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willThrowException($exception);
        $this->httpClient->method('request')->willReturn($response);

        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessage('DNS resolution failed');

        $this->dispatcher->dispatch(['data' => []], 'order.created');
    }

    public function testDispatchThrowsJsonExceptionOnEncodingFailure(): void
    {
        $this->stubConfig(true, 'https://kenzi.test', 'secret', 'store.com');
        $this->httpClient->expects($this->never())->method('request');

        $this->expectException(\JsonException::class);

        $this->dispatcher->dispatch(['bad' => \NAN], 'order.created');
    }

    public function testEachDispatchGeneratesUniqueDeliveryId(): void
    {
        $this->stubConfig(true, 'https://kenzi.test', 'secret', 'store.com');

        $deliveryIds = [];
        $this->httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$deliveryIds) {
                $deliveryIds[] = $options['headers']['x-kenzi-delivery-id'];
                return $this->createResponseMock(200);
            });

        $this->dispatcher->dispatch(['data' => []], 'order.created');
        $this->dispatcher->dispatch(['data' => []], 'order.created');

        $this->assertCount(2, $deliveryIds);
        $this->assertNotSame($deliveryIds[0], $deliveryIds[1]);
    }

    public function testDispatchDerivesWebhookUrlFromAppBaseUrl(): void
    {
        $this->stubConfig(true, 'https://app.kenzi.chat', 'secret', 'store.com');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://app.kenzi.chat/webhooks/oro-commerce',
                $this->anything()
            )
            ->willReturn($this->createResponseMock(200));

        $this->dispatcher->dispatch(['data' => []], 'order.created');
    }

    public function testSignatureUsesRawBodyBytes(): void
    {
        $secret = 'known_secret';
        $this->stubConfig(true, 'https://kenzi.test', $secret, 'store.com');

        $payload = ['url' => 'https://example.com/path', 'emoji' => "\u{1F600}"];

        $capturedBody = null;
        $capturedSignature = null;
        $this->httpClient->method('request')
            ->willReturnCallback(function ($method, $url, $options) use (&$capturedBody, &$capturedSignature) {
                $capturedBody = $options['body'];
                $capturedSignature = $options['headers']['x-kenzi-signature'];
                return $this->createResponseMock(200);
            });

        $this->dispatcher->dispatch($payload, 'order.created');

        // Verify the exact same bytes were used for signing and sending
        $expectedSignature = base64_encode(hash_hmac('sha256', $capturedBody, $secret, true));
        $this->assertSame($expectedSignature, $capturedSignature);

        // Verify JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE are in effect
        $this->assertStringContainsString('https://example.com/path', $capturedBody);
        $this->assertStringNotContainsString('\/', $capturedBody);
    }

    /**
     * Stub ConfigManager::get() — all Kenzi config is read from global scope.
     */
    private function stubConfig(bool $enabled, string $appBaseUrl, string $secret, string $instanceKey): void
    {
        $this->configManager->method('get')
            ->willReturnCallback(function (string $key) use ($enabled, $appBaseUrl, $secret, $instanceKey): mixed {
                $paramName = str_replace(Configuration::ROOT_NODE . '.', '', $key);

                return match ($paramName) {
                    Configuration::PARAM_NAME_SYNC_ENABLED => $enabled,
                    Configuration::PARAM_NAME_APP_BASE_URL => $appBaseUrl,
                    Configuration::PARAM_NAME_SHARED_SECRET => $secret,
                    Configuration::PARAM_NAME_INSTANCE_KEY => $instanceKey,
                    default => null,
                };
            });
    }

    /** @return ResponseInterface&MockObject */
    private function createResponseMock(int $statusCode): MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->willReturn('');
        return $response;
    }
}
