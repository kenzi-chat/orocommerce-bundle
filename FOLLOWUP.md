# FOLLOWUP

Known-but-deferred work for the Kenzi OroCommerce bundle. Items here came out
of code review and are tracked for later — each has a documented reason for
not being done yet. If you're wondering "is this a bug?" the answer is "yes,
but the fix is disproportionate to the frequency or we're waiting on an
enabling change."

---

## 1. Decouple OAuth2 client owner from the current web request

**Source:** Code review (clean-architecture-expert, 2026-04 review of OAuth2 credential delivery)

**Symptom:** `OAuthClientFactory::create()` uses
`TokenAccessorInterface::getUserId()` to set the OAuth2 client's owning user.
This only works inside a web request — any CLI command, queued worker, or
scheduled job that calls `create()` will see `getUserId() === null` and
throw `"No authenticated user — cannot set OAuth2 client owner"` then fail.

**Root cause:** The owning user is read at *mint time* from the request
context instead of being captured at *connect time* and persisted.

**Proposed fix:**
1. Add `PARAM_NAME_OAUTH_OWNER_USER_ID` to `Configuration` as a fifth
   lifecycle key.
2. `ConnectController::connect()` captures the current user id and stores it
   in config alongside the other connection state.
3. `OAuthClientFactory::create()` accepts a user id parameter instead of
   reading from `TokenAccessor`. `TokenAccessor` dependency can be dropped
   from the factory.
4. On disconnect, clear the stored user id alongside other lifecycle keys.

**Why deferred:** There is no CLI / queue / scheduled-job retry path today.
`ConnectController::configure()` is the only caller of `create()`, and it
runs in a web request, so `TokenAccessor` is always populated. The
constraint is purely latent.

**Revisit trigger:** The moment we add any non-web-request call site for
`OAuthClientFactory::create()` — an Oban-style queue retry, a CLI command,
a cron job, etc. This is a blocker for those, so the work should land as
part of whichever feature introduces them.

---

## 2. Eager-load product images in OrderWebhookProcessor

**Source:** GitHub code review (commit 3686a95b, 2026-04)

**Symptom:** `OrderPayloadSerializer::resolveProductImageUrl()` calls
`$product->getImagesByType('listing')` per line item. Since the processor
loads the Order via `$repository->find($orderId)` (plain Doctrine find),
all associations are lazy proxies. Each line item triggers separate queries
for the product and its image collection — an N+1 problem.

**Impact:** For an order with N line items, this adds ~2N extra SQL queries
per webhook dispatch. The processor runs in Oro's async message queue worker
so it doesn't block user-facing requests, and typical order sizes (5-20
line items) keep the absolute cost low.

**Proposed fix:** Replace `$repository->find()` with a DQL query that uses
`JOIN FETCH` for `lineItems`, `lineItems.product`, and the product's image
collection. Requires mapping out Oro's exact association names for product
images (`Product::$images` → `ProductImage::$image` → `File`).

**Why deferred:** Low user-facing impact (async worker, small line item
counts). The fix requires understanding Oro's internal entity mapping for
product images across both 6.0 and 6.1, which is non-trivial to verify
without integration testing on both versions.

**Revisit trigger:** Performance complaints from high-volume stores, or if
we add synchronous webhook dispatch for any reason.

---

## 3. Align product image selection between webhook and backfill paths

**Source:** GitHub code review (commit 3686a95b, 2026-04)

**Symptom:** The webhook and backfill code paths use different strategies to
resolve a product's display image, which can produce different `image_url`
values for the same product:

- **Webhook (PHP):** `$product->getImagesByType('listing')` — filters by
  the `listing` image type via Doctrine, then resolves the `product_small`
  filtered URL via `AttachmentManager`.
- **Backfill (Elixir):** `include=images.image` in the JSON:API request —
  fetches ALL product images regardless of type, then `pick_image_url`
  selects the `product_small` dimension from the file's `filePath` array.

A product with only a `main` image (no `listing` type) would get
`image_url: null` from the webhook but could resolve a URL from backfill.

**Proposed fix:** Filter `productimages` resources in the Elixir backfill
path by their image type attribute (if exposed by the JSON:API), matching
the PHP path's `listing` type constraint. Requires inspecting the actual
`productimages` JSON:API resource schema on a live Oro instance to identify
the correct attribute name.

**Why deferred:** Most products with images will have a `listing` type
(it's the standard storefront display type). The divergence only affects
products with unusual image configurations. We need access to a live Oro
instance to inspect the `productimages` resource schema.

**Revisit trigger:** Any report of mismatched product images between
webhook-synced and backfill-synced products, or next time we're inspecting
the Oro JSON:API responses on a demo instance.

---

## 4. Extract a shared accessor for ConfigManager boilerplate

**Source:** Code review (clean-architecture-expert + orocommerce-bundle-reviewer)

**Symptom:** The boilerplate
`$this->configManager->get(Configuration::getConfigKeyByName($paramName))`
(plus its `set` and `reset` siblings) repeats across 5 classes:

- `ConnectController` — has private `getConfig`/`setConfig`/`resetConfig` trio.
- `WebhookDispatcher` — has its own private `getConfig` (identical body to
  ConnectController's).
- `KenziConnectButtonType` — inline reads.
- `WidgetDataProvider` — inline reads.
- `LoadKenziBaseUrls` (data migration) — inline writes.

Two classes carry their own copy of the same one-liner; three have the
boilerplate inline.

**Proposed fix:** Extract a shared accessor service that wraps
`ConfigManager` + `Configuration::getConfigKeyByName` and exposes named
domain getters (`getSharedSecret(): string`, `getGrants(): list<string>`,
`isConnected(): bool`, `saveConnection(...)`, `disconnect()`, etc.).
Match Oro core's `*ConfigProvider` precedent (`CustomerConfigProvider`,
`TaxConfigProvider`, `CheckoutConfigProvider`); the WordPress plugin's
`platforms/wordpress/src/Settings.php` is a useful reference shape.
Refactor all 5 classes to inject the new service. Delete the per-class
private helpers in `ConnectController` and `WebhookDispatcher`.

**Why deferred:** The narrow per-class private helpers are correct for
each class in isolation. The cross-class duplication is real but no
worse than today, and the narrow V9 fix made it MORE visible (5 identical
helper bodies is a louder signal than mixed inline/helper styles).
Bundling the cross-class extraction into the V9 PR would have been a
larger churn touching 4 stable files.

**Revisit trigger:** A 6th caller of the `configManager->get(...)`
boilerplate appearing, OR any of the three currently-inline classes
(`KenziConnectButtonType`, `WidgetDataProvider`, `LoadKenziBaseUrls`)
growing its own private helper. Either is a clear "now extract" signal.
