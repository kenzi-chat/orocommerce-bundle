# Kenzi OroCommerce Bundle

Integrates [Kenzi Chat](https://kenzi.chat) into OroCommerce 6.0+ storefronts.

- **Chat Widget** — Injects the Kenzi widget loader script into storefront pages via Oro's layout system
- **Connect Flow** — Admin controller to store/clear credentials from the Kenzi connect popup
- **Order Webhooks** — Listens for order checkout and update events, enqueues messages via Oro's Message Queue, and asynchronously serializes and dispatches HMAC-signed webhooks to Kenzi. Retries failed dispatches 3 times with backoff (10s, 60s, 5min). Only triggers on payload-relevant field changes — timestamp-only updates are ignored.

## Kenzi Integration Contract

### Integration Model

**1 integration per OroCommerce application.** A single Oro instance — regardless of how many Websites it hosts — maps to exactly one Kenzi integration record. The admin JSON:API is a single global endpoint with one set of credentials, so all Websites share one integration. Individual Websites are channels within that integration, not separate integrations.

Per-website settings like `widget_enabled` and `sync_enabled` control which storefronts are active, but the integration identity, credentials, and API endpoint are at the application level.

### Integration Key (instance_key)

The integration key is the **application hostname** — the hostname of the Oro admin backend, not any individual Website's storefront URL. This key uniquely identifies the Oro instance and is used by Kenzi to route incoming webhooks.

PHP derivation (from the admin URL):

```php
$adminUrl = $this->router->generate('oro_default', [], UrlGeneratorInterface::ABSOLUTE_URL);
$instanceKey = parse_url($adminUrl, PHP_URL_HOST);
```

This hostname is stable regardless of which Website triggers the Connect flow.

### Kenzi Connect Parameters

| Param | Value | Source |
|-------|-------|--------|
| `platform` | `oro_commerce` | Hardcoded |
| `instance_key` | Application hostname | Admin URL hostname (see above) |
| `api_url` | Back-office API base URL (e.g., `https://oro.acme.com/admin/api`) | Derived from application URL |
| `nonce` | Random UUID | `crypto.randomUUID()` |
| `origin` | Oro admin origin | `window.location.origin` |
| `capabilities` | `commerce` | Hardcoded |
| `admin_url` | Oro admin dashboard URL, no trailing slash | `rtrim($router->generate('oro_default', [], ABSOLUTE_URL), '/')` |

The `api_url` is stored by Kenzi in `integration.meta["api_url"]` and used directly as the `base_url` for JSON:API calls (e.g. `https://oro.acme.com/admin/api/orders`).

### Webhook Resolution

The `WebhookDispatcher` sends the instance key in the `X-Kenzi-Integration` header. Kenzi looks up the integration by direct match: `(:oro_commerce, instance_key)`.

The header value is derived from the app URL's hostname:

```php
$instanceKey = parse_url($appBaseUrl, PHP_URL_HOST);
```

| Header | Purpose |
|--------|---------|
| `X-Kenzi-Integration` | Application hostname — used by Kenzi to resolve the integration |
| `X-Kenzi-Signature` | HMAC-SHA256 of body, signed with `shared_secret` |
| `X-Kenzi-Event` | Event name (e.g., `order.created`) |
| `X-Kenzi-Delivery-Id` | UUID v4 for deduplication |
| `X-Kenzi-Timestamp` | Unix timestamp at dispatch time |

### Multi-Website (EE)

In Enterprise Edition with multiple Websites:

- **All Websites share one integration** — same `instance_key`, same `shared_secret`, same `api_url`
- **`sync_enabled` is per-website** — controls which Websites dispatch webhooks
- **`widget_enabled` is per-website** — controls which storefronts show the chat widget
- **Orders are not website-filtered during backfill** — the admin API returns all orders across all Websites. Website context is a relationship on the order entity, not a routing concern.

## Installation

```bash
composer require kenzi-chat/orocommerce-bundle
php bin/console cache:clear
php bin/console oro:platform:update --force
```

The bundle auto-registers via `Resources/config/oro/bundles.yml`.

## Configuration

Settings are managed through OroCommerce System Configuration (**Commerce > Kenzi Chat > Connection**).

### Admin-Visible Fields

| Parameter | Scope | Description |
|-----------|-------|-------------|
| Enable Widget | Website | Show chat widget on storefront |

### Environment-Seeded Fields (set by data migration, not editable in admin)

| Parameter | Config Key | Scope | Default | Description |
|-----------|-----------|-------|---------|-------------|
| App Base URL | `app_base_url` | Global | `https://app.kenzi.chat` | Kenzi app origin — used to open the connect popup and construct the webhook URL (`{app_base_url}/webhooks/oro-commerce`) |
| Static Base URL | `static_base_url` | Global | `https://static.kenzi.chat` | Kenzi static CDN — used to construct the widget loader URL (`{static_base_url}/widget/loader.js`) |

Override with `KENZI_APP_BASE` and `KENZI_STATIC_BASE` environment variables for local development (both `http://localhost:4000`).

### Programmatic-Only Fields (set by connect flow)

**Instance-level** (one value for the entire Oro application):

| Parameter | Scope | Description |
|-----------|-------|-------------|
| Workspace ID | Global | Kenzi workspace identifier |
| Shared Secret | Global | HMAC-SHA256 shared secret (received from Kenzi Connect popup) |
| Instance Key | Global | Application hostname (e.g. `oro.acme.com`), derived from the app URL's hostname. Sent as `X-Kenzi-Integration` header in webhooks |
| Connected At | Global | Timestamp of initial connection |

**Per-website** (controls which storefronts are active):

| Parameter | Scope | Description |
|-----------|-------|-------------|
| Enable Sync | Website | Enable entity webhook dispatching for this website |

### CE/EE Compatibility

This bundle supports both OroCommerce Community Edition (CE) and Enterprise Edition (EE).

- **CE (single website):** All values resolve through the global scope. The single Website uses the instance-level connection parameters directly.
- **EE (multi-website):** Instance-level parameters (shared secret, store key, workspace ID) are shared across all Websites. Per-website parameters (`sync_enabled`, `widget_enabled`) control which storefronts are active.

The bundle defines parameters in both `system_configuration` and `website_configuration` trees following the same pattern as Oro's own bundles (TaxBundle, CustomerBundle, CookieConsentBundle).

## Requirements

- PHP 8.1+
- OroCommerce 6.0+ (tested on 6.0.2 and 6.1.6)

## Testing

### Standalone (recommended for development)

```bash
cd platforms/orocommerce
composer install
vendor/bin/phpunit
```

This installs `oro/commerce` and all transitive dependencies into the bundle's own `vendor/`, giving tests access to Oro classes for mocking and type resolution without requiring a running Oro application.

### Via the Oro application

```bash
cd kenzi-orocommerce
php bin/phpunit vendor/kenzi-chat/orocommerce-bundle/tests/ --no-configuration
```

This uses the Oro app's PHPUnit and autoloader. The bundle is symlinked at `vendor/kenzi-chat/orocommerce-bundle`. Use `--no-configuration` to skip the app's `phpunit.xml` and avoid booting the kernel.

### Writing tests

Tests are pure PHPUnit unit tests — no Oro kernel, no database. Oro services like `ConfigManager` are mocked.

```
tests/Unit/
├── Async/
│   ├── OrderWebhookProcessorTest.php
│   └── OrderWebhookTopicTest.php
├── BundleTest.php
├── Controller/
│   └── ConnectControllerTest.php
├── DependencyInjection/
│   ├── ConfigurationTest.php
│   ├── KenziOroCommerceExtensionTest.php
│   └── SystemConfigurationYmlTest.php
├── EventListener/
│   ├── OrderCheckoutListenerTest.php
│   └── OrderUpdateListenerTest.php
├── Layout/
│   └── DataProvider/
│       └── WidgetDataProviderTest.php
├── Serializer/
│   └── OrderPayloadSerializerTest.php
└── Webhook/
    └── WebhookDispatcherTest.php
```

### Dependency notes

PHPUnit is pinned to `~9.5.28` to avoid `myclabs/deep-copy` version conflicts with `oro/platform 6.1`. Two security advisories are ignored in `composer.json` because they affect dev-only transitive dependencies not used by this bundle:

- **PKSA-z3gr-8qht-p93v** — PHPUnit PHPT deserialization (we don't use `.phpt` files)
- **PKSA-csyb-yc4p-mnbs** — `nesbot/carbon` (transitive Oro dep, not imported by the bundle)

These ignores can be removed once Oro releases a 6.1.x patch that bumps its `deep-copy` and `carbon` pins.

## Quality tools

```bash
composer lint          # Check code style (php-cs-fixer --dry-run)
composer lint:fix      # Fix code style
composer analyze       # Static analysis (phpstan)
composer test          # Run tests (phpunit)
```
