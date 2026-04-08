# FOLLOWUP

Known-but-deferred work for the Kenzi OroCommerce bundle. Items here came out
of code review and are tracked for later — each has a documented reason for
not being done yet. If you're wondering "is this a bug?" the answer is "yes,
but the fix is disproportionate to the frequency or we're waiting on an
enabling change."

---

## 1. Bind `credentials_delivered` to connection identity

**Source:** Code review (orocommerce-bundle-reviewer, 2026-04 review of OAuth2 credential delivery)

**Symptom:** After delivering credentials successfully, disconnecting, and
reconnecting to a *different* Kenzi workspace, `CredentialDelivery::deliver()`
can return early from the `isDelivered()` guard and skip the PATCH. The new
workspace ends up connected for webhooks but without OAuth2 client credentials,
so backfill fails with 401.

**Root cause:** `isDelivered()` reads a plain boolean config value
(`credentials_delivered`). It has no binding to *which* connection the
delivery was performed for, so state from a previous connection leaks
across a reconnect.

**Why the obvious fix is wrong:** The original review suggested binding to
`instance_key`. That would not help — `instance_key` is derived from
`oro_ui.application_url` (the Oro hostname) and stays constant across
reconnects. The values that actually change are `workspace_id` and
`shared_secret`.

**Proposed fix:** Bind delivery state to a short hash of `shared_secret`.

- Rename `PARAM_NAME_CREDENTIALS_DELIVERED` → `PARAM_NAME_CREDENTIALS_DELIVERED_SECRET_HASH`
- Type changes from `bool` → `string` (empty = not delivered)
- `isDelivered()` becomes:
  ```php
  $stored = (string) $this->getConfig(PARAM_NAME_CREDENTIALS_DELIVERED_SECRET_HASH);
  return $stored !== '' && $stored === $this->currentSecretHash();
  ```
- On successful delivery, store `substr(hash('sha256', $sharedSecret), 0, 16)`
  instead of `true`.
- `KenziConnectButtonType` keeps exposing `credentials_delivered` as a derived
  bool in the view vars so the Twig template is untouched.

**Cost:** ~6 touch points plus a data migration
(`LoadKenziCredentialsDeliveredMigration`) that rewrites existing rows:
`true` → current hash of the stored secret, `false` → `''`.

**Why deferred:** Reconnecting a production Oro bundle to a different Kenzi
workspace is vanishingly rare. Not worth the migration cost until we see it
in the wild.

**Revisit trigger:** Any support ticket where a customer reports "backfill
is broken after reconnecting" or similar.

---

## 2. Decouple OAuth2 client owner from the current web request

**Source:** Code review (clean-architecture-expert, 2026-04 review of OAuth2 credential delivery)

**Symptom:** `CredentialDelivery::findOrCreateOAuthClient()` uses
`TokenAccessorInterface::getUserId()` to set the OAuth2 client's owning user.
This only works inside a web request — any CLI command, queued worker, or
scheduled job that calls `deliver()` will see `getUserId() === null` and
log `"No authenticated user — cannot set OAuth2 client owner"` then fail.

**Root cause:** The owning user is read at *delivery time* from the request
context instead of being captured at *connect time* and persisted.

**Proposed fix:**
1. Add `PARAM_NAME_OAUTH_OWNER_USER_ID` to `Configuration`.
2. `ConnectController::connect()` captures the current user id and stores it
   in config alongside the other connection state.
3. `findOrCreateOAuthClient()` reads the stored user id instead of calling
   `TokenAccessor`. `TokenAccessor` dependency can eventually be dropped from
   `CredentialDelivery`.
4. On disconnect, clear the stored user id alongside other connection state.

**Why deferred:** There is no CLI / queue / scheduled-job retry path today.
The `retry-delivery` endpoint added for code review item #9 runs in a web
request, so `TokenAccessor` is always populated. The constraint is purely
latent.

**Revisit trigger:** The moment we add any non-web-request call site for
`CredentialDelivery::deliver()` — an Oban-style queue retry, a CLI command,
a cron job, etc. This is a blocker for those, so the work should land as
part of whichever feature introduces them.
