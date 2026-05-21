<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Controller;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Kenzi\OroCommerceBundle\OAuth\OAuthClientFactory;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
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
        $data = json_decode($request->getContent(), associative: true);

        if (!\is_array($data)) {
            return $this->failure('Connect request was not valid.');
        }

        $sharedSecret = trim((string) ($data['shared_secret'] ?? ''));
        $workspaceId = trim((string) ($data['workspace_id'] ?? ''));
        $grants = $data['grants'] ?? null;

        if ($sharedSecret === ''
            || !\str_starts_with($sharedSecret, 'ss_')
            || $workspaceId === ''
            || !\is_array($grants)) {
            return $this->failure('Connect request is missing required fields.');
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

                return $this->failure('Unable to create OAuth credentials. The server\'s OAuth2 encryption keys have not been generated.');
            }

            try {
                [$clientId, $clientSecret] = $this->oauthClientFactory->create(
                    self::OAUTH_CLIENT_NAME,
                    ['client_credentials'],
                );
            } catch (\RuntimeException $e) {
                $this->logger->error('OAuth client mint failed', [
                    'error' => $e->getMessage(),
                ]);

                // Static body — the logger has the detail; don't leak internals to the wire.
                return $this->failure('OAuth client creation failed.');
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

            return $this->failure('Configure request failed.');
        }

        if ($response->getStatusCode() === 200) {
            return new JsonResponse(['ok' => true]);
        }

        $body = json_decode($response->getContent(throw: false), true) ?? [];

        return $this->failure('Configure request was rejected.', $body);
    }

    /**
     * Fetch the current integration projection from Kenzi.
     *
     * Returns ok: false when no shared secret is stored — there is no
     * connection to query yet. The JS short-circuits to the connect
     * button without making an outbound HTTP call.
     */
    #[Route(path: '/integration', name: 'kenzi_integration', methods: ['GET'])]
    public function integration(): JsonResponse
    {
        $secret = $this->getConfig(Configuration::PARAM_NAME_SHARED_SECRET);

        if (!\is_string($secret) || $secret === '') {
            return $this->failure('No connection established.');
        }

        try {
            $response = $this->request('GET');
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->error('Integration GET failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->failure('Integration request failed.');
        }

        $body = json_decode($response->getContent(throw: false), true) ?? [];

        $result = $response->getStatusCode() === 200
            ? new JsonResponse(['ok' => true] + $body)
            : $this->failure('Integration request was rejected.', $body);

        $result->headers->set('Cache-Control', 'no-store');

        return $result;
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
     * @param array<string, mixed> $apiResponse
     */
    private function failure(string $errorMessage, array $apiResponse = []): JsonResponse
    {
        $consoleError = $apiResponse['error'] ?? $errorMessage;

        return new JsonResponse([
            'ok' => false,
            'error' => $errorMessage,
            'consoleError' => $consoleError,
        ]);
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
