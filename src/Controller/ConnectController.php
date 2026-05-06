<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Controller;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Kenzi\OroCommerceBundle\OAuth\OAuthClientFactory;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\OAuth2ServerBundle\Entity\Client;
use Oro\Bundle\OAuth2ServerBundle\Security\EncryptionKeysExistenceChecker;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Lifecycle endpoints for the Kenzi connection.
 *
 * Four actions, one resource:
 *   POST /admin/kenzi/connect      — store credentials handed off by the popup
 *   POST /admin/kenzi/configure    — mint OAuth (if commerce) and PATCH Kenzi
 *   GET  /admin/kenzi/integration  — fetch current integration projection
 *   POST /admin/kenzi/disconnect   — best-effort claim release + local cleanup
 *
 * All four share an ACL (`oro_config_system`) and the same dependencies.
 * Outbound HTTP to Kenzi is inlined via the private `request()` helper —
 * the only shared abstraction needed for three call sites.
 */
#[AclAncestor('oro_config_system')]
class ConnectController
{
    private const KENZI_INTEGRATION_PATH = '/api/integration';
    private const OAUTH_CLIENT_NAME = 'Kenzi OroCommerce';
    private const COMMERCE_GRANT = 'commerce';

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthClientFactory $oauthClientFactory,
        private readonly ApplicationUrlResolver $urlResolver,
        private readonly LoggerInterface $logger,
        private readonly EncryptionKeysExistenceChecker $encryptionKeysChecker,
    ) {
    }

    /**
     * Store credentials received from the Kenzi connect popup.
     *
     * Pure local write — no HTTP to Kenzi. The JS pipeline calls
     * `/configure` next to mint OAuth and hand off the configured URLs.
     */
    #[Route(path: '/connect', name: 'kenzi_connect', methods: ['POST'])]
    #[CsrfProtection]
    public function connect(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], 422);
        }

        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Body must be a JSON object'], 422);
        }

        $sharedSecret = trim((string) ($data['shared_secret'] ?? ''));
        $workspaceId = trim((string) ($data['workspace_id'] ?? ''));
        $grants = $data['grants'] ?? null;

        if ($sharedSecret === ''
            || !\str_starts_with($sharedSecret, 'ss_')
            || $workspaceId === ''
            || !\is_array($grants)) {
            return new JsonResponse(['error' => 'Missing required fields'], 422);
        }

        // Keep only string entries — defends against array_values returning
        // non-strings if Kenzi ever ships a malformed payload.
        $grants = array_values(array_filter($grants, 'is_string'));

        $this->setConfig(Configuration::PARAM_NAME_SHARED_SECRET, $sharedSecret);
        $this->setConfig(Configuration::PARAM_NAME_GRANTS, $grants);
        $this->setConfig(Configuration::PARAM_NAME_WORKSPACE_ID, $workspaceId);
        $this->configManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Mint OAuth (if commerce grant present) and PATCH Kenzi with the
     * application config. Forwards Kenzi's integration projection to the
     * JS adapter, which renders the green or yellow panel based on the
     * `configured` and `claimed` flags.
     */
    #[Route(path: '/configure', name: 'kenzi_configure', methods: ['POST'])]
    #[CsrfProtection]
    public function configure(): JsonResponse
    {
        $grants = $this->getGrants();

        $config = [
            'api_url' => $this->urlResolver->apiUrl(),
            'admin_url' => $this->urlResolver->adminUrl(),
            'base_url' => $this->urlResolver->baseOrigin(),
        ];

        if (\in_array(self::COMMERCE_GRANT, $grants, true)) {
            if (!$this->encryptionKeysChecker->isPrivateKeyExist()
                || !$this->encryptionKeysChecker->isPublicKeyExist()) {
                $this->logger->error('OAuth2 encryption keys missing — cannot mint client');

                return new JsonResponse(
                    ['error' => 'Unable to create OAuth credentials. The server\'s OAuth2 encryption keys have not been generated.'],
                    500
                );
            }

            try {
                [$clientId, $clientSecret] = $this->oauthClientFactory->create(
                    self::OAUTH_CLIENT_NAME,
                    [Client::CLIENT_CREDENTIALS],
                );
            } catch (\RuntimeException $e) {
                $this->logger->error('OAuth client mint failed', [
                    'error' => $e->getMessage(),
                ]);

                // Static body — the logger has the detail; don't leak internals to the wire.
                return new JsonResponse(['error' => 'OAuth client creation failed'], 500);
            }

            // Persist the client identifier before the PATCH so that disconnect
            // can revoke it even if Kenzi never confirms the configure. If the
            // PATCH fails, we have an orphan that disconnect can still clean up.
            $this->setConfig(Configuration::PARAM_NAME_OAUTH_CLIENT_ID, $clientId);
            $this->configManager->flush();

            $config['client_id'] = $clientId;
            $config['client_secret'] = $clientSecret;
        }

        try {
            $response = $this->request('PATCH', ['config' => $config]);
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('Configure PATCH failed', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Configure request failed'], 502);
        }

        return $this->forward($response);
    }

    /**
     * Fetch the current integration projection from Kenzi.
     *
     * Returns 404 when the bundle has no stored secret — there is no
     * connection to query yet, so the JS short-circuits to the connect
     * button without an outbound HTTP call.
     */
    #[Route(path: '/integration', name: 'kenzi_integration', methods: ['GET'])]
    public function integration(): JsonResponse
    {
        $secret = $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET);

        if (!\is_string($secret) || $secret === '') {
            return new JsonResponse(['error' => 'Not connected'], 404);
        }

        try {
            $response = $this->request('GET');
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('Integration GET failed', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Integration request failed'], 502);
        }

        return $this->forward($response, ['Cache-Control' => 'no-store']);
    }

    /**
     * Best-effort release the claim on Kenzi and revoke the OAuth client,
     * then unconditionally clear local lifecycle config. Always returns 200
     * so reconnect is a clean fresh start, even if Kenzi or the OAuth
     * revoke step was unreachable during disconnect.
     */
    #[Route(path: '/disconnect', name: 'kenzi_disconnect', methods: ['POST'])]
    #[CsrfProtection]
    public function disconnect(): JsonResponse
    {
        try {
            $this->request('PATCH', ['claim' => null], timeout: 5);
        } catch (\Throwable $e) {
            $this->logger->info('Best-effort disconnect PATCH failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $clientId = (string) $this->getConfig(Configuration::PARAM_NAME_OAUTH_CLIENT_ID);

        // Best-effort revoke. The OAuth client deletes in its own DB write,
        // separate from the configManager->flush() below. If the second write
        // fails after this one succeeded, the user can click Disconnect again
        // to finish the job — every step is safe to repeat. An orphan client
        // can also be deleted by hand in Oro admin → OAuth Applications.
        try {
            $this->oauthClientFactory->revoke($clientId);
        } catch (\Throwable $e) {
            $this->logger->info('Best-effort OAuth revoke failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // reset() removes the override row from oro_config_value entirely.
        $this->resetConfig(Configuration::PARAM_NAME_SHARED_SECRET);
        $this->resetConfig(Configuration::PARAM_NAME_GRANTS);
        $this->resetConfig(Configuration::PARAM_NAME_WORKSPACE_ID);
        $this->resetConfig(Configuration::PARAM_NAME_OAUTH_CLIENT_ID);
        $this->resetConfig(Configuration::PARAM_NAME_WIDGET_ENABLED);
        $this->configManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * One place that builds the bearer + URL + JSON body envelope for
     * outbound HTTP to Kenzi. All three lifecycle actions that hit the
     * Kenzi API route through here.
     *
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, ?array $body = null, int $timeout = 30): ResponseInterface
    {
        $secret = (string) $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET);
        $baseUrl = (string) $this->getConfig(Configuration::PARAM_NAME_APP_BASE_URL);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept' => 'application/json',
            ],
            'timeout' => $timeout,
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        return $this->httpClient->request($method, $baseUrl . self::KENZI_INTEGRATION_PATH, $options);
    }

    /**
     * Pass an upstream Kenzi response straight through to the browser.
     *
     * Uses `JsonResponse(..., json: true)` so the original JSON bytes are
     * preserved verbatim — no decode/encode roundtrip, so key order and
     * slash escaping match what Kenzi sent.
     *
     * @param array<string, string> $extraHeaders
     */
    private function forward(ResponseInterface $response, array $extraHeaders = []): JsonResponse
    {
        $body = $response->getContent(throw: false);
        $statusCode = $response->getStatusCode();

        $headers = ['Content-Type' => 'application/json'] + $extraHeaders;

        return new JsonResponse($body, $statusCode, $headers, json: true);
    }

    /**
     * @return list<string>
     */
    private function getGrants(): array
    {
        $grants = $this->getConfig(Configuration::PARAM_NAME_GRANTS);

        if (!\is_array($grants)) {
            return [];
        }

        return array_values(array_filter($grants, 'is_string'));
    }

    private function getConfig(string $paramName): mixed
    {
        return $this->configManager->get(Configuration::getConfigKeyByName($paramName));
    }

    /**
     * @param string|bool|list<string> $value
     */
    private function setConfig(string $paramName, string|bool|array $value): void
    {
        $this->configManager->set(
            Configuration::getConfigKeyByName($paramName),
            $value
        );
    }

    private function resetConfig(string $paramName): void
    {
        $this->configManager->reset(
            Configuration::getConfigKeyByName($paramName)
        );
    }
}
