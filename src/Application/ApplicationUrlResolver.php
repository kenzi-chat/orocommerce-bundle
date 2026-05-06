<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Application;

use Oro\Bundle\ConfigBundle\Config\ConfigManager;

/**
 * Single source of truth for deriving URLs that describe "this Oro instance".
 *
 * Reads Oro's canonical `oro_ui.application_url` system config (set during
 * `oro:install` and editable under System Configuration > General Setup) and
 * the `web_backend_prefix` container parameter (defaults to `/admin`, but
 * deployments may mount the back office at a different path or the root).
 *
 * Both ConnectController and KenziConnectButtonType used to duplicate this
 * parsing — any drift between them would silently break the connect flow or
 * the backfill API URL on non-default deployments.
 */
class ApplicationUrlResolver
{
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly string $webBackendPrefix,
    ) {
    }

    /**
     * Scheme + host (+ port) of the application, e.g. `https://oro.acme.com`.
     */
    public function baseOrigin(): string
    {
        $parts = parse_url($this->applicationUrl());
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * Stable identifier for this Oro instance — used as the Kenzi popup
     * `?key=` param and `x-kenzi-integration` webhook header.
     *
     * Lowercased host plus the URL path (with any trailing slash stripped),
     * so casing and trailing-slash variants of the same application URL
     * all produce the same key. The port is intentionally omitted so the
     * key survives a port change without re-handshaking.
     */
    public function instanceKey(): string
    {
        $parts = parse_url($this->applicationUrl());
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $host . $path;
    }

    /**
     * Back-office dashboard URL, e.g. `https://oro.acme.com/admin`.
     *
     * The back-office prefix is the container parameter `web_backend_prefix`,
     * which Oro guarantees either starts with `/` or is empty.
     */
    public function adminUrl(): string
    {
        return $this->baseOrigin() . $this->webBackendPrefix;
    }

    /**
     * Back-office REST API base URL, e.g. `https://oro.acme.com/admin/api`.
     *
     * Honours `web_backend_prefix` so installs that mount the back office at
     * a non-default path (or at the root) still produce a working API URL.
     */
    public function apiUrl(): string
    {
        return $this->adminUrl() . '/api';
    }

    private function applicationUrl(): string
    {
        return (string) $this->configManager->get('oro_ui.application_url');
    }
}
