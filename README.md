# Kenzi OroCommerce Bundle

Integrates [Kenzi Chat](https://kenzi.chat) into OroCommerce 6.1 storefronts.

- **Chat Widget** — Injects the Kenzi widget loader script into storefront pages via Oro's layout system
- **Order Webhooks** — Serializes OroCommerce Order entities into Kenzi webhook payloads

## Installation

```bash
composer require kenzi/orocommerce-bundle
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
| Webhook URL | Global | Kenzi endpoint for webhooks |

### Programmatic-Only Fields (set by connect flow)

| Parameter | Scope | Description |
|-----------|-------|-------------|
| Widget Script URL | Website | Base URL for widget loader |
| Workspace ID | Website | Kenzi workspace identifier |
| Enable Sync | Website | Enable entity webhook dispatching |
| Webhook Secret | Website | HMAC-SHA256 shared secret |
| Store Key | Website | Identifies this OroCommerce website to Kenzi |
| Connected At | Website | Timestamp of initial connection |

### CE/EE Compatibility

This bundle supports both OroCommerce Community Edition (CE) and Enterprise Edition (EE).

- **EE (multi-website):** Website-scoped parameters are stored per-website via `WebsiteScopeManager`. Each storefront gets its own independent Kenzi connection.
- **CE (single website):** The `website_configuration` tree is parsed but dormant (no `WebsiteScopeManager` in CE). All values resolve through the global scope, which is correct since CE has only one website.

The bundle defines parameters in both `system_configuration` and `website_configuration` trees following the same pattern as Oro's own bundles (TaxBundle, CustomerBundle, CookieConsentBundle).

## Requirements

- PHP 8.1+
- OroCommerce 6.1

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
php bin/phpunit vendor/kenzi/orocommerce-bundle/tests/ --no-configuration
```

This uses the Oro app's PHPUnit and autoloader. The bundle is symlinked at `vendor/kenzi/orocommerce-bundle`. Use `--no-configuration` to skip the app's `phpunit.xml` and avoid booting the kernel.

### Writing tests

Tests are pure PHPUnit unit tests — no Oro kernel, no database. Oro services like `ConfigManager` are mocked.

```
tests/Unit/
├── BundleTest.php
├── DependencyInjection/
│   ├── ConfigurationTest.php
│   └── KenziOroCommerceExtensionTest.php
└── Layout/
    └── DataProvider/
        └── WidgetDataProviderTest.php
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
