# CLAUDE.md — Kenzi OroCommerce Bundle

## Supported Platform Versions

This bundle MUST support both OroCommerce 6.0 and 6.1. The `oro/commerce` constraint in `composer.json` MUST remain `^6.0` — do NOT narrow it to `^6.1` or any single-version constraint.

| Platform | Versions | Demo Environments |
|----------|----------|-------------------|
| OroCommerce | 6.0, 6.1 | `oro60.demo.kenzi.chat` (6.0.2), `oro61.demo.kenzi.chat` (6.1.6) |
| PHP | 8.1+ | 8.3 (demo) |

Both versions are continuously tested via the demo environments. Any change to this bundle must work on both Oro 6.0 and 6.1.

## Overview

Standalone Composer package (`kenzi-chat/orocommerce-bundle`) that integrates Kenzi Chat into OroCommerce 6.0+ storefronts. Current capabilities:

1. **Chat Widget** — Injects the Kenzi widget loader script into storefront pages via Oro's layout system
2. **Connect Controller** — Admin-panel endpoints to connect/disconnect credentials from the Kenzi connect popup, scoped per-website
3. **Order Payload Serializer** — Converts OroCommerce Order entities into the JSON payload structure defined by the Kenzi webhook contract
4. **Webhook Dispatcher** — Signs payloads with HMAC-SHA256 and dispatches them to the Kenzi webhook endpoint with all required headers

Entity listeners dispatch webhooks on order events (checkout completion and order updates).

## Installation

```bash
composer require kenzi-chat/orocommerce-bundle
php bin/console cache:clear
php bin/console oro:platform:update --force
```

The bundle auto-registers via `Resources/config/oro/bundles.yml`.

## Configuration Parameters

All settings are managed through OroCommerce System Configuration (System Configuration > Integrations > Kenzi Chat).

### Admin-Visible Fields (in `system_configuration.yml` `fields:` section)

| Parameter | Config Key | Scope | Default | Description |
|-----------|-----------|-------|---------|-------------|
| Enable Widget | `widget_enabled` | Global + Website | `false` | Show chat widget on storefront. Global tree sets the default; per-website tree allows override |

### Environment-Seeded Fields (set by data migration from env vars)

These values are seeded by `LoadKenziBaseUrls` on every `oro:install` / `oro:platform:update`. The migration unconditionally overwrites DB values with the current env var (or the fallback). The schema default in `Configuration.php` is `""` — the "Fallback" column below is what the migration writes when the env var is absent.

| Parameter | Config Key | Scope | Fallback | Env Override | Description |
|-----------|-----------|-------|----------|-------------|-------------|
| App Base | `app_base_url` | Global | `https://app.kenzi.chat` | `KENZI_APP_BASE` | Kenzi app origin — used to open the connect popup and construct the webhook URL (`{app_base_url}/webhooks/oro-commerce`) |
| Static Base | `static_base_url` | Global | `https://static.kenzi.chat` | `KENZI_STATIC_BASE` | Kenzi static CDN — used to construct the widget loader URL (`{static_base_url}/widget/loader.js`) |

### Programmatic-Only Fields (set by connect flow, no admin UI)

| Parameter | Config Key | Scope | Default | Description |
|-----------|-----------|-------|---------|-------------|
| Workspace ID | `workspace_id` | Global | `""` | Kenzi workspace identifier appended as `?w=` param |
| Enable Sync | `sync_enabled` | Global | `false` | Master switch for webhook dispatch (set by connect/disconnect flow). See "Planned: Per-website order sync" below |
| Shared Secret | `shared_secret` | Global | `""` | HMAC-SHA256 shared secret (received as `shared_secret` from the Kenzi Connect popup's `kenzi_connected` postMessage payload) |
| Instance Key | `instance_key` | Global | `""` | Application hostname (e.g. `oro.acme.com`), auto-derived from the admin URL. Sent as `x-kenzi-integration` webhook header so Kenzi can look up the matching `Integration` record |
| Connected At | `connected_at` | Global | `""` | Timestamp of initial connection |
| OAuth Client ID | `oauth_client_id` | Global | `""` | Oro database identifier of the generated OAuth2 Client (client_credentials grant). Stored so `CredentialDelivery` can find or revoke the client on retry/disconnect |
| Credentials Delivered | `credentials_delivered` | Global | `false` | Whether the OAuth2 client_id + client_secret were successfully PATCHed to Kenzi. Idempotency gate — `deliver()` exits early when `true` |

### Configuration Scoping (CE/EE Compatibility)

**Design principle:** One Oro instance = one Kenzi integration. All connection parameters are global. `widget_enabled` appears at both global and website scope — the global value acts as the default, and per-website overrides control which storefronts show the chat widget. The `x-kenzi-integration` header carries the application hostname so Kenzi identifies the integration regardless of which website an order belongs to.

**Planned: Per-website order sync** — Currently `sync_enabled` is global (master switch set by connect/disconnect). A future `order_sync_enabled` config will be added as a per-website toggle in the `website_configuration` tree, following the same pattern as `widget_enabled`. The dispatcher will then use a two-gate check: (1) global `sync_enabled` = integration is connected, (2) per-website `order_sync_enabled` = this website's orders should be dispatched. Until implemented, all websites dispatch webhooks when the integration is connected.

**How scoping works in Oro:**

- `Configuration.php` (`SettingsBuilder::append`) defines parameters and defaults — does NOT determine scope
- `system_configuration.yml` trees determine scope:
  - `system_configuration` tree → Global scope (one value for the entire Oro instance)
  - `website_configuration` tree → Website scope (per-website overrides on EE, dormant on CE)
  - A field can appear in **both** trees — global provides the default, website provides the override (e.g., `widget_enabled`)
- Parameters need a `fields:` entry ONLY if they should appear in the admin UI
- Programmatic-only parameters (set via `ConfigManager::set()` in code) do NOT need tree entries — scoping is determined by how `set()` is called (with or without a Website entity), not by tree placement

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
├── Async/                     # Message queue topic + processor for async webhook dispatch
├── Controller/                # Admin endpoints for connect/disconnect flow
├── DependencyInjection/       # Config tree + extension loader
├── EventListener/             # Order event listeners → produce async MQ messages
├── Layout/DataProvider/       # Layout data provider (reads config, exposes to Twig)
├── Migrations/Data/ORM/       # Data migrations (seeds base URLs from env vars)
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

- **`POST /admin/kenzi/connect/connect`** (route: `kenzi_orocommerce_connect`) — Receives `{workspace_id, shared_secret}` from the connect popup's JavaScript. Stores credentials in `ConfigManager` at global scope, enables sync, and auto-derives `instance_key` from the application hostname (via `oro_ui.application_url`).
- **`POST /admin/kenzi/connect/disconnect`** (route: `kenzi_orocommerce_disconnect`) — Clears all Kenzi connection config at global scope (shared_secret, workspace_id, instance_key, connected_at) and disables sync. Takes no request body.

**Key details:**

- `#[CsrfProtection]` uses Oro's Double Submit Cookie pattern — the admin JS framework sends `X-CSRF-Header` automatically
- `#[AclAncestor('oro_config_system')]` on both actions — only admins with system configuration permission can connect or disconnect
- `instance_key` is derived from the application hostname via `oro_ui.application_url` (Oro's canonical application URL config, set during `oro:install`)
- Credentials are trimmed before validation and storage — whitespace-only values are rejected
- Routes are registered via `Resources/config/oro/routing.yml` with attribute-based routing and `/admin` prefix

The service is registered as `kenzi_oro_commerce.controller.connect` with `ConfigManager` (global) injected.

### Connect Button JavaScript

`kenzi-connect-component.js` is an Oro `BaseComponent` auto-initialized on the system configuration page. It handles the popup-based connect flow:

**Popup URL parameters** (sent to Kenzi `/connect`):

| Param | Value | Description |
|-------|-------|-------------|
| `platform` | `oro_commerce` | Platform identifier |
| `instance_key` | Application hostname | Unique identifier for the Oro instance |
| `api_url` | Back-office API base URL | Full URL with scheme and `/admin/api` prefix (e.g. `https://oro.acme.com/admin/api`) |
| `nonce` | `crypto.randomUUID()` | CSRF correlation — echoed back in postMessage |
| `origin` | `window.location.origin` | Oro admin origin for postMessage targeting |
| `requested_capabilities` | `commerce` | Requests commerce data sync capability |
| `admin_url` | Oro admin dashboard URL | For deep-linking to orders/customers in Kenzi |
| `base_url` | Application root origin (e.g., `https://oro.acme.com`) | Stored in `integration.meta["base_url"]` — used to derive the OAuth2 token endpoint and to construct absolute product image URLs during backfill |

**postMessage contract** — the JS listens for `kenzi_connected` (underscore, not colon) from the Kenzi popup:

```javascript
// Received from Phoenix
{ type: "kenzi_connected", nonce, workspace_id, shared_secret, ... }

// Sent to ConnectController via AJAX
{ workspace_id, shared_secret }
```

### Order Payload Serializer

`OrderPayloadSerializer` converts an `Order` entity into the JSON structure expected by the Kenzi webhook endpoint. The top-level envelope:

```json
{
  "event": "order.created",
  "timestamp": 1709500000,
  "data": { /* serialized order */ }
}
```

Constructor dependencies: `AttachmentManager` (Oro's file URL generator) and `ApplicationUrlResolver` (derives the application root origin).

Key behaviors:

- **Money formatting** — `formatMoney()` normalizes all monetary values to 2-decimal strings (e.g. `"49.99"`) or `null`
- **Status resolution** — `resolveStatus()` reads `Order::getInternalStatus()->getId()`, falling back to `"unknown"` when the internal status is null
- **Product image URL** — `resolveProductImageUrl()` resolves the `listing`-type product image via `AttachmentManager::getFilteredImageUrl()` (filter: `product_small`), then prepends the application root origin from `ApplicationUrlResolver::baseOrigin()` to produce an absolute URL. Returns `null` when the product or image is absent.
- **Nullable associations** — `customer`, `customer_user`, `billing_address`, `shipping_address`, `website` are omitted from the payload when null (not sent as `null` keys)
- **Collections** — `line_items` and `shipping_trackings` are always present as arrays (empty if none)
- **Timestamps** — All date fields use ISO 8601 format (`'c'`)

Design decisions:

- **`price_type` is passed as Oro's raw integer** (e.g. `10` = unit, `20` = bundled) rather than mapped to human-readable strings. The serializer is a transport layer — it faithfully relays what Oro stores. Mapping here would be fragile if Oro adds new price types in future versions. The webhook consumer (Kenzi backend) interprets these values instead.
- **`event` parameter is not validated at runtime** — the `@param non-empty-string` annotation is enforced statically by PHPStan. The caller is always an internal entity listener passing known event strings (`order.created`, `order.updated`, etc.), not user input. Runtime validation would be defensive programming against internal code.

The service is registered manually in `services.yml` as `kenzi_oro_commerce.serializer.order_payload` (no autowiring — follows Oro bundle convention).

### Webhook Dispatcher

`WebhookDispatcher` signs and sends payloads to the Kenzi webhook endpoint. It derives the webhook URL from the global `app_base_url` config (`{app_base_url}/webhooks/oro-commerce`) and reads `shared_secret` and `integration_key` from global scope.

**Dispatch flow:**

1. Check `sync_enabled` — return `false` if disabled
2. Derive webhook URL from global `app_base_url` (`{app_base_url}/webhooks/oro-commerce`), read `shared_secret`, `instance_key` — return `false` if any are empty
3. JSON-encode payload with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`
4. Compute HMAC-SHA256: `base64_encode(hash_hmac('sha256', $rawBody, $sharedSecret, true))` — raw binary output, then base64
5. Generate unique delivery ID (`Uuid::v4`) and current Unix timestamp
6. POST with headers: `x-kenzi-signature`, `x-kenzi-delivery-id`, `x-kenzi-timestamp`, `x-kenzi-integration`, `x-kenzi-event`

**Critical invariants:**

- The JSON body sent in the HTTP request is the exact same bytes used for HMAC computation (encode once, use for both)
- Timestamp is `time()` at dispatch time, NOT the order creation time (Kenzi rejects timestamps > 5 min old)
- Each dispatch generates a fresh UUID delivery ID (Kenzi deduplicates by delivery ID)
- The `sign()` helper uses `hash_hmac(..., true)` for raw binary, NOT hex encoding

The service is registered as `kenzi_oro_commerce.webhook.dispatcher` with the `kenzi_oro_commerce` monolog channel.

### Async Webhook Dispatch

Order webhooks are dispatched asynchronously via Oro's Message Queue:

1. **Event Listeners** produce lightweight messages (just `order_id` + `event` string):
   - **`OrderCheckoutListener`** — Listens to `extendable_action.finish_checkout` at priority `-10`. Produces `order.created` message.
   - **`OrderUpdateListener`** — Doctrine entity listener on `Order::postUpdate`. Produces `order.updated` message only when payload-relevant fields change (see Changeset Filtering below).

2. **`OrderWebhookTopic`** — Defines the message schema (`order_id: int`, `event: string`, `retry_count: int`) with OptionsResolver validation.

3. **`OrderWebhookProcessor`** — Runs in Oro's background worker. Loads the Order, checks if sync is enabled for the order's Website, serializes the payload, and dispatches the webhook via `WebhookDispatcher`.

This decoupling ensures checkout/order-update requests are never blocked by webhook HTTP calls. Messages persist in the queue across worker restarts, and Oro's MQ dashboard (`System > Message Queue`) provides visibility into pending/failed messages.

### Retry Mechanism

On transient failure (network error, non-2xx response), the processor schedules retries with increasing delays:

- **Retry 1:** 10 seconds — covers brief network blips, load balancer hiccups
- **Retry 2:** 60 seconds — covers short deployments, service restarts
- **Retry 3:** 300 seconds (5 min) — covers longer outages, scaling events

After 3 failed retries (~6 minute window), the message is permanently rejected with a `CRITICAL` log entry. Permanent errors like JSON encoding failures (`JsonException`) are rejected immediately without retry.

The dispatcher throws exceptions on failure (both `TransportExceptionInterface` for network errors and `RuntimeException` for non-2xx responses) so the processor can differentiate transient vs permanent failures.

### Changeset Filtering

`OrderUpdateListener` checks `UnitOfWork::getEntityChangeSet()` before producing a message. A webhook is only triggered when at least one field that appears in the webhook payload changes. This prevents unnecessary dispatches when only internal/audit fields are modified.

**Watched fields** (trigger webhook): `identifier`, `internalStatus`, `email`, `currency`, `subtotal`, `total`, `totalDiscounts`, `shippingMethod`, `shippingMethodType`, `shippingCost`, `estimatedShippingCostAmount`, `overriddenShippingCostAmount`, `poNumber`, `customerNotes`, `shipUntil`, `sourceEntityClass`, `sourceEntityId`, `sourceEntityIdentifier`, `customer`, `customerUser`, `billingAddress`, `shippingAddress`

**Excluded fields** (never trigger webhook alone): `updatedAt`, `createdAt` — these change as a byproduct of any update. If only timestamps changed, Kenzi would receive identical substantive data. When a real field changes, the current timestamps are included in the payload naturally.

### Widget Injection Flow

1. `WidgetDataProvider` (layout data provider, alias: `kenzi_widget`) reads `widget_enabled`, `workspace_id` from `ConfigManager` (auto-scoped via Oro's scope cascade) and derives the widget script URL from the global `static_base_url` config (`{static_base_url}/widget/loader.js`)
2. Layout import `kenzi_widget.yml` uses `visible: '=data["kenzi_widget"].isWidgetEnabled()'` to hide the block when disabled, and passes `widgetScriptUrl`/`workspaceId` to Twig via `options.vars`
3. `kenzi_widget.html.twig` renders `<script>` tag using block variables (not `data["..."]` — Oro layout `data` is only available in YAML expressions, not in Twig)

## Key Dependencies

- **OroCommerce 6.0+** (`oro/commerce: ^6.0`) — Platform framework
- **Symfony 6.4** — Config/DI components
- **Oro ConfigBundle** — System configuration management (CE: global scope; EE: website-scoped via `WebsiteScopeManager`)
- **PHP 8.1+** — Required for typed properties, nullsafe operator, named arguments

## Common Commands

```bash
# Testing (standalone — run from this directory)
composer install              # First time only — installs Oro + PHPUnit into vendor/
vendor/bin/phpunit            # Run all unit tests

# Testing (via Oro app — run from kenzi-orocommerce/)
php bin/phpunit vendor/kenzi-chat/orocommerce-bundle/tests/ --no-configuration

# Quality
composer lint                 # Check code style (php-cs-fixer --dry-run)
composer lint:fix             # Fix code style
composer analyze              # Static analysis (phpstan)

# Oro app commands (run from kenzi-orocommerce/)
php bin/console cache:clear
php bin/console oro:platform:update --force
```
