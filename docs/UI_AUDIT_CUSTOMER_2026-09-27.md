# UI audit — customer role on the web account (2026-09-27)

Scope: the signed-in customer area `/account` (resources/views/public/account, public/landing/portal/*.js) and the public buy page,
compared with the customer screens of the mobile app (`mobile app/`, read-only) and the customer API (`/api/v1/mobile/*`,
`/api/v1/policies/{policy}/*`). Partner-only pages (customers, book, leads, reports, commissions, staff, claims desk) were not changed.

Test: `tests/Feature/Web/CustomerNavCrawlTest.php`.

## Feature coverage (API → web page → entry button)

| Area | API | Web page | Entry | Status |
|---|---|---|---|---|
| Sign-in / session | auth/mobile/* | /login | header | existing |
| Profile | mobile/account/profile, customer-profile | /account/profile | side nav, user menu | existing |
| KYC | mobile/kyc/profile, kyc/documents, kyc/submission, mobile/documents | **/account/kyc** (new) | side nav, profile | **added** |
| Consents | mobile/account/consents | **/account/privacy** (new) | side nav, profile | **added** |
| Data requests (export / erasure) | mobile/account/privacy-requests | /account/privacy | side nav | **added** |
| Notification preferences, message language | mobile/account/notification-preferences, account/locale | /account/privacy | side nav | **added** |
| Signed-in devices | mobile/account/devices (+ DELETE) | /account/privacy | side nav | **added** |
| Quotes, comparison | mobile/quotes, quotes/{id}/resume | /account/quotes, /account/buy, /account/quotes/{id} | side nav, "+ New quote" | existing |
| Counter-offer answer | mobile/proposals, proposals/{id}/counteroffer/{accept,decline} | /account/quotes (card shown when the insurer counter-offered) | quotes page | **added** |
| Proposal / payment status | mobile/purchases/{id}/status, mobile/payments, payments/{id}/retry, receipt | /account/quotes/{id}/confirmation, /account/payments, /account/payments/new | side nav, policy page | existing |
| Policies, documents, certificate download + verify | mobile/wallet, policies/{id}/documents(/pack), certificate verification_url | /account/policies, /account/policies/{id} | side nav | existing |
| Policy service requests (endorsement, cancellation, address/vehicle/beneficiary change, re-issue) | policies/{policy}/service-requests, mobile/policy-service-requests(/{id}/messages) | **/account/requests** (new) | side nav, policy page "New request" | **added** |
| Renewal | policies/{policy}/renewal-quote → compare on /account/quotes/{id} | /account/requests#renew | policy page "Get renewal quotes" | **added** |
| Claims FNOL, drafts, evidence upload, timeline, withdraw | mobile/claims, claims/drafts, claims/{id}/evidence, timeline, withdraw | /account/claims, /account/claims/new, /account/claims/{id} | side nav, policy page | existing |
| Claim appeal | mobile/claims/{id}/appeals | /account/claims/{id} "Appeal this decision" (DECLINED, PARTIALLY_APPROVED, PAID, CLOSED) | claim page | **added** |
| Emergency assistance | mobile/claims/emergency-assistance | /account/support (card, active policies only) | side nav | **added** |
| Notifications | mobile/notifications, read, read-all | /account/notifications | bell, side nav | existing |
| Support tickets | mobile/support/cases, messages | /account/support | side nav, claim / policy pages | existing; **fixed** |
| Vehicles / assets | mobile/assets | /account/vehicles | side nav | existing |
| Account deletion | public page | /account/delete | privacy page (erasure request) | existing |

## Defects fixed

1. **Claim page "Contact" link lost the claim.** `/account/claims/{id}` links to `/account/support?claim_id=…`, but the support page only read `?policy=`, so the case was created unlinked and under "General question". It now sends `claim_id` / `payment_id` and preselects the topic.
2. No web page for KYC, consents, data-subject requests, notification preferences, devices, message language, policy service requests, renewals, counter-offers, claim appeals or emergency assistance, although the app and API offer them (table above).
3. Profile page had no path to identity verification or privacy settings; it now links to both and to policy requests.

## Checks

- **EN/FR:** all new copy lives in `resources/lang/{en,fr}/account_customer.php` (key sets asserted equal); new side-nav labels in `account.php` (asserted equal). The crawl fails on any raw `account*.x.y` key in rendered text, in both languages.
- **Money:** all amounts go through `Opes.money` (minor units ÷ 100, `Intl.NumberFormat` fr-FR / en-GB, suffix FCFA); counter-offer totals use the same helper.
- **Dates:** `Opes.date` formats in the Africa/Douala zone with the page locale; the new pages use it for every date (consents, devices, data-request due dates, request timeline).
- **Mobile width:** new pages use the existing `agrid c2` / `main-side` / `op-form` layouts, which collapse to one column under 760 px; action rows use wrapping `btnbar` / `op-case` flex rows; no fixed widths.

## Remaining gaps (not built, by design or out of scope)

- **Settlement accept/reject** (`claims/{id}/settlement/decision`) and **refund request** (`payments/{id}/refunds`) need `step-up` (OTP challenge through `mobile/security/step-up/*`); the web shell has no step-up dialog yet. The claim page shows the settlement read-only. Next step: a shared step-up modal in portal.js.
- **Inspection reschedule**, **incident edit** and **third-party parties** on a claim (`claims/{id}/inspection/reschedule`, `incident`, `parties`) are shown read-only on the claim page.
- **Deliveries** (`mobile/deliveries/*`, physical certificate delivery) and **support attachments** (`support/cases/{id}/attachments`, multipart) have no web UI.
- Push tokens, device attestation, sync/offline queue and runtime telemetry are app-only by nature.
- The pages load data in the browser. The crawl checks rendering and the API contract (every read the pages make returns 200 for a customer; the new writes succeed), but no browser was driven for this audit because no local web server was running.
