# Debug sessions (client telemetry)

A merchant-started, time-boxed diagnostic feed. Off until someone presses
**Start debug session** in *Modules → Module Manager → Paypercut → Configure →
Debug Session*; ends by itself after about an hour.

Nothing is sent when no session is running. `PaypercutTelemetryRecorder::record()`
reads one already-preloaded `Configuration` value and returns, so call sites on
the checkout path are free to report unconditionally.

## Shape

```
Start  →  POST {api}/v1/telemetry/tokens   (the store's API key, once)
          →  short-lived RS256 token
Events →  POST {edge}/v1/telemetry         (the token; never the API key)
```

Both hosts are resolved from the single `PAYPERCUT_ENVIRONMENT` setting, in the
same call sequence, before any network call. A token minted for one environment
is rejected by every other environment's edge with a 401 that is
indistinguishable from a forged token, so an unrecognised environment yields
**no session at all** rather than a confusing one. The payment API base falls
back to production for an unrecognised environment; the telemetry edge never
does.

| Environment | API base | Telemetry edge |
|---|---|---|
| `production` | `https://api.paypercut.io/` | `https://telemetry.paypercut.io/` |
| `stage` | `https://api.stage.paypercut.net/` | `https://telemetry.stage.paypercut.net/` |
| `dev` | `https://api.dev.paypercut.net/` | `https://telemetry.dev.paypercut.net/` |
| anything else | `https://api.paypercut.io/` | *(none — no session)* |

Both bases pass `PaypercutEnvironment::allowedPaypercutBase()`, which accepts
only `https` on a `paypercut.net` / `paypercut.io` host. A credential travels on
the mint request, so the destination is checked rather than trusted.

The edge verifies the token offline and never calls back into the platform, so
telemetry cannot block a payment.

| Piece | Role |
|---|---|
| `PaypercutTelemetryEvent` | Named constructors, the deny assertion, the wire envelope |
| `PaypercutTelemetryRecorder` | Buffers events; one queue write per request, at shutdown |
| `PaypercutTelemetryQueue` | Capped store; splits batches to the edge's bounds |
| `PaypercutTelemetryStore` | The `paypercut_telemetry_store` table and the locks |
| `PaypercutTelemetrySession` | Session record, token custody, the single teardown path |
| `PaypercutTokenMinter` | Exchanges the API key for a token, on the mint host |
| `PaypercutMintErrorMapper` | Turns a mint rejection into merchant-facing copy |
| `PaypercutEdgeClient` | POSTs a batch; reads `accepted`/`dropped` off a 202 |
| `PaypercutTelemetryFlusher` | Delivers from back-office requests only; handles 413 by splitting |
| `PaypercutSentLog` | The last 100 delivered envelopes, for the merchant to read |
| `PaypercutFatalErrorWatch` | Shutdown handler for fatals that reach no catch block |

## Storage

PrestaShop has no transient concept and its cache may be non-persistent, so
everything but the session record lives in a module table with an `expires_at`
column. A cache flush mid-session would silently lose the merchant's
diagnostics, which is the one failure mode a debug session cannot tolerate.

| Key | Where | Notes |
|---|---|---|
| `PAYPERCUT_TELEMETRY_SESSION` | `Configuration` | The durable record. Preloaded on every request, so the storefront gate is free. Not a settings-form field, so a save cannot write it. |
| `paypercut_telemetry_token` | `paypercut_telemetry_store` | base64 JWT + a copy of `expires_at`; TTL'd |
| `paypercut_telemetry_queue` | `paypercut_telemetry_store` | Appended by storefront requests |
| `paypercut_telemetry_inflight` | `paypercut_telemetry_store` | A batch taken but not yet settled |
| `paypercut_telemetry_runtime` | `paypercut_telemetry_store` | Counters, backoff, last error |
| `paypercut_telemetry_sent_log` | `paypercut_telemetry_store` | What the panel shows the merchant |
| `paypercut_telemetry_start_lock` | `paypercut_telemetry_store` | `INSERT IGNORE` on the `UNIQUE (name, id_shop)` key |
| `paypercut_telemetry_flush_lock` | `paypercut_telemetry_store` | Same |

Every row is keyed on `(name, id_shop)` and every read filters on the shop, so
two shops on a multistore install cannot trample each other's token or queue —
the session record in `Configuration` is shop-scoped the same way.

Uninstalling calls `PaypercutTelemetrySession::end()` and then drops the table,
so no key survives — the sent log included.

## Budgets

| Constant | Value | Why |
|---|---|---|
| `MAX_QUEUE_EVENTS` | 200 | Anonymous storefront requests append here; unbounded growth is a denial of service against the store |
| `MAX_QUEUE_BYTES` | 65536 | The same bound in the dimension that actually hurts |
| `MAX_BATCH_BYTES` | 16384 | Well under the edge's 64 KiB body cap: the edge does not deduplicate |
| `MAX_BATCH_EVENTS` | 50 | The edge's own `MaxEventsPerBatch`, so we never provoke an avoidable 413 |
| `Event::MAX_ATTRS` | 16 | Half the edge's 32; the edge truncates in sorted key order, which takes the version fields first |
| `Event::MAX_TEXT_BYTES` | 256 | The edge's `MaxStringLen`, measured in bytes |
| `Event::MAX_STACK_FRAMES` | 8 | The edge's `MaxStackFrames` |
| `SESSION_MAX_SECONDS` | 3600 | With no revocation anywhere, this ceiling **is** the consent |
| `SentLog::MAX_ENTRIES` | 100 | A tail, not a transcript — the panel says so |

## Events

Lifecycle and environment:

| Event | When |
|---|---|
| `session.started` / `session.stopped` | Lifecycle. `session.started` carries the session id, the environment and the deadline — never who started it |
| `environment.snapshot` | Module, PrestaShop, PHP and theme versions; multistore and TLS flags |
| `environment.configuration` | How the module is configured; re-sent when settings are saved mid-session |
| `environment.plugins` | Active module names and versions, chunked |
| `php.fatal` | A fatal that ended the request, with the file that died |

Checkout:

| Event | `error.code` | Notes |
|---|---|---|
| `checkout.hosted.redirected` | — | Shopper sent to the hosted page |
| `checkout.hosted.create_failed` | `http_<status>` · `session_create` | Session creation was refused or threw |
| `checkout.hosted.redirect_missing` | `redirect_absent` | The request succeeded but the response had no URL |
| `checkout.hosted.order_created` | — | Order written after a hosted payment |
| `checkout.embedded.session_created` | — | The browser got a session |
| `checkout.embedded.create_failed` | `invalid_cart` · `no_session_id` · `http_<status>` · `session_create` | |
| `checkout.embedded.order_created` | — | Order written after an embedded payment |
| `checkout.return.pending` | — | The shopper is back but the session is still open |
| `checkout.return.duplicate` | — | The shopper returned to an order that already existed |
| `checkout.return.unverifiable` | `no_session_meta` · `no_payment_status` · `lookup_failed` · `http_<status>` | The return could not be checked, so no order was written |
| `checkout.webhook.order_created` | — | The `checkout.completed` fallback wrote the order |
| `checkout.order_missing` | `order_not_found` | A completed checkout named a cart that no longer exists |

> PrestaShop only writes the order once the payment is verified, so every event
> before the return correlates by cart (`order_ref: cart_<id>`) rather than by
> order reference. There is no Blocks-style second checkout implementation, so
> the reference's `checkout.blocks.*` group has no counterpart here.

Payment and order:

| Event | `error.code` | Notes |
|---|---|---|
| `payment.succeeded` | — | Paypercut reported paid, with whether the order moved |
| `payment.failed` | `expired` · `<failure_status>` | The session expired, or a delivery reported a decline |
| `webhook.order_updated` | — | An order was updated from a delivery |
| `order.marked_paid` / `order.marked_failed` | — | An order status actually changed, with `from_status` and `to_status` |
| `order.confirmation_skipped` | — | A guard declined to confirm, with `reason` |
| `order.status_unhandled` | `unknown_payment_status` | A status this module has no rule for |

Webhooks:

| Event | `error.code` | Notes |
|---|---|---|
| `webhook.received` | — | A delivery arrived, duplicate or not |
| `webhook.rejected` | `empty_body` · `missing_signature` · `invalid_signature` | **The single most useful event a session carries.** A merchant whose orders never leave "pending" is almost always looking at one of these, and none of it is visible from Paypercut's side |
| `webhook.unresolved` | `order_not_found` | A delivery that matched no order here |
| `webhook.skipped` | — | A delivery type this module does not handle, or signature verification skipped because no secret is stored |
| `webhook.payload_invalid` | `empty_or_unparsable` | |
| `webhook.error` | `http_500` | Processing threw |
| `webhook.registered` / `webhook.registration_failed` | `rejected` | Webhook setup from the settings page |
| `webhook.deleted` / `webhook.delete_failed` | `rejected` | |

Refunds:

| Event | `error.code` | Notes |
|---|---|---|
| `refund.succeeded` | — | With `is_partial` and `has_reason` |
| `refund.rejected` | `invalid_amount` · `missing_payment_intent` | The refund never left this store |
| `refund.failed` | `http_<status>` · `transport` · `reported_failed` | |

> `has_reason` is a boolean. The refund reason is merchant-authored free text
> and is on the "not shared" list.

Setup and administration:

| Event | `error.code` | Notes |
|---|---|---|
| `connection.validated` | — | Settings saved with an API key, with environment and key mode |
| `connection.tested` | `credentials_rejected` | Test Connection ran; `ok` either way |
| `connection.webhook_registration_failed` | `already_exists` | Onboarding-time webhook setup |
| `payment_domain.registered` / `payment_domain.registration_failed` | `rejected` | |
| `settings.webhooks_unreadable` | `lookup_failed` | The settings page could not read the webhook |

API transport:

| Event | `error.code` | Notes |
|---|---|---|
| `api.request_failed` | `http_<status>` · `connect` · `transport` | Carries `api_context` and `duration_ms`, never `error.message` |
| `api.request_slow` | — | A call that succeeded but took ≥ 3000 ms |
| `api.response_unparsable` | `decode_failed` | Byte count only — never the body |

`api_context` is the caller's fixed phrase (`checkout_create`, `refund_create`,
`webhook_list`, …), never the path: a path carries ids and the merchant host in
its query string.

## What never goes on the wire

Card data (Luhn-screened on every value), credentials of any kind, refund reason
text, customer names, email addresses, billing or shipping addresses, order
totals, line items, absolute filesystem paths, the employee id of whoever
started the session, and upstream API prose.

Two controls, in this order:

1. **Named constructors are the boundary.** There is no generic field-bag
   constructor; the two snapshot constructors iterate their *own* declared
   schema and read keys out of the caller's array, never the reverse.
2. **The deny assertion is the safety net.** `PaypercutTelemetryQueue::append()`
   screens the **whole envelope** as it will be serialised — correlation fields
   included, two levels deep — for denied field names, denied value shapes, a
   Luhn-valid PAN, and the store's actual credentials, whole or clipped by the
   byte clamp. It **drops the whole event** rather than redacting a field: a
   field that trips it means the event was assembled wrongly, so the rest of it
   cannot be trusted either. The audit line records the event name only. Screen
   the envelope, never a named subset — `about()` writes top-level siblings of
   `attrs` from upstream webhook JSON.

`PaypercutTelemetrySession::credentials()` must enumerate every
credential-bearing setting: comparing a value against the real secret is the
only screen that catches a format nobody anticipated. **A future gateway adding
its own credential setting silently weakens it** — add the new key there.

Upstream prose is dropped wholesale: no named constructor copies a
`Throwable::getMessage()` onto the wire. The platform quotes submitted input
back inside it (a rejected key arrives in the message), and
`PrestaShopDatabaseException` inlines the failing SQL and the database
`user@host` whenever `_PS_DEBUG_SQL_` is on — the state a store under a debug
session is in. `error.type`, `error.stack`, `origin`, `api_code`, `api_param`
and `trace_id` carry the diagnosis instead. A message this module authored
itself, passed to `because()`, stays.

## Structural blind spots

1. **A store that has never connected cannot start a session** — the token is
   minted from the store's API key, so first-time onboarding failures are
   invisible by construction.
2. **A credential or environment change ends the session mid-request**
   (`connection_changed`), so anything after that point is not recorded.
3. **A card refused inside the checkout iframe is not visible.** Closing that
   needs browser-side telemetry.
4. **This module does not reject unsigned deliveries.** When no webhook secret
   is stored it processes the delivery and emits `webhook.skipped` with
   `reason: webhook_secret_not_configured` rather than `webhook.rejected`.
5. **Only `merchant_stopped` produces a `session.stopped` wire event.** Expiry,
   a re-key and an environment change are recorded locally, in the session
   record and the PrestaShop log, but the edge never hears about them — the
   token is gone by then.
6. **There is no `payment.closed_unpaid`.** The reference implementation splits
   an expired session from a `complete` + `unpaid` one, because the latter is a
   successful authorisation awaiting manual capture rather than a decline. This
   module has no code path that reaches that state, so reporting it would be
   inventing a case.

## Translations

The debug-session copy is English-only. `translations/` carries hand-written
translations for 13 languages and PrestaShop falls back to the English source
for anything absent, so the panel works everywhere — but the consent disclosure
is legal-adjacent copy and should be translated by a person, not generated.
Add the strings to `tools/generate_translations.php` when those translations
exist.

## Pointing a store at dev or stage

Set **Environment** on the module's API Configuration tab. Both the payment API
and the telemetry edge follow it. `PAYPERCUT_TELEMETRY_BASE_URI` may be defined
in `config/defines.inc.php` to retarget the edge alone; it applies on `dev` and
`stage` only. On `production` it cannot retarget a live store, and on an
unrecognised environment it must not manufacture an edge the mint host would not
follow — that pairs a production token with a dev edge, which 401s and burns the
merchant's consent with no re-mint.

## Tests

`php tests/run.php` lints every PHP file and runs the suites, with no Composer
dependency. `tests/` is excluded from the release zip and both entry points
refuse to run outside the CLI, so a store served from a checkout does not expose
them. `EnvironmentTest` pins the host pairing and the allow-list,
`DenyAssertionTest` pins the privacy contract, `DisclosureTest` fails the build
if the panel copy and `README.md` drift apart, and `EventCatalogTest` fails if a
call site emits an event this page does not document.
