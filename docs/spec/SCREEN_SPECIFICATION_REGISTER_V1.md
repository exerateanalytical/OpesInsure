# OpesInsure Screen Specification Register (SSR) v1.0 — owner specification, locked 2026-09-24

Single source of truth for design, Expo, web, Laravel, QA and API. Baseline: the 428 screen IDs in ENTERPRISE_SCREEN_REGISTER_V1.md. New screens must be classed NEW / REPLACEMENT / MERGE / VARIANT and checked against the register before creation.

## 1. Completion rule
A screen is complete only when identity, route, role, permissions, data, components, actions, validation, API contract, workflow states, navigation, notifications, documents, audit, loading/empty/error states, offline, responsive, localization and security controls are implemented **and tested**. Visual existence is not completion.

## 2. Mandatory screen definition
- **Identity:** Screen ID, canonical name, module, submodule, actor, platform, route, workflow IDs, primary entity, secondary entities.
- **Access:** tenant, role, permission, record-level authorization, branch restriction, assignment restriction, sensitive-data restriction.
- **Navigation:** parent nav item, entry points, previous, next, deep link, notification deep link, breadcrumb, mobile back behaviour.
- **Visual structure:** header, context header, KPI cards, tabs, sections, tables, lists, charts, timeline, status badges, context panel, primary CTA, secondary and overflow actions.
- **Data (per value):** source entity, source field, derived status, formatting, permissions, masking, empty behaviour.
- **Forms (per field):** key, EN label, FR label, type, required, default, validation, dependency, conditional visibility, editable states, permissions.
- **Actions:** label, icon, permission, API/command, confirmation, reason requirement, maker-checker, resulting transition, notification, audit event.
- **API contract:** endpoint, method, authentication, authorization, request DTO, response DTO, validation errors, idempotency, pagination, concurrency.
- **UI states:** loading, populated, empty, search_no_result, validation_error, server_error, network_error, integration_error, permission_denied, record_not_found, stale_record, offline, sync_pending, success. No blank screens.

## 3. Route namespaces
Customer `/app/...` · Agent `/agent/...` · Broker `/broker/...` · Carrier `/carrier/...` · Underwriting `/underwriting/...` · Claims professional `/adjuster/...` · Finance `/finance/...` · Compliance `/compliance/...` · Branch `/branch/...` · Admin `/admin/...` · Developers `/developers/...` · Operations `/operations/...` · Regulatory `/regulatory/...` · Public `/verify/...`

## 4–12. Customer screen specs (routes and required content)
| ID | Route | Required content / actions |
|---|---|---|
| CUST-001 Splash | `/` | Version, environment, stored session, language, config version; validates session, fetches config + feature flags; → CUST-017 if signed in, else CUST-002 |
| CUST-002 Welcome | `/welcome` | Identity, Sign In, Create Account, EN/FR switch, legal links |
| CUST-003 Sign In | `/auth/login` | Phone/email, password/PIN; Sign In, biometric, Forgot Password; `POST /api/v1/auth/login` |
| CUST-004 Registration | `/auth/register` | Phone, email, password + confirmation, terms, privacy consent; Create Account; WF-001 |
| CUST-005 OTP | `/auth/verify` | Masked destination, OTP fields, countdown, resend state; Verify, Resend, Change Number/Email |
| CUST-006 Forgot Password | `/auth/forgot-password` | Phone/email lookup, secure recovery |
| CUST-007 Reset Password | `/auth/reset-password` | OTP/token + new password |
| CUST-008 Account Created | `/auth/registration-success` | CTA "Complete My Profile" |
| CUST-009 Onboarding Overview | `/app/onboarding` | Completion %, identity, contact, KYC, required documents, incomplete items; Continue |
| CUST-010 Personal Information | `/app/onboarding/personal` | Legal name, DOB, nationality, other attributes |
| CUST-011 Contact & Address | `/app/onboarding/contact` | Verified contacts, address |
| CUST-012 Identity Document | `/app/kyc/identity` | Type, number, issuing authority, issue date, expiry |
| CUST-013 ID Capture | `/app/kyc/identity/capture` | Camera, upload, retake, remove |
| CUST-014 KYC Review | `/app/kyc/review` | Read-before-submit; "Submit for Verification" |
| CUST-015 KYC Status | `/app/kyc/status` | Draft, Submitted, Reviewing, Approved, More Information Required, Rejected, Expired |
| CUST-016 KYC Remediation | `/app/kyc/remediation` | Exact deficiency, not "KYC failed" |
| CUST-017 Dashboard | `/app/dashboard` | KPIs Active Policies, Expiring Soon, Outstanding Premium, Active Claims (all drill down); sections Action Required, My Insurance, Claims, Payments, Documents, Notifications; actions Buy Insurance, Get Quote, Pay Premium, Renew, Make a Claim, View Policies |
| CUST-018 Portfolio | `/app/portfolio` | Filters Active, Expiring, Expired, Cancelled, class, insurer |
| CUST-019 Action Centre | `/app/actions` | Priority, entity, action, due date, reason, direct CTA |
| CUST-020 Notifications | `/app/notifications` | Filters policy, claim, payment, renewal, KYC, system |
| CUST-021 Marketplace | `/app/insurance` | Motor, Health, Travel, Property, Personal Accident, Business, Marine/Cargo, configured products |
| CUST-022 Product Search | `/app/insurance/search` | Filters class, insurer, coverage, customer type |
| CUST-023 Product Details | `/app/insurance/products/:productId` | Tabs Overview, Coverage, Exclusions, Eligibility, Requirements, Documents; "Get Quote" |
| CUST-024 Comparison | `/app/insurance/compare` | Premium, coverages, limits, deductibles, exclusions, duration, insurer |
| CUST-025 Needs Assessment | `/app/insurance/needs-assessment` | Dynamic questionnaire feeding eligible products |
| CUST-026 Start Quote | `/app/quotes/new` | Product, policyholder, risk type, effective date |
| CUST-027 Risk Details | `/app/quotes/:quoteId/risk` | Product-driven dynamic fields |
| CUST-028 Coverage Selection | `/app/quotes/:quoteId/coverage` | Base and optional guarantees, limits, deductible, extensions |
| CUST-029 Calculation | `/app/quotes/:quoteId/calculate` | Base premium, loadings, discounts, taxes, fees, total |
| CUST-030 Quote Summary | `/app/quotes/:quoteId` | Number, insurer, product, risk, premium, coverage, validity, requirements; Accept, Save, Download, Share, Request Assistance |
| CUST-031 My Quotes | `/app/quotes` | Draft, Generated, Sent, Accepted, Declined, Expired |
| CUST-032 Quote Details | `/app/quotes/:quoteId/details` | Timeline + pricing version |
| CUST-033 Acceptance | `/app/quotes/:quoteId/accept` | Explicit acceptance |
| CUST-034 Proposal | `/app/proposals/:proposalId` | Applicant, insured, risk, coverage, declarations, beneficiaries, documents, premium |
| CUST-035 Proposal Documents | `/app/proposals/:proposalId/documents` | Required, Uploaded, Rejected, Missing, Accepted |
| CUST-036 Checkout | `/app/checkout/:obligationId` | Final amount before payment |
| CUST-037 Payment Method | `/app/payments/:paymentId/method` | MTN MoMo, Orange Money, Bank, Card, configured alternatives |
| CUST-038 Processing | `/app/payments/:paymentId/processing` | Never shows success before backend verification |
| CUST-039 Result | `/app/payments/:paymentId/result` | Successful, Pending, Failed, Reversed |
| CUST-040 Payments & Receipts | `/app/payments` | Filters policy, status, method, date |
| CUST-041 My Policies | `/app/policies` | Card: insurer, product, number, status, expiry, premium |
| CUST-042 Policy Details | `/app/policies/:policyId` | Tabs Overview, Coverage, Insured Risks, Payments, Claims, Documents, Timeline; state-dependent actions |
| CUST-043 Policy Documents | `/app/policies/:policyId/documents` | Policy, schedule, attestation, endorsement, receipt, renewal notice |
| CUST-044 Request Change | `/app/policies/:policyId/endorsements/new` | — |
| CUST-045 Endorsement Status | `/app/endorsements/:endorsementId` | — |
| CUST-046 Renewal | `/app/policies/:policyId/renew` | Current vs renewal terms, changes, premium, validity |
| CUST-047 Renewal Payment | `/app/renewals/:renewalId/payment` | — |
| CUST-048 Cancellation | `/app/policies/:policyId/cancellation` | Implications shown before confirmation |
| CUST-049 Claims Dashboard | `/app/claims` | Action Required, Active, Recently Updated, Closed |
| CUST-050 FNOL | `/app/claims/new` | Steps: policy, incident type, date/time, location, persons/assets, description, initial evidence, review, submit |
| CUST-051 Evidence | `/app/claims/:claimId/evidence` | Category, upload time, uploader, hash, review status |
| CUST-052 Claim Details | `/app/claims/:claimId` | Tabs Overview, Evidence, Assessment, Decision, Settlement, Communications, Documents, Timeline; Upload Evidence, Respond, Accept Settlement, Appeal, Contact Agent when applicable |

## 13. Agent specs
AGT-004 `/agent/dashboard` (KPIs Leads, Quotes, Policies Issued, Premium Produced, Renewals Due, Open Claims, Commission Earned; queues leads to contact, quotes to follow up, proposals missing info, payments pending, renewals, claims; actions New Lead, Create Customer, New Quote, Initiate Payment, Start Claim, Search Customer) · AGT-013 `/agent/customers/:customerId` (tabs Overview, KYC, Quotes, Policies, Vehicles, Payments, Claims, Documents, Communications, Tasks, Timeline; permission-scoped) · AGT-020 `/agent/customers/:customerId/quotes/new` (no premium manipulation outside rules) · AGT-027 `/agent/quotes` (Draft, Generated, Sent, Viewed, Accepted, Lost, Expired) · AGT-032 `/agent/proposals/:proposalId/underwriting` · AGT-035 `/agent/customers/:customerId/payments/new` (agent initiates; backend confirms; never converts failed to paid) · AGT-039 `/agent/policies` · AGT-044 `/agent/renewals` (90/60/30/15/7 days, overdue/lapsed) · AGT-051 `/agent/claims` · AGT-055 `/agent/commissions` (Accrued, Earned, Pending, Paid, Reversed; drill-down to transactions).

## 14. Broker specs
BRK-001 `/broker/dashboard` (GWP, collected, outstanding, active policies, renewals, open claims, broker commission, carrier payables; production trends, branch/agent performance, product/insurer mix, financial and claims position, compliance alerts, exceptions) · BRK-013 `/broker/customers/:customerId` · BRK-031 `/broker/quotes/dashboard` · BRK-035 `/broker/quotes/:quoteId/override` (requested change, old/new premium, reason, requester, approval authority, permanent audit) · BRK-040 `/broker/underwriting` (New, Assigned, Information Required, Awaiting Carrier, Conditional, Approved, Declined, SLA Breach) · BRK-045 `/broker/payments/dashboard` · BRK-050 `/broker/payments/unmatched` (provider ref, amount, date, payer, probable customer/invoice, confidence, controlled matching) · BRK-054 `/broker/policies/dashboard` · BRK-058 `/broker/policies/issuance-failures` (proposal, customer, payment, insurer, retries, failure, trace, last attempt, next action; Retry, Escalate, Resolve Manually, Controlled Refund; idempotent) · BRK-064 `/broker/renewals` (Identified → Assigned → Contacted → Quoted → Accepted → Payment Pending → Renewed; Lost, Lapsed) · BRK-073 `/broker/motor/stickers` (Received, Available, Allocated, Assigned, Issued, Damaged, Lost, Void, Returned) · BRK-077 `/broker/claims` (New, Coverage Review, Evidence Required, Assessment, Investigation, Decision, Settlement, Appeal, SLA Breach) · BRK-078 `/broker/claims/:claimId` (13 tabs + context panel) · BRK-084 `/broker/claims/:claimId/decision` (Approve, Partially Approve, Reject, Further Review; reason, coverage, deductible, assessed, approved, authority) · BRK-089 `/broker/commissions` · BRK-091 `/broker/settlements`.

## 15–22. Enterprise specs
CAR-001 `/carrier/dashboard` · CAR-013 `/carrier/products` (versions, effective periods, distribution, publication) · CAR-019 `/carrier/tariffs` (never overwrite history; version, effective from/to, approval, products, rules) · CAR-025 `/carrier/policies/issuance` · CAR-030 `/carrier/claims` · UND-001 `/underwriting/dashboard` · UND-005 `/underwriting/cases/:caseId` (10 tabs; Request Information, Adjust Authorized Terms, Conditional Acceptance, Approve, Decline, Escalate) · UND-018 decision (outcome, reason, findings, conditions, coverage and premium changes, approval level) · CLP-001 `/adjuster/dashboard` · CLP-011 `/adjuster/claims/:claimId/inspection` · CLP-014 report builder (never the final decision) · FIN-001 `/finance/dashboard` · FIN-013 `/finance/ledger` · FIN-019 `/finance/settlements` (Draft → Calculated → Review → Approved → Processing → Settled → Reconciled) · FIN-021 `/finance/reconciliation` · CMP-001 `/compliance/dashboard` · CMP-009 `/compliance/cases/:caseId` (records the trigger) · ADM-001 `/admin/dashboard` · ADM-020 `/admin/access/permissions` (Role × Module × Resource × Action × Scope) · ADM-026 `/admin/documents/templates` · ADM-028 `/admin/workflows` · DEV-001 `/developers` · DEV-002 `/developers/docs` · DEV-004 `/developers/credentials` (secret shown once) · DEV-009 `/developers/webhooks` · DEV-011 delivery logs · OPS-001 `/operations` · OPS-007 `/operations/jobs/failed` · OPS-009 `/operations/integrations` · OPS-020 `/operations/incidents` · SHR-006 `/verify` · SHR-005 `/verify/:code` (VALID, EXPIRED, REVOKED, REPLACED, NOT FOUND; minimal public data).

## 24–33. Canonical standards
- **Buttons:** every button has visibility rule (state + permission + thresholds), click flow (confirmation → form → POST command), backend chain (authorization, guard, lock/version, persistence, event, audit, notification, downstream) and resulting transition. Never `onclick = update status`.
- **Tables:** search, filters, sorting, pagination, column customization, saved filters, export permission, row selection, safe bulk actions, status, owner, date, SLA, contextual actions; row opens detail.
- **Timeline entries:** timestamp, actor, actor role, event, resulting state, reason, attachment, source channel.
- **Financial display:** base + additions + loadings − discounts + taxes + fees = total; calculation basis visible to authorized users.
- **Status vocabulary:** one canonical key per state from the API (e.g. `information_required`), localized labels on the client (EN "Information Required", FR "Informations requises").
- **Localization:** EN + FR titles, labels, statuses, notifications, documents; no hard-coded strings.
- **Responsive:** desktop cockpit; mobile prioritizes status → required action → key info → primary CTA → timeline → details; Expo uses native patterns with identical rules and APIs.
- **Offline:** cache policy summary, downloaded documents, profile, agent records; drafts allowed for FNOL, evidence, field inspection; never finalize offline: payment success, issuance, reconciliation, commission settlement, claim approval, refunds.
- **Workflow integrity:** UI → API → Authentication → Authorization → Validation → Domain Command → Transition Guard → Transaction → Persistence → Domain Event → Outbox → Audit → Notification/Integration → Response.
- **Entity continuity:** one customer, quote, proposal, payment, policy, endorsement, claim, document, financial transaction; e.g. CUST-052, AGT-053, BRK-078, CAR-031, CLP-005 all show the same claim_id.

## 34. Definition of Done (per screen)
Screen ID · route · role restriction · record authorization · desktop/mobile layout · English · French · API connected · real persisted data · no demo-only calculations · loading · empty · error · permission-denied · validation · primary actions · secondary actions · server-side guards · audit events · notifications · documents · financial reconciliation · retry handling · idempotency · automated tests · RBAC tests · tenant-isolation tests · accessibility review · security review · UAT acceptance.

## 36. No-gap rule
Actor → entry point → workflow → every required screen → actions → API command → domain logic → transition enforced → state persisted → audit/event → notifications/documents/accounting → consistent across Customer, Agent, Broker, Carrier and specialists. Any missing link means the module is not complete.

## 37. Locked architecture
Fourteen experiences (Customer, Agent, Broker ERP, Carrier, Underwriting, Claims Professional, Finance, Compliance, Branch, Platform Admin, Developer, Operations, Regulatory, Public Verification) consuming one governed set of domains (Customer, Product, Quote, Underwriting, Policy, Payment, Claims, Document, Commission, Settlement, Compliance, Identity & Access, Notification, Audit) through one versioned API.

## Open decision recorded
The current Expo app uses routes without the `/app` prefix (e.g. `/policy/[id]`) and deep links map `/app/...` → in-app paths. Adopting the SSR route namespace for the Expo customer app, or treating SSR routes as web routes with an Expo route map, is to be decided.
