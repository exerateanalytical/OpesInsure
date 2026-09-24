# Master + Enterprise Screen Register — gap analysis (2026-09-24, 428 screens)

Read-only comparison of the owner's 220 canonical screens (`docs/spec/MASTER_SCREEN_REGISTER_V1.md`) against the code as it stood when this was read. Other agents were still editing code, so recently added mobile files may change.
Inputs: `docs/audit/SCREEN_REGISTER.md` (289 existing screens), `docs/spec/WORKFLOW_REGISTER_SPEC.md` (WF-001..090), `docs/audit/DASHBOARDS_SPEC.md`, `mobile app/app/**` (133 route files), `app/Filament/**`, `resources/views/**`, and `php artisan route:list` (611 routes).

## Coverage levels
- **FULL**: a dedicated screen exists and is wired to a real API, and it has the main "must carry" content for its purpose. No screen meets the owner's full per-screen specification (all states, EN/FR, maker-checker, audit event, breadcrumb). FULL here means the working flow is complete, not that the screen is spec-perfect.
- **PARTIAL**: something real exists, but it is combined with another screen, is a list with no detail or actions, or is on the wrong surface. For brokers, a screen that exists only in Filament counts as PARTIAL at best.
- **MISSING**: no screen exists.

## Key facts about the surfaces
- **Mobile** (Expo, `mobile app/app/`). This is the only role-facing surface that works. Customers have a complete stack. Agents have 18 screens and brokers have 15, and apart from the dashboards, the broker screens are mostly **lists with no detail pages** (quotes, policies and claims are list-only).
- **Web role portals** (`/portal/{customer|agent|broker|carrier}`). These are one generic placeholder template (`resources/views/wave10/portal.blade.php`) that shows raw counts. It has no navigation and no actions. **No role screen is implemented on the web.** The only canonical web screen is the new public `/verify` page.
- **Filament `/admin`**. The panel access rule is `User::canAccessPanel`, which lets in SYSTEM/PLATFORM/COMPLIANCE/FINANCE admins, CLAIMS_MANAGER/OFFICER, **BROKER_STAFF and AGENT**. What each role can see after login depends on the resource policies (`app/Policies/*`):
  - BROKER roles appear only in Consent, InsuranceProduct, Party, TenantBranch, TenantCustomer, TenantInvitation and TenantMembership policies. Broker staff can open **Customers, Parties, Products, Consents, Branches, Invitations and Memberships**.
  - Claims (`ClaimPolicy`: platform, compliance and claims roles only), policies, payments, underwriting, renewals, stickers and settlements are closed to broker staff. Those pages exist, but only platform staff can use them.
  - Scoping is by **tenant** (`TenantContext`), not by broker firm or book of business.
  - Most resources are List/View only and have few actions. For example, `ViewClaim` has only Assign and Reserve.
  - The Filament dashboard has no widgets (`app/Filament/Admin/Widgets` is empty).
- **Backend**:
  - Full-domain REST covers claims (assign, decisions, evidence verify, disputes, payments, reserves, recoveries), policies (transactions, approve/reject), proposals (documents review), underwriting (assign, decision, referrals), reconciliation, settlements, partner statements, refunds, certificates (void), sticker batches and `compliance/audit-log`. Endpoints marked "(ops API)" below come from this domain REST.
  - Mobile adapters: mobile/agent, mobile/partner/agent, mobile/broker, mobile/partner/broker, and customer mobile/*.
  - Broker **mobile** endpoints are read-only lists. None of them take actions.

Short names used in the table: `m:` = `mobile app/app/`, `F:` = Filament `/admin/<resource>` (staff-only unless marked "broker-accessible").

---

## Summary

| Actor | Canonical | FULL | PARTIAL | MISSING | FULL % | Any implementation % |
|---|---|---|---|---|---|---|
| Customer (CUST) | 52 | 18 | 27 | 7 | 35% | 87% |
| Agent (AGT) | 56 | 7 | 16 | 33 | 13% | 41% |
| Broker (BRK) | 92 | 0 | 44 | 48 | 0% | 48% |
| Shared (SHR) | 20 | 8 | 6 | 6 | 40% | 70% |
| **Total (v1)** | **220** | **33** | **93** | **94** | **15.0%** | **57.3%** |

### All 428 screens (v1 plus the enterprise register `ENTERPRISE_SCREEN_REGISTER_V1.md`)

| Area | Canonical | FULL | PARTIAL | MISSING | Any implementation % | Main surface today |
|---|---|---|---|---|---|---|
| Customer (CUST) | 52 | 18 | 27 | 7 | 87% | Mobile |
| Agent (AGT) | 56 | 7 | 16 | 33 | 41% | Mobile |
| Broker (BRK) | 92 | 0 | 44 | 48 | 48% | Mobile (read-only) + Filament (mostly staff-only) |
| Shared (SHR) | 20 | 8 | 6 | 6 | 70% | Mobile (+ web `/verify`) |
| Carrier (CAR) | 34 | 3 | 17 | 14 | 59% | Mobile carrier stack; Filament config is staff-only (CARRIER_* roles are refused by `canAccessPanel`) |
| Underwriting (UND) | 20 | 0 | 5 | 15 | 25% | Mobile carrier referrals + Filament UnderwritingCases (no actions); there is no UNDERWRITER role |
| Claims professional (CLP) | 18 | 0 | 4 | 14 | 22% | Filament Claims + workspace claims module; there is no ADJUSTER role |
| Finance (FIN) | 24 | 0 | 15 | 9 | 63% | Filament list/view pages (mostly without actions) |
| Compliance & Risk (CMP) | 20 | 0 | 7 | 13 | 35% | Filament RiskAlerts / ComplianceCases / RegulatoryReports |
| Branch management (BRM) | 16 | 0 | 1 | 15 | 6% | None; there is no BRANCH_MANAGER role and nothing is branch-scoped |
| Platform admin (ADM) | 34 | 10 | 9 | 15 | 56% | Filament |
| API / Developer (DEV) | 14 | 0 | 3 | 11 | 21% | Admin side of Filament only; no partner-facing portal |
| Security & Tech Ops (OPS) | 20 | 0 | 5 | 15 | 25% | Filament IntegrationHealth / delivery logs; Horizon is in vendor but not configured |
| Regulatory reporting (REG) | 8 | 0 | 2 | 6 | 25% | Filament RegulatoryReports |
| **Total (428)** | **428** | **46** | **161** | **221** | **48.4%** | FULL = **10.7%** |

Enterprise screens only (208): 13 FULL / 68 PARTIAL / 127 MISSING, so 38.9% have some implementation. Staff-facing Filament pages are the right surface for FIN/CMP/ADM/OPS/REG, so they can count as FULL. They rarely do, because most resources are list/view only without the domain actions that the API already has. For example, Journals has no reverse or approve, CarrierSettlements has no submit/approve/paid, TariffVersions has no submit/approve, and Reconciliations has no resolve.

Roles the enterprise register assumes but `app/Application/Identity/RoleCatalogue.php` does not define: UNDERWRITER, ADJUSTER/EXPERT, BRANCH_MANAGER, DEVELOPER/partner integrator. The catalogue has AGENT, BROKER_ADMIN/STAFF, CARRIER_ADMIN/STAFF, CLAIMS_MANAGER/OFFICER, COMPLIANCE_ADMIN, CUSTOMER, FINANCE_ADMIN/MANAGER, PLATFORM_ADMIN and SYSTEM_ADMIN.

Totals by surface:
- **Mobile**: all 33 FULL screens, and 63 of the PARTIAL screens have a mobile implementation.
- **Filament**: 31 broker PARTIAL screens exist only in Filament, and 26 of them are staff-only.
- **Web**: no screens for any logged-in role. The only canonical web screen is SHR-006 Public Verification (the new `/verify` page).

Notes:
- **Broker, 0 FULL**: no broker screen combines a broker-facing surface with actions. The mobile broker stack is read-only, and the Filament screens with actions are closed to broker staff.
- **Agent**: the agent sees only the Lead/Client/Sale part of the journey. There are no agent screens for proposal, underwriting, policy detail, endorsement, motor/sticker or claims (AGT-029..054).
- **Dashboards**: every dashboard fails the KPI drill-down rule in DASHBOARDS_SPEC (see SCREEN_REGISTER, section "26 dashboards").

### The 20 highest-value MISSING screens (by workflow impact)
| # | ID | Screen | Why (workflows unblocked) | Backend |
|---|---|---|---|---|
| 1 | BRK-080 | Evidence Review | WF-052; the broker claim chain breaks at step 4 | `POST claims/{id}/evidence/{doc}/verify` exists |
| 2 | BRK-079 | Coverage Review | WF-050; needed before any claim decision | needs API (coverage validation) |
| 3 | BRK-081 | Expert Assignment | WF-053, WF-054; adjuster role does not exist | needs API + adjuster role |
| 4 | BRK-058 | Failed Issuance Queue | WF-084; paid policies stuck with nobody working them | needs query API (issuance status exists) |
| 5 | BRK-068 | Paid Renewal Issuance Exceptions | WF-087, WF-043 | needs API |
| 6 | BRK-049 | Failed Payments | WF-085, WF-025 | needs list API (retry exists) |
| 7 | BRK-051 | Duplicate Payment Review | WF-086, WF-063 | needs API |
| 8 | AGT-052 | Assisted FNOL | WF-048, WF-049; no agent claims at all | `claims/fnol` exists; needs agent-scoped auth |
| 9 | AGT-040 | Agent Policy Details | WF-034, WF-035, WF-044; agent policy list goes nowhere | needs agent-scoped policy show |
| 10 | BRK-020 | KYC Review Queue | WF-003, WF-004, WF-075 | `documents/{d}/review` exists; needs queue API |
| 11 | CUST-016 | KYC Remediation | WF-075, WF-074 | needs API (remediation state) |
| 12 | BRK-042 / AGT-033 | Information Request Mgmt / Agent Info Request | WF-019; carrier can request info on claims only | needs UW info-request API |
| 13 | BRK-087 | Claim Appeal (broker) | WF-062; customers can appeal but nobody can resolve it | `claims/{id}/disputes/{d}/resolve` exists |
| 14 | BRK-062 | Cancellation Queue | WF-045; customer cancellation requests are service tickets with no approver screen | policy transactions approve exists |
| 15 | BRK-075 / AGT-049 | Sticker Allocation / Assignment | WF-032, WF-033 | needs API (only batch create exists) |
| 16 | BRK-011 (+009/010) | Lead Assignment / Directory | WF-006, WF-007; leads exist for agents only | needs broker lead API |
| 17 | AGT-029 | Proposal Builder | WF-015, WF-016 by agent | `proposals` REST exists; needs agent auth |
| 18 | SHR-013 / SHR-014 | Complaint Form / Details | WF-071, WF-072; complaints are not modelled (support tickets only) | needs API |
| 19 | CUST-023 | Product Details | WF-008, WF-009 | `catalogue/products/{product}` exists |
| 20 | SHR-001 | Global Search | Cross-module navigation for all roles | needs API |

---

## A. Customer (52)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| CUST-001 | Splash | m:`index.tsx` (session router) + native splash | PARTIAL: no branded bootstrap / offline / update states | `mobile/runtime/bootstrap` | WF-001 | Mobile only |
| CUST-002 | Welcome | m:`welcome.tsx` | FULL | n/a (static) | WF-001 | No web equivalent |
| CUST-003 | Sign In | m:`(auth)/sign-in.tsx` | FULL | `auth/mobile/password-login`, `otp/*` | WF-001 | Shared by every role |
| CUST-004 | Registration | m:`(auth)/sign-up.tsx` | FULL | `public/accounts`, `otp/request` | WF-001 | |
| CUST-005 | OTP Verification | m:`(auth)/verify.tsx` | FULL | `auth/mobile/otp/verify` | WF-002 | |
| CUST-006 | Forgot Password | m:`(auth)/forgot-password.tsx` | FULL | `auth/mobile/password/forgot` | WF-001 | |
| CUST-007 | Reset Password | same file (second step) | FULL | `auth/mobile/password/reset` | WF-001 | Combined with 006, not a separate route |
| CUST-008 | Account Created | none | MISSING | n/a | WF-001 | Sign-up goes straight to home or KYC |
| CUST-009 | Onboarding Overview | none (`onboarding/kyc.tsx` is a single wizard) | MISSING | `mobile/kyc/profile` | WF-003 | |
| CUST-010 | Personal Information | m:`onboarding/kyc.tsx`, `account/profile.tsx` | PARTIAL: one step of the KYC wizard | `mobile/kyc/profile` PATCH, `mobile/account/customer-profile` | WF-003 | |
| CUST-011 | Contact & Address | m:`onboarding/kyc.tsx` | PARTIAL: address not a dedicated step | `mobile/account/customer-profile` | WF-003 | |
| CUST-012 | Identity Document | m:`onboarding/kyc.tsx` (addIdentifier) | PARTIAL | `mobile/kyc/profile` | WF-003 | |
| CUST-013 | ID Capture | m:`onboarding/kyc.tsx` (attachKycDocument) | PARTIAL: no guided camera / quality check | `mobile/kyc/documents`, `mobile/uploads/*` | WF-003 | |
| CUST-014 | KYC Review | m:`onboarding/kyc.tsx` (submitKyc) | PARTIAL: no review summary before submit | `mobile/kyc/submission` | WF-003 | |
| CUST-015 | KYC Status | none (status shown inline only) | MISSING | `mobile/kyc/profile` | WF-003 | |
| CUST-016 | KYC Remediation | none | MISSING | needs API | WF-075, WF-074 | |
| CUST-017 | Customer Dashboard | m:`(customer)/(tabs)/index.tsx` | PARTIAL: no amount due / next payment; KPIs not drillable | `mobile/claims`, `notifications`, `quotes`, `wallet` | WF-034 | Web `/portal/customer` is a placeholder |
| CUST-018 | Insurance Portfolio | m:`(tabs)/policies.tsx`, `wallet/index.tsx` | PARTIAL: no totals, sum insured or insurer breakdown | `mobile/wallet` | WF-034 | |
| CUST-019 | Action Centre | none | MISSING | needs API (pending actions aggregate) | WF-073 | |
| CUST-020 | Notifications Centre | m:`notifications/index.tsx` | FULL | `mobile/notifications`, `read-all` | WF-073 | |
| CUST-021 | Insurance Marketplace | m:`(tabs)/explore.tsx`, `institutions/*`, `quote/product.tsx` | PARTIAL: institutions directory and product picker, no marketplace browse | `public/institutions`, `catalogue/products` | WF-008 | |
| CUST-022 | Product Search | m:`quote/product.tsx` (search box) | PARTIAL | `catalogue/products` | WF-008 | |
| CUST-023 | Product Details | none | MISSING | `catalogue/products/{product}` | WF-008 | |
| CUST-024 | Product Comparison | m:`quote/compare.tsx`, `(tabs)/compare.tsx` (static launcher) | PARTIAL: compares rated offers, not products | `web-experiences/marketplace/comparisons` | WF-009 | |
| CUST-025 | Needs Assessment | none | MISSING | needs API | WF-008 | |
| CUST-026 | Start Quote | m:`quote/product.tsx` | FULL | `quotes` | WF-010 | |
| CUST-027 | Risk Details | m:`quote/risk.tsx` | FULL | `mobile/catalogue/lines/{code}/risk-schema`, `mobile/assets` | WF-010, WF-077 | |
| CUST-028 | Coverage Selection | inside m:`quote/offers.tsx` | PARTIAL: no coverage/option configuration | `quotes/{q}/rate` | WF-010, WF-011 | |
| CUST-029 | Quote Calculation | m:`quote/offers.tsx` | PARTIAL: no recalculation | `quotes/{q}/rate` | WF-010, WF-011 | |
| CUST-030 | Quote Summary | m:`quote/offers.tsx` / `quote/compare.tsx` | PARTIAL: no single summary screen | `quotes/{q}` | WF-010 | Start of the policy chain |
| CUST-031 | My Quotes | m:`quotes/index.tsx` | FULL | `mobile/quotes` | WF-010 | |
| CUST-032 | Quote Details | m:`quotes/[id].tsx` | FULL | `mobile/quotes/{q}`, resume, DELETE | WF-010, WF-014 | |
| CUST-033 | Quote Acceptance | m:`quote/terms.tsx` + offer accept | PARTIAL: acceptance split across terms and checkout | `quotes/{q}/offers/{o}/accept`, `proposals/{p}/terms` | WF-013 | |
| CUST-034 | Insurance Proposal | m:`quote/questions.tsx`, `quote/disclosure.tsx`, `proposals/[id].tsx` | PARTIAL: `disclosure.tsx` is store-only; list and detail are newly added | `proposals/{p}/disclosure*`, `mobile/proposals` | WF-015, WF-016, WF-020 | Counteroffer API (`mobile/proposals/{p}/counteroffer`) |
| CUST-035 | Proposal Documents | m:`proposals/[id].tsx` (upload) | PARTIAL: no requirements checklist | `proposals/{p}/documents` | WF-016, WF-019 | |
| CUST-036 | Checkout | m:`checkout.tsx` | FULL | payments via store | WF-022 | |
| CUST-037 | Payment Method | inside m:`payment.tsx` | PARTIAL: no saved or selectable methods screen | `payments/{p}/initiate` | WF-022, WF-023, WF-024 | |
| CUST-038 | Payment Processing | m:`payment.tsx` (polling) | FULL | `mobile/purchases/{p}/status` | WF-023 | |
| CUST-039 | Payment Result | m:`confirmation.tsx` | FULL | `mobile/purchases/{p}/status` | WF-028, WF-029 | |
| CUST-040 | Payments & Receipts | m:`payments/index.tsx`, `payments/[id].tsx`, `payments/[id]/refund.tsx` | PARTIAL: no outstanding/overdue/due summary | `mobile/payments*` | WF-025, WF-063 | |
| CUST-041 | My Policies | m:`(tabs)/policies.tsx` | FULL | `mobile/wallet` | WF-034 | |
| CUST-042 | Policy Details | m:`policy/[id].tsx` (+ duplicate `wallet/policy/[id].tsx`) → `src/components/policies/PolicyDetailView.tsx` | PARTIAL: against the owner reference spec, missing the Insured and Timeline tabs, a documents list and status-driven actions | `mobile/wallet/policies/{p}` | WF-034 | Owner reference screen |
| CUST-043 | Policy Documents | m:`documents/[id].tsx` (single viewer) | PARTIAL: no document library list | `mobile/documents`, `policy-documents/{d}/download` | WF-030, WF-031 | |
| CUST-044 | Request Policy Change | m:`policy/[id]/service.tsx` (type ENDORSEMENT) | PARTIAL: generic service request; no endorsement quote or additional premium | `mobile/policy-service-requests` | WF-035, WF-037 | `policies/{p}/transactions` exists but the app does not use it |
| CUST-045 | Endorsement Status | m:`services/index.tsx`, `services/[id].tsx` | PARTIAL: shows ticket status, not endorsement state | `mobile/policy-service-requests/{id}` | WF-035, WF-036 | |
| CUST-046 | Renewal | m:`policy/[id]/renew.tsx` | PARTIAL: no comparison with the current term | `policies/{p}/renewal-quote` | WF-041 | |
| CUST-047 | Renewal Payment | reuses the checkout/payment flow | PARTIAL: no renewal-specific screen | `payments*` | WF-042 | |
| CUST-048 | Cancellation Request | m:`policy/[id]/service.tsx` (CANCELLATION_REVIEW) | PARTIAL: no refund estimate or cancellation rules | `mobile/policy-service-requests` | WF-044 | |
| CUST-049 | Claims Dashboard | m:`(tabs)/claims.tsx` | PARTIAL: list only, no KPIs | `mobile/claims` | WF-048 | |
| CUST-050 | Make a Claim / FNOL | m:`claim/new.tsx`, `claim/[id]/incident.tsx`, `claim/[id]/parties.tsx`, `claim/emergency.tsx` | FULL | `mobile/claims` POST, incident, parties, emergency-assistance | WF-048 | |
| CUST-051 | Claim Evidence | m:`claim/[id]/checklist.tsx`, `claim/[id]/evidence.tsx` | FULL | `mobile/claims/{c}/evidence*` | WF-051 | |
| CUST-052 | Claim Details & Timeline | m:`claim/[id].tsx` (+ inspection/repair/settlement sub-screens) | FULL | `mobile/claims/{c}`, `timeline` | WF-048..060 | |

## B. Agent (56)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| AGT-001 | Agent Sign In | m:`(auth)/sign-in.tsx` (shared) | FULL | `auth/mobile/*` | — | AGT can also log into Filament (`canAccessPanel`) |
| AGT-002 | Agent MFA | m:`security/step-up.tsx` | PARTIAL: OTP step-up only, no TOTP enrolment | `mobile/security/step-up/*`, `me/mfa/totp` | — | |
| AGT-003 | Workspace Selection | m:`(auth)/role.tsx` | FULL | session | — | |
| AGT-004 | Agent Dashboard | m:`agent/index.tsx` | PARTIAL: no pipeline or sales KPIs, no drill-down | `mobile/agent/dashboard` | WF-064 | Web `/portal/agent` is a placeholder |
| AGT-005 | Action Centre | none | MISSING | needs API | WF-073 | |
| AGT-006 | Notifications | m:`agent/notifications.tsx` | FULL | `mobile/notifications` | WF-073 | |
| AGT-007 | Lead Pipeline | m:`agent/leads/index.tsx` | PARTIAL: list, no stage pipeline | `mobile/partner/agent/leads` | WF-005, WF-006 | Newly added |
| AGT-008 | Lead Details | m:`agent/leads/[id].tsx` | FULL | leads/{lead} GET/PATCH/convert | WF-006 | Newly added |
| AGT-009 | Create Lead | m:`agent/leads/new.tsx` | FULL | `mobile/partner/agent/leads` POST | WF-005 | |
| AGT-010 | My Customers | m:`agent/clients/index.tsx` | FULL | `mobile/agent/clients` | WF-088 | |
| AGT-011 | Customer Search | search inside the clients list | PARTIAL | `mobile/agent/clients` | WF-088 | |
| AGT-012 | Create Customer | m:`agent/clients/new.tsx` | FULL | `mobile/partner/agent/clients` | WF-003 | |
| AGT-013 | Customer 360 | m:`agent/clients/[id].tsx` | PARTIAL: no tabs for policies, claims, payments or activities | `mobile/agent/clients/{c}` | WF-088 | |
| AGT-014 | Customer KYC | none | MISSING | needs agent-scoped KYC API | WF-003, WF-075 | |
| AGT-015 | KYC Document Capture | none | MISSING | `mobile/uploads/*` (customer-scoped) | WF-003 | |
| AGT-016 | Customer Activities | none | MISSING | needs API | WF-088 | |
| AGT-017 | Product Catalogue | product picker inside m:`agent/sales/new.tsx` | PARTIAL | `catalogue/products` | WF-008 | |
| AGT-018 | Product Details | none | MISSING | `catalogue/products/{p}` | WF-008 | |
| AGT-019 | Needs Assessment | none | MISSING | needs API | WF-008 | |
| AGT-020 | New Quote | m:`agent/sales/new.tsx` | PARTIAL: single assisted-sale form | `mobile/agent/sales` | WF-010 | |
| AGT-021 | Quote Risk Details | inside m:`agent/sales/new.tsx` | PARTIAL: no schema-driven risk form | `mobile/catalogue/lines/{code}/risk-schema` | WF-010 | |
| AGT-022 | Coverage Configuration | none | MISSING | needs API | WF-010, WF-011 | |
| AGT-023 | Quote Calculation | rating inside createSale | PARTIAL | `mobile/agent/sales` | WF-010, WF-011 | |
| AGT-024 | Quote Comparison | none | MISSING | `quotes/{q}` offers | WF-009 | |
| AGT-025 | Quote Details | m:`agent/sales/[id].tsx` | PARTIAL: sale view, not quote lifecycle | `mobile/agent/sales/{id}` | WF-010 | Start of the agent policy chain |
| AGT-026 | Send Quote | none | MISSING | needs API | WF-012 | |
| AGT-027 | Quote Pipeline | m:`agent/quotes.tsx` | PARTIAL: list only | `mobile/partner/agent/quotes` | WF-010, WF-014 | |
| AGT-028 | Lost Quote | none | MISSING | needs API | WF-014 | |
| AGT-029 | Proposal Builder | none | MISSING | `proposals` REST (not agent-scoped) | WF-015, WF-016 | |
| AGT-030 | Proposal Documents | none | MISSING | `proposals/{p}/documents` | WF-016 | |
| AGT-031 | Proposal Review | none | MISSING | `proposals/{p}` | WF-016 | |
| AGT-032 | Underwriting Status | none | MISSING | needs agent-scoped query | WF-017, WF-018 | |
| AGT-033 | Information Request | none | MISSING | needs API | WF-019 | |
| AGT-034 | Conditional Offer | none | MISSING | `mobile/proposals/{p}/counteroffer` (customer) | WF-020 | |
| AGT-035 | Initiate Payment | m:`agent/sales/[id].tsx` (requestPayment) | PARTIAL | `mobile/agent/sales/{id}/payment-request` | WF-022 | |
| AGT-036 | Payment Status | m:`agent/sales/[id].tsx` | PARTIAL | `mobile/agent/sales/{id}` | WF-022, WF-025 | |
| AGT-037 | Payment Assistance | none | MISSING | needs API | WF-025, WF-085 | |
| AGT-038 | Customer Payment History | none | MISSING | needs agent-scoped API | WF-022 | |
| AGT-039 | My Policy Portfolio | m:`agent/policies.tsx` | PARTIAL: list only, rows go nowhere | `mobile/partner/agent/policies` | WF-034 | |
| AGT-040 | Policy Details | none | MISSING | needs agent-scoped show | WF-034 | |
| AGT-041 | Policy Documents | none | MISSING | needs API | WF-030 | |
| AGT-042 | Endorsement Request | none | MISSING | `policies/{p}/transactions` (not agent-scoped) | WF-035 | |
| AGT-043 | Endorsement Tracking | none | MISSING | needs API | WF-035, WF-036 | |
| AGT-044 | Renewal Queue | m:`agent/renewals.tsx` | PARTIAL: list, no actions | `mobile/agent/renewals` | WF-039, WF-040 | |
| AGT-045 | Renewal Details | none | MISSING | `renewals/{r}/quote` | WF-040 | |
| AGT-046 | Cancellation Assistance | none | MISSING | needs API | WF-044 | |
| AGT-047 | Vehicle Profile | none (`assets/*` is customer-only) | MISSING | `mobile/assets` (customer-scoped) | WF-077 | |
| AGT-048 | Vehicle Registration | none | MISSING | `risk-assets` REST | WF-077 | |
| AGT-049 | Sticker Assignment | none | MISSING | needs API | WF-032, WF-033 | |
| AGT-050 | Sticker Handover | none | MISSING | needs API | WF-033 | Delivery confirm exists for the customer only |
| AGT-051 | Claims Portfolio | none | MISSING | needs agent-scoped API | WF-048 | |
| AGT-052 | Assisted FNOL | none | MISSING | `claims/fnol` (not agent-scoped) | WF-048, WF-049 | |
| AGT-053 | Claim Details | none | MISSING | needs API | WF-048..060 | |
| AGT-054 | Claim Evidence Assistance | none | MISSING | `claims/{id}/evidence` | WF-051 | |
| AGT-055 | Commission Dashboard | m:`agent/wallet.tsx`, `agent/withdrawal.tsx` | PARTIAL: no by-period/product view or statements | `mobile/agent/commissions`, `withdrawals` | WF-064, WF-067 | |
| AGT-056 | Tasks & Follow-Ups | none (`agent/offline.tsx` is the sync queue) | MISSING | needs API | WF-006 | |

## C. Broker (92)

Surface rule applied. "Mobile broker" means `m:broker/*`, which is broker-facing but read-only. "F: staff-only" means the Filament resource exists but its policy excludes BROKER_STAFF. Brokers have **no web ERP**.

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| BRK-001 | Executive Dashboard | m:`broker/index.tsx` | PARTIAL: no trends, breakdowns or drill-down | `mobile/broker/dashboard` | — | Web `/portal/broker` is a placeholder |
| BRK-002 | Operations Dashboard | none | MISSING | needs API | WF-081 | |
| BRK-003 | Sales & Production | m:`broker/production.tsx` | PARTIAL: register only, no KPIs or period comparison | `mobile/broker/production` | WF-064 | |
| BRK-004 | Customer Dashboard | none | MISSING | needs API | WF-088 | |
| BRK-005 | Renewal Dashboard | m:`broker/renewals.tsx` | PARTIAL: list, no due-30/60/90 buckets or renewal rate | `mobile/broker/renewals`, `reports/renewals` | WF-039 | |
| BRK-006 | Claims Dashboard | none (list only in `broker/claims.tsx`) | MISSING | needs API | WF-048..060 | |
| BRK-007 | Finance Dashboard | m:`broker/receivables.tsx` | PARTIAL | `mobile/broker/receivables`; `MobileBrokerFinanceController::dashboard` exists but the app does not call it | WF-065, WF-068 | |
| BRK-008 | Compliance & Risk Dashboard | m:`broker/compliance.tsx` | PARTIAL: list | `mobile/broker/compliance` | WF-089, WF-090 | |
| BRK-009 | Lead Directory | none | MISSING | needs broker lead API | WF-005, WF-006 | Leads exist only on the agent side |
| BRK-010 | Lead Details | none | MISSING | needs API | WF-006 | |
| BRK-011 | Lead Assignment | none | MISSING | needs API | WF-007 | |
| BRK-012 | Customer Directory | m:`broker/clients.tsx`; F: Customers (broker-accessible) | PARTIAL | `mobile/broker/clients`, `customers` | WF-088 | |
| BRK-013 | Customer 360 | m:`broker/clients/[id].tsx` | PARTIAL: no tabs or activity | `mobile/broker/clients/{c}` | WF-088 | |
| BRK-014 | Individual Customer Details | F: Customers view (broker-accessible) | PARTIAL: admin CRUD, tenant-scoped | `customers/{c}` | WF-003 | |
| BRK-015 | Corporate Customer Details | F: Parties view (broker-accessible) | PARTIAL: no corporate structure or directors | `parties/{p}` | WF-004, WF-076 | |
| BRK-016 | Customer Activity Timeline | none | MISSING | needs API (audit-log exists) | WF-088, WF-090 | |
| BRK-017 | Duplicate Customer Review | none | MISSING | needs API | WF-088 | |
| BRK-018 | Customer Portfolio Transfer | none | MISSING | needs API | WF-079 | |
| BRK-019 | KYC Dashboard | none | MISSING | needs API | WF-003 | |
| BRK-020 | KYC Review Queue | none | MISSING | `documents/{d}/review` | WF-003, WF-004 | |
| BRK-021 | KYC Case Details | none | MISSING | needs API | WF-003 | |
| BRK-022 | KYC Remediation | none | MISSING | needs API | WF-075 | |
| BRK-023 | Expiring Documents | none | MISSING | needs API | WF-074 | |
| BRK-024 | Corporate Due Diligence | none | MISSING | needs API | WF-004, WF-076 | |
| BRK-025 | Product Catalogue | m:`broker/publications.tsx`; F: InsuranceProducts (broker-accessible) | PARTIAL | `catalogue/products`, `mobile/broker/marketplace-publications` | WF-008 | |
| BRK-026 | Product Details | F: InsuranceProducts edit (broker-accessible) | PARTIAL: admin form | `catalogue/products/{p}` | WF-008 | |
| BRK-027 | Insurer Product Mapping | m:`broker/publications.tsx` (toggle) | PARTIAL | `marketplace-publications` PATCH | WF-008 | |
| BRK-028 | Product Eligibility Rules | none | MISSING | needs API | WF-017 | |
| BRK-029 | Coverage & Guarantee Config | F: CoverageDefinitions, ExclusionDefinitions, TariffVersions (staff-only) | PARTIAL | `catalogue/lines/{l}/coverages`, `tariffs*` | WF-010 | Carrier/platform config, not broker-facing |
| BRK-030 | Product Documents/Requirements | F: DocumentRequirements (staff-only) | PARTIAL | `underwriting/document-requirements*` | WF-016 | |
| BRK-031 | Quote Dashboard | none | MISSING | needs API | WF-010 | |
| BRK-032 | Quote Directory | m:`broker/quotes.tsx`; F: Quotes (staff-only) | PARTIAL: list only | `mobile/partner/broker/quotes` | WF-010, WF-014 | |
| BRK-033 | Quote Details | F: Quotes view (staff-only) | PARTIAL | `quotes/{q}` | WF-010 | Start of the broker policy chain; no broker surface |
| BRK-034 | Exceptional Quote Review | none | MISSING | needs API | WF-081 | |
| BRK-035 | Premium Override Approval | none | MISSING | needs API | WF-081 | |
| BRK-036 | Quote Conversion Analytics | none | MISSING | needs API | WF-013, WF-014 | |
| BRK-037 | Proposal Queue | F: Proposals list (staff-only) | PARTIAL | needs list API | WF-016 | |
| BRK-038 | Proposal Details | F: Proposals view (staff-only) | PARTIAL | `proposals/{p}` | WF-016 | |
| BRK-039 | Proposal Completeness Review | none | MISSING | `proposals/{p}/documents/{d}/review` | WF-016, WF-019 | |
| BRK-040 | Underwriting Queue | F: UnderwritingCases (staff-only); carrier: m:`carrier/referrals` | PARTIAL | `underwriting/cases/{c}/assign` | WF-018 | |
| BRK-041 | Underwriting Case | F: UnderwritingCases view; carrier: m:`carrier/referrals/[id].tsx` | PARTIAL: carrier-facing, not broker | `underwriting/cases/{c}/decision`, `referrals/{r}/resolve` | WF-018, WF-020, WF-021 | |
| BRK-042 | Information Request Management | none | MISSING | needs API | WF-019 | |
| BRK-043 | Conditional Offer Review | none | MISSING | needs API | WF-020 | |
| BRK-044 | Declined Proposal Review | none | MISSING | needs API | WF-021 | |
| BRK-045 | Payment Dashboard | none | MISSING | needs API | WF-026 | |
| BRK-046 | Payment Directory | F: PaymentRequests, PaymentAttempts (staff-only) | PARTIAL | `payments/{p}` | WF-022, WF-026 | |
| BRK-047 | Payment Details | F: PaymentRequests view (staff-only) | PARTIAL | `payments/{p}` | WF-022 | |
| BRK-048 | Pending Payments | none | MISSING | needs API | WF-022 | |
| BRK-049 | Failed Payments | none | MISSING | needs list API | WF-085, WF-025 | |
| BRK-050 | Unmatched Payments | F: Reconciliations (staff-only) | PARTIAL | `reconciliation/imports*`, `items/{i}/resolve` | WF-026, WF-027 | |
| BRK-051 | Duplicate Payment Review | none | MISSING | needs API | WF-086 | |
| BRK-052 | Refund Queue | F: FinancialCases (staff-only) | PARTIAL | `payments/{p}/refunds`, `refunds/{r}/approve` | WF-038, WF-063 | |
| BRK-053 | Refund Details/Approval | F: FinancialCases view (staff-only) | PARTIAL | `refunds/{r}/approve` | WF-063, WF-081 | |
| BRK-054 | Policy Dashboard | none | MISSING | `reports/insurance-portfolio` | WF-034 | |
| BRK-055 | Policy Directory | m:`broker/policies.tsx`; F: Policies (staff-only) | PARTIAL | `mobile/partner/broker/policies`, `policies` | WF-034 | |
| BRK-056 | Policy Details | F: Policies view (staff-only) | PARTIAL | `policies/{p}` | WF-034 | No broker surface |
| BRK-057 | Policy Issuance Queue | F: PolicyIssuances (staff-only); carrier: m:`carrier/issuance.tsx` | PARTIAL | `policy-issuance-requests/*` | WF-028 | |
| BRK-058 | Failed Issuance Queue | none | MISSING | needs API | WF-084 | |
| BRK-059 | Issuance Exception Details | none | MISSING | needs API | WF-084 | |
| BRK-060 | Endorsement Queue | F: PolicyTransactions (staff-only) | PARTIAL | `policies/{p}/transactions*` | WF-036 | |
| BRK-061 | Endorsement Details | F: PolicyTransactions view (staff-only) | PARTIAL | transactions approve/reject | WF-036, WF-037 | |
| BRK-062 | Cancellation Queue | none | MISSING | transactions approve (partial) | WF-045 | |
| BRK-063 | Suspension/Reinstatement Queue | none | MISSING | needs API | WF-046, WF-047 | |
| BRK-064 | Renewal Pipeline | m:`broker/renewals.tsx`; F: Renewals (staff-only) | PARTIAL | `mobile/broker/renewals`, `broker/renewals/seed` | WF-039, WF-040 | |
| BRK-065 | Renewal Case | F: Renewals view (staff-only) | PARTIAL | `renewals/{r}/quote`, `complete` | WF-040, WF-043 | |
| BRK-066 | Renewal Assignment | none | MISSING | needs API | WF-040 | |
| BRK-067 | Lapsed Policies | none | MISSING | needs API | WF-083 | |
| BRK-068 | Paid Renewal Issuance Exceptions | none | MISSING | needs API | WF-087 | |
| BRK-069 | Document Centre | F: Certificates (staff-only) | PARTIAL: certificates only | `certificates` | WF-030, WF-031 | |
| BRK-070 | Document Details | F: Certificates view (staff-only) | PARTIAL | `documents/{d}` | WF-030 | |
| BRK-071 | Document Generation Queue | none | MISSING | needs API | WF-030 | |
| BRK-072 | Revoked/Replaced Documents | none | MISSING | `certificates/{c}/void` | WF-031 | |
| BRK-073 | Sticker Inventory | F: StickerInventory (staff-only) | PARTIAL | `sticker-batches` | WF-032 | |
| BRK-074 | Sticker Batch Details | F: StickerBatches (list/create only) | PARTIAL: no view page | `sticker-batches` | WF-032 | |
| BRK-075 | Sticker Allocation | none | MISSING | needs API | WF-032 | |
| BRK-076 | Sticker Reconciliation | none | MISSING | needs API | WF-032 | |
| BRK-077 | Claims Work Queue | m:`broker/claims.tsx`; F: Claims (staff-only) | PARTIAL: list only | `mobile/partner/broker/claims`, `claims` | WF-049 | |
| BRK-078 | Claim Details | F: `Claims/Pages/ViewClaim.php` (Assign and Reserve only; staff-only) | PARTIAL: against the owner reference spec, missing 9 of 10 tabs, the operational side panel and most context actions | `claims/{id}`, assign, transitions | WF-049..060 | Owner reference screen; no broker surface |
| BRK-079 | Coverage Review | none | MISSING | needs API | WF-050 | |
| BRK-080 | Evidence Review | none | MISSING | `claims/{id}/evidence/{d}/verify` | WF-052 | |
| BRK-081 | Expert Assignment | none | MISSING | needs API | WF-053 | |
| BRK-082 | Assessment Review | none | MISSING | needs API | WF-054 | |
| BRK-083 | Claim Investigation | none (F: RiskAlerts is only loosely related) | MISSING | `trust/fraud-alerts*` | WF-055, WF-089 | |
| BRK-084 | Claim Decision | F: ClaimDecisions list (staff-only); carrier: m:`carrier/claims/[id].tsx` | PARTIAL | `claims/{id}/decisions*` | WF-056, WF-057, WF-058 | |
| BRK-085 | Settlement Preparation | none | MISSING | `claims/{id}/decisions/{d}/payments` | WF-059 | |
| BRK-086 | Claim Payment Tracking | F: ClaimPayments (staff-only) | PARTIAL | `claims/{id}/payments/*` | WF-059 | |
| BRK-087 | Claim Appeal | none | MISSING | `claims/{id}/disputes/{d}/resolve` | WF-062 | |
| BRK-088 | Claim Reopening | none | MISSING | `claims/{id}/transitions` | WF-061 | |
| BRK-089 | Commission Dashboard | m:`broker/commissions.tsx`; F: CommissionAccruals (staff-only) | PARTIAL | `mobile/partner/broker/commissions`, `mobile/broker/commission-accruals` | WF-065, WF-066 | |
| BRK-090 | Commission Statement / Adjustments | m:`broker/receivables.tsx` (statements); F: PartnerStatements (staff-only) | PARTIAL: no adjustments | `mobile/broker/statements*`, `partner-statements*`, commissions clawback | WF-066, WF-067 | |
| BRK-091 | Carrier Settlement Dashboard | F: CarrierSettlements (staff-only) | PARTIAL | `carrier-settlements*` | WF-068 | |
| BRK-092 | Settlement Batch / Reconciliation | F: CarrierSettlements view, Reconciliations (staff-only) | PARTIAL | `settlements/{batch}`, `reconciliation/*` | WF-068, WF-069 | |

## D. Shared (20)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| SHR-001 | Global Search | none | MISSING | needs API | — | |
| SHR-002 | Advanced Search | none | MISSING | needs API | — | |
| SHR-003 | Notification Details | m:`notifications/[id].tsx` | FULL | `mobile/notifications/{n}` | WF-073 | |
| SHR-004 | Document Viewer | m:`documents/[id].tsx` | FULL | `mobile/documents/{d}`, access, download | WF-030 | |
| SHR-005 | Document Verification Result | result state inside m:`verify.tsx` | PARTIAL | `public/certificates/verify`, `public/insurance/verify` | WF-082 | |
| SHR-006 | Public Verification | m:`verify.tsx`; Web `GET /verify` → `app/Interfaces/Http/Controllers/Web/PublicVerifyPageController.php` + `resources/views/public/verify.blade.php` | FULL | same | WF-082 | The web page is new; another agent added it while this was being written |
| SHR-007 | Payment Receipt | m:`payments/[id]/receipt.tsx` (+ PDF view) | FULL | `mobile/payments/{p}/receipt(.pdf)` | WF-022 | |
| SHR-008 | Audit Timeline | none | MISSING | `compliance/audit-log` | WF-090 | |
| SHR-009 | File Upload Manager | m:`sync/index.tsx` | PARTIAL: local queue only | `mobile/uploads/*` | WF-051 | |
| SHR-010 | Camera / Document Capture | m:`assets/[id]/scan.tsx` | PARTIAL: asset-specific | `mobile/assets/{a}/scan` | WF-077, WF-051 | |
| SHR-011 | Support Centre | m:`support/index.tsx`, `support/new.tsx`, `support/faq.tsx` | PARTIAL: FAQ is static | `mobile/support/cases` | WF-070 | |
| SHR-012 | Support Ticket Details | m:`support/[id].tsx` | FULL | `mobile/support/cases/{c}` messages, attachments, escalate | WF-070 | |
| SHR-013 | Complaint Form | none | MISSING | needs API | WF-071 | Complaints are not separate from support |
| SHR-014 | Complaint Details | none | MISSING | needs API | WF-072 | |
| SHR-015 | Claim Settlement Acceptance | m:`claim/[id]/settlement.tsx` | FULL | `mobile/claims/{c}/settlement/decision` | WF-059 | |
| SHR-016 | Claim Appeal Submission | m:`claim/[id]/appeal.tsx` | FULL | `mobile/claims/{c}/appeals` | WF-062 | |
| SHR-017 | Communication Centre | none | MISSING | `communications/logs` (write only) | WF-073 | |
| SHR-018 | User Profile & Settings | m:`account/profile.tsx`, `(tabs)/profile.tsx`, `PortalAccount` | FULL | `mobile/account/profile` | — | F: `/admin/profile` for staff |
| SHR-019 | Security & Sessions | m:`account/security.tsx`, `account/devices.tsx` | PARTIAL: security screen reads the session store only; no password change or MFA UI | `me/password`, `me/mfa/totp`, `mobile/account/devices` | — | |
| SHR-020 | Language & Accessibility | m:`account/language.tsx` | PARTIAL: no accessibility settings | `mobile/account/locale` | — | |

---

## Existing screens that map to no canonical ID

Each of these should either be added to the register or retired.

**Add to the register.** These cover actors that are out of scope for v1, or real customer functions the register does not list:
- **Carrier mobile stack** (16): `m:carrier/*`, including dashboard, products, proposals, referrals and decision, issuance, policies, claims and decision, payments, settlements and detail, bordereaux, partners. This belongs to the Carrier/Underwriter register that is still to be written.
- **Staff workspace** (2): `m:workspace/[role].tsx`, `workspace/[role]/module/[module].tsx`. Belongs to the Claims/Finance/Compliance/Admin registers.
- **Filament platform administration**. Belongs to the Platform/Tenant admin, Master-data, Security and Regulatory registers:
  - Tenants, Users, Memberships, Invitations, Branches, Devices, PrivilegedAccess
  - IntegrationClients, IntegrationDeliveryAttempts, IntegrationHealth, PlatformSettings
  - InsuranceLines, TariffVersions, DisclosureSchemas, ExclusionDefinitions, CancellationRules, CertificateTemplates, CommissionRules
  - Journals, Bordereaux, PartnerPayouts, Partners, PartnerLicences, Attributions
  - ComplianceCases, DataSubjectRequests, RegulatoryReports, RiskAlerts, NotificationDeliveries, MobileIssueReports, Consents, Fulfilments, ClaimReserves
- **Customer screens not in the register**:
  - My assets: `assets/index`, `new`, `[id]`
  - Delivery: `delivery/[id]`, `address`, `confirm` (certificate/sticker delivery)
  - Institutions: `institutions/insurers`, `brokers`, and the profile screens
  - Services: `services/*` (generic policy service requests)
  - Claim sub-screens: `claim/emergency`, `claim/[id]/inspection`, `repair`, `settlement-payment`
  - Quote: `quote/referral`
  - Payments: `payments/[id]/refund`
  - Proposals: `proposals/index`

  Several of these could become sub-states of CUST-042, CUST-052 or CUST-040.
- **Agent screens not in the register**: `agent/onboarding` (agent licensing), `agent/withdrawal`, `agent/offline` (sync queue).
- **Broker screens not in the register**: `broker/staff` (staff and invitations; relates to WF-080 agent suspension), `broker/publications` (partly BRK-027).
- **Shared screens not in the register**:
  - `(auth)/invitation`
  - `security/step-up`, `security/device-status`
  - `account/devices`, `account/notifications`, `account/data-usage`, `account/privacy` (local only; the `mobile/account/consents` and `privacy-requests` APIs exist but the screen does not use them)
  - `system/status`
  - `terms`

**Retire or merge:**
- `m:wallet/policy/[id].tsx` (duplicate of `policy/[id]`)
- `m:(customer)/(tabs)/compare.tsx` (static launcher)
- `m:quote/disclosure.tsx` (store-only; overlaps `questions` and `terms`)
- `resources/views/public/landing.blade.php` (not routed)
- The five `/portal/{portal}` wave10 placeholder portals: replace them with real role web portals or remove them
- Filament default Dashboard (A5, empty)

**System state screens.** These are excluded from the register by the owner's rule: `access-denied`, `session-expired`, `+not-found`. Public web pages: `/`, `/download`, `/demo`.

---

# Enterprise register (208): CAR, UND, CLP, FIN, CMP, BRM, ADM, DEV, OPS, REG

Source: `docs/spec/ENTERPRISE_SCREEN_REGISTER_V1.md`.

Surface rule:
- **Staff areas** (FIN, CMP, ADM, OPS, REG, and claims staff for CLP): Filament is an acceptable surface, because these roles pass `canAccessPanel` and the resource policies.
- **Carrier roles** (CARRIER_ADMIN/STAFF): these do **not** pass `canAccessPanel`. Filament product, tariff and policy pages are staff-only for CAR, so they count as PARTIAL at best.

`F:` = Filament resource under `app/Filament/Admin/Resources/<Dir>`.

## E. Carrier (34)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| CAR-001 | Carrier Executive Dashboard | m:`carrier/index.tsx` | PARTIAL: KPI counts only, no trends or drill-down | `mobile/carrier/dashboard` | — | Web `/portal/carrier` is a placeholder |
| CAR-002 | Carrier Operations Dashboard | none | MISSING | needs API | WF-018, WF-028 | |
| CAR-003 | Production Dashboard | none | MISSING | needs API | — | |
| CAR-004 | Portfolio Dashboard | none | MISSING | `reports/insurance-portfolio` | WF-034 | |
| CAR-005 | Claims Performance Dashboard | none | MISSING | needs API | WF-056..060 | |
| CAR-006 | Broker Production Dashboard | none | MISSING | needs API | WF-065 | |
| CAR-007 | Product Performance Dashboard | none | MISSING | needs API | — | |
| CAR-008 | Carrier Notifications | m:`carrier/notifications.tsx` (PortalNotifications) | FULL | `mobile/notifications` | WF-073 | |
| CAR-009 | Broker Directory | m:`carrier/partners.tsx` | PARTIAL: list only | `mobile/partner/carrier/partners` | — | Newly added |
| CAR-010 | Broker Details | F: Partners view (staff-only) | PARTIAL | `partners/{p}`, `commission-balance` | — | No carrier surface |
| CAR-011 | Broker Agreement Details | none | MISSING | `carrier/delegated-authorities*` | — | |
| CAR-012 | Agent/Intermediary Overview | none | MISSING | needs API | — | |
| CAR-013 | Product Catalogue | m:`carrier/products.tsx` | PARTIAL: activate/deactivate only | `mobile/partner/carrier/products*` | WF-008 | |
| CAR-014 | Product Details | F: InsuranceProducts edit (staff/broker, not carrier) | PARTIAL | `catalogue/products/{p}` | — | |
| CAR-015 | Product Version Management | none | MISSING | `catalogue/products/{p}/submit`, `publish` | — | |
| CAR-016 | Coverage Configuration | F: CoverageDefinitions (staff-only) | PARTIAL | `catalogue/lines/{l}/coverages` | WF-010 | |
| CAR-017 | Exclusions & Conditions | F: ExclusionDefinitions (staff-only) | PARTIAL | `catalogue/lines/{l}/exclusions` | — | |
| CAR-018 | Product Eligibility Rules | none | MISSING | needs API | WF-017 | |
| CAR-019 | Tariff Catalogue | F: TariffVersions list (staff-only) | PARTIAL | `tariffs` | WF-010 | |
| CAR-020 | Tariff Details | F: TariffVersions edit (staff-only) | PARTIAL | `tariffs` | — | |
| CAR-021 | Tariff Versioning | F: TariffVersions (no submit/approve actions) | PARTIAL | `tariffs/{t}/submit`, `approve` | WF-081 | Maker-checker exists in the API only |
| CAR-022 | Rating Rules | none | MISSING | needs API | WF-011 | |
| CAR-023 | Proposal Intake | m:`carrier/proposals.tsx` | PARTIAL: list only | `mobile/partner/carrier/proposals` | WF-016, WF-018 | Newly added |
| CAR-024 | Proposal Details | F: Proposals view (staff-only) | PARTIAL: no carrier detail | `proposals/{p}` | WF-016 | |
| CAR-025 | Policy Issuance Queue | m:`carrier/issuance.tsx` (approve/reject); F: PolicyIssuances (approve/reject) | FULL | `mobile/partner/carrier/issuance/{i}/approve`, `reject` | WF-028 | |
| CAR-026 | Policy Details | m:`carrier/policies.tsx` (list only); F: Policies view (staff-only) | PARTIAL | `mobile/partner/carrier/policies`, `policies/{p}` | WF-034 | |
| CAR-027 | Policy Amendment Review | F: PolicyTransactions (staff-only) | PARTIAL | `policies/{p}/transactions/{t}/approve`, `reject` | WF-036 | |
| CAR-028 | Renewal Portfolio | F: Renewals (staff-only) | PARTIAL | `reports/renewals` | WF-039..043 | |
| CAR-029 | Cancellation/Suspension Review | none | MISSING | needs API | WF-045..047 | |
| CAR-030 | Carrier Claims Queue | m:`carrier/claims.tsx` | FULL | `mobile/carrier/claims` | WF-049 | |
| CAR-031 | Carrier Claim Details | m:`carrier/claims/[id].tsx` (acknowledge / propose / approve decision / request info) | PARTIAL: no tabs or record shell | `mobile/partner/carrier/claims/{c}/*` | WF-056..058 | Newly added |
| CAR-032 | Broker Settlement Overview | m:`carrier/settlements.tsx`, `carrier/settlement/[id].tsx`, `carrier/bordereaux.tsx` | PARTIAL: read-only; the bordereau decision API is not wired | `mobile/carrier/settlements*`, `carrier/bordereaux/{b}/decision` | WF-068, WF-069 | |
| CAR-033 | Carrier Documents Centre | none | MISSING | `documents` | WF-030 | |
| CAR-034 | Carrier Reports | none | MISSING | `reports/*` | — | |

## F. Underwriting (20)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| UND-001 | Underwriting Dashboard | none | MISSING | needs API | WF-017, WF-018 | |
| UND-002 | Work Queue | F: UnderwritingCases list; m:`carrier/referrals/index.tsx` | PARTIAL: no SLA or ageing; Filament has no actions | `mobile/carrier/referrals` | WF-018 | |
| UND-003 | New Referrals | m:`carrier/referrals/index.tsx` | PARTIAL | `mobile/carrier/referrals` | WF-018 | |
| UND-004 | Assigned Cases | none | MISSING | `underwriting/cases/{c}/assign` | WF-018 | |
| UND-005 | Case Details | m:`carrier/referrals/[id].tsx`; F: UnderwritingCases view | PARTIAL: no record shell or tabs | `mobile/carrier/referrals/{id}` | WF-018 | |
| UND-006 | Applicant/Risk Profile | none | MISSING | `parties/{p}`, `risk-assets/{a}` | WF-018 | |
| UND-007 | Policy History | none | MISSING | needs API | WF-018 | |
| UND-008 | Claims History | none | MISSING | needs API | WF-018 | |
| UND-009 | Underwriting Questionnaire | none (answers are captured on the customer side) | MISSING | `proposals/{p}/disclosure` | WF-016 | |
| UND-010 | Supporting Documents | none | MISSING | `proposals/{p}/documents/{d}/review` | WF-016 | |
| UND-011 | Risk Assessment | none | MISSING | needs API | WF-018 | |
| UND-012 | Risk Score Details | none | MISSING | needs API | WF-017 | |
| UND-013 | Coverage Configuration | none | MISSING | needs API | WF-020 | |
| UND-014 | Pricing & Premium Review | none | MISSING | needs API | WF-020 | |
| UND-015 | Additional Information Request | none | MISSING | needs API | WF-019 | |
| UND-016 | Inspection/Medical Requirement | none | MISSING | needs API | WF-019 | |
| UND-017 | Conditional Acceptance | decision form in m:`carrier/referrals/[id].tsx` | PARTIAL: no conditions editor | `mobile/carrier/referrals/{id}/decision`; customer `counteroffer` | WF-020 | |
| UND-018 | Underwriting Decision | m:`carrier/referrals/[id].tsx` | PARTIAL: no supervisor step | `.../decision`, `underwriting/cases/{c}/decision` | WF-018, WF-021 | |
| UND-019 | Supervisor Approval | none | MISSING | needs API | WF-081 | |
| UND-020 | Performance & SLA | none | MISSING | needs API | — | |

## G. Claims Professional / Adjuster (18)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| CLP-001 | Adjuster Dashboard | none | MISSING | needs API | WF-053, WF-054 | No ADJUSTER role |
| CLP-002 | Assignment Queue | F: Claims list (assignee column) | PARTIAL | `claims`, `claims/{id}/assign` | WF-053 | |
| CLP-003 | Assigned Claims | m:`workspace/[role]/module/[module].tsx` (claims module) | PARTIAL: rows can't be tapped | `mobile/workspace/modules/{key}` | WF-053 | |
| CLP-004 | Claim Assignment Details | none | MISSING | needs API | WF-053 | |
| CLP-005 | Claim Overview | F: `Claims/Pages/ViewClaim.php` (Assign, Reserve) | PARTIAL | `claims/{id}` | WF-049 | |
| CLP-006 | Incident Details | none on the staff side (customer `claim/[id]/incident`) | MISSING | `mobile/claims/{c}/incident` (customer) | WF-048 | |
| CLP-007 | Policy & Coverage View | none | MISSING | needs API | WF-050 | |
| CLP-008 | Evidence Repository | none | MISSING | `claims/{id}/evidence*` | WF-052 | |
| CLP-009 | Inspection Scheduling | none (customer can only reschedule) | MISSING | needs API | WF-054 | |
| CLP-010 | Inspection Details | none | MISSING | needs API | WF-054 | |
| CLP-011 | Field Inspection Capture | none | MISSING | `mobile/uploads/*` | WF-054 | |
| CLP-012 | Damage Assessment | none | MISSING | needs API | WF-054 | |
| CLP-013 | Estimate / Valuation | none | MISSING | needs API | WF-054 | The estimate must stay separate from the settlement amount |
| CLP-014 | Assessment Report Builder | none | MISSING | needs API | WF-054 | |
| CLP-015 | Additional Evidence Request | m:`carrier/claims/[id].tsx` (request information) | PARTIAL: carrier, not adjuster | `mobile/partner/carrier/claims/{c}/request-information` | WF-051 | |
| CLP-016 | Submit Recommendation | none | MISSING | needs API | WF-054, WF-056 | |
| CLP-017 | Completed Assignments | none | MISSING | needs API | — | |
| CLP-018 | Adjuster Performance | none | MISSING | needs API | — | |

## H. Finance & Accounting (24)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| FIN-001 | Finance Executive Dashboard | m:`workspace/[role].tsx` (FINANCE_*) | PARTIAL: generic 6 KPIs | `mobile/workspace/dashboard` | — | Filament dashboard is empty |
| FIN-002 | Cash & Collections | none | MISSING | needs API | WF-022 | |
| FIN-003 | Receivables | none for finance (broker `m:broker/receivables`) | MISSING | `mobile/broker/receivables` (broker-scoped) | WF-022 | |
| FIN-004 | Payables | F: PartnerPayouts (L/V) | PARTIAL: no process/complete actions | `partner-payouts/{p}/*` | WF-067 | |
| FIN-005 | Reconciliation Dashboard | none | MISSING | needs API | WF-026, WF-069 | |
| FIN-006 | Financial Exceptions | F: FinancialCases | PARTIAL | needs API | WF-085, WF-086 | |
| FIN-007 | Premium Receivables | F: PaymentRequests list | PARTIAL | `payments` | WF-022 | |
| FIN-008 | Premium Collection Details | F: PaymentRequests / PaymentAttempts view | PARTIAL | `payments/{p}` | WF-022..025 | |
| FIN-009 | Customer Account Statement | none | MISSING | needs API | — | |
| FIN-010 | Broker Account Statement | F: PartnerStatements | PARTIAL: no approve/publish actions | `partner-statements/*` | WF-066 | |
| FIN-011 | Carrier Account Statement | none | MISSING | needs API | WF-068 | |
| FIN-012 | Agent Account Statement | F: PartnerStatements (agent partners) | PARTIAL | `partner-statements/*` | WF-064, WF-067 | |
| FIN-013 | General Ledger | F: Journals (report) | PARTIAL: no account view | `ledger/accounts` | — | |
| FIN-014 | Journal Entries | F: Journals list | PARTIAL | `ledger/journals` | — | |
| FIN-015 | Journal Entry Details | F: Journals view | PARTIAL: no reverse action | `ledger/journals/{j}`, `reverse` | — | |
| FIN-016 | Manual Journal Approval | none | MISSING | needs API (no approve endpoint) | WF-081 | |
| FIN-017 | Commission Ledger | F: CommissionAccruals | PARTIAL: no vest/clawback actions | `financial-distribution/commissions/*` | WF-064..066 | |
| FIN-018 | Refund Management | F: FinancialCases | PARTIAL | `refunds/{r}/approve` | WF-038, WF-063 | |
| FIN-019 | Settlement Batches | F: CarrierSettlements list | PARTIAL | `carrier-settlements` | WF-068 | |
| FIN-020 | Settlement Batch Details | F: CarrierSettlements view | PARTIAL: no submit/approve/paid/reverse actions | `carrier-settlements/{s}/*`, `settlements/{batch}` | WF-068 | |
| FIN-021 | Bank/Mobile Money Reconciliation | F: Reconciliations | PARTIAL: no import approve | `reconciliation/imports*` | WF-026 | |
| FIN-022 | Unmatched Transaction Workspace | none | MISSING | `reconciliation/items/{i}/resolve` | WF-027 | |
| FIN-023 | Financial Period Closing | none | MISSING | needs API | — | |
| FIN-024 | Financial Reports | none | MISSING | needs API | — | |

## I. Compliance & Risk (20)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| CMP-001 | Compliance Dashboard | m:`workspace/[role].tsx` (COMPLIANCE_ADMIN) | PARTIAL | `mobile/workspace/dashboard` | WF-090 | |
| CMP-002 | Risk Dashboard | none | MISSING | needs API | WF-089 | |
| CMP-003 | Compliance Alerts | F: RiskAlerts (decide) | PARTIAL | `risk-alerts*` | WF-089 | |
| CMP-004 | KYC Compliance Queue | none | MISSING | needs API | WF-003 | |
| CMP-005 | KYC Case | none | MISSING | needs API | WF-003, WF-075 | |
| CMP-006 | Corporate Due Diligence | none | MISSING | needs API | WF-004 | |
| CMP-007 | Expired Documentation | none | MISSING | needs API | WF-074 | |
| CMP-008 | Suspicious Activity Queue | F: RiskAlerts list | PARTIAL | `risk-alerts` | WF-089 | |
| CMP-009 | Suspicious Activity Case | F: RiskAlerts view (decide) | PARTIAL: explainable reason not verified | `risk-alerts/{a}/decision` | WF-089 | |
| CMP-010 | Fraud Alert Queue | F: RiskAlerts | PARTIAL | `trust/fraud-alerts*`, `fraud-rules` | WF-089 | |
| CMP-011 | Claim Fraud Review | none | MISSING | `trust/fraud-alerts/{x}/decision` | WF-089 | |
| CMP-012 | Payment Risk Review | none | MISSING | needs API | WF-086 | |
| CMP-013 | Policy Exception Review | none | MISSING | needs API | WF-084 | |
| CMP-014 | Underwriting Override Review | none | MISSING | needs API | WF-081 | |
| CMP-015 | Commission Exception Review | none | MISSING | needs API | WF-066 | |
| CMP-016 | Compliance Investigation | F: ComplianceCases (transition) | PARTIAL | `trust/compliance-cases*` | WF-090 | |
| CMP-017 | Compliance Findings | none | MISSING | needs API | WF-090 | |
| CMP-018 | Corrective Actions | none | MISSING | needs API | WF-090 | |
| CMP-019 | Compliance Reports | F: RegulatoryReports (approve/submit) | PARTIAL | `trust/regulatory-report-runs/*` | — | |
| CMP-020 | Compliance Audit Trail | none | MISSING | `compliance/audit-log` | WF-090 | The API exists; no viewer anywhere |

## J. Branch Management (16)

Branches exist only as Filament CRUD (`F: Branches`, L/C/E/V, which broker staff can also reach). There is no BRANCH_MANAGER role, and no query anywhere is branch-scoped.

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| BRM-001 | Branch Manager Dashboard | none | MISSING | needs API | — | Dashboard #15 in DASHBOARDS_SPEC |
| BRM-002 | Branch Production | none | MISSING | needs API | — | |
| BRM-003 | Branch Customers | none | MISSING | needs API | WF-088 | |
| BRM-004 | Branch Agents | none | MISSING | needs API | WF-080 | |
| BRM-005 | Branch Staff | m:`broker/staff.tsx` (firm-wide, not branch) | PARTIAL | `mobile/partner/broker/staff`, `branches` | WF-080 | |
| BRM-006 | Branch Quotes | none | MISSING | needs API | — | |
| BRM-007 | Branch Policies | none | MISSING | needs API | — | |
| BRM-008 | Branch Renewals | none | MISSING | needs API | — | |
| BRM-009 | Branch Claims | none | MISSING | needs API | — | |
| BRM-010 | Branch Collections | none | MISSING | needs API | — | |
| BRM-011 | Branch Commissions | none | MISSING | needs API | — | |
| BRM-012 | Branch Sticker Inventory | none | MISSING | needs API | WF-032 | |
| BRM-013 | Branch Tasks | none | MISSING | needs API | — | |
| BRM-014 | Branch Approvals | none | MISSING | needs API | WF-081 | |
| BRM-015 | Branch Compliance | none | MISSING | needs API | — | |
| BRM-016 | Branch Performance Reports | none | MISSING | needs API | — | |

## K. Platform Administration (34)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| ADM-001 | Platform Administrator Dashboard | F: default Dashboard (empty); m:`workspace/[role].tsx` | PARTIAL | `mobile/workspace/dashboard` | — | |
| ADM-002 | Tenant Directory | F: Tenants list | FULL | `tenants` | — | |
| ADM-003 | Tenant Details | F: Tenants view | FULL | `tenants/{t}` | — | |
| ADM-004 | Create Tenant | F: Tenants create | FULL | `tenants` POST | — | |
| ADM-005 | Tenant Activation | F: Tenants `change_status` action | PARTIAL: an action, not a guided screen | `tenants/{t}/status` | — | |
| ADM-006 | Tenant Suspension | same action | PARTIAL | `tenants/{t}/status` | — | |
| ADM-007 | Tenant Branding | none | MISSING | needs API | — | |
| ADM-008 | Tenant Configuration | F: Tenants edit; `Pages/PlatformSettingsPage.php` | PARTIAL | `tenants/{t}` PATCH | — | |
| ADM-009 | Insurance Company Directory | F: Partners / Parties | PARTIAL: no carrier-specific directory | `partners` | — | |
| ADM-010 | Insurer Details | F: Partners view | PARTIAL | `partners/{p}` | — | |
| ADM-011 | Broker Directory | F: Partners | PARTIAL | `partners` | — | |
| ADM-012 | Broker Details | F: Partners view + PartnerLicences | PARTIAL | `partners/{p}/licences*` | — | |
| ADM-013 | Branch Directory | F: Branches list | FULL | `branches` | — | |
| ADM-014 | Branch Details | F: Branches view/edit | FULL | `branches` | — | |
| ADM-015 | User Directory | F: Users list | FULL | — | — | |
| ADM-016 | User Details | F: Users view (+ Memberships) | FULL | `memberships/{m}/revoke` | — | |
| ADM-017 | Create User | F: Users create, Invitations create | FULL | `invitations` | — | |
| ADM-018 | Roles | none (roles are code constants in `RoleCatalogue`) | MISSING | needs API | — | |
| ADM-019 | Role Details | none | MISSING | needs API | — | |
| ADM-020 | Permission Matrix | none (`config/permissions.php`) | MISSING | needs API | — | |
| ADM-021 | Organizational Structure | none | MISSING | needs API | — | |
| ADM-022 | Insurance Classes | F: InsuranceLines (L/C/E) | FULL | `catalogue/lines` | — | |
| ADM-023 | Master Product Categories | none | MISSING | needs API | — | |
| ADM-024 | Master Data Management | none | MISSING | `configuration/regulatory-reference-sets*` | — | |
| ADM-025 | Numbering & Sequence Configuration | none | MISSING | needs API | — | |
| ADM-026 | Document Template Management | F: CertificateTemplates (L/C, approve) | PARTIAL: certificates only | `certificate-templates*` | WF-030 | |
| ADM-027 | Notification Templates | none | MISSING | `notification-templates*` | WF-073 | The API exists; no screen |
| ADM-028 | Workflow Configuration | none | MISSING | needs API | — | |
| ADM-029 | Approval Matrix | none | MISSING | needs API | WF-081 | |
| ADM-030 | Feature Flags | none | MISSING | needs API | — | |
| ADM-031 | Localization Management | none | MISSING | needs API | — | |
| ADM-032 | System Configuration | F: `Pages/PlatformSettingsPage.php` | FULL | settings store | — | Also F: PaymentConnections |
| ADM-033 | System Audit Logs | none | MISSING | `compliance/audit-log` | WF-090 | |
| ADM-034 | Administrative Reports | none | MISSING | needs API | — | |

## L. API / Developer Platform (14)

The admin side only: Filament IntegrationClients (advance / suspend / revoke / reinstate) and IntegrationDeliveryAttempts (replay). Partners have no self-service developer portal.

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| DEV-001 | Developer Portal Home | none | MISSING | — | — | |
| DEV-002 | API Documentation | none | MISSING | — | — | |
| DEV-003 | API Products | none | MISSING | needs API | — | |
| DEV-004 | API Credentials | F: IntegrationClients (admin view) | PARTIAL | `integrations/clients*` | — | Admin-facing, not partner-facing |
| DEV-005 | Create API Client | none (Filament resource is L/V only) | MISSING | `integrations/clients` POST | — | |
| DEV-006 | OAuth Application Details | F: IntegrationClients view | PARTIAL | `integrations/clients/{c}` | — | Passport is installed |
| DEV-007 | Sandbox | none | MISSING | — | — | |
| DEV-008 | API Explorer | none | MISSING | — | — | |
| DEV-009 | Webhook Configuration | none | MISSING | `integrations/clients/{c}/webhooks` | — | |
| DEV-010 | Webhook Event Catalogue | none | MISSING | needs API | — | |
| DEV-011 | Webhook Delivery Logs | F: IntegrationDeliveryAttempts (replay) | PARTIAL | `integrations/delivery-attempts/{a}/replay` | — | |
| DEV-012 | API Request Logs | none | MISSING | needs API | — | |
| DEV-013 | API Usage & Rate Limits | none | MISSING | needs API | — | |
| DEV-014 | API Versions & Changelog | none | MISSING | — | — | |

## M. Security & Technical Operations (20)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| OPS-001 | System Health Dashboard | F: `Pages/IntegrationHealth.php`; m:`system/status.tsx` (client-side only) | PARTIAL | `integrations/health` | — | |
| OPS-002 | Infrastructure Health | none | MISSING | needs API | — | |
| OPS-003 | API Health | none | MISSING | needs API | — | |
| OPS-004 | Database Health | none | MISSING | needs API | — | |
| OPS-005 | Redis/Cache Health | none | MISSING | needs API | — | |
| OPS-006 | Queue Dashboard | none (`laravel/horizon` in vendor, no `config/horizon.php`) | MISSING | — | — | Configuring Horizon could cover OPS-006..008 |
| OPS-007 | Failed Jobs | none | MISSING | — | — | |
| OPS-008 | Job Details | none | MISSING | — | — | |
| OPS-009 | Integration Health | F: IntegrationHealth | PARTIAL: summary only | `integrations/health` | — | |
| OPS-010 | Carrier API Monitoring | none | MISSING | needs API | — | |
| OPS-011 | Payment Provider Monitoring | none (F: PaymentConnections is configuration) | MISSING | needs API | WF-023, WF-024 | |
| OPS-012 | Webhook Monitoring | F: IntegrationDeliveryAttempts | PARTIAL: outbound only; inbound payment webhooks are not shown | replay API | — | |
| OPS-013 | Notification Delivery Health | F: NotificationDeliveries | PARTIAL: no retry/cancel actions | `notifications/{d}/retry`, `cancel` | WF-073 | |
| OPS-014 | Security Dashboard | none | MISSING | needs API | — | |
| OPS-015 | Security Alerts | none | MISSING | `release-assurance/security-findings` (write only) | — | |
| OPS-016 | Login Activity | none | MISSING | needs API | — | |
| OPS-017 | Active Sessions | F: Devices list; m:`account/devices.tsx` (own devices) | PARTIAL | `me/devices`, `mobile/account/devices` | — | |
| OPS-018 | Backup & Recovery | none | MISSING | `release-assurance/recovery-exercises` | — | |
| OPS-019 | Deployment / Release History | none | MISSING | `release-assurance/candidates*` | — | |
| OPS-020 | Incident Management | none (F: MobileIssueReports is user bug reports) | MISSING | needs API | — | |

## N. Regulatory & Executive Reporting (8)

| ID | Canonical name | Existing implementation | Coverage | Backend API | Workflows | Notes |
|---|---|---|---|---|---|---|
| REG-001 | Regulatory Reporting Dashboard | none | MISSING | needs API | — | |
| REG-002 | Premium Production Reports | none | MISSING | needs API | — | |
| REG-003 | Policy Portfolio Reports | none | MISSING | `reports/insurance-portfolio` | — | The API exists; no screen |
| REG-004 | Claims Reports | none | MISSING | needs API | — | |
| REG-005 | Intermediary Reports | none | MISSING | needs API | — | |
| REG-006 | Commission Reports | none | MISSING | needs API | — | |
| REG-007 | Compliance / Audit Reports | F: RegulatoryReports | PARTIAL | `trust/regulatory-report-runs/*` | WF-090 | |
| REG-008 | Regulatory Report Generation & Export | F: RegulatoryReports (approve/submit) | PARTIAL: formats not configurable | `trust/regulatory-reports/{d}/runs` | — | |

Existing screens now mapped by the enterprise register, which were listed earlier as unmapped:
- The carrier mobile stack now maps to CAR/UND/CLP.
- The workspace screens map to FIN-001, CMP-001, ADM-001 and CLP-003.
- Most Filament admin resources map to ADM/FIN/CMP/DEV/OPS/REG.

Still unmapped:
- F: Consents, DataSubjectRequests, PrivilegedAccess. These are privacy and security admin, so candidates for CMP or OPS.
- F: Fulfilments (certificate/sticker delivery orders).
- F: Attributions.
- F: Bordereaux on the staff side (CAR-032 covers only the carrier view).
- F: MobileIssueReports.
- F: RiskAssets.
- F: ClaimReserves (fits CLP/BRK-078 financials as a tab).

---

## Reuse assessment: record shell and dashboard shell

The owner's principle is one **record shell** (header with status, identity and actions → KPI cards → tabs for overview, documents, communications, timeline and audit → context panel with owner, SLA, tasks and alerts) and one **dashboard shell** (DASHBOARDS_SPEC, 20 components with KPI drill-down).

**Mobile** (`mobile app/src/components/`). This is the strongest place to start.
- `portal/PortalShell.tsx` exports `PortalScreen`, `PortalHeader`, `PortalTabBar`, `PortalNotifications` and `PortalAccount`. Four role stacks already use the page frame, header and tab bar. It is the natural host for both shells.
- `StatePanel.tsx` exports `StatePanel`, `LoadingState`, `EmptyState`, `ErrorState`, `Notice` and `errorMessage`. These cover the loading, empty, API-failure and permission states the spec requires for every screen. The **stale, offline and sync** states are still missing; `sync/` and the resilience store can supply them.
- `portal/Workspace.tsx` has `WorkspaceMenu`, and `workspace/[role]` + `module/[module]` form a generic, permission-filtered module list. This is the best seed for a generic **work-queue list** that can open records.
- `OperationsList.tsx`, `SearchBar.tsx` and `FlowPrimitives.tsx` (`FlowRow`, `Step`) are building blocks for lists and wizard steps.
- `src/components/policies/PolicyDetailView.tsx` is the closest thing to a record shell today, with policy sections for claims, payments and coverage.
- **What is missing:**
  - There is no generic `RecordShell` (tabs + context panel) and no `DashboardShell`.
  - KPI cards are a non-pressable `Card` rendering `{label, value}`, which blocks drill-down. They need `{key, label, value, change, tone, filter/route, freshness}`.

**Filament** (`app/Filament/Admin/`). This is the right host for staff areas (FIN, CMP, ADM, OPS, REG, and claims staff).
- **Record shell**:
  - `ViewRecord` pages use header actions. Several pages already call domain services through the `Concerns\ServiceValidation` trait: ViewClaim, ViewPolicyIssuance, ViewRenewal, Tenants, RiskAlerts, ComplianceCases, RegulatoryReports, PrivilegedAccess, DataSubjectRequests, IntegrationClients and CertificateTemplates.
  - **No resource uses RelationManagers or infolist tabs yet** (0 found). A shared base for ViewRecord pages would give every Filament page the record shell: infolist Tabs for overview, documents and timeline, a relation manager for audit, and a sidebar section for owner, SLA and tasks.
  - The biggest cheap win is exposing the domain actions the API already has. For example, carrier-settlement submit/approve/paid, tariff submit/approve, journal reverse, reconciliation resolve, and notification retry.
- **Dashboard shell**:
  - The panel already calls `discoverWidgets(app/Filament/Admin/Widgets)`, but the directory is empty.
  - `StatsOverviewWidget` (a stat with a `->url()` to a pre-filtered resource list) gives KPI drill-down for free.
  - `ChartWidget` and `TableWidget` cover trends and work queues.
- **Scoping limit**:
  - The panel is tenant-scoped only.
  - Broker, carrier, branch and agent scoping would need per-role `getEloquentQuery` filters and policies. CARRIER_* roles are also missing from `canAccessPanel`.
  - Until that exists, Filament cannot be the broker, carrier or branch portal.

**Web role portal** (`resources/views/wave10/portal.blade.php` + `PortalDashboardQuery` / `PortalWorkspaceService`, route `/portal/{portal}`).
- It has the right **dashboard shell layout** (sidebar navigation, KPI strip, "priority work" and "next actions" panels) and workspace preferences (`PUT web-experiences/{portal}/workspace`).
- Its content is placeholder: raw `COUNT(*)`, a single `#` nav link and no actions.
- It could become the web dashboard shell for BRK, CAR, BRM and AGT desktop users once it is fed from a shared `DashboardMetric` contract. It has no record shell.

**Backend.** No shared dashboard/metric contract or record-summary contract exists; each controller builds its own array. Two backend pieces are common to all three surfaces:
- A `DashboardMetric` DTO plus a record "context panel" DTO (owner, SLA, age, next action, pending documents, approvals, alerts).
- An audit-timeline read model over `compliance/audit-log`.
