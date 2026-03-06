# CLAUDE.md — Kenzi OroCommerce Bundle

## Overview

Standalone Composer package (`kenzi/orocommerce-bundle`) that integrates Kenzi Chat into OroCommerce 6.1 storefronts. Current capabilities:

1. **Chat Widget** — Injects the Kenzi widget loader script into storefront pages via Oro's layout system
2. **Connect Controller** — Admin-panel endpoints to connect/disconnect credentials from the Kenzi connect popup, scoped per-website
3. **Order Payload Serializer** — Converts OroCommerce Order entities into the JSON payload structure defined by the Kenzi webhook contract
4. **Webhook Dispatcher** — Signs payloads with HMAC-SHA256 and dispatches them to the Kenzi webhook endpoint with all required headers

Entity listeners dispatch webhooks on order events (checkout completion and order updates).

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
| Secret | `secret` | Website | `""` | HMAC-SHA256 shared secret |
| Store Key | `store_key` | Website | `""` | Website hostname (e.g. `b2b.acme-corp.com`), auto-derived from `oro_website.url`. Sent as `X-Kenzi-Store-Key` webhook header so Kenzi can look up the matching `Integration` record |
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
├── Controller/                # Admin endpoints for connect/disconnect flow
├── DependencyInjection/       # Config tree + extension loader
├── EventListener/             # Order event listeners → webhook dispatch
├── Layout/DataProvider/       # Layout data provider (reads config, exposes to Twig)
├── Serializer/                # Order → webhook payload conversion
├── Webhook/                   # HMAC signing + HTTP dispatch to Kenzi
├── Resources/
│   ├── config/
│   │   ├── oro/               # bundles.yml, routing.yml, system_configuration.yml
│   │   └── services.yml       # Service definitions
│   ├── translations/          # messages.en.yml
│   └── views/layouts/         # Twig layout for widget injection
└── KenziOroCommerceBundle.php
```

### Connect Controller

`ConnectController` provides two POST endpoints behind Oro's admin authentication firewall:

- **`POST /admin/kenzi/connect/connect`** — Receives `{workspace_id, secret, website_id}` from the connect popup's JavaScript. Stores credentials in `ConfigManager` scoped to the specified Website, enables sync, and auto-derives `store_key` from the Website's configured URL hostname.
- **`POST /admin/kenzi/connect/disconnect`** — Clears all Kenzi config fields for a Website (secret, workspace_id, store_key, connected_at) and disables sync.

**Key details:**

- `#[CsrfProtection]` uses Oro's Double Submit Cookie pattern — the admin JS framework sends `X-CSRF-Header` automatically
- `#[AclAncestor('oro_config_system')]` on both actions — only admins with system configuration permission can connect or disconnect
- Website lookup uses `AclHelper::apply()` to scope by the current user's organization — prevents cross-org access in EE multi-org setups
- `store_key` is derived from `oro_website.url` config (read via `ConfigManager`), not from the `Website` entity directly (which has no `getUrl()` method)
- Credentials are trimmed before validation and storage — whitespace-only values are rejected
- Routes are registered via `Resources/config/oro/routing.yml` with attribute-based routing and `/admin` prefix

The service is registered as `kenzi_oro_commerce.controller.connect` with `ConfigManager`, `ManagerRegistry`, and `AclHelper` injected.

### Order Payload Serializer

`OrderPayloadSerializer` converts an `Order` entity into the JSON structure expected by the Kenzi webhook endpoint. The top-level envelope:

```json
{
  "event": "order.created",
  "timestamp": 1709500000,
  "data": { /* serialized order */ }
}
```

Key behaviors:

- **Money formatting** — `formatMoney()` normalizes all monetary values to 2-decimal strings (e.g. `"49.99"`) or `null`
- **Status resolution** — `resolveStatus()` reads `Order::getInternalStatus()->getId()`, falling back to `"unknown"` when the internal status is null
- **Nullable associations** — `customer`, `customer_user`, `billing_address`, `shipping_address` are omitted from the payload when null (not sent as `null` keys)
- **Collections** — `line_items` and `shipping_trackings` are always present as arrays (empty if none)
- **Timestamps** — All date fields use ISO 8601 format (`'c'`)

Design decisions:

- **`price_type` is passed as Oro's raw integer** (e.g. `10` = unit, `20` = bundled) rather than mapped to human-readable strings. The serializer is a transport layer — it faithfully relays what Oro stores. Mapping here would be fragile if Oro adds new price types in future versions. The webhook consumer (Kenzi backend) interprets these values instead.
- **`event` parameter is not validated at runtime** — the `@param non-empty-string` annotation is enforced statically by PHPStan. The caller is always an internal entity listener passing known event strings (`order.created`, `order.updated`, etc.), not user input. Runtime validation would be defensive programming against internal code.

The service is registered manually in `services.yml` as `kenzi_oro_commerce.serializer.order_payload` (no autowiring — follows Oro bundle convention).

### Webhook Dispatcher

`WebhookDispatcher` signs and sends payloads to the Kenzi webhook endpoint. It reads all configuration scoped to the order's Website.

**Dispatch flow:**

1. Check `sync_enabled` — return `false` if disabled
2. Read `webhook_url`, `secret`, `store_key` — return `false` if any are empty
3. JSON-encode payload with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`
4. Compute HMAC-SHA256: `base64_encode(hash_hmac('sha256', $rawBody, $secret, true))` — raw binary output, then base64
5. Generate unique delivery ID (`Uuid::v4`) and current Unix timestamp
6. POST with headers: `x-kenzi-signature`, `x-kenzi-delivery-id`, `x-kenzi-timestamp`, `x-kenzi-store-key`, `x-kenzi-event`

**Critical invariants:**

- The JSON body sent in the HTTP request is the exact same bytes used for HMAC computation (encode once, use for both)
- Timestamp is `time()` at dispatch time, NOT the order creation time (Kenzi rejects timestamps > 5 min old)
- Each dispatch generates a fresh UUID delivery ID (Kenzi deduplicates by delivery ID)
- The `sign()` helper uses `hash_hmac(..., true)` for raw binary, NOT hex encoding

The service is registered as `kenzi_oro_commerce.webhook.dispatcher` with the `kenzi_oro_commerce` monolog channel.

### Event Listeners

Two listeners wire OroCommerce order events to the webhook dispatcher:

- **`OrderCheckoutListener`** — Listens to `extendable_action.finish_checkout` (Symfony EventDispatcher). Extracts the Order from `$event->getData()->get('order')` and dispatches `order.created`. Runs at priority `-10` so the order is fully persisted first.
- **`OrderUpdateListener`** — Doctrine entity listener on `Order::postUpdate`. Receives the Order as a type-hinted first parameter and dispatches `order.updated`. Uses `Doctrine\Persistence\Event\LifecycleEventArgs` (not ORM-specific args).

Both pass `$order->getWebsite()` to the dispatcher for website-scoped config resolution.

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
