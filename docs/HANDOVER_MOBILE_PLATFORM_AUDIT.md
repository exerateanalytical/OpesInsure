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

## Work plan / status

Each module is deployed as soon as it lands; status is updated below.

- [ ] A. Platform data + permissions: seeders for lines/coverages/carriers/products/
      approved tariffs/tax+fee rules, demo scenario per persona, role permission repair,
      tenant_customers provisioning. 
- [ ] B. App: claims → `/mobile/claims`, disclosure paths, tab-bar safe area, home quick
      view (insurers/brokers), new APK.
- [ ] C. Backend: account, notifications, support, policy service requests, claims
      completion, renewal quote, emergency assistance.
- [ ] D. Backend: agent dashboard/profile/renewals/sales, broker ops, carrier ops,
      workspace, device attestation; dashboard shape adapters.
- [ ] E. Institutional directory in Laravel + public endpoints; app reads API.
- [ ] F. API docs page (`/docs/api`, OpenAPI via dedoc/scramble).
- [ ] G. Run the app in a real runtime (react-native-web in browser) and click through.
