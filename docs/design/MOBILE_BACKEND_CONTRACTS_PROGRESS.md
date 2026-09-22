# OpesInsure Mobile — Backend Contracts Progress

Tracks Laravel-side implementation of the API contracts the Expo mobile app (`mobile app/`) expects, per `mobile app/BATCH_1_CLAUDE_HANDOFF.md` and each patch bundle's `CLAUDE_MERGE_GUIDE.md` (v0.5.0 through v1.2.0). Separate from `INTEGRATION_GAP_CLOSURE_PROGRESS.md`, which tracks the external-partner integration plan — this is the mobile app's own backend, not a partner-facing API. A full contract inventory (every route across all 8 patches, organized by version) was compiled during Batch 1 but is not reproduced here — it lives in that batch's research and should be re-read from the patch bundles directly before starting a new area, not trusted as a cached list.

## Batch 4 — Quotes (list/resume/cancel): **Done** (2026-09-22)

The mobile-app patch guide's entire spec for this area is one table row — `GET /mobile/quotes, GET/DELETE /mobile/quotes/{id}, POST /mobile/quotes/{id}/resume` — with no JSON shapes, no "resume" semantics, and no "expired" business rule defined anywhere in any `CLAUDE_MERGE_GUIDE.md`. A research pass confirmed the gap before building: `Quote`/`QuoteOffer` already carry real `expires_at`/`valid_until` timestamps (used today only to cap offer validity and to lazily reject an accept-after-expiry), but no `EXPIRED` status is ever set anywhere in the codebase — the Filament admin UI's `EXPIRED`/`CANCELLED` badge colors are dead/aspirational code with nothing behind them — and no scheduled job enforces quote expiry the way `policies:notify-expiry` does for policies. This batch had to design the contract from scratch rather than implement a spec.

### Files changed / created

**Models**: `App\Models\Quote` — added a computed `is_expired` accessor (`expires_at !== null && expires_at->isPast()`), appended to the model's JSON so every consumer (mobile and staff) gets a server-computed, clock-skew-safe answer instead of each caller diffing `expires_at` itself. Purely additive; no existing field changed.

**Services**: `App\Application\Quotes\QuoteService` — added `cancel(Quote $quote, ?User $actor): Quote`, following the exact pattern of the existing `accept()` method (guard on status, wrapped in `DB::transaction`, writes both `AuditWriter` and `OutboxWriter` events). Only callable from `SUBMITTED|REFERRED|OFFERED`; rejects with a `ValidationException` otherwise (already-`ACCEPTED` or already-`CANCELLED`). `App\Application\Quotes\MobileQuoteService` (new) — list/show/resume/cancel, ownership-scoped via `PartyResolver` and the direct `party_id` column (like `MobileWalletService`, not the join pattern `MobilePaymentService` needs), reusing the same 403-vs-404 `owned()`/`ownedQuery()` idiom established in Batch 3.

**Controllers**: `App\Interfaces\Http\Controllers\Api\V1\Quotes\MobileQuoteController` — thin, `index`/`show`/`resume`/`destroy`.

**Routes** (`routes/api.php`, inside the existing `['auth:api','tenant','json.api']` group): `GET mobile/quotes`, `GET mobile/quotes/{quote}`, `DELETE mobile/quotes/{quote}`, `POST mobile/quotes/{quote}/resume` (throttled).

**i18n**: `resources/lang/{en,fr}/wave2.php` — `quote_not_cancellable` (domain-level, thrown by `QuoteService::cancel()`). `resources/lang/{en,fr}/wave12.php` — `quote_expired`, `quote_not_resumable` (mobile-level, thrown by `MobileQuoteService::resume()`).

**Tests** (+11, `tests/Feature/Wave12/MobileQuoteTest.php`, all passing), plus two new shared fixture builders in `Concerns/mobile_customer_helpers.php` (`makeMobileTestQuote`, `makeMobileTestQuoteOffer`) and three new keys returned from `makeMobileCustomerFixture()` (`product`, `tariff`, `quote` — all additive, no existing test broke from the wider return array).

### API contracts implemented (designed, not spec'd — see gaps below)

- `GET /mobile/quotes` — paginated, newest-first, scoped to the caller's own `party_id`. Same `{"data": {"data": [...], ...paginator}}` nesting as `GET /mobile/wallet` and `GET /mobile/payments`, for client-side consistency.
- `GET /mobile/quotes/{quote}` — `{quote, offers}`, same envelope shape as the existing staff `QuoteLifecycleController::show()`, with `quote.is_expired` now present on every response. 403 (not 404) for a quote that exists in-tenant but belongs to someone else.
- `POST /mobile/quotes/{quote}/resume` — same `{quote, offers}` envelope, but only succeeds when the quote is still in `SUBMITTED|REFERRED|OFFERED` **and** not expired; 422 with `quote_not_resumable` if already `ACCEPTED`/`CANCELLED`, 422 with the more specific `quote_expired` if the status is still active but `expires_at` has passed — deliberately checked in that order so an already-converted quote never gets the misleading "start a new one" message.
- `DELETE /mobile/quotes/{quote}` — implemented as a **soft cancel** (`status → CANCELLED`), not a row deletion: no cancel/withdraw concept existed anywhere in the domain before this batch, and an actual `DELETE FROM quotes` would both break the `quote_offers` FK chain and destroy the audit/outbox trail every other quote-lifecycle action already produces. Only allowed from `SUBMITTED|REFERRED|OFFERED`; 422 if already `ACCEPTED` (a quote that's already become a proposal can't be un-accepted through this endpoint) or already `CANCELLED`.

### Security controls implemented

1. **Same ownership-scoped 403-vs-404 pattern as Batch 3** — `party_id` is a direct column on `Quote` (unlike `PaymentIntentRecord`), so `MobileQuoteService::ownedQuery()` filters directly rather than through a `whereHas` join; an unresolvable `Party` still returns the guaranteed-empty `whereRaw('1 = 0')` guard, not a sentinel-value comparison.
2. **Server-authoritative expiry, not client-trusted** — the handoff doc's stated enforcement bullet is *"preserve server-authoritative quote expiry"*; since no cron flips a status, this batch computes `is_expired` from `expires_at` on every read/resume rather than trusting any stored status value, so a stale `OFFERED` quote past its `expires_at` cannot be resumed regardless of what the client believes.
3. **Cancel is layered validation, not duplicated business logic** — `MobileQuoteService::cancel()` only checks ownership, then delegates the actual state-transition guard to the same `QuoteService::cancel()` a staff caller would eventually use too, mirroring how `MobilePaymentService::requestRefund()` reuses `FinancialCaseService` and `MobileDeliveryService::confirm()` reuses `FulfilmentStateMachine` rather than re-implementing domain rules per channel.

### Live verification

`MobileQuoteTest.php` (11 tests), the full `tests/Feature/Wave12/` directory (56 tests), `tests/Feature/Wave2/` (to confirm the `Quote`/`QuoteService` changes don't regress existing quote-governance tests), and the full project suite were all run against the real Postgres test database. Full suite: **251 passed, 3 failed** — same pre-existing, unrelated baseline as Batches 2–3 (`PartyIdentityTest`, `CertificateSecurityTest`, `RenewalAttributionTest`), +11 from this batch, no regressions. Config/route/application caches cleared afterward.

### Remaining gaps (honest, not glossed over)

- **`RATED` is a real status value only in test fixtures, never in production code.** The audit that preceded this batch found `Quote::create(['status' => 'RATED', ...])` used directly in three separate test-fixture files across earlier waves, bypassing `QuoteService` entirely — the real `rate()` method only ever sets `OFFERED` or `REFERRED`. This batch's `MobileQuoteService` treats `RATED` as **not** resumable (it's outside `RESUMABLE_STATUSES = ['SUBMITTED','REFERRED','OFFERED']`), which is defensively correct but was a judgment call, not a spec'd decision — worth confirming if `RATED` was meant to be a real status the rating engine should be producing.
- **No proactive expiry enforcement exists anywhere** — this batch computes `is_expired` reactively on read, but nothing marks a `Quote`/`QuoteOffer` row `EXPIRED` in the database the way `policies:notify-expiry` does for policies, and no notification is sent to a customer whose quote is about to lapse. A future `quotes:expire-stale` scheduled command (mirroring `PollPendingMobileMoneyPayments`'s structure) would be a natural follow-up if the mobile app wants push-style "your quote is expiring soon" behavior rather than pull-only.
- **`DELETE` is irreversible from the mobile side** — once cancelled, nothing in this batch offers an "un-cancel" or "restart from this quote's risk facts" path; a customer who cancels by mistake has to submit an entirely new quote. This matches how every other terminal state in this codebase works (no "un-accept", no "un-fail" a payment), but is worth flagging since the handoff doc gives no guidance either way.
- **The `is_expired` computed attribute is now appended to every `Quote` JSON response app-wide**, including the pre-existing staff `QuoteLifecycleController` and the Filament admin resource — a deliberate, low-risk choice (the concept belongs on the model, not duplicated per-caller) but it does mean this mobile-focused batch changed a shared model's serialized shape, not just added new mobile-only surface area.

### Rollback and operational impact

No new migrations — everything is additive at the application layer (`Quote::is_expired` accessor, `QuoteService::cancel()`, new mobile service/controller/routes). Safe to roll back by removing the new routes and the `cancel()` method; the `is_expired` accessor is inert until something reads it, so leaving it in place is harmless even if the rest of this batch is reverted.

## Batch 3 — Payments, Wallet, and Delivery: **Done** (2026-09-22)

With the `PartyResolver` FK-first identity link in place from Batch 2, this batch built the first three customer-facing contract areas that depend on it directly: payment history/retry/receipt/refund-request, the policy wallet (list/show/certificate), and delivery (show/address-update/OTP-confirm) — all newly ownership-scoped to the calling customer's `Party` rather than any staff permission.

### Files changed / created

**Services**: `App\Application\Payments\MobilePaymentService` (list/show/retry/receipt/requestRefund — the refund path reuses the existing staff-facing `FinancialCaseService::requestRefund()`, which has no permission check baked into it, gated here by ownership instead of the `refund.request` permission). `App\Application\Policies\MobileWalletService` (wallet list/show/certificate). `App\Application\Logistics\MobileDeliveryService` (show/updateAddress/confirm, confirm reusing the existing `FulfilmentStateMachine`).

**Controllers**: `App\Interfaces\Http\Controllers\Api\V1\Payments\MobilePaymentController`, `App\Interfaces\Http\Controllers\Api\V1\Policies\MobileWalletController`, `App\Interfaces\Http\Controllers\Api\V1\Logistics\MobileDeliveryController` — all thin, resolving `TenantContext` inline.

**Models**: `App\Models\Policy` — added a `fulfilmentOrder(): HasOne` relation (the model had no way to reach its delivery order before this).

**Routes** (`routes/api.php`, all inside the existing `['auth:api','tenant','json.api']` group): `GET/POST mobile/payments`, `mobile/payments/{payment}`, `mobile/payments/{payment}/retry` (throttled), `mobile/payments/{payment}/receipt`, `mobile/payments/{payment}/refunds`; `GET mobile/wallet`, `mobile/wallet/policies/{policy}`, `GET policies/{policy}/certificate` (deliberately **not** nested under `mobile/wallet/` — matches the handoff spec's exact path); `GET mobile/deliveries/{delivery}`, `PUT mobile/deliveries/{delivery}/address`, `POST mobile/deliveries/{delivery}/confirm` (throttled).

**i18n**: `resources/lang/{en,fr}/wave12.php` — added `payment_not_retryable`, `delivery_otp_invalid`, `delivery_address_locked`.

**Tests** (+18, all in `tests/Feature/Wave12`, all passing): `MobilePaymentTest` (7), `MobileWalletTest` (4), `MobileDeliveryTest` (7), plus a shared `Concerns/mobile_customer_helpers.php` fixture file (customer+tenant+proposal fixture, plus payment/policy/delivery/certificate-template builders).

### API contracts implemented

- `GET /mobile/payments` — paginated, newest-first, scoped to the caller's own proposals via `party_id`.
- `GET /mobile/payments/{payment}` — 200 if owned, **403** (not 404) if it exists in-tenant but belongs to someone else, 404 if it doesn't exist at all.
- `POST /mobile/payments/{payment}/retry` — re-initiates a `FAILED` intent via the existing `PaymentInitiationService`; 422 if the intent isn't in a retryable state.
- `GET /mobile/payments/{payment}/receipt` — a JSON summary (id, reference, provider, amount, currency, status, payer phone, proposal id, requested/confirmed timestamps) — **not a PDF**, see gaps below.
- `POST /mobile/payments/{payment}/refunds` — lets the owning customer request a refund on their own payment with **no `refund.request` permission required**, gated purely by ownership; returns 201 (or 200 on idempotent replay, matching `FinancialCaseService`'s existing idempotency behavior).
- `GET /mobile/wallet` — paginated list of the caller's own issued policies.
- `GET /mobile/wallet/policies/{policy}` — a single owned policy with `carrier`, `certificates`, and `fulfilmentOrder` eager-loaded; 403 for someone else's.
- `GET /policies/{policy}/certificate` — latest `VALID` certificate's metadata (id, serial number, status, issued_at); 404 if none is valid yet.
- `GET /mobile/deliveries/{delivery}` — a single owned delivery with `courier` loaded; 403 for someone else's.
- `PUT /mobile/deliveries/{delivery}/address` — allowed only while the order is `CREATED` or `READY_FOR_PICKUP`; 422 (`delivery_address_locked`) once it's moved past that, writes an `ADDRESS_UPDATED` row to `fulfilment_events` for audit.
- `POST /mobile/deliveries/{delivery}/confirm` — customer-side delivery confirmation: validates the OTP against `delivery_otp_hash`, asserts the `IN_TRANSIT → DELIVERED` transition via the existing `FulfilmentStateMachine`, sets `delivered_at`/`proof_of_delivery`, and writes a `CUSTOMER_CONFIRMED` row to `fulfilment_events`; 422 on a wrong OTP.

### Security controls implemented

1. **Ownership-scoped, not permission-scoped** — every endpoint in this batch resolves the caller's `Party` via `PartyResolver` and filters at the query level (`whereHas('proposal', ...)`, `where('party_id', ...)`, `whereHas('policy', ...)`), the same 403-vs-404 pattern established in Batch 1's `MobilePurchaseStatusService`: exists-but-not-yours is distinguished from doesn't-exist-at-all, so a customer probing IDs can't learn whether a record exists in another tenant/customer's data.
2. **A `whereRaw('1 = 0')` null-safety guard**, not a sentinel-value comparison — an unresolvable `Party` (no FK, no phone match) returns a guaranteed-empty query rather than comparing a UUID column against a placeholder string, which Postgres rejects outright (`invalid input syntax for type uuid`). Caught by a live Postgres error during development, not by inspection.
3. **The refund-request endpoint deliberately bypasses the staff `refund.request` permission** by calling `FinancialCaseService::requestRefund()` directly rather than through the staff-facing controller/route — confirmed safe because that service method has no permission check of its own (permission enforcement lived only in the staff route's middleware), so reusing it here is not a permission bypass of anything the service itself guarantees, only a deliberate choice to gate this specific customer-facing path by ownership instead.
4. **Delivery OTP confirmation only compares hashes** (`Hash::check($otp, $order->delivery_otp_hash)`) — the plaintext OTP is never read back out or exposed by this batch's endpoints (consistent with Batch/Wave 8's existing delivery-OTP-notification design, which already stopped returning the plaintext OTP in any response).

### Live verification

All three new test files, the full `tests/Feature/Wave12/` directory (45 tests), and the full project suite were run against the real Postgres test database. Full suite: **240 passed, 3 failed** — the same pre-existing, unrelated baseline as Batch 2 (`PartyIdentityTest`, `CertificateSecurityTest`, `RenewalAttributionTest`), +18 from this batch's new tests, no regressions. Config/route/application caches cleared afterward.

### Remaining gaps (honest, not glossed over)

- **The payment receipt is JSON, not a PDF or any downloadable document** — no PDF-generation library exists anywhere in this app, so `GET /mobile/payments/{payment}/receipt` returns structured data for the mobile app to render client-side, not a document the handoff spec's wording might imply.
- **The certificate endpoint returns metadata only, never a signed document URL or the sticker image itself** — no document-storage/signed-URL adapter exists anywhere in this codebase yet (a real gap the earlier 7-agent audit also flagged for the Documents contract area generally); `serial_number`/`status`/`issued_at` is all a client can get today.
- **Updating the delivery address has no side effect on the courier or fulfilment_events beyond the audit row** — no notification is sent to the assigned courier (if one is already assigned) when a customer changes the address; `updateAddress()` only blocks the change once the order has moved past `CREATED`/`READY_FOR_PICKUP`, on the assumption a courier already in motion shouldn't have the target silently change under them, but nothing today would notify anyone besides the audit trail even for still-early-stage orders.
- **`MobilePaymentService::retry()` re-initiates through the same `PaymentInitiationService` used everywhere else** — it does not add any mobile-specific retry-count limiting or cooldown beyond the route's `throttle:10,1` middleware; a customer could still retry a failed payment repeatedly across separate throttle windows.

### Rollback and operational impact

No new migrations this batch — everything is additive at the application layer (new services/controllers/routes) plus one new model relation (`Policy::fulfilmentOrder()`, purely additive, no schema change). Safe to roll back by removing the new routes; nothing else in the app depends on any of this batch's new code.

## Batch 2 — User→Party/Partner identity link: **Done** (2026-09-22)

A full audit sweep across every remaining contract area (Customer Core, Customer Lifecycle, Claims Completion, Agent Mode, Broker/Carrier Operations, Offline Sync, Runtime Bootstrap/Step-up/Telemetry — 7 parallel research passes) independently hit the same wall five times over: nothing links a `User` (login identity) to a `Party` (policyholder) or, transitively, a `Partner` (broker/agent/carrier org). `PartyResolver`'s phone-number-matching heuristic (built in Batch 1 for the purchase-status endpoint) was the only workaround, and its own docblock already called it a stopgap. Since every "show me my own X" endpoint in every remaining area depends on resolving this identity, fixing it once now was chosen over rebuilding ownership checks against the heuristic repeatedly across five-plus future batches.

### Files changed / created

**Migration**: `2026_09_22_000007_add_party_id_to_users.php` — adds `users.party_id` (nullable FK to `parties`, `nullOnDelete`). Nullable on purpose: internal staff users have no `Party`, and plenty of `Party` rows (third parties, non-app customers) have no `User`.

**Models**: `App\Models\User` — added `party_id` to `$fillable` and a `party(): BelongsTo` relation.

**Services**: `App\Application\Identity\PartyResolver` — rewritten. `forUser()` now checks `users.party_id` first (the real FK, one query via the relation); only when that's unset does it fall back to the old phone-match, and when the fallback finds something it **persists it back onto the user row** — so the heuristic runs at most once per user, not on every request, and existing callers (`MobileAuthService`, `MobilePurchaseStatusService`) needed no changes to benefit. Added `partnerForUser(User $user): ?Partner`, resolving an individual's own `Partner` org (broker/agent/carrier) via `Partner.party_id` — needed by essentially every future Agent Mode and Broker/Carrier endpoint, none of which existed a way to answer "which Partner am I" before this.

**Console**: `App\Console\Commands\BackfillUserPartyLinks` (`identity:backfill-party-links`) — a one-time operational tool, not scheduled, since the resolver already self-heals lazily; exists only to eagerly backfill existing users right after the migration lands instead of waiting for their next login.

**Tests** (+6, `tests/Feature/Wave12/PartyResolverTest.php`, all passing): fast-path FK resolution, phone-fallback-with-self-heal (and confirms the *second* call takes the fast path), no-match returns null cleanly, `partnerForUser` for an agent and for an ordinary customer (no Partner row), and the backfill command's selective linking.

### Live verification

Ran `identity:backfill-party-links` against the real local database (not just the test DB): `Checked: 9. Linked: 0.` — correctly checked all 9 demo accounts (including the `BROKER_STAFF`/`AGENT` ones added in a previous session) and linked none, because none of them are backed by an actual `Party`/`PartyContact` row — they're internal staff/demo seed data, not customers, which is the correct outcome, not a failure. Full project suite re-run: **222 passed, 3 failed** — same pre-existing, unrelated baseline (`PartyIdentityTest`, `CertificateSecurityTest`, `RenewalAttributionTest`), +6 from this batch's new tests, no regressions.

### Remaining gaps (honest, not glossed over)

- **Nothing yet sets `users.party_id` at account-creation time.** New customers created via `CustomerController::store()`/`PartyService::create()` still don't get linked to a `User` (they're often created by staff on the customer's behalf, before that customer ever logs in) — the link is only populated lazily (on first mobile login that matches an existing `PartyContact` phone) or via the one-time backfill command. A future "customer self-registers via the app" or "agent registers a client who then logs in" flow should set `party_id` explicitly at creation time rather than relying on the phone-match fallback ever firing.
- **The phone-match fallback is still exactly as heuristic as before** — this batch made it run at most once per user and persist the result, but didn't change *how* it resolves a match. A phone number reused across two different `Party` rows (shouldn't happen given `party_contacts`' own unique constraint, but not impossible if that constraint is ever relaxed) would still resolve ambiguously.
- **`partnerForUser()` assumes at most one `Partner` row per `Party`** — true today (nothing in the schema prevents multiple, but nothing in the codebase creates more than one either), not enforced by a unique constraint.

### Rollback and operational impact

Migration has a working `down()`. Purely additive — no existing column changed type or behavior, and every existing caller of `PartyResolver` (`MobileAuthService`, `MobilePurchaseStatusService`) continues to work unchanged, now just faster and more reliable after the first resolve for a given user. Safe to roll back: `party_id` is nullable and nothing else in the schema depends on it yet.

## Batch 1 — Mobile authentication adapter and purchase-status adapter: **Done** (2026-09-22)

The two pieces `BATCH_1_CLAUDE_HANDOFF.md` calls out as required before anything else in the app can work: without login, no other mobile screen can call an authenticated endpoint at all; without the purchase-status adapter, a customer who pays has no server-authoritative way to know whether they actually own a policy yet.

### Files changed / created

**Migrations**: `2026_09_22_000005_fix_oauth_user_id_uuid_compatibility.php` — widens `oauth_access_tokens.user_id`, `oauth_auth_codes.user_id`, `oauth_device_codes.user_id` from Passport's stock `bigint` to `uuid`, matching this app's UUID user primary keys (see Security controls below). `2026_09_22_000006_create_mobile_refresh_tokens.php` — new table for refresh-token rotation with replay-family revocation (Passport 13 has no password grant and personal access tokens carry no refresh mechanism of their own).

**Models**: `App\Models\VerificationChallenge` (new model for the `verification_challenges` table, which already existed — created in Batch 1 of the *original* integration plan, migration `2026_09_20_000002_...`, but had never been given a model or wired to anything). `App\Models\MobileRefreshToken` (new).

**Services**: `App\Application\Identity\MobileAuthService` (OTP request/verify, session bootstrap, refresh rotation, logout — see contracts below). `App\Application\Identity\PartyResolver` (shared phone-number heuristic for resolving a `User` to a `Party`/`TenantCustomer`, used by both `MobileAuthService` and the new `MobilePurchaseStatusService` — see the honest gap noted below). `App\Application\Payments\MobilePurchaseStatusService`.

**Controllers**: `App\Interfaces\Http\Controllers\Api\V1\Identity\MobileAuthController`, `App\Interfaces\Http\Controllers\Api\V1\Payments\MobilePurchaseController`.

**Routes** (`routes/api.php`): `POST v1/auth/mobile/otp/request`, `POST v1/auth/mobile/otp/verify`, `POST v1/auth/mobile/refresh` (all public — that's the point); `GET v1/auth/mobile/session`, `POST v1/auth/mobile/logout` (authenticated but deliberately **not** tenant-scoped, since discovering workspaces has to work before one is picked); `GET v1/mobile/purchases/{proposal}/status` (authenticated and tenant-scoped, alongside the existing `payments/*` routes).

**Seeders**: `MobileOAuthClientSeeder` (creates the one "OpesInsure Mobile" Passport personal-access-grant client every environment needs — reference data, wired into `DatabaseSeeder::run()` unconditionally like the other reference seeders).

**i18n**: `resources/lang/{en,fr}/wave12.php` — `otp_invalid`, `otp_rate_limited`, `refresh_invalid`.

**Tests** (+21, all in `tests/Feature/Wave12`, all passing): `MobileAuthFlowTest` (9), `MobileRefreshAndLogoutTest` (5), `MobilePurchaseStatusTest` (7), plus `Concerns/mobile_auth_helpers.php`.

### API contracts implemented

- `POST /auth/mobile/otp/request` — `{phone_e164}` → `{challenge_id, delivery_status, expires_in}`. Identical response shape/timing whether or not the phone belongs to a real user; only a real user actually gets an SMS.
- `POST /auth/mobile/otp/verify` — `{challenge_id, code, device_fingerprint, device_name?, platform?}` → `{access_token, refresh_token, expires_in, user, workspaces[]}`. Each `workspaces[]` entry has `membership_id, tenant_id, tenant_name, tenant_type, role_code, permissions[], customer_id` exactly as the handoff spec requires.
- `GET /auth/mobile/session` — same `{user, workspaces[]}` shape, no new tokens issued.
- `POST /auth/mobile/refresh` — `{refresh_token}` → new `{access_token, refresh_token, expires_in}`. Single-use; rotates on every call.
- `POST /auth/mobile/logout` — revokes the current access token and (if a refresh token is supplied) that device's whole refresh-token family. Other devices/sessions for the same user are untouched.
- `GET /mobile/purchases/{proposal}/status` — `{status, payment, policy}`, `status` ∈ `PAYMENT_PENDING|PAYMENT_PROCESSING|PAYMENT_FAILED|ISSUANCE_PENDING|POLICY_ISSUED`; `POLICY_ISSUED` only when a real, persisted `Policy` row exists — a `SUCCEEDED` payment alone maps to `ISSUANCE_PENDING`, never straight to issued.

### Security controls implemented

1. **A pre-existing, latent schema bug found and fixed, not something this batch introduced**: Passport's stock migrations declare `oauth_access_tokens.user_id` (and the two sibling `_codes` tables) as `bigint`, assuming Laravel's classic auto-increment user IDs — but this app's `users.id` is a UUID. This was never hit before because the only tokens ever issued were client-credentials tokens for integration partners (external-integration Batch 1), which always have a `null` user_id. Mobile personal-access tokens are the first thing in this app that binds a token to a real human `User`, and would have failed outright (`invalid input syntax for type bigint`) without the fix. **This also fixed a previously-failing, seemingly unrelated pre-existing test** (`Tests\Feature\Wave0\MembershipRevocationTest`) — it was failing for exactly this reason (a `$user->tokens()->delete()` call hitting the same bigint/UUID mismatch), confirmed by the full suite's known-failure count dropping from 4 to 3 after this fix, with no other change to that test or the code it exercises.
2. **A real, serious bug found via live testing, not by inspection**: both `verifyOtp()` and `refresh()` originally did their work — including `attempts`-incrementing on a wrong OTP code, and revoking an entire refresh-token family on replay detection — *inside* a single `DB::transaction()` closure that then threw a `ValidationException` to reject the request. Laravel rolls back everything in a transaction when its closure throws, which silently discarded both security-critical writes on every single rejection: OTP attempts never actually accumulated (unlimited guessing, the `max_attempts` lockout never engaged), and a detected refresh-token replay never actually revoked anything. Caught by a test asserting `attempts === 1` after one wrong code and finding `0`, and a second test expecting a locked-out challenge that instead accepted the correct code on attempt six. Fixed by splitting each method into two transactions: the state-mutating check runs to completion and returns a plain outcome value (never throws), and the `ValidationException` is thrown from *outside* that transaction based on the outcome.
3. **Hashed, expiring, attempt-limited OTPs** via the (previously unused) `verification_challenges` table — `code_hash` via `Hash::make()`, `max_attempts` default 5, `expires_at` 5 minutes, `request_ip_hash`/`destination_hash` stored hashed, never the raw phone/code.
4. **Enumeration-safe by construction, not by convention**: `requestOtp()` always creates a challenge row and always returns the same response shape, regardless of whether the phone matches a real user — only the SMS-send step is conditional, and a live-verified test asserts the two response bodies have identical keys/values for a known vs. unknown number.
5. **Refresh-token rotation with replay-family revocation**: each refresh token is single-use (`mobile_refresh_tokens.revoked_at` set on use); presenting an already-used one doesn't just get rejected — it revokes every still-active token in that `family_id`, on the reasoning that reuse of a rotated token is a theft signal, not a race. Live-verified: a legitimate client's freshly-rotated token is confirmed dead too when an attacker replays the stolen predecessor.
6. **IP and phone-number rate limiting** on OTP requests (`RateLimiter::attempt`, keyed on hashed phone/IP, matching the existing programmatic pattern from `AuthenticateIntegrationClient`), plus route-level `throttle:` middleware on every mobile auth endpoint.
7. **No OTP or token ever logged** — `sendOtpSms()` swallows provider failures via `report()` (ops visibility) without ever including the code in the report, and nothing in this batch logs `access_token`/`refresh_token` values.
8. **Personal access tokens are short-lived** (`Passport::personalAccessTokensExpireIn(30 minutes)`, set globally in `AppServiceProvider` — the only personal-access-token consumer in this app today is mobile login, confirmed by the earlier audit, so this is safe as a global setting for now but will need revisiting if a second personal-access-token consumer is ever added).

### Bugs found and fixed this batch (all caught by live/automated execution, not code review)

1. The `DB::transaction()`-swallows-security-writes bug above (#2 in Security controls) — the single most important finding this batch.
2. The pre-existing `oauth_*.user_id` bigint/UUID mismatch (#1 above) — not introduced by this batch, but blocking for it and now fixed, with a welcome side effect on a previously-failing unrelated test.
3. A genuine Laravel *test-harness* artifact, not a production bug: `Auth::guard('api')` (`Laravel\Passport\Guards\TokenGuard`) caches its resolved user for the guard instance's lifetime, and `AuthManager` caches the guard instance for the whole test method (not per simulated HTTP call) — so a logout test that immediately re-checks the same token within one test method got a stale cached "still authenticated" result even though the DB's `revoked` flag was confirmed correct via direct query. A real request always gets a fresh PHP process/app bootstrap, so this can't happen in production. Fixed by calling `$this->app['auth']->forgetGuards()` between simulated requests in the affected test, not by changing any application code.

### Live verification (not just automated tests)

The Wave12 Feature tests hit real routes/middleware/controllers/services against a real Postgres test database (not mocks), and confirm the actual token returned by `otp/verify` authenticates a subsequent real request. Beyond the automated suite: hit `POST /api/v1/auth/mobile/otp/request` on the actual running local server (`opesinsure.test`, real Apache + real dev Postgres database, not the test DB) with a real demo account's phone number, confirmed a real `verification_challenges` row was created with the correct `user_id`, confirmed the response carried the real `X-RateLimit-*` headers from the route's `throttle:5,1` middleware, and confirmed (via the app log) that the Twilio send attempt failed gracefully without breaking the response — expected and correct, since no real Twilio credentials exist yet, matching the honest-until-configured pattern already established for MTN/Orange/Twilio in earlier batches. Demo data cleaned up afterward. Full project suite re-run: **216 passed, 3 failed** — down from the long-standing 4-failure baseline (`MembershipRevocationTest`, `PartyIdentityTest`, `CertificateSecurityTest`, `RenewalAttributionTest`); the drop to 3 is the welcome side effect of the `oauth_*` UUID fix, not a change to any test itself.

### Remaining gaps (honest, not glossed over)

- **No FK links a `User` (login identity) to a `Party`/`TenantCustomer` (insurance-domain policyholder) anywhere in this schema.** `PartyResolver` bridges this with a same-phone-number heuristic, used for both the mobile bootstrap's `customer_id` field and `MobilePurchaseStatusService`'s ownership check. This works for the common case but is not authoritative — a party without a matching `PartyContact` (or a `User` whose phone doesn't match how the customer was originally created) will not resolve. A proper `users.party_id` (or equivalent) FK would replace this outright; not built this batch since it's a real identity-modeling decision, not something to introduce silently as a side effect of the mobile work.
- **Self-registration via OTP alone is out of scope.** `requestOtp()`/`verifyOtp()` only work for a phone number that already matches an existing `User` row — there is no "OTP creates an account" path. The handoff doc doesn't describe one either (a separate `POST /public/accounts` registration endpoint already exists), but this is a scope decision worth confirming with the user if the mobile app's actual onboarding flow expects sign-up-by-phone.
- **Only 2 of the ~60+ routes** documented across the 8 patch bundles' `CLAUDE_MERGE_GUIDE.md` files are implemented. Everything else — KYC, insured assets/OCR, disclosure, payment history/receipts/refunds, wallet/delivery, quote history, secure documents, policy service requests, notifications, support/complaints, claims completion (incident/parties/evidence/inspection/repair/settlement/emergency), the entire agent-mode surface, the entire broker/carrier surface, the sync/offline-replay contract, runtime bootstrap, step-up authentication, telemetry, and device attestation — remains unimplemented. This batch deliberately stopped at the two pieces the handoff doc itself calls "required" before anything else can work.
- **Twilio SMS delivery for OTPs is unverified against a real Twilio account** — same honest caveat as the rest of this session's communications work; the code path is real and tested via `Http::fake()`, but no real SMS has ever been sent.
- **Step-up authentication** (named in v1.1.0's contract, retroactively required by refund/withdrawal/settlement-decision flows in earlier patches) does not exist yet — nothing in this batch built `PAYMENT_REFUND_REQUEST`/`COMMISSION_WITHDRAWAL`/`CLAIM_SETTLEMENT_DECISION` grants.

### Rollback and operational impact

Both new migrations have working `down()` methods. The `oauth_*` UUID-compatibility migration is safe to roll forward or back because all three tables are, in practice, empty of user-bound rows in every environment today (confirmed: the only tokens ever issued are client-credentials tokens with `user_id = null`) — there is no data to lose either direction. Nothing in this batch changes behavior for any existing route; the new mobile routes are entirely additive, and `Passport::personalAccessTokensExpireIn()` only affects personal-access tokens, of which mobile login is the only issuer today.
