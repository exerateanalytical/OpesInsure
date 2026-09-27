# Backend needs from the mobile full audit (2026-09-27)

Source: `app screens/OpesInsure_Mobile_Full_Audit_2026-09-27.json`. The mobile app already
builds against these shapes where possible and hides or labels "not available yet" until
they exist. Server-side authorization stays authoritative for every item.

## A. Cross-cutting
1. **Capabilities (ARCH-004/005):** `GET /mobile/capabilities` → `{data:{modules:{<module>:{view:bool, actions:[string]}}}}`, and `allowed_actions: string[]` on every detail resource (policy, claim, quote, proposal, payment, party).
2. **Dashboard metrics (DASH-002/003):** `metrics[]` on `GET /mobile/{agent|broker|carrier}/dashboard` gain `key` (e.g. `renewals_due`), localized labels, optional `href`/`filter`, `tone`. Add work-queue metrics: leads due, quotes pending, unpaid premiums, claim tasks, reconciliation exceptions, bordereau discrepancies, SLA breaches — each with a filter param the list endpoints accept.
3. **Agent/broker module permissions (NAV-003):** expose module permission codes in `activeWorkspace.permissions` like carrier.
4. **Telemetry (PERF-004):** accept `route` and `app_build` attributes; optional `APP_ANR` and `SCREEN_SLOW` (`duration_ms`) events.
5. **Public indicative quote (LAND-002):** `POST /public/indicative-quotes` `{line_code, answers{}}` → `{offers:[{carrier_id, carrier_name, logo_url, product_name, indicative_premium_minor, currency}]}`; anonymous, throttled, no PII stored.
6. **Search (FLT-005):** include business registration number in `GET /search`.

## B. Security (SEC / SEC-ACC / FRAUD)
1. `GET /mobile/account/devices` rows: `model, os_version, app_version, first_seen_at, last_auth_method, attestation_status, approx_location` (server-derived country/city, never GPS).
2. `GET /me/security/login-activity` rows: `outcome (SUCCESS|FAILED), app_version, masked_ip`, and `event_type` for failed sign-in, OTP/biometric, password change, revocation, logout, session expiry, step-up and attestation failures.
3. Security alerts (push/email/SMS per preferences, deep link `/account/security`): new device, password/identity change, logout-all, repeated failed sign-ins, integrity failure, payout destination change.
4. Staff security: `GET /partner/staff/{user}/security` → `{status, last_sign_in_at, active_sessions, security_state}` (no device/IP without `security.centre.read`); `POST .../suspend-access`, `POST .../force-reauth` (permission-gated).
5. Step-up purposes: accept and enforce `PAYOUT_DESTINATION_CHANGE`, `PROFILE_SECURITY_CHANGE`, `SIGN_OUT_EVERYWHERE`.
6. Device assess: verify Play Integrity / App Attest tokens when `provider` is `PLAY_INTEGRITY` / `APP_ATTEST`.

## C. Agent portal (AGT / COM)
1. `GET /mobile/agent/commissions`: add `policy_number, customer_id/name, carrier_id/name, line_code, product_id/name, product_family_id, premium_minor, rate_bps, basis, paid_minor, sale_at, issued_at, accrued_at, paid_at, statement_number, adjustments[], audit[]`; accept filters `carrier_id, product_id, product_family_id, status[], lifecycle=unpaid|paid, date_basis, from, to` with matching totals. Never accept `producer_user_id` or another partner id (COM-008).
2. Withdrawals: `GET /mobile/agent/withdrawals`, `GET /mobile/agent/withdrawals/{id}` (`payout_number, transaction_reference, failure_reason, approved_at, processing_at, paid_at, audit[], can_cancel`), `POST .../{id}/cancel`.
3. `GET /mobile/agent/sales/{id}`: add `proposal_id, policy_id, payment_attempts[] (id, status, provider, failure_reason, created_at), payment_verified_at, receipt_url, issuance_status, commission_status`.
4. `GET /mobile/partner/agent/claims/{id}` with timeline and information requests; `POST .../claims/{id}/documents` (agent's own book only).
5. `POST /mobile/partner/agent/renewals/{policy}/requote` → sale/quote with `renewal_of_policy_id`; renewal work-item status on the renewals list.
6. `GET /mobile/partner/agent/clients/{id}/payments`.
7. AGT-007: `AGENCY_ADMIN` / `AGENCY_STAFF` roles with organisation membership and staff endpoints like broker.

## D. Broker portal (BRK / FLT / CUST)
1. Client lists (`/mobile/broker/clients`, `/mobile/agent/clients`): add `customer_type, ownership (OWN_ORIGINATED|ASSIGNED|ORGANIZATION), producer_user_id, producer_name, carrier_ids[], line_codes[], product_ids[], kyc_status (broker), open_claims`; accept filters `carrier_id, line_code, product_id, customer_type, ownership, producer_user_id, branch_id, policy_status, renewal_within_days, payment_state, kyc_status, city, q, page, per_page`.
2. Assisted broker quote: allow partners into the quote capture flow with a `customer_id` override, or `POST /mobile/partner/broker/sales {customer_id, line_code, risk_facts}` → `{id, quote_id, status}`.
3. Detail endpoints: `GET /mobile/partner/broker/policies/{id}` (payments, documents, commission attribution, receivable state, timeline); `GET /mobile/partner/broker/claims/{id}` (evidence requirements, info requests, inspection, timeline); `GET /mobile/broker/renewals/{id}`.
4. Renewal actions: `POST /mobile/broker/renewals/{policy}/requote`, `/customer-decision {decision}`, `/assign {user_id}`.
5. Receivables: `GET /mobile/broker/receivables/{id}` → `allocations[], reconciliation_events[], source_transactions[]`; CSV export.
6. Compliance: `GET /mobile/broker/compliance/{id}` → `issuer, verification, document_url, requirements[], review_history[]`.
7. Staff lifecycle (broker admin only): `PATCH /mobile/partner/broker/staff/{membership} {role_code|status}`, `DELETE .../staff/invitations/{id}`, `POST .../staff/{membership}/transfer-work {to_user_id}`; per-member `last_active_at`, `assigned_work_count`.
8. Commissions: `producer_id`, `producer_name` on `/mobile/partner/broker/commissions` accruals (broker admin scope); `producer_user_id` filter limited to the broker's own organisation.

## E. Insurer portal (CAR / ISS)
1. Detail GETs, each with `capabilities: string[]`: `/mobile/partner/carrier/proposals/{id}`, `/products/{id}` (coverages, exclusions, eligibility, risk questions, commission, channels, regulatory docs, status audit), `/mobile/carrier/issuance/{id}` (`stage`, `approvals[]`, verified payment, wording version), `/mobile/partner/carrier/policies/{id}`, `/payments/{id}` (gateway ref, webhook verification, allocation, refunds, audit), `/partners/{id}`.
2. Issuance maker-checker: `POST .../issuance/{id}/request-correction {reason}`, `.../verify`, `.../second-approve`; approve returns 409 `AUTHORITY_EXCEEDED` / `SECOND_APPROVAL_REQUIRED`; queue rows carry `capabilities, stage, approvals`.
3. Delegated authority: `GET /mobile/partner/delegated-authority` (carrier, products, territory, limits, risk classes, dates, actions, second-approval threshold, agreement ref); audit every use.
4. Referrals: `POST /mobile/carrier/referrals/{id}/assign {assignee_id}`, `/escalate {reason}`, `/conditions {conditions[]}`; GET adds `decisions[], assigned_to, capabilities`.
5. Settlements: `POST /mobile/carrier/settlements/{id}/{review|approve|return|reconcile|record-payment|resolve-discrepancy}`, proof upload, `GET .../export`, `capabilities`.
6. Bordereaux: `POST /mobile/carrier/bordereaux/{id}/{validate|return|accept|reject|reconcile}`, `GET .../export`; items with `policy_number, validation_errors, discrepancies`; `capabilities`.
7. Carrier staff: `GET /mobile/partner/carrier/staff`, `POST .../staff/invitations {phone_e164, role_code}`, `POST .../staff/{membership}/{suspend|reactivate|revoke}` gated on `identity.roles.manage` / `identity.invite`.
8. Claims file: inspection, repair, reserves, fraud flags, assignment, settlement endpoints on the carrier claim.
9. Grant finance/claims/compliance roles the matching `carrier.*` permissions scoped to their carrier (today only demo roles get `*`).

## F. Already agreed earlier today
- `POST /mobile/claims` accepts `latitude`/`longitude` (a4d3c59) — confirm when live.
- Top-level `latitude`/`longitude` on customer profile and quote risk answers — after current deploy.
