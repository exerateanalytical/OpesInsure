# Mobile security review, 2026-09-27 (APK 1.5.0 remediation plan)

Scope: the backend behind the partner/agent/broker/carrier mobile surfaces, the mobile-money callbacks, agent
withdrawals, mobile auth rate limits and mobile uploads. Play Integrity / device attestation is out of scope (another
workstream owns it). Nothing here has been deployed.

Regression tests:

- `tests/Architecture/MobilePartnerRouteAuthorizationTest.php` (item 1)
- `tests/Feature/SecurityReview/MobilePartnerIdorTest.php` (item 2)
- `tests/Feature/SecurityReview/MobileSecurityReviewTest.php` (items 3 to 6)

| # | Item | Status |
|---|------|--------|
| 1 | Role and tenant authorization on partner routes | Verified. One gap fixed: an unthrottled write route |
| 2 | IDOR and tenant isolation | Verified. No leak found; regression tests added |
| 3 | MTN MoMo and Orange Money callbacks | Verified. Defence-in-depth hardening added |
| 4 | Withdrawals, payout-number change, cooling-off | Gap fixed: free-form payout number and no cooling-off |
| 5 | Auth rate limiting | Per-phone limits were fine. Per-IP limits tightened |
| 6 | Mobile uploads | Gap fixed: the chunked-upload path did not sniff magic bytes |

---

## 1. Role and tenant authorization on /mobile/{partner,agent,broker,carrier}/*

**Status:** verified, with one gap fixed.

**Evidence**

- `php artisan route:list` shows 80 routes under these prefixes, all under `api/v1`. Each one runs
  `Authenticate:api`, `ResolveTenant` and `RequirePermission:<code>`. The route files involved are `routes/api.php`,
  `crm.php`, `wave12_agentmode.php`, `wave12_brokercarrier.php`, `wave14_mobile.php` and `wave16_partner.php`.
- Data scoping below the gate:
  - Agents: `AgentPartnerResolver` plus a `customer_attributions` partner filter (`AgentClientIntakeService::show`), and
    `partner_id` filters on commissions, withdrawals and statements.
  - Brokers: `BookScope::bookOf()` (`MobileBrokerOpsController::clientQuery`) and `PortalScope` on the
    partner-workspace book endpoints.
  - Carriers: `CarrierScopeResolver`, which scopes to the carrier on the caller's membership. This is also covered by
    `tests/Feature/Rbac/CarrierFinanceClaimsScopeTest.php`.
- Gap: `PATCH mobile/agent/profile`, which sets the payout MoMo number, had no throttle.

**Fix**

- Added `throttle:10,1` to `PATCH mobile/agent/profile` in `routes/wave14_mobile.php`.
- Added an architecture test that walks the router and fails on any matching route that:
  - lacks auth:api, ResolveTenant or a RequirePermission gate;
  - lives outside `api/v1`; or
  - is a mutating route without a throttle.
- The allow-list `MOBILE_PARTNER_ROUTE_ALLOW_LIST` must give a `why` for every entry, and stale entries fail the test.
  It is currently empty.

**Observations (not changed; product decisions)**

- `PATCH mobile/agent/profile` is gated by `agent.clients.read`, a read permission guarding a write. The step-up
  (item 4) is the real control on the sensitive field.
- `POST mobile/partner/broker/staff/invitations` is gated by `broker.portal.read`. The BROKER_ADMIN check happens
  inside the controller and is covered by `tests/Feature/Partners/PartnerWorkspaceBookTest.php`.

## 2. IDOR and tenant isolation

**Status:** verified. No leak found.

**Evidence** (`MobilePartnerIdorTest`)

- **Agent vs rival agent in the same tenant:**
  - `mobile/agent/clients/{id}` returns 403/404, and `mobile/partner/agent/clients/{id}/documents` returns 404.
  - The policy, claim, commission and withdrawal lists exclude the rival's rows.
- **Agent from another tenant:**
  - With their own `X-Tenant-Id`, the victim's ids return 404 and the lists come back empty.
  - With the victim's `X-Tenant-Id`, `ResolveTenant` returns 403.
- **Broker vs broker in another tenant:**
  - `broker/statements/{id}`, `broker/clients/{id}` and `partner/broker/clients/{id}/documents` return 404.
  - The policy and claim lists exclude the other broker's rows.
  - The foreign tenant header returns 403.
- **Broker vs a rival partner in the same tenant:** clients and documents return 404, and commission accruals exclude
  the rival's rows.
- **Carrier A vs carrier B in one tenant:**
  - `partner/carrier/claims/{id}` and its `/evidence` return 404.
  - Evidence access returns 403/404 for a foreign claim, and also for a foreign document on the caller's own claim.
  - `carrier/settlements/{id}` returns 403/404.
- **Sweep:** every `{param}` route in the four groups (32 routes) is called by an agent from another tenant. That agent holds
  every permission these routes use. Each route is called twice: once with the victim's real ids (customer, claim,
  document, statement, settlement, bordereau, proposal, product) and once with random UUIDs. Every answer is 403, 404
  or 422. None is 2xx or 500.

**Fix:** none needed; tests added.

## 3. MTN MoMo and Orange Money callbacks

**Status:** verified. Defence-in-depth added.

**Evidence**

- **Authenticity:**
  - `MtnMomoCallbackController` and `OrangeMoneyCallbackController` require the per-provider `callback_token`, compared
    with `hash_equals`; otherwise they return 401 and never call the operator.
  - They never read a status from the callback body or query string. They only re-query the operator:
    `MtnMomoAdapter::status()` (GET requesttopay/{ref}) and `OrangeMoneyAdapter::status()` (transactionstatus).
  - The generic signed endpoint `webhooks/payments/{provider}` allow-lists providers and does not accept `mtn_momo` or
    `orange_money` (404).
- **Idempotency:**
  - `MobileMoneyStatusReconciler` derives the event id `provider:reference:status`.
  - `WebhookProcessingService::process()` claims it with `insertOrIgnore` on `webhook_inbox`, then locks the intent
    (`lockForUpdate`) and enforces the `PaymentMachine` transitions.
  - The GL posting (`AccountingEventPoster`) is idempotent per (event, intent).
  - A replayed callback is a no-op.
- **Gap (defence-in-depth):** the reconciler never compared the operator's own record with the intent, so a status
  record for another amount, currency or order would still have been credited against the intent's amount.

**Fix**

- `MobileMoneyStatusReconciler::sameTransaction()` refuses to reconcile when the re-queried record reports an
  `externalId`/`order_id`, `amount` or `currency` that differs from the intent. Fields the operator does not report are
  not held against the intent. A refusal is logged as `mobile_money.reconcile.mismatch`.
- Tests:
  - Three replays produce exactly one SUCCEEDED `payment_events` row and one `webhook_inbox` row.
  - A forged SUCCESSFUL body while MTN still says PENDING changes nothing.
  - A mismatched amount, currency or externalId is not credited.
  - A missing token returns 401 with no operator call.
  - MTN and Orange are refused on the generic endpoint.

## 4. Agent withdrawals and payout MoMo number

**Status:** gap fixed.

**Evidence**

- `POST mobile/agent/withdrawals` already required a single-use `COMMISSION_WITHDRAWAL` step-up grant
  (`RequireStepUpGrant`), an Idempotency-Key and `throttle:5,1`.
- **Gap:** `destination_phone` was taken from the request body and never checked against the agent's registered payout
  number. A stolen session that passed one step-up could send commission to any number. This also made any cooling-off
  on the profile number meaningless.
- **Gap:** there was no cooling-off after a payout-number change.
- Step-up on changing `momo_phone_e164`: the `PAYOUT_DESTINATION_CHANGE` step-up (`StepUpGate`) plus the
  `PAYOUT_DESTINATION_CHANGED` security alert are being added by the concurrent mobile-audit B5 work. That work is in
  the working tree but not in this commit. The regression test for that gate skips until `StepUpGate` exists.

**Fix**

- New `App\Application\Agents\PayoutDestinationGuard`:
  - `stampIfChanged()` writes `compliance.momo_phone_changed_at` whenever the registered number changes, including the
    first registration.
  - `assertWithdrawable()` accepts only the registered number (`compliance.momo_phone_e164`, falling back to the
    user's own phone when none is set). Otherwise it returns 422 `PAYOUT_DESTINATION_NOT_REGISTERED`.
  - During the cooling-off period it returns 422 `PAYOUT_DESTINATION_COOLING_OFF` with `cooling_off_until`.
- Config: `payments.payout_destination_cooling_off_hours` (env `PAYOUT_DESTINATION_COOLING_OFF_HOURS`, default 24).
- EN/FR messages in `resources/lang/*/wave12.php`.
- Audit trail:
  - `agent.profile.updated` (fields) is recorded on the change.
  - `agent.withdrawal.requested` is recorded on the request.
  - The step-up attempt lands on the security timeline (B5).
- Tests:
  - A request to a non-registered number is refused.
  - After a change, withdrawals are blocked, the old number is refused as well, and after 25 hours the new number
    works.
  - An unchanged number does not restart the clock.
  - Step-up is still required on withdrawals.
- `tests/Feature/Wave12/AgentModeWithdrawalTest.php` now withdraws to the fixture's registered number.

## 5. Rate limiting on OTP request, password login and password/forgot

**Status:** per-phone limits were adequate. Per-IP limits were too loose and have been tightened.

**Evidence**

- By owner decision on 2026-09-24, there is no route-level `throttle` on login endpoints; see the comment in
  `routes/api.php`. The limits live in `MobileAuthService` (RateLimiter) instead:
  - 6 code sends per phone per hour (OTP request, password/forgot, registration, phone verify).
  - 10 password failures per phone per 15 minutes.
  - 5 wrong guesses per OTP code.
  - A generic 300 requests per IP per hour.
- Demo persona phones are exempt; demo mode stays on.
- **Gap:** 300 per IP per hour allows SMS pumping (about 300 OTP sends from one IP across many numbers) and
  credential stuffing across phones.
- Email is not an identifier on these endpoints; they are phone-only.

**Fix** (in `MobileAuthService`, alongside the generic per-IP ceiling):

- `IP_CODE_SENDS_PER_HOUR = 60`: OTP request, password/forgot and registration code sends, per IP.
- `IP_PASSWORD_ATTEMPTS_PER_15_MIN = 60`: password-login attempts per IP.
- The ceilings stay above carrier-grade NAT sharing levels in Cameroon. Responses are 429 with `Retry-After`.
- Tests:
  - A 7th send to one phone returns 429.
  - After 60 sends from one IP across many phones and both endpoints, the next returns 429.
  - An 11th wrong password for one phone returns 429.
  - After 60 password attempts from one IP across many phones, the next returns 429.

## 6. Mobile uploads (claim evidence, KYC)

**Status:** gap fixed.

**Evidence**

- `ResumableUploadService` already had:
  - an allowed-type list (pdf, jpeg, png, mp4);
  - size limits (100 MiB total, 10 MiB per chunk, 2000 chunks);
  - per-user and per-tenant ownership;
  - optional sha256 checking; and
  - storage on the private `local` disk, which is never publicly served.
- Base64 document uploads (`MobileDocumentService`) already checked magic bytes. They are scanned through
  `MalwareScanAdapter`: ClamAV when `CLAMAV_HOST` is set, otherwise `FailClosedMalwareScanAdapter`, which returns
  FAILED.
- Documents stay unusable until `scan_status = CLEAN`:
  - `ClaimEvidenceService::attach`, `KycService`, `KycRequirementService` and `IssuanceDocumentAcceptance` refuse them.
  - The `MobileDocumentService` access/download path refuses them too.
  - Registering an upload session as claim evidence scans it first; see `MobileClaimEvidenceTest`, "fails closed with
    no scanner configured".
- **Gap:** the chunked path (`/mobile/uploads` then finalize, then register as evidence) trusted the declared
  `mime_type`. There was no magic-byte check, so an executable or HTML file declared as `image/jpeg` would be assembled
  and stored.

**Fix**

- New `App\Application\Uploads\FileSignature`. It holds the one allowed list and the magic-byte check for pdf, jpeg, png
  and mp4 (`ftyp`). `MobileDocumentService` now uses it too, which replaces its private copy.
- `ResumableUploadService::finalize()` sniffs the assembled file. On a mismatch it deletes the assembled file and the
  chunks, marks the session FAILED, and returns 422.
- `MobileClaimEvidenceService` re-sniffs before registering a session's bytes, as defence in depth.
- Tests:
  - A PE header declared as jpeg returns 422, the session is FAILED, and no file is left.
  - A real PDF finalizes.
  - html, exe and svg types are rejected at start, and so is anything over 100 MiB.
  - A unit check of every signature.
- The existing upload and evidence tests now use real JPEG magic bytes.

**Residual risk (unchanged):** production scanning depends on `CLAMAV_HOST`. Until it is set, every mobile upload lands
as FAILED and cannot be used (fail-closed), which is safe but blocks real evidence and KYC. This is an ops item.
