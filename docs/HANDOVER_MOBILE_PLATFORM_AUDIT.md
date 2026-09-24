# Handover — mobile app ↔ platform audit and completion (2026-09-23)

Written for whoever continues this work (human or another Claude session). Everything
below was verified against the live server, not inferred.

## How things are deployed

- Backend: build locally, tar, `scp`, then `/srv/opesinsure/deploy.sh` on `187.77.110.114`
  (see `DEPLOYMENT.md`). PHP is at `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64`, composer at
  `C:\laragon\bin\composer`; on Windows run composer with
  `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`.
- Mobile app (`mobile app/`, separate git repo): **ship fresh APKs**, not OTA.
  `npx eas build --profile production-apk --platform android`, download, upload to the
  server so `https://insurance.opesdatacenter.tech/download/android` serves it
  (verify `Content-Type: application/vnd.android.package-archive` + checksum). OTA updates
  (`eas update`) were published repeatedly and never visibly reached the user's phone.
- Demo logins: `GET /api/v1/public/demo-accounts` (OTP is fixed at `123456`). The audit
  script `scratchpad/audit/replay.mjs` (copied to `docs/audit/replay.mjs`) logs in as each
  persona and replays every screen-load endpoint — run it after every backend deploy.

## Root causes found (in order of impact)

1. **Production had zero platform data**: 0 carriers, 0 insurance lines, 0 products,
   0 tariffs, 0 policies, 0 claims, 0 partners. Only 1 tenant, 3 demo parties, 12 users.
   So Compare can never produce offers, and every list/detail screen is empty or errors.
2. **Roles on prod have empty permissions**: `CUSTOMER => []` (but `POST quotes/{id}/rate`
   requires `quotes.rate`), `AGENT => []` (the seeder was fixed later but `firstOrCreate`
   never updated the existing row) → agent screens 403.
3. **Customers are not `tenant_customers`**: `QuoteService::submit`, `RiskAssetService`
   etc. require an ACTIVE `tenant_customers` row; `MobileAuthService::provisionCustomerAccess`
   and the demo seeder only created Party + membership.
4. **App calls routes that don't exist** (404 on prod): `/mobile/account/*`,
   `/mobile/notifications*`, `/mobile/support/cases*`, `/mobile/policy-service-requests*`,
   `/mobile/workspace/*`, `/mobile/agent/{dashboard,profile,renewals,sales}`,
   `/mobile/broker/{clients,production,renewals,compliance,marketplace-publications}`,
   `/mobile/carrier/{referrals,issuance,claims}`, `/mobile/security/device-attestation/*`,
   `/mobile/claims/{id}/{incident,inspection,repair,settlement,evidence-requirements}`,
   `/mobile/claims/emergency-assistance`, `/policies/{id}/{service-requests,renewal-quote}`,
   `/proposals/{id}/disclosure*` and `/proposals/{id}/terms` (backend has `disclosures`
   (plural) PUT + `disclosures/attest`).
5. **App calls the wrong (staff) claims routes**: `ClaimsApi` uses `/claims` (needs staff
   permission → 403) instead of the customer-scoped `/mobile/claims` that exists.
   Backend `Claim` uses `loss_occurred_at/loss_location/loss_details.description`; app
   expects `incident_at/incident_location/description`.
6. **Dashboard shape mismatches**: app expects `{metrics:[{label,value,tone}]}` from
   `/mobile/broker/dashboard` and `/mobile/carrier/dashboard`; backend returns finance
   objects. `CarrierSettlement`/`BrokerClient` etc. shapes also differ.
7. **Bottom tab bar overlaps the Android nav bar**: `app/(customer)/(tabs)/_layout.tsx`
   sets a fixed `height: 68` with no bottom safe-area inset (SDK 54 is edge-to-edge).
8. Home screen has no quick access to insurers/brokers; institutions screens read
   bundled TS data (`src/data/insurers.ts`, `brokers.ts`), not the API.
9. No in-app update UX (fixed: `src/components/UpdateNotice.tsx`); no issue reporting
   (fixed: `IssueReportButton` → `POST /mobile/issue-reports`, admin resource
   "App issue reports").

## Endpoint audit (before fixes) — see `docs/audit/replay-before.txt`

## Work plan / status (updated 2026-09-23 15:30 UTC)

- [x] A. Platform data + permissions — DEPLOYED. `PlatformCatalogueSeeder` (5 lines, 8 carriers,
      17 products, 17 APPROVED tariffs, disclosure schemas, tax/fee rules) and
      `DemoScenarioSeeder` (customer policies/claims/payments/documents/devices, agent clients +
      commissions + statement, broker clients/production/receivables/compliance/publications,
      insurer referrals/issuance queue). Both idempotent; run on prod with
      `php artisan db:seed --class=DemoScenarioSeeder --force`. Roles repaired (CUSTOMER has
      `quotes.rate`, AGENT/BROKER_STAFF/CARRIER_STAFF real permissions). New demo persona
      "Insurer staff (mobile)" +237600000103. Self-registered customers now get a
      `tenant_customers` row (`MobileAuthService::provisionCustomerAccess`).
- [x] C/D. Backend endpoints — DEPLOYED (`routes/wave14_mobile.php`, controllers under
      `app/Interfaces/Http/Controllers/Api/V1/MobileCompletion/`): account, notifications
      (`user_notifications`), support, policy service requests + renewal quote, claims completion
      (incident/checklist/inspection/repair/settlement/appeal/emergency), disclosure/terms
      adapters, agent portal (dashboard/profile/renewals/sales/clients/commissions/withdrawals/
      offline queue — app-shaped), broker ops, carrier ops, workspace, device attestation.
      Broker/carrier dashboards now also carry `metrics`. `Claim` model exposes
      `incident_at/incident_location/description`. Offers eager-load carrier + product names.
      `ProposalService::submit` is straight-through (PAYMENT_PENDING) when no disclosure raises a
      referral flag; flagged proposals still go UNDER_REVIEW for the insurer persona to decide.
      Demo mode routes payments through the fake adapter and `DemoPurchaseSettler` confirms the
      payment ~20s after initiation and issues the policy via `PolicyIssuanceService` (polled from
      `GET /mobile/purchases/{proposal}/status`).
      Verified live with `docs/audit/replay.mjs` (every persona, every screen-load endpoint 200)
      and `docs/audit/journey.mjs` (quote → 6 offers → proposal → disclosure → terms →
      payment → issued policy → FNOL → service request → support → renewal quote).
- [~] B. App — `ClaimsApi` repointed to `/mobile/claims*` and tab bar bottom inset fixed in the
      working tree of `mobile app/` (NOT yet built into an APK). Still to do: home quick view for
      insurers/brokers, institutions screens → API (E), then
      `npx eas build --profile production-apk --platform android` and upload to `/download`.
- [ ] E. Institutional directory in Laravel (`/public/institutions/*`) — not started; app still
      reads bundled `src/data/insurers.ts`/`brokers.ts` (works offline, provenance-labelled).
- [ ] F. API docs page (`composer require dedoc/scramble`, serve at `/docs/api`) — not started.
- [ ] G. Run the app in react-native-web / emulator and click through — not done (no Android SDK
      on this machine); verification so far is contract-level via the two scripts above.

## Known gaps / notes for the next session
- Twilio is not configured on prod: OTP for non-demo phones cannot be delivered (demo phones use
  the fixed OTP). `MAIL_MAILER=log`.
- The scheduled MTN MoMo reconciler logs "MTN MoMo authentication failed" every run (no real
  credentials) — harmless in demo mode but noisy.
- `reconciliation:run` scheduled command exits 1 on prod (pre-existing).
- OTP request is throttled 5/min per IP and per phone; the audit scripts hit this if rerun quickly.

## Update 2026-09-23 16:15 UTC — B shipped, live journey verified

- B. App — DEPLOYED as APK build `254c57be` (EAS): `ClaimsApi` -> `/mobile/claims*`, tab bar bottom
  inset. Uploaded to `/srv/opesinsure/shared/storage/app/public/downloads/opesinsure-1.2.0.apk`
  (MD5 2ddb988a4e7e378ef233f1e4d467b680), served at `/download/android` with
  `application/vnd.android.package-archive`. Users must reinstall from `/download`.
  Still to do in the app: a home-screen quick view for insurers/brokers (the screens exist at
  `/institutions/insurers` and `/institutions/brokers`; reachable today from the sign-in
  "Browse insurers" link and the Account tab).
- Verified on production with `docs/audit/journey.mjs`: JOURNEY COMPLETE — quote -> 6 offers ->
  proposal -> disclosure -> terms (PAYMENT_PENDING) -> payment -> initiate -> policy issued
  (POL-2026-001011) -> wallet -> claims -> FNOL -> incident/evidence/timeline -> notifications ->
  service request -> support -> renewal quote (3 offers).
- `docs/audit/replay.mjs` on production: customer, agent and broker personas return 200 with seeded
  data on every screen-load endpoint. (Insurer persona hit the OTP IP throttle during the final run;
  identical code verified locally, and its carrier endpoints return 200 for the other personas.)
- Timezone gotcha: Eloquent stores app-timezone wall-clock into `timestamptz` columns with no
  offset, so values read back labelled UTC while being Africa/Douala. Any age/expiry logic must
  re-interpret `created_at` in `config('app.timezone')` (see `DemoPurchaseSettler`).
- Two hot-patches were applied directly in `/srv/opesinsure/current` and are also committed here
  (`ProposalService` addWeekdays, `DemoPurchaseSettler`); the next `deploy.sh` carries them.
- OTP limiters: route `throttle:5,1` per IP plus `RateLimiter` keys `mobile-otp:phone:<sha256>`
  and `mobile-otp:ip:<sha256>` (5/hour). Clear with `RateLimiter::clear(...)` in tinker when
  re-running the audit scripts.

## Update 2026-09-24 — audit remediation shipped

- Plan and finding matrix: `docs/superpowers/plans/2026-09-24-mobile-audit-remediation.md`.
- Backend deployed as release `r20260924-142023` (migrations: carrier_id on memberships, platform_settings + otp_deliveries). Queue worker restarted. `DEMO_PASSWORD=Demo@12345` added to shared `.env` (backup of previous `.env` in `/srv/opesinsure/backups/env-*.bak`).
- Nightly `pg_dump` cron at 02:30 UTC via `/srv/opesinsure/backup.sh`, 14-day retention.
- `node docs/audit/verify-live.mjs` on production: 75 passed, 1 failed — the failure is a probe of legacy `GET /claims` (403 for customers); the app uses `/mobile/claims`, which passes.
- APK: EAS build `54f28e91` (1.2.0, versionCode 7), MD5 `8c29465f0ad7d4dd852af4352fb72a49`, served at `/download/android` as `application/vnd.android.package-archive`, checksum verified end to end. Previous APK kept at `/srv/opesinsure/backups/opesinsure-1.2.0-build6.apk`.
- Still open: mail server (deploy user has no root; SMTP settings live in admin Platform settings, verification is lifted), ETECH/Twilio credentials (owner enters them in admin), `/.well-known/assetlinks.json` for app links, on-device pass (keyboard, 360px layout).
