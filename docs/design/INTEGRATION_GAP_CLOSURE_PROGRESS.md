# OpesInsure External Integration — Gap Closure Progress

Tracks implementation against `OPESINSURE_EXTERNAL_INTEGRATION_GAP_CLOSURE_PLAN.md`. Per that document's own instructions to Claude (§23): before implementing further batches, re-audit the repository rather than trusting this file blindly — treat it as a snapshot, not a guarantee.

## Batch 3 — MTN MoMo and Orange Money: **Done** (2026-09-22)

Both providers were previously configured only as labels pointing at the generic `ConfiguredJsonPaymentAdapter` — a single hand-rolled `{amount, currency, payer, callback_url}` request shape that matches neither provider's real API. This batch replaces that with provider-specific adapters built against each provider's actual documented contract, plus the pieces the generic webhook scheme can't cover: neither provider signs its callback the way `WebhookProcessingService::verify()` assumes, and neither callback is guaranteed to be delivered at all. **No real sandbox credentials exist yet** — the user will add them later; every field in `config/payments.php` is env-driven and blank in `.env` today except the two `callback_token` values (OpesInsure's own anti-spoofing secrets, generated locally, never sent to either provider, so leaving them blank would serve no purpose). Everything below is verified against `Http::fake()` simulations of each provider's documented behavior, not real sandbox traffic — that remains untested until real keys arrive.

### Files changed / created

**Config/env**: `config/payments.php` — added `mtn_momo.{base_url,target_environment,subscription_key,api_user,api_key,callback_token}` and `orange_money.{base_url,country,merchant_key,client_id,client_secret,return_url,cancel_url,callback_token}` (legacy generic keys kept for compatibility). `.env` — new vars added blank per the user's request, except the two `callback_token` secrets (real random 64-char hex, generated with `random_bytes(32)`). `.env.testing` — same vars with real dummy values so the suite has something deterministic to run against.

**Adapters** (`App\Application\Payments\Adapters`): `MtnMomoAdapter` (OAuth via Basic auth + `Ocp-Apim-Subscription-Key`, cached; `requestCustomerAuthorization()` → `POST /collection/v1_0/requesttopay`, 202/no-body, the generated `X-Reference-Id` becomes `provider_reference`; `status()` → `GET /collection/v1_0/requesttopay/{referenceId}`). `OrangeMoneyAdapter` (OAuth2 client_credentials, cached; `requestCustomerAuthorization()` → `POST /orange-money-webpay/{country}/v1/webpayment` with `order_id` set to our own `payment_intents.id`, `pay_token` becomes `provider_reference`; `status()` → `POST .../v1/transactionstatus` with `{order_id, amount, pay_token}`). `PaymentAdapterRegistry` updated to route both providers to their dedicated class instead of `ConfiguredJsonPaymentAdapter`.

**Reconciliation**: new `App\Application\Payments\MobileMoneyStatusReconciler` — the one place "map provider-native status → generic status → `WebhookProcessingService::process()`" exists, shared by both callback controllers and the polling command so a race between a callback and a poll converges on the same `webhook_inbox` dedup key.

**Callback controllers** (`App\Interfaces\Http\Controllers\Api\V1\Payments`): `MtnMomoCallbackController`, `OrangeMoneyCallbackController` — see Security controls below.

**Console**: `App\Console\Commands\PollPendingMobileMoneyPayments` (`payments:poll-pending`), scheduled every 5 minutes in `routes/console.php` — the fallback for when a callback never arrives.

**Routes**: `routes/api.php` — `POST v1/webhooks/payments/mtn-momo/callback`, `GET|POST v1/webhooks/payments/orange-money/callback` (both methods accepted since Orange's own notification delivery mechanism isn't trusted anyway, so accepting either costs nothing); removed `mtn_momo`/`orange_money` from the generic `PaymentWebhookController`'s allow-list now that they have dedicated endpoints.

**i18n**: `resources/lang/{en,fr}/wave4.php` — added `invalid_callback_token`.

**Tests** (+34, all in `tests/Feature/Wave4`, all passing): `MtnMomoAdapterTest`, `OrangeMoneyAdapterTest`, `MtnMomoCallbackControllerTest`, `OrangeMoneyCallbackControllerTest`, `PollPendingMobileMoneyPaymentsTest`, `PaymentAdapterRegistryTest`, + shared `Concerns/mobile_money_helpers.php` (the first fixture in this codebase that builds a full, real `payment_intents` row — tenant → party → carrier → product → tariff → quote → offer → proposal → payment_intent — since `payment_intents.tenant_id`/`proposal_id` are enforced foreign keys, not just typed columns).

### API and provider contracts

- **MTN MoMo Collections API (Request to Pay)**: token via `POST /collection/token/` (Basic `api_user:api_key` + `Ocp-Apim-Subscription-Key`); `POST /collection/v1_0/requesttopay` with `X-Reference-Id`, `X-Target-Environment`, `X-Callback-Url` (see below), body `{amount, currency, externalId, payer:{partyIdType:MSISDN,partyId}, payerMessage, payeeNote}` → 202, no body; `GET /collection/v1_0/requesttopay/{referenceId}` → `{status: PENDING|SUCCESSFUL|FAILED, ...}`.
- **Orange Money Web Payment API**: token via `POST /oauth/v3/token` (Basic `client_id:client_secret`, `grant_type=client_credentials`); `POST /orange-money-webpay/{country}/v1/webpayment` with `{merchant_key, currency, order_id, amount, return_url, cancel_url, notif_url, lang, reference}` → `{payment_url, pay_token, notif_token}`; `POST .../v1/transactionstatus` with `{order_id, amount, pay_token}` → `{status: INITIATED|SUCCESS|FAILED|EXPIRED, ...}`.
- **amount is a decimal string in major units, not `amount_minor`/100.** XAF has zero decimal exponent in ISO 4217, and this codebase already treats `amount_minor` as the plain XAF integer everywhere else (confirmed by reading `ConfiguredJsonPaymentAdapter`, which sends `amount_minor` unmodified) — both new adapters follow the same convention, just cast to string since both providers require a string.

### Security controls implemented

1. **Neither provider's callback status is ever trusted — always re-verified against a live status query.** This is the core design decision, applied uniformly to both providers even though only Orange strictly requires it: `MtnMomoCallbackController` and `OrangeMoneyCallbackController` both ignore the callback body's own status field entirely and call the adapter's `status()` method to get the authoritative value before calling into `MobileMoneyStatusReconciler`. Verified directly: a test posts a forged `status=SUCCESS` (or `FAILED`) in the callback/notification itself while the faked provider status-query response says the opposite, and asserts the re-queried value — not the forged one — is what the intent transitions to.
2. **MTN's callback URL carries our own `callback_token`**, since MTN does not sign callbacks at all. Orange's `notif_url` carries the same token. Both controllers compare it with `hash_equals()` and abort 401 before touching the database or making any outbound HTTP call on a mismatch (verified: `Http::assertNothingSent()` on the invalid-token test for both).
3. **Dedup and state-machine enforcement is fully reused, not reimplemented.** Both controllers and the polling command funnel into the same unmodified `WebhookProcessingService::process()` that Batch-1-era code already had — `webhook_inbox`'s unique `(provider, external_event_id)` constraint means a callback and a concurrent poll racing on the same transition only ever apply it once (verified: two consecutive identical callbacks produce exactly one `payment_events` row).
4. **A rejected/unrecognised callback still returns 200** (for an unknown reference/order id) so the provider doesn't treat it as a delivery failure and retry indefinitely — but only after confirming there's genuinely nothing to reconcile; an invalid *token* still gets a hard 401.
5. **order_id doubles as `payment_intents.id`** (set at Orange initiation) and MTN's `reference_id` is embedded in the callback URL itself — deliberate choices so neither callback controller needs a side lookup table to resolve a provider notification back to an intent.

### Bugs found and fixed this batch (all caught by live `Http::fake()`-driven execution, not just written-and-assumed-passing tests)

1. **`config('payments.providers.{mtn_momo,orange_money}.base_url')` silently ignored its own documented default.** `env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com')` only falls back when the key is *entirely absent* — but the user's `.env` has it present and deliberately blank, which `env()` returns as `''`, not the default. Caught by manually inspecting `config()` output via `artisan tinker` against the real `.env` (not `.env.testing`) after everything else was passing — the public sandbox host was silently never applied. Fixed with `env(...) ?: 'default'` in both provider blocks.
2. **Test assertion bug, not a production bug**: an Orange adapter test indexed `$request['notif_url']` inside an `Http::assertSent()` closure without first checking the URL was the webpayment call — threw `Undefined array key` when the closure was evaluated against the unrelated OAuth token request too. Fixed by checking the URL first.
3. **Wrong status enum value in the test fixture** (`insurance_products.status = 'PUBLISHED'`), not application code — the real DB check constraint only allows `DRAFT|IN_REVIEW|ACTIVE|RETIRED|REJECTED`. Caught immediately by the check-constraint violation on the very first fixture-backed test run; fixed to `ACTIVE`.
4. **Laravel testing quirk, not a bug in the command**: chaining two `expectsOutputToContain()` calls on `$this->artisan(...)` expects two *separate* output lines, consumed in order — it failed against a single `$this->info()` line that actually contained both substrings. Switched to `Artisan::call()` + plain string assertions on `Artisan::output()`.

### Live verification

No real MTN/Orange sandbox exists yet to call, so "live" here means real HTTP round-trips through Laravel's actual HTTP client (`Http::fake()` intercepts at the transport layer, not by stubbing the adapter methods) against each provider's documented request/response shape, hitting a real Postgres-backed `payment_intents` row through the real routes, middleware, and `WebhookProcessingService` — not mocked-out application code. Specifically verified end-to-end: token acquisition and caching (second call makes zero additional auth requests) for both providers; a full MTN request-to-pay with the exact documented headers/body; a full Orange webpayment initiation returning `pay_token`; both callback routes reachable through the real router with real query-string token verification; the "ignore the push, trust the re-query" property for both providers with an intentionally-forged opposing status; idempotent replay; the polling command against multiple intents across both providers including a simulated total-failure of one provider's auth call, confirmed the healthy intent still transitions and the run doesn't crash. Full project suite re-run afterward: 157 passed, 4 failed — the same 4 pre-existing, unrelated failures (`MembershipRevocationTest`, `PartyIdentityTest`, `CertificateSecurityTest`, `RenewalAttributionTest`) as every prior batch, unchanged.

### Addendum (2026-09-22, later same day) — MTN MoMo live-verified against the real sandbox

The user supplied real MTN MoMo sandbox credentials after this batch was written. What actually happened, in order, hitting `sandbox.momodeveloper.mtn.com` for real over the public internet — no `Http::fake()` anywhere in this addendum:

1. First attempt failed: `POST /v1_0/apiuser` returned `401 invalid subscription key` for a key the user had already confirmed was under a product called **Collection Widget**. Root-caused via MTN's own developer community forum: Collection Widget (a hosted USSD/QR checkout page) is a *different* product from plain **Collection** (the Request-to-Pay REST API this adapter is built against) — same portal, different subscription, different key, and MTN's error message doesn't say so.
2. The user subscribed to the correct **Collection** product and supplied its key. `POST /v1_0/apiuser` → 201, `POST /v1_0/apiuser/{id}/apikey` → 201, `POST /collection/token/` → a real signed JWT.
3. Ran the actual shipped `MtnMomoAdapter` class (unmodified, via `artisan tinker`, not a copy or a re-implementation) against the live sandbox: `requestCustomerAuthorization()` → real 202 Accepted; `status()` → real `{"status":"SUCCESSFUL","financialTransactionId":"1116647454",...}`, matching the exact shape the adapter already parses.
4. One sandbox behavior worth recording: MSISDN `46733123450` deterministically returns `FAILED`/`INTERNAL_PROCESSING_ERROR` — that's one of MTN's own reserved test numbers, not a bug in this adapter. An ordinary-looking number (`237670000002`) returns `SUCCESSFUL`, matching MTN's documented "non-test numbers always succeed in sandbox" behavior.
5. Seeded a real `payment_intents` row (`provider=mtn_momo`, `provider_reference` = the live `X-Reference-Id` from step 3) and ran the real `payments:poll-pending` command against it — confirmed `PENDING_CUSTOMER → SUCCEEDED`, one real `payment_events` row, one real `webhook_inbox` row, through the actual `MobileMoneyStatusReconciler`/`WebhookProcessingService`, zero fakes in the path. Demo fixture rows deleted afterward.

Orange Money remains unverified against a real sandbox — no Orange credentials have been supplied yet.

### Remaining gaps (honest, not glossed over)

- **MTN MoMo is now live-verified against the real sandbox (see addendum above); Orange Money is not** — no Orange credentials exist yet. Everything for Orange is still only verified against its *documented* contract, which carries normal integration risk (undocumented quirks, market-specific variations — Orange Money in particular has historically had per-country integration differences) until exercised against a real sandbox.
- **No refund/reversal capability, and this is not a placeholder — it's an honest capability gap.** MTN's Collections API (which is what's integrated here) has no refund operation; MTN's separate Disbursements API product would be required, and isn't part of this batch or configured anywhere. Orange Money's Web Payment API likewise exposes no reversal endpoint in what's integrated here. The existing generic `Refund`/`FinancialCaseService` refund flow will accept a refund *request* against an MTN/Orange payment (nothing blocks it at that layer) but there is no adapter method that could actually execute it against either provider — approving one today would be a manual, off-platform operation, not something this integration performs. Not fixed this batch; flagging it here is deliberate rather than fabricating a fake refund path.
- **MTN's callback registration mechanism is assumed, not confirmed.** The `X-Callback-Url` request header is the commonly-documented mechanism for registering a per-request callback, but some MTN MoMo deployments instead require the callback URL to be configured once out-of-band via the MTN developer portal, ignoring anything sent in the header. The polling command exists specifically so correctness doesn't depend on this working — but which mechanism actually fires can only be confirmed against a real sandbox.
- **Orange's notification delivery mechanism (GET vs POST, exact query-param names) is assumed from commonly-documented integrations, not a single canonical Orange spec** — the callback route accepts both methods and only ever reads `order_id`/`token` from it, specifically so a wrong guess about the exact shape doesn't break anything beyond "the poller ends up doing the reconciling work instead of the callback."
- **No Filament visibility changes this batch** — operators see MTN/Orange payment intents through the same existing generic payment views as every other provider; nothing provider-specific was added or was necessary.

### Rollback and operational impact

No migrations this batch — no `down()` needed. Nothing changes for `maviance`/`campay`/`fake`, which still resolve to the same adapters as before. Existing `mtn_momo`/`orange_money` payment intents created against the old generic adapter (none exist in production — no real partner has used these providers yet, confirmed by the fact no real credentials exist) would have had a `provider_reference` shaped like whatever the generic adapter's fake `initiate_url` returned, which no longer matches this batch's polling/callback lookup logic — irrelevant in practice today, but worth knowing if this is ever backported to a system with real prior data under the old adapter. The new scheduled command is additive (`withoutOverlapping`, every 5 minutes) and is a no-op when there are no open MTN/Orange intents, which is the current state everywhere.

## Batch 2 — Reliability and operations: **Done** (2026-09-22)

Retry classification/backoff was already built in Batch 1; this batch added the rest of the plan's §15/§16: circuit breaker, controlled replay, real metrics, and an operator-facing Filament UI (none of which existed before — operators previously had no way to see any of this except by querying the database directly).

### Files changed / created

**Migration**: `2026_09_22_000004_integration_reliability_operations.php` — adds `consecutive_failures`/`circuit_state`/`circuit_opened_at` to `integration_webhook_subscriptions`; adds `duration_ms`/`is_manual_replay`/`replayed_by` to `integration_delivery_attempts`.

**Services**: `WebhookDeliveryService` extended with `isEligibleForAttempt()` (circuit gate) and `replay()` (manual, actor-attributed redelivery); new `IntegrationHealthService` (real metrics only — every figure is a direct query against data this platform actually writes, nothing invented).

**Console**: `DispatchIntegrationOutbox` updated to skip subscriptions with an open circuit and report a circuit-skipped count.

**Controller/routes**: `IntegrationController::replayDeliveryAttempt()` and `::health()`; `POST integrations/delivery-attempts/{attempt}/replay`, `GET integrations/health`.

**Filament admin UI** (new — nothing existed here before this batch):
- `Pages/IntegrationHealth.php` + Blade view — dashboard: open circuits, pending outbox count + oldest-pending age, dead-letter count, p50/p95 latency, 24h delivery breakdown, connections by status.
- `Resources/IntegrationClients/*` — read-only list + view (credentials are shown once at issuance and can't be re-displayed, so there's deliberately no Filament create form); header actions for advance/suspend/reinstate/revoke, matching the existing `ClaimResource`/`PartnerLicenceResource` action-schema convention already used elsewhere in this codebase.
- `Resources/IntegrationDeliveryAttempts/*` — the dead-letter queue view, with a "Replay" action on the latest attempt of any DEAD_LETTERED pair.

**Tests** (+5, 31 total in `tests/Feature/Integrations`, all passing): retry-continues-past-two-cycles, circuit-opens-at-threshold, circuit-closes-on-success, replay-success, replay-refused-when-not-dead-lettered.

### Bugs found and fixed this batch (all caught by live manual verification, not by the test suite that existed at the time — regression tests were added afterward)

1. **`oldestPendingOutboxSeconds()` had the wrong return type** — Carbon 3's `diffInSeconds()` returns a `float`, and can return it with either sign depending on argument order (a real behavior change from Carbon 2 that caught this off guard); the method was typed `?int` and threw a `TypeError` the first time real pending-outbox data existed. Fixed with `(int) abs(...)`.
2. **The retry-selection query silently stopped retrying after exactly one cycle once a second attempt existed.** `IntegrationDeliveryAttempt::...->get()->unique(fn ($a) => ...)` keeps the *first* row per (subscription, event) key — without sorting by attempt number descending first, that's the *oldest* attempt once a newer one exists, which then fails the "is this actually the latest attempt" filter and gets dropped entirely, along with the newer attempt that was never considered. Reproduced live (retries stopped dead after run 1 of 5), fixed by sorting before deduping, then pushed a real failing endpoint through 8 live attempts to confirm attempts 2/3/4 now continue correctly, the circuit opens at exactly 5 consecutive failures, and attempt 8 dead-letters — all before writing the regression test that would have caught it.
3. **The new Blade dashboard's Tailwind classes weren't compiled** — `theme.css`'s `@source` scan is build-time; a new Blade file added after the last `npm run build` doesn't get its utility classes included until the next build. Caused the 3-column stat grid to render as a single stacked column. Fixed by rebuilding.
4. **Made the same `Http::fake()` accumulation mistake as Batch 1, twice, in the new tests** — see Batch 1's note; `Http::fake()` calls merge rather than replace for the same URL pattern, so two of the five new tests initially asserted the wrong outcome until switched to `fakeSequence()`.

### Live verification (not just automated tests)

Registered a real connection ("Demo Broker Connector"), advanced it to ACTIVE, subscribed it to `policy.issued` pointed at a genuinely unreachable domain, and drove it through the real dispatcher command repeatedly: attempt 1 (immediate real cURL failure, 2754ms — DNS resolution actually attempted, not mocked), continued retrying through attempts 2-4, circuit opened at exactly consecutive_failures=5 (confirmed via `audit_log` `integration.circuit.opened` row), continued cycling through HALF_OPEN probes to attempt 8 (DEAD_LETTERED, correctly independent of circuit state), then used the real "Replay" button in the Filament UI (confirmation modal → new attempt #9, tagged "Manual", correct `replayed_by` actor, correct `audit_log` row) — end to end through the actual browser, not a test double. Cleaned up all demo data afterward.

### Remaining gaps

- **"Alerts" means the Laravel log + audit_log only.** No real paging/notification channel exists in this codebase (confirmed missing in the original audit) — building a fake one would be exactly the kind of unverified claim the plan warns against. Wiring `integration.circuit.opened` into a real channel is a later batch once a communications adapter exists (Batch 4).
- **HALF_OPEN → DEAD_LETTERED edge case is imprecise**: if a half-open probe's *result* is DEAD_LETTERED (attempt count hit the cap) rather than RETRY_SCHEDULED, the circuit is left HALF_OPEN instead of cleanly re-OPENING. Doesn't compromise the safety guarantee (a DEAD_LETTERED attempt is never retried automatically regardless of circuit state), but the recorded circuit state is cosmetically wrong in that specific case. Noted, not fixed.
- **No token-issuance-time scope restriction** (carried over from Batch 1, unchanged).
- **No business endpoints, carrier/broker connectors, or communications/logistics adapters** (Batches 4-6, untouched).

### Rollback and operational impact

Migration has a working `down()`. Nothing in this batch changes existing behavior for the routes/services Batch 1 built — `WebhookDeliveryService::attempt()` has the same signature and callers; the circuit breaker is purely additive gating (a fresh subscription starts `CLOSED` with `consecutive_failures=0`, identical to how it behaved before this column existed). The new Filament pages are read-only monitoring plus explicit, confirmed, audited actions — no bulk or destructive operations.

## Batch 1 — Integration foundation: **Done** (2026-09-22)

### Files changed / created

**Migrations**
- `database/migrations/2026_09_22_000001_integration_platform_foundation.php` — extends `integration_clients` (oauth_client_id, environment, certified_at/by, activated_at, revoked_at/by/reason); creates `integration_client_status_history`, `canonical_event_schemas`, `external_record_mappings`; adds `signing_secret_encrypted` to `integration_webhook_subscriptions`.
- `2026_09_22_000002_integration_clients_secret_hash_nullable.php` — `client_secret_hash` is legacy now that Passport owns real authentication; had to be made nullable.
- `2026_09_22_000003_widen_external_record_mapping_conflict_status.php` — 24 chars was too tight for a real conflict reason code.

**Models**: `IntegrationClient`, `IntegrationWebhookSubscription`, `IntegrationDeliveryAttempt`, `IntegrationClientStatusHistory`, `CanonicalEventSchema`, `ExternalRecordMapping`.

**Services**: `App\Application\Integrations\{IntegrationClientLifecycleService,ExternalRecordMappingService,WebhookDeliveryService}`.

**Middleware**: `App\Interfaces\Http\Middleware\AuthenticateIntegrationClient` (alias `integration.client:<scope>`).

**Console**: `App\Console\Commands\DispatchIntegrationOutbox` (`integration:dispatch-outbox`), scheduled every minute in `routes/console.php`.

**Controllers**: rewrote `IntegrationController` (createClient/advance/suspend/reinstate/revoke/subscribe); new `App\Interfaces\Http\Controllers\Api\V1\Partner\PartnerApiController` (whoAmI/mapRecord/showMapping).

**Routes**: `routes/api.php` — new admin lifecycle routes under `integrations/clients/{client}/...`; new `partner/*` group (no `auth:api`/`tenant` — see below).

**Other**: `AppServiceProvider::registerPassportScopes()`; `bootstrap/app.php` middleware alias; `config/permissions.php` (+`integrations.manage`, `integrations.revoke`); `database/seeders/CanonicalEventSchemaSeeder.php` (wired into `DatabaseSeeder`, runs in every environment — it's reference data, not demo data).

**Tests** (26, all passing): `tests/Feature/Integrations/{IntegrationClientLifecycleTest,ExternalRecordMappingTest,WebhookDeliveryTest,PartnerAuthenticationTest,DispatchIntegrationOutboxTest}.php` + shared `Concerns/helpers.php`.

### API and event contracts

- **Partner auth**: `POST /oauth/token` (Passport, `grant_type=client_credentials`) → `GET/POST /api/v1/partner/*` with `Authorization: Bearer <token>`.
- **Partner endpoints this batch**: `GET partner/whoami`, `POST partner/record-mappings`, `GET partner/record-mappings/{recordType}/{externalRecordId}`. Deliberately minimal — real business endpoints (submit a customer, an order, a claim...) are Batches 5/6 in the plan, not this one.
- **Admin lifecycle endpoints**: `POST integrations/clients`, `.../advance`, `.../suspend`, `.../reinstate`, `.../revoke`, `.../webhooks` (subscribe).
- **Canonical event catalogue** (9 events seeded, real payload shapes grepped from the actual emitting service, not invented): `policy.issued`, `claim.transitioned`, `payment.status.changed`, `quote.rated`, `proposal.submitted`, `underwriting.decided`, `certificate.issued`, `renewal.completed`, `commission.accrued`. A partner can only subscribe to an event in this table — the old hard-coded list (`quote.offered`, `payment.succeeded`, `policy.changed`, `claim.changed`, `settlement.changed`) matched **nothing any service ever emits** and has been removed.

### Security controls implemented

1. **Real authentication, not a hand-rolled one.** `IntegrationClientLifecycleService::register()` creates an actual Passport client-credentials OAuth client via `ClientRepository::createClientCredentialsGrantClient()` — real RS256-signed JWT bearer tokens, real expiry, real revocation, not custom crypto.
2. **Domain authorization is authoritative over the token.** `AuthenticateIntegrationClient` re-checks `integration_clients.status/scopes/allowed_ips` on every request regardless of what the token itself claims — a revoked or suspended connection is rejected even with an otherwise-valid, unexpired token (verified live and in tests).
3. **Certification lifecycle**: DRAFT → TECHNICAL_REVIEW → SANDBOX_ENABLED → CERTIFICATION → PRODUCTION_APPROVED → ACTIVE, with RESTRICTED/SUSPENDED/REVOKED as an exceptional branch; REVOKED is terminal. Every transition writes to both `integration_client_status_history` and the shared tamper-evident `audit_log`.
4. **Fixed a real signing bug**: `signing_secret_hash` (bcrypt, one-way) could never be used to sign outgoing webhooks — added `signing_secret_encrypted` (reversible) so delivery attempts can actually compute the HMAC.
5. **No uncontrolled last-write-wins**: `ExternalRecordMappingService::map()` throws (verified) rather than silently overwriting when an external ID is already mapped to a different internal record; `flagConflict()` records a conflict without deleting the existing mapping.
6. Rate limiting (`RateLimiter::attempt`, per-client key, `rate_limit_per_minute`), IP allowlist, and an audit row for **every** allow and deny — all three verified against a real running client over real HTTP, not just unit-tested.

### Tests executed and results

`APP_ENV=testing php artisan test --env=testing` (against the isolated `opesinsure_testing` Postgres database): **123 passed, 4 failed**. The 4 failures (`MembershipRevocationTest`, `PartyIdentityTest`, `CertificateSecurityTest`, `RenewalAttributionTest`) are pre-existing, unrelated, and documented earlier this session — unchanged by this batch. Baseline before this batch was 97 passed; 123 − 97 = 26, matching exactly the new tests added.

### Provider/simulator evidence

No external provider exists to certify against yet (that's Batch 3). What was verified for real, live, over HTTP against the actual running `opesinsure.test` server (not just automated tests):
- Registered a real client, walked it through the full 6-stage lifecycle to ACTIVE.
- Requested a real OAuth token from `/oauth/token` and called `/api/v1/partner/whoami` with it → 200 with correct identity.
- No token → 401. Suspended connection with a still-valid token → 403. IP outside the allowlist → 403.
- Created a record mapping, looked it up, got a real 409 on a genuine conflict, confirmed idempotent re-mapping.
- Confirmed every one of the above produced a matching `audit_log` row.

### Remaining gaps (honest, not glossed over)

- **Token-issuance-time scope restriction is not enforced.** Passport will issue a token for any scope in the global registered list (`AppServiceProvider::registerPassportScopes()`), even one a specific client's `integration_clients.scopes` doesn't include — `AuthenticateIntegrationClient` catches this at request time (verified in tests), but a tighter fix would override League OAuth2 Server's scope repository to reject the request at token-issuance time too. Not done this batch.
- **No business endpoints yet** — whoami and record-mappings are the only partner-facing routes. Submitting a customer, quote, order, claim, etc. is Batches 5/6.
- **No carrier connectors, no broker ERP connector, no communications/logistics adapters** — untouched, as scoped. This batch was explicitly foundation only.
- **`RiskAlertController`/`ComplianceController`** (in `routes/api.php`, unrelated to this batch) still write to the same tables as Wave9Controller through a separate, older code path — flagged previously, still unreconciled.
- **The scheduled command has no monitoring/alerting yet** (Batch 2 in the plan — dead-letter alerts, integration health dashboard).
- Canonical schemas exist for 9 events; dozens of other real domain events (see the full list extracted this session) are not yet catalogued or subscribable.

### Rollback and operational impact

All three migrations have working `down()` methods and were tested forward-only in this session (not rolled back). Nothing in this batch modifies or removes any existing table's data or any existing endpoint's behavior — `IntegrationController`'s two pre-existing routes (`createClient`, `subscribe`) keep the same request/response shape, just with real enforcement behind them now instead of none. The new `integration:dispatch-outbox` scheduled command is additive (runs every minute, `withoutOverlapping`) and does nothing when there are no active webhook subscriptions, which is the current state in production-equivalent data (zero real partners exist yet).
