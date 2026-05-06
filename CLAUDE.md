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
| Shared Secret | `shared_secret` | Global | `""` | HMAC-SHA256 shared secret. Received from the Kenzi Connect popup's postMessage payload, written by `POST /admin/kenzi/connect`, sent as `Authorization: Bearer` on outbound calls to Kenzi and used to sign webhook payloads |
| Grants | `grants` | Global | `[]` | Capabilities granted by the Kenzi workspace (e.g. `["commerce"]`). Drives the gate in `WebhookDispatcher::isEnabled()` (commerce grant required for webhook dispatch) and the OAuth2 mint decision in `ConnectController::configure()` |
| Workspace ID | `workspace_id` | Global | `""` | Kenzi workspace identifier appended as `?w=` param on the storefront widget loader URL |
| OAuth Client ID | `oauth_client_id` | Global | `""` | Oro database identifier of the OAuth2 client (client_credentials grant) minted during `/configure`. Used by `/disconnect` to revoke the client via `OAuthClientFactory::revoke()` |

The `instance_key` (Oro application hostname) is no longer cached in config — it is computed live from `oro_ui.application_url` via `ApplicationUrlResolver::instanceKey()` everywhere it is needed.

### Configuration Scoping (CE/EE Compatibility)

**Design principle:** One Oro instance = one Kenzi integration. All connection parameters are global. `widget_enabled` appears at both global and website scope — the global value acts as the default, and per-website overrides control which storefronts show the chat widget. The `x-kenzi-integration` header carries the application hostname (computed live by `ApplicationUrlResolver::instanceKey()`) so Kenzi identifies the integration regardless of which website an order belongs to.

**Webhook dispatch gate:** `WebhookDispatcher::isEnabled()` returns `true` when `shared_secret` is non-empty AND `commerce` is in `grants`. There is no separate "sync enabled" master switch — the integration is "synced" iff it is connected (has a secret) and the workspace granted commerce capability.

**Planned: Per-website order sync** — A future `order_sync_enabled` config could be added as a per-website toggle in the `website_configuration` tree, following the same pattern as `widget_enabled`. The dispatcher would then use a two-gate check: (1) global `secret + commerce grant` = integration is connected and authorized, (2) per-website `order_sync_enabled` = this website's orders should be dispatched. Until implemented, all websites dispatch webhooks when the integration is connected.

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

`ConnectController` provides four endpoints behind Oro's admin authentication firewall, all under the `/admin/kenzi` prefix:

- **`POST /admin/kenzi/connect`** (route: `kenzi_connect`) — Receives `{shared_secret, grants, workspace_id}` from the connect popup's JavaScript. Stores all three values in `ConfigManager` at global scope. No HTTP to Kenzi.
- **`POST /admin/kenzi/configure`** (route: `kenzi_configure`) — Reads stored `grants`. If `commerce` is granted, mints a fresh OAuth2 client via `OAuthClientFactory::create()` and stores its identifier in `oauth_client_id`. PATCHes Kenzi's `/api/integration` with the application config (`api_url`, `admin_url`, `base_url`) plus `client_id`/`client_secret` when commerce is granted. Forwards Kenzi's response body to the JS verbatim.
- **`GET /admin/kenzi/integration`** (route: `kenzi_integration`) — Returns `404` when no `shared_secret` is stored. Otherwise GETs Kenzi's `/api/integration` and forwards the projection to the JS with `Cache-Control: no-store`.
- **`POST /admin/kenzi/disconnect`** (route: `kenzi_disconnect`) — Best-effort PATCHes Kenzi's `/api/integration` with `{claim: null}` to release the workspace claim, best-effort revokes the stored OAuth2 client via `OAuthClientFactory::revoke()`, then unconditionally `reset()`s the lifecycle config keys (`shared_secret`, `grants`, `workspace_id`, `oauth_client_id`, `widget_enabled`). Always returns `200 {ok: true}` so a re-connect always sees a clean state.

**Key details:**

- `#[AclAncestor('oro_config_system')]` is set at the class level — applies to all four actions. Only admins with system configuration permission can hit any of them.
- `#[CsrfProtection]` is set on the three POST actions. The GET action passes through Oro's admin firewall + ACL alone (per spec §8.2).
- All outbound HTTP to Kenzi (configure PATCH, integration GET, disconnect claim release) is inlined via a private `request()` helper that builds the bearer + URL + JSON envelope. No separate `IntegrationApiClient` service.
- All five lifecycle config keys are cleared on disconnect via `ConfigManager::reset()` (which removes the override row from `oro_config_value` entirely — no trace, no audit row).
- `OAuthClientFactory::create()` is stateless: every call mints a fresh client (per spec §7.5 — no read-before-write, no cleanup at configure time). The `oauth_client_id` config key tracks the most-recently-minted client so disconnect can revoke it. The disconnect-time revoke pre-dates the new integration model — it was already in place and kept as-is. It cleans up only the *most-recent* client; orphans from prior re-configure cycles still need manual cleanup in Oro admin → OAuth Applications, filtered by name "Kenzi OroCommerce".

The service is registered as `kenzi_oro_commerce.controller.connect` with `ConfigManager` (global), `HttpClientInterface`, `OAuthClientFactory`, `ApplicationUrlResolver`, and `LoggerInterface` injected.

### Connect Button JavaScript

`kenzi-connect-component.js` is an Oro `BaseComponent` auto-initialized on the system configuration page. The Twig form widget renders three sibling slot containers (`data-state="disconnected"`, `data-state="connected"`, `data-state="incomplete"`), all hidden by default. The JS picks one based on the integration projection received from `GET /admin/kenzi/integration` and unhides it. Server-side branching on connection state was removed — the integration object IS the state.

**Bootstrap data (`data-kenzi-bootstrap` attribute on the container):**

| Field | Source | Purpose |
|-------|--------|---------|
| `kenzi_app_origin` | `app_base_url` config | popup URL base + postMessage origin check |
| `instance_key` | `ApplicationUrlResolver::instanceKey()` (live) | popup URL `?key=` param |
| `supported_grants` | static `["commerce"]` | popup URL `?supported_grants=` param |
| `endpoints` | `RouterInterface::generate()` for the four routes | XHR targets |
| `secret_exists` | non-empty `shared_secret` config check | drives the loading-flow short-circuit (skip `GET /integration` when no secret) |

**Loading flow (on page mount):**

1. If `secret_exists === false` → render `disconnected` slot. Done. No HTTP.
2. Else `GET /admin/kenzi/integration` → projection picks `disconnected`, `connected`, or `incomplete` → render. On uncaught fetch error → fall back to `disconnected`.

**Pure projection (`connectionState`):**

Reached when `secret_exists` is true (the short-circuit handles the no-secret case upstream). Per product: any non-200 from the GET means there is no working integration on Kenzi's side, so the user gets the Connect button to start fresh.

```javascript
function connectionState(httpStatus, body) {
  if (httpStatus !== 200) return 'disconnected';
  if (body && body.configured && body.claimed) return 'connected';
  return 'incomplete';
}
```

**View meanings:**

- `disconnected` — no working integration. Either no secret stored, or Kenzi did not return a 200 (bundle 4XX/5XX, Kenzi unreachable, network error, etc.). Show the Connect button so the user can start fresh.
- `connected` — Kenzi confirms `configured && claimed`. Show the green status panel and Disconnect button.
- `incomplete` — Kenzi returned 200 but the integration is not yet `configured && claimed`. Show the yellow warning panel and Disconnect button.

**Connect popup URL (sent to Kenzi):**

```
{kenzi_app_origin}/connect?type=oro_commerce&key={instance_key}&supported_grants=commerce
```

**postMessage payload** — the JS listens for messages whose `event.origin` matches `kenzi_app_origin` AND `event.source` is the popup window:

```javascript
// Received from Kenzi popup
{ integration_id, workspace_id, shared_secret, grants }

// Sent to /admin/kenzi/connect via $.ajax
{ shared_secret, workspace_id, grants }
```

After `/connect` returns 200, the JS chains to `POST /admin/kenzi/configure` and reloads the page on success. Disconnect also reloads on success so all derived UI state (the `widget_enabled` checkbox, etc.) refreshes.

XHR uses `$.ajax`, which goes through Oro's jQuery prefilter for automatic `X-CSRF-Header` injection from the `_csrf` cookie.

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

`WebhookDispatcher` signs and sends payloads to the Kenzi webhook endpoint. It derives the webhook URL from the global `app_base_url` config (`{app_base_url}/webhooks/oro-commerce`), reads `shared_secret` from global scope, and computes `instance_key` live via `ApplicationUrlResolver::instanceKey()`.

**Gate (`isEnabled()`):** returns `true` IFF `shared_secret` is a non-empty string AND `commerce` is in `grants`. Both are config-derived; there is no separate `sync_enabled` flag.

**Dispatch flow:**

1. Read `app_base_url` and `shared_secret` from config; compute `instance_key` from the resolver
2. JSON-encode payload with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`
3. Compute HMAC-SHA256: `base64_encode(hash_hmac('sha256', $rawBody, $sharedSecret, true))` — raw binary output, then base64
4. Generate unique delivery ID (`Uuid::v4`) and current Unix timestamp
5. POST with headers: `x-kenzi-signature`, `x-kenzi-delivery-id`, `x-kenzi-timestamp`, `x-kenzi-integration`, `x-kenzi-event`

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

Doctrine surfaces changed fields in two places on `Order`, so the listener watches both:

**`DIRECT_FIELDS`** (top-level changeset keys) — scalars, associations, and the underlying columns that back MultiCurrency/Price value objects (changes to the value object itself do not appear in the changeset; the underlying column does):

`identifier`, `email`, `currency`, `shippingMethod`, `shippingMethodType`, `poNumber`, `customerNotes`, `shipUntil`, `sourceEntityClass`, `sourceEntityId`, `sourceEntityIdentifier`, `customer`, `customerUser`, `billingAddress`, `shippingAddress`, `subtotalValue`, `totalValue`, `totalDiscountsAmount`, `estimatedShippingCostAmount`, `overriddenShippingCostAmount`

**`SERIALIZED_ENUM_FIELDS`** (nested inside the `serialized_data` changeset entry, which holds Oro's serialized-fields bundle data) — key naming is inconsistent in Oro (`internal_status` is snake_case, `shippingStatus` is camelCase); use the exact key as serialized:

`internal_status`, `shippingStatus`

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
