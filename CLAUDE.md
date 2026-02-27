# CLAUDE.md — Kenzi OroCommerce Bundle

## Overview

Standalone Composer package (`kenzi/orocommerce-bundle`) that integrates Kenzi Chat into OroCommerce 6.1 storefronts. Current capability:

1. **Chat Widget** — Injects the Kenzi widget loader script into storefront pages via Oro's layout system

Commerce webhook dispatching (entity listeners, HMAC signing) will be added in later implementation tasks.

## Installation

```bash
composer require kenzi/orocommerce-bundle
php bin/console cache:clear
php bin/console oro:platform:update --force
```

The bundle auto-registers via `Resources/config/oro/bundles.yml`.

## Configuration Parameters

All settings are managed through OroCommerce System Configuration (Commerce > Kenzi Chat > Connection).

### Admin-Visible Fields (in `system_configuration.yml` `fields:` section)

| Parameter | Config Key | Scope | Default | Description |
|-----------|-----------|-------|---------|-------------|
| Enable Widget | `widget_enabled` | Website | `false` | Show chat widget on storefront |
| Webhook URL | `webhook_url` | Global | `https://app.kenzi.chat/orocommerce/webhooks` | Kenzi endpoint for webhooks |

### Programmatic-Only Fields (set by connect flow, no admin UI)

| Parameter | Config Key | Scope | Default | Description |
|-----------|-----------|-------|---------|-------------|
| Widget Script URL | `widget_base_url` | Website | `""` | Base URL for widget loader |
| Workspace ID | `workspace_id` | Website | `""` | Kenzi workspace identifier appended as `?w=` param |
| Enable Sync | `sync_enabled` | Website | `false` | Enable entity webhook dispatching |
| Webhook Secret | `webhook_secret` | Website | `""` | HMAC-SHA256 shared secret |
| Store Key | `store_key` | Website | `""` | Identifies this OroCommerce website to Kenzi |
| Connected At | `connected_at` | Website | `""` | Timestamp of initial connection |

### Configuration Scoping (CE/EE Compatibility)

**Design principle:** Each OroCommerce website = one independent Kenzi workspace connection. All connection parameters are website-scoped except `webhook_url` (global — one Kenzi endpoint for all websites, differentiated by `X-Kenzi-Store-Key` header).

**How scoping works in Oro:**

- `Configuration.php` (`SettingsBuilder::append`) defines parameters and defaults — does NOT determine scope
- `system_configuration.yml` trees determine scope:
  - `system_configuration` tree → Global scope (one value for the entire Oro instance)
  - `website_configuration` tree → Website scope (per-website values on EE, dormant on CE)
- Parameters need a `fields:` entry ONLY if they should appear in the admin UI
- Parameters without `fields:` entries can still appear in tree children for scoping purposes

**CE vs EE behavior:**

| Feature | CE (single website) | EE (multi-website) |
|---------|--------------------|--------------------|
| `WebsiteScopeManager` | Not present | Present |
| `website_configuration` tree | Parsed but dormant | Active, renders in admin UI |
| Config resolution | customer → customer_group → user → global | customer → customer_group → user → website → organization → global |
| Effect on this bundle | All values resolve via global scope (correct — CE has one website) | Per-website values via `WebsiteScopeManager` |

This dual-tree pattern matches Oro's own bundles (TaxBundle, CustomerBundle, CookieConsentBundle) for CE/EE compatibility.

## Architecture

### Bundle Structure

```
src/
├── DependencyInjection/       # Config tree + extension loader
├── Layout/DataProvider/       # Layout data provider (reads config, exposes to Twig)
├── Resources/
│   ├── config/
│   │   ├── oro/               # bundles.yml, system_configuration.yml
│   │   └── services.yml       # Service definitions
│   ├── translations/          # messages.en.yml
│   └── views/layouts/         # Twig layout for widget injection
└── KenziOroCommerceBundle.php
```

### Widget Injection Flow

1. `WidgetDataProvider` (layout data provider, alias: `kenzi_widget`) reads `widget_enabled`, `workspace_id`, `widget_base_url` from `ConfigManager` (auto-scoped via Oro's scope cascade — see Configuration Scoping above)
2. Layout import `kenzi_widget.yml` uses `visible: '=data["kenzi_widget"].isWidgetEnabled()'` to hide the block when disabled, and passes `widgetBaseUrl`/`workspaceId` to Twig via `options.vars`
3. `kenzi_widget.html.twig` renders `<script>` tag using block variables (not `data["..."]` — Oro layout `data` is only available in YAML expressions, not in Twig)

## Key Dependencies

- **OroCommerce 6.1** (`oro/commerce: 6.1.*`) — Platform framework
- **Symfony 6.4** — Config/DI components
- **Oro ConfigBundle** — System configuration management (CE: global scope; EE: website-scoped via `WebsiteScopeManager`)
- **PHP 8.1+** — Required for typed properties, nullsafe operator, named arguments

## Common Commands

```bash
# Testing (standalone — run from this directory)
composer install              # First time only — installs Oro + PHPUnit into vendor/
vendor/bin/phpunit            # Run all unit tests

# Testing (via Oro app — run from kenzi-orocommerce/)
php bin/phpunit vendor/kenzi/orocommerce-bundle/tests/ --no-configuration

# Quality
composer lint                 # Check code style (php-cs-fixer --dry-run)
composer lint:fix             # Fix code style
composer analyze              # Static analysis (phpstan)

# Oro app commands (run from kenzi-orocommerce/)
php bin/console cache:clear
php bin/console oro:platform:update --force
```
