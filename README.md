# Kenzi OroCommerce Bundle

Integrates [Kenzi Chat](https://kenzi.chat) into OroCommerce 6.0+ storefronts.

- **Chat Widget** — Injects the Kenzi widget loader script into storefront pages via Oro's layout system
- **Connect Flow** — Admin controller to store/clear credentials from the Kenzi connect popup, scoped per-website
- **Order Webhooks** — Listens for order checkout and update events, enqueues messages via Oro's Message Queue, and asynchronously serializes and dispatches HMAC-signed webhooks to Kenzi. Retries failed dispatches 3 times with backoff (10s, 60s, 5min). Only triggers on payload-relevant field changes — timestamp-only updates are ignored.

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
| App Base URL | `app_base_url` | Global | `https://app.kenzi.chat` | Kenzi app origin — used to open the connect popup and construct the webhook URL (`{app_base_url}/orocommerce/webhooks`) |
| Static Base URL | `static_base_url` | Global | `https://static.kenzi.chat` | Kenzi static CDN — used to construct the widget loader URL (`{static_base_url}/widget/loader.js`) |

Override with `KENZI_APP_BASE` and `KENZI_STATIC_BASE` environment variables for local development (both `http://localhost:4000`).

### Programmatic-Only Fields (set by connect flow)

| Parameter | Scope | Description |
|-----------|-------|-------------|
| Workspace ID | Website | Kenzi workspace identifier |
| Enable Sync | Website | Enable entity webhook dispatching |
| Shared Secret | Website | HMAC-SHA256 shared secret (received as `shared_secret` from the Kenzi Connect popup) |
| Store Key | Website | Website hostname (e.g. `b2b.acme-corp.com`), auto-derived from the website URL. Sent as `X-Kenzi-Store-Key` header in webhooks so Kenzi can match the request to the right integration |
| Connected At | Website | Timestamp of initial connection |

### CE/EE Compatibility

This bundle supports both OroCommerce Community Edition (CE) and Enterprise Edition (EE).

- **EE (multi-website):** Website-scoped parameters are stored per-website via `WebsiteScopeManager`. Each storefront gets its own independent Kenzi connection.
- **CE (single website):** The `website_configuration` tree is parsed but dormant (no `WebsiteScopeManager` in CE). All values resolve through the global scope, which is correct since CE has only one website.

The bundle defines parameters in both `system_configuration` and `website_configuration` trees following the same pattern as Oro's own bundles (TaxBundle, CustomerBundle, CookieConsentBundle).

## Requirements

- PHP 8.1+
- OroCommerce 6.0+

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
