# OpesInsure Workflow Register (as-built vs owner spec) — 2026-09-24

Source of truth: `docs/spec/WORKFLOW_REGISTER_SPEC.md` (the 90 canonical workflows WF-001…WF-090, canonical state models, backend rule, required domain events). Per-role step narratives come from `docs/spec/WORKFLOWS_SPEC.md` (the old 1–50 numbering, cross-referenced as "N#"). Evidence: `artisan route:list` (1,264 routes), `app/Domain`, `app/Application`, `app/Filament/Admin/Resources`, `database/migrations`, `mobile app/app`, `mobile app/src/api/*.ts`, `docs/audit/2026-09-24-checklist-audit.md`.

This is a read-only snapshot. Other agents were editing code while it was written.

**Legend**
- Status: **BUILT** = works end to end for that actor, matching the spec's intent · **PARTIAL** = some screens/APIs/states exist, gaps remain · **MISSING** = nothing usable · `—` = the actor has no role in this workflow.
- **[WIP]** = uncommitted work in progress (modified or untracked in `git status`, backend or `mobile app/`). It is not deployed and not guaranteed to stay.
- Surfaces: `m:` = `mobile app/app/…` (Expo, Mobile) · `F:` = `app/Filament/Admin/Resources/…` (Filament back office; the only web back office, **not role-scoped for brokers**) · `api:` = `/api/v1/…`.
- Actors: C Customer · A Agent · B Broker · S System · I Insurer/Underwriter · P Public.

**Work in progress seen at snapshot time (git status)**
- Backend [WIP]: `OwnershipScope` wired into Customer/Policy/Payment/Quote/Proposal/RiskAsset controllers (fixes audit blocker A1); `routes/wave16_lifecycle.php` (mobile proposals list + counter-offer answer, customer profile/consents/privacy, support escalate, logout-all, signed policy-document and receipt-PDF downloads); `routes/wave16_partner.php` (agent leads, agent/broker quotes·policies·claims·staff·commissions, carrier products/proposals/policies/claim actions/issuance approve·reject/payments/partners); `partner_leads` migration; `policy_expiry_reminders` + `public_verification_lookups` migration; `PaymentIssuanceTrigger` (payment success → issuance request + notification); `PolicyDocumentService` + `resources/views/pdf/*` (certificate, schedule, receipt PDFs); `LifecycleNotificationProducer`, `CustomerNotifier`, Expo push sender; commands `policies:expire`, `policies:notify-expiry`, `settlements:prepare`, `reconciliation:run`; `PolicyStateMachine` adds `LAPSED`; `ClaimIncidentService`, `MobileProposalService`, `RiskSchemaCatalogue`.
- Mobile [WIP]: new `(tabs)/explore.tsx`, `(tabs)/profile.tsx` (renamed from account), `quote/compare.tsx`, `proposals/*`, `agent/leads/*`, `agent/quotes.tsx`, `agent/policies.tsx`, `broker/{quotes,policies,claims,staff,commissions}.tsx`, `carrier/{products,proposals,policies,payments,partners}.tsx`, `carrier/claims/[id].tsx`, `support/faq.tsx`, `account/privacy.tsx`, `src/api/customer.ts`, `src/api/partner.ts`, i18n en/fr; ~50 modified screens (payments, wallet, claim, quote, checkout).

---

## 1. Summary matrix

| WF | Workflow | C | A | B | S / I / P | Old N# |
|---|---|---|---|---|---|---|
| 001 | Customer registration | PARTIAL | — | — | — | 1 |
| 002 | Phone/email verification | PARTIAL | — | — | — | 1 |
| 003 | Individual KYC | PARTIAL | PARTIAL | MISSING | — | 1 |
| 004 | Corporate KYC | MISSING | MISSING | MISSING | — | 36 |
| 005 | Create prospect/lead | MISSING | PARTIAL | — | — | 2 |
| 006 | Lead qualification | — | PARTIAL | MISSING | — | 2 |
| 007 | Lead assignment/reassignment | — | — | MISSING | — | 2 |
| 008 | Product discovery | PARTIAL | PARTIAL | — | — | 3 |
| 009 | Product comparison | PARTIAL | MISSING | — | — | 5 |
| 010 | Create quotation | PARTIAL | PARTIAL | — | — | 4 |
| 011 | Recalculate quotation | PARTIAL | PARTIAL | — | — | 4 |
| 012 | Send/share quotation | — | MISSING | — | — | 4 |
| 013 | Quote acceptance | PARTIAL | — | — | — | 4, 8 |
| 014 | Quote rejection/loss | PARTIAL | MISSING | — | — | 4 |
| 015 | Convert quote to proposal | PARTIAL | PARTIAL | — | — | 6 |
| 016 | Submit proposal | PARTIAL | PARTIAL | — | — | 6 |
| 017 | Straight-through underwriting | — | — | — | S: PARTIAL | 7 |
| 018 | Manual underwriting referral | — | — | PARTIAL | I: PARTIAL | 7 |
| 019 | Additional information request | MISSING | MISSING | PARTIAL | I: PARTIAL | 6, 7 |
| 020 | Conditional acceptance | PARTIAL | — | — | I: PARTIAL | 7, 8 |
| 021 | Proposal rejection | — | — | — | I: PARTIAL | 7 |
| 022 | Initiate premium payment | PARTIAL | PARTIAL | — | — | 9 |
| 023 | Mobile-money payment | PARTIAL | — | — | — | 9 |
| 024 | Bank/card payment | MISSING | — | — | — | 9 |
| 025 | Payment retry | PARTIAL | MISSING | — | — | 9, 47 |
| 026 | Payment reconciliation | — | — | PARTIAL | — | 29 |
| 027 | Unmatched payment resolution | — | — | PARTIAL | — | 29 |
| 028 | Policy issuance | — | — | PARTIAL | I: PARTIAL | 10 |
| 029 | Policy activation | — | — | — | S: PARTIAL | 10 |
| 030 | Policy document generation | — | — | — | S: PARTIAL | 12 |
| 031 | Attestation generation | — | — | — | S: PARTIAL | 12, 13 |
| 032 | Sticker allocation | — | MISSING | PARTIAL | — | 13 |
| 033 | Sticker issuance | — | MISSING | — | — | 13 |
| 034 | View/manage policy | PARTIAL | PARTIAL | PARTIAL | — | 11 |
| 035 | Request policy amendment | PARTIAL | MISSING | — | — | 14 |
| 036 | Review/approve endorsement | — | — | PARTIAL | I: MISSING | 14 |
| 037 | Additional premium | PARTIAL | — | — | — | 14 |
| 038 | Premium refund (endorsement) | — | — | MISSING | — | 14, 26 |
| 039 | Renewal identification | — | — | — | S: PARTIAL | 15 |
| 040 | Renewal quotation | — | PARTIAL | PARTIAL | — | 15 |
| 041 | Customer renewal acceptance | PARTIAL | — | — | — | 15 |
| 042 | Renewal payment | PARTIAL | — | — | — | 15 |
| 043 | Renewal issuance | — | — | PARTIAL | S: PARTIAL | 15 |
| 044 | Cancellation request | PARTIAL | MISSING | — | — | 16 |
| 045 | Cancellation approval | — | — | PARTIAL | — | 16 |
| 046 | Suspension | — | — | MISSING | — | 17 |
| 047 | Reinstatement | — | — | PARTIAL | — | 17 |
| 048 | FNOL | BUILT | PARTIAL | — | — | 18 |
| 049 | Claim registration | — | — | PARTIAL | S: PARTIAL | 18 |
| 050 | Claim coverage validation | — | — | MISSING | I: MISSING | 18 |
| 051 | Evidence submission | BUILT | MISSING | — | — | 19 |
| 052 | Evidence review | — | — | PARTIAL | — | 19 |
| 053 | Expert/adjuster assignment | — | — | PARTIAL | — | 20 |
| 054 | Claim assessment | — | — | PARTIAL | I: PARTIAL | 20 |
| 055 | Claim investigation | — | — | PARTIAL | — | 21 |
| 056 | Claim approval | — | — | PARTIAL | I: PARTIAL | 22 |
| 057 | Partial claim approval | — | — | PARTIAL | I: PARTIAL | 22 |
| 058 | Claim rejection | — | — | PARTIAL | I: PARTIAL | 22, 24 |
| 059 | Claim settlement | PARTIAL | — | PARTIAL | I: PARTIAL | 23 |
| 060 | Claim closure | — | — | PARTIAL | — | 23 |
| 061 | Claim reopening | MISSING | — | MISSING | — | 25 |
| 062 | Claim appeal/dispute | PARTIAL | — | PARTIAL | — | 24 |
| 063 | Customer refund | PARTIAL | — | PARTIAL | — | 26 |
| 064 | Agent commission accrual | — | — | — | S: PARTIAL | 27 |
| 065 | Broker commission accrual | — | — | — | S: PARTIAL | 27 |
| 066 | Commission approval | — | — | PARTIAL | — | 27 |
| 067 | Commission payment | — | PARTIAL | PARTIAL | — | 27 |
| 068 | Broker-insurer settlement | — | — | PARTIAL | — | 28 |
| 069 | Settlement reconciliation | — | — | MISSING | — | 28 |
| 070 | Customer support ticket | BUILT | — | — | — | 30 |
| 071 | Complaint registration | PARTIAL | — | — | — | 31 |
| 072 | Complaint resolution | — | — | PARTIAL | — | 31 |
| 073 | Workflow notification | — | — | — | S: PARTIAL | 32, 39 |
| 074 | Document expiry remediation | MISSING | MISSING | — | — | 34 |
| 075 | KYC remediation | MISSING | MISSING | MISSING | — | 35 |
| 076 | Corporate insurance onboarding | MISSING | MISSING | — | — | 36 |
| 077 | Vehicle registration | PARTIAL | MISSING | — | — | 37 |
| 078 | Beneficiary management | MISSING | MISSING | — | — | 38 |
| 079 | Agent portfolio transfer | — | — | PARTIAL | — | 40 |
| 080 | Agent suspension | — | — | PARTIAL | — | 41 |
| 081 | Maker-checker approval | — | — | PARTIAL | — | 42 |
| 082 | Public document verification | — | — | — | P: PARTIAL | 44 |
| 083 | Expired policy recovery | PARTIAL | PARTIAL | — | — | 45 |
| 084 | Failed policy issuance | — | — | PARTIAL | — | 46 |
| 085 | Failed payment recovery | PARTIAL | — | PARTIAL | — | 47 |
| 086 | Duplicate payment | — | — | PARTIAL | — | 48 |
| 087 | Paid renewal, issuance failure | — | — | MISSING | — | 49 |
| 088 | Customer 360 | — | PARTIAL | PARTIAL | — | 50 |
| 089 | Suspicious claim review | — | — | PARTIAL | — | 21 |
| 090 | Operational audit review | — | — | PARTIAL | — | — |

**Totals (142 actor cells across 90 workflows)**

| | C | A | B | S/I/P | All |
|---|---|---|---|---|---|
| BUILT | 3 | 0 | 0 | 0 | **3** |
| PARTIAL | 28 | 15 | 37 | 21 | **101** |
| MISSING | 9 | 16 | 11 | 2 | **38** |
| Actor cells | 40 | 31 | 48 | 23 | **142** |

(The other 218 of the 360 cells are `—`: the actor has no role in that workflow.) No workflow is BUILT for every actor it involves. The 3 BUILT cells (FNOL, evidence submission, support ticket for the customer) still carry caveats listed in their entries.

**Domain events (owner's required list) vs code.** None of them exist as event classes (`app/Domain/Shared/DomainEvent.php` is a base class only, and no `app/Events` directory exists). Events are emitted as outbox strings through `OutboxWriter::record($event, …)`:

| Owner event | Outbox/audit string found | | Owner event | Outbox/audit string found |
|---|---|---|---|---|
| CustomerRegistered | `customer.registered` ✓ | | EndorsementIssued | `policy.service.approved` (generic) ~ |
| KYCSubmitted | `kyc_submission.submitted` ✓ | | RenewalDue | none ✗ |
| KYCApproved | none ✗ | | PolicyRenewed | `renewal.completed` ~ |
| QuoteCalculated | `quote.rated` ~ | | ClaimReported | `claim.fnol.submitted` ~ |
| QuoteAccepted | `quote.offer.accepted` ~ | | ClaimEvidenceReceived | `claim.evidence.attached` ~ |
| ProposalSubmitted | `proposal.submitted` ✓ | | ClaimAssigned | `claim.assigned` ✓ |
| UnderwritingApproved | `underwriting.decided` (generic) ~ | | ClaimApproved | `claim.decision.approved` ~ |
| PaymentInitiated | `payment.requested`, `payment.authorization.requested` ~ | | ClaimRejected | only `claim.transitioned` ✗ |
| PaymentSucceeded / PaymentFailed | `payment.status.changed` (generic) ~ | | ClaimSettled | `claim.payment.paid` ~ |
| PolicyIssued | `policy.issued` ✓ | | RefundApproved | `refund.approved` ✓ |
| PolicyActivated | none (folded into issued) ✗ | | CommissionAccrued | `commission.accrued` ✓ |
| AttestationGenerated | `certificate.issued` ~ [WIP auto-issue] | | CommissionSettled | `partner.payout.paid` ~ |
| | | | SettlementCompleted | `carrier.settlement.*` ~ |

Result: Of the 26: 7 exact (✓), 15 approximate or generic (~), 4 absent (✗). Needed: a typed event catalogue (class or enum of names + versioned payload schema) and a consumer registry that maps each event to its notification and integration handlers.

**Backend rule compliance (Request → Auth → Authorization → Validate → Domain Command → Guard → Transaction → Persist → Event → Outbox → Audit).** The core services follow it: Proposal, Quote, PolicyServicing, PolicyIssuance, ClaimLifecycle/Payment, Commission, CarrierSettlement, Reconciliation, Refund/FinancialCase. They all run `DB::transaction`, guard, write history rows, and call `AuditWriter` and `OutboxWriter`. The gaps:
1. State guards are spread out. Only claim, policy, ticket and fulfilment have a transition table (`app/Domain/**/…StateMachine.php`). Quote, lead, KYC, renewal, sticker, settlement and refund statuses are raw strings checked inline.
2. There are **two claim machines that disagree**: `ClaimStateMachine` has no `PAYMENT_PENDING`/`REOPENED`, while `ClaimLifecycle` has both.
3. `DemoPurchaseSettler` writes `SUCCEEDED` and self-issues, so the payment and issuance commands never run (audit A2). It is modified in [WIP].
4. Mobile "completion" controllers (`MobileCompletion/*`, `PartnerWorkspace/*` [WIP]) often use `DB::table()->update/insert` directly, skipping the domain service and outbox (for example support escalate and carrier workspace actions). Each one needs checking.
5. Notifications do not come from outbox consumers. They are produced by model observers in `LifecycleNotificationProducer` [WIP], so they fire on persistence instead of on domain events.

---

## 2. State model conflicts (owner canonical vs backend)

| Entity | Owner canonical (WORKFLOW_REGISTER_SPEC) | Backend implemented | Conflict / gap |
|---|---|---|---|
| Registration | initiated → contact_pending → contact_verified → profile_incomplete → registered | No registration state. `users` + `parties` rows are created by `MobileAuthService`; `tenant_customers.status` ACTIVE/SUSPENDED/ARCHIVED (`CustomerService`) | Whole state model missing; no `/auth/register` (it is `public/accounts` + `auth/mobile/otp/*` + `password-login`) |
| KYC | not_started → draft → submitted → reviewing → approved; more_information_required, rejected, expired | `kyc_submissions.status`: DRAFT, SUBMITTED only (`MobileKycService`). `NOT_STARTED` is synthesised in agent views | No reviewing/approved/rejected/more-info/expired; no review command |
| Lead | New → Contacted → Qualified → Quote Started → Quote Sent → Won / Lost / Dormant | [WIP] `partner_leads` CHECK: NEW, CONTACTED, QUALIFIED, CONVERTED, LOST | No QUOTE_STARTED, QUOTE_SENT, DORMANT; CONVERTED ≈ Won; no customer-created leads; no broker ownership/SLA |
| Quotation | draft → calculated → generated → sent → viewed → accepted; declined, expired, cancelled | **No enum / no machine.** `quotes.status` strings: SUBMITTED, OFFERED, REFERRED, ACCEPTED, CANCELLED, FAILED; `quote_offers.status`: OFFERED, ACCEPTED, NOT_SELECTED (`QuoteService`). Expiry is derived from `valid_until`; the app also expects RATED/EXPIRED | Missing DRAFT, CALCULATED (≈OFFERED), GENERATED, SENT, VIEWED, DECLINED, EXPIRED (persisted); extra REFERRED/FAILED; no machine class |
| Proposal | draft → submitted → reviewing → information_required → resubmitted → approved / declined | `ProposalStatus` enum: DRAFT, DISCLOSURES_PENDING, DOCUMENTS_PENDING, SUBMITTED, UNDER_REVIEW, APPROVED, COUNTEROFFERED, DECLINED, PAYMENT_PENDING; DB CHECK also allows WITHDRAWN (the enum lacks it) | Missing INFORMATION_REQUIRED, RESUBMITTED on the proposal (only `underwriting_cases.status` AWAITING_INFORMATION); enum ≠ DB CHECK (WITHDRAWN); extra DISCLOSURES_/DOCUMENTS_PENDING, COUNTEROFFERED (≈ conditional), PAYMENT_PENDING (STP jumps SUBMITTED→PAYMENT_PENDING, skipping APPROVED) |
| Underwriting case | referred → assigned → reviewing → decision_pending → approved / conditional / declined | `underwriting_cases`: QUEUED, IN_REVIEW, AWAITING_INFORMATION, DECIDED, CANCELLED; decision stored separately (APPROVED / COUNTEROFFER / DECLINED) | No ASSIGNED / DECISION_PENDING states; decision outcome not a state |
| Payment | created → initiated → pending → successful → reconciled; failed, expired, cancelled, reversed, refunded | `PaymentStatus` enum: CREATED, PENDING_CUSTOMER, PROCESSING, SUCCEEDED, FAILED, EXPIRED, REFUND_PENDING, REFUNDED, CHARGEBACK_OPEN, CHARGED_BACK; attempts: STARTED/ACCEPTED/FAILED | Missing INITIATED (≈PENDING_CUSTOMER), RECONCILED (lives on `reconciliation_items` MATCHED only), CANCELLED, REVERSED (≈CHARGED_BACK); naming differs |
| Policy | issuance_pending → issued → active → expired; amended, suspended, cancelled, renewed | `PolicyStateMachine`: PENDING_PAYMENT, PAID_PENDING_ISSUANCE, ACTIVE, EXPIRING, ENDORSEMENT_PENDING, CANCELLATION_PENDING, SUSPENDED, CANCELLED, EXPIRED, [WIP] LAPSED | No ISSUED, AMENDED, RENEWED; renewal links via `renewal_cases.successor_policy_id` but the predecessor stays EXPIRED/ACTIVE; EXPIRED→ACTIVE allowed (owner WF-087: an old policy must never be reactivated by a renewal payment) |
| Issuance request | issuance_pending → issuing → issued → active; issuance_failed | `policy_issuance_requests`: PENDING_APPROVAL, APPROVED, REJECTED | No ISSUING/ISSUANCE_FAILED/retry count; REJECTED ≠ failed |
| Attestation / certificate | requested → generated → active; revoked, replaced, expired | `policy_certificates`: DRAFT, VALID, VOID (`CertificateService`, `PolicyDocumentService` [WIP]) | No REQUESTED, REPLACED, EXPIRED; REVOKED ≈ VOID |
| Public verification | VALID, EXPIRED, REVOKED, REPLACED, NOT_FOUND | `public/certificates/verify`, `public/insurance/verify` return VALID/VOID-derived results | REPLACED and EXPIRED not distinguished |
| Sticker | Received → In Stock → Allocated → Assigned → Issued → Active; Damaged / Lost / Void / Returned | `sticker_stock`: RECEIVED, IN_STOCK, ASSIGNED, ACTIVE, VOID (`CertificateService`); `sticker_custody_events` table | Missing ALLOCATED (branch/agent), ISSUED, DAMAGED, LOST, RETURNED; no machine |
| Endorsement | Draft → Submitted → Under Review → Approved (→ additional_premium_required / refund_due) → Financial Adjustment → Issued | `policy_transactions` type ENDORSEMENT, status PENDING_APPROVAL / PENDING_CUSTOMER (payment) / APPROVED / REJECTED (`PolicyServicingService`); policy → ENDORSEMENT_PENDING | No DRAFT, UNDER_REVIEW, FINANCIAL_ADJUSTMENT, ISSUED (avenant); no new policy version |
| Renewal | Upcoming → Contacted → Quoted → Accepted → Paid → Renewed; Declined / Lapsed / Lost | `renewal_cases`: DUE, CONTACTED, QUOTED, RENEWED (`RenewalService`) | No UPCOMING (=DUE), ACCEPTED, PAID, DECLINED, LAPSED, LOST |
| Cancellation | Requested → Under Review → Approved → Financial Adjustment → Cancelled | `policy_transactions` type CANCELLATION: PENDING_APPROVAL → APPROVED/REJECTED; policy CANCELLATION_PENDING → CANCELLED | No UNDER_REVIEW / FINANCIAL_ADJUSTMENT stages |
| Suspension/reinstatement | Suspended → reinstated | Policy SUSPENDED exists; transaction type REINSTATEMENT only | **No command to suspend** (no SUSPENSION transaction type) |
| Claim | draft → submitted → registered → reviewing → assessment → decision_pending → approved → settlement_pending → paid → closed; information_required, investigating, partially_approved, rejected, appealed, reopened | `ClaimLifecycle`: DRAFT, SUBMITTED, ACKNOWLEDGED, EVIDENCE_PENDING, ASSESSMENT, CARRIER_REVIEW, APPROVED, PARTIALLY_APPROVED, DECLINED, PAYMENT_PENDING, PAID, DISPUTED, CLOSED, REOPENED. `ClaimStateMachine` (a second copy) lacks PAYMENT_PENDING/REOPENED | REGISTERED≈ACKNOWLEDGED, REVIEWING/DECISION_PENDING≈CARRIER_REVIEW, INFORMATION_REQUIRED≈EVIDENCE_PENDING, REJECTED≠DECLINED, APPEALED≠DISPUTED, SETTLEMENT_PENDING≈PAYMENT_PENDING; **no INVESTIGATING**; two conflicting machines; the app used REJECTED/SETTLED (audit A9; `claim/[id].tsx` modified [WIP]) |
| Claim settlement | settlement_preparation → awaiting_authorization → payment_pending → paid → reconciled (N23: Approved → Payment Pending → Processing → Paid → Settled → Closed) | `claim_payments`: PENDING_APPROVAL, APPROVED, PROCESSING, PAID, RETRY_PENDING, REVERSED (`ClaimPaymentService`) | No SETTLEMENT_PREPARATION, RECONCILED/SETTLED; discharge not a state |
| Adjuster | assignment_pending → assigned → scheduled → inspection_complete → report_submitted | Inspection read + reschedule on mobile (`MobileClaimCompletionController`: DISPATCHING/RESCHEDULED); `claims/{id}/assign` assigns a handler | No adjuster assignment entity or states |
| Refund | candidate → calculated → reviewed → approved → paid → reconciled | `refunds` (REQUESTED → APPROVED via `refunds/{r}/approve`), `financial_cases` OPEN/REQUESTED/APPROVED | No CALCULATED, PAID, RECONCILED on the refund |
| Commission | Accrued → Earned → Statement → Approved → Paid; reversal | `commission_accruals`: PENDING → VESTED (earned) / clawback; `partner_statements` DRAFT → APPROVED → PUBLISHED; `partner_payout_requests` REQUESTED → APPROVED → PROCESSING → PAID / FAILED / REVERSED | Mostly mapped; no RECONCILED on payouts |
| Settlement batch | Open → Calculated → Awaiting Approval → Approved → Processing → Settled → Reconciled | `carrier_settlements`: DRAFT → FINANCE_APPROVAL → APPROVED → BANK_SUBMITTED → BANK_CONFIRMED/PAID / BANK_FAILED / REVERSED; `settlement_batches` table separate | No CALCULATED, RECONCILED; two settlement tables |
| Support ticket | (N30) Issue → Ticket → Resolution → Close/Rate | `TicketStateMachine`: OPEN, TRIAGED, IN_PROGRESS, WAITING_CUSTOMER, ESCALATED, RESOLVED, REOPENED, CLOSED, CANCELLED | Fine for support |
| Complaint | Submitted → Acknowledged → Classified → Assigned → Investigated → Resolution → Communicated → Closed/Escalated | Same ticket table with `type` COMPLAINT / REGULATORY_COMPLAINT and the ticket state machine | **Owner requires complaints kept separate from support**; no Acknowledged/Classified/Communicated |

---

## 3. Workflow entries

Each entry lists Trigger/Pre · Screens · Actions · API · States · Guards · Notify · Docs · Audit/Events · Failures · Final · Status (per actor, with what is needed).

### Identity & KYC

**WF-001 Customer registration** — Identity · C
- Trigger/Pre: "Create Account"; no active account for the same verified phone.
- Screens: `m:welcome.tsx`, `m:(auth)/role.tsx`, `m:(auth)/sign-up.tsx` [WIP mod], `m:(auth)/verify.tsx`, `m:terms.tsx`, `m:onboarding/kyc.tsx`. Missing: Personal Details step and Consent step in the signup flow, a Success screen.
- Actions: Sign up, Accept terms, Verify OTP, Continue.
- API: exist `POST public/accounts`, `POST auth/mobile/otp/request|verify`, `POST auth/mobile/password-login`, [WIP] `PATCH mobile/account/customer-profile`, `PUT mobile/account/consents`. Spec wants `POST /auth/register`, `POST /customers/profile` (map or alias).
- States: none persisted (see §2). Needed: a `registration_status` on users/parties.
- Guards: phone uniqueness + OTP throttling exist (`MobileAuthService`); consent is **not enforced server-side** (audit: consent ref made on the device); password rules exist.
- Notify: OTP via ETECH/Twilio exists; "account created" message missing.
- Docs: none. Audit/Events: `customer.registered`, `party.created` exist; CustomerRegistrationStarted and OTPVerified missing.
- Failures: duplicate → 422 handled; OTP expired/limit handled (lockout notice [WIP]); API down → generic error.
- Final: registered → Dashboard.
- Status C **PARTIAL** — needs a registration state + server-side consent capture + welcome notification.

**WF-002 Phone/email verification** — Identity · C
- Screens: `m:(auth)/verify.tsx`, `m:verify.tsx`, `m:account/security.tsx`. API: `auth/mobile/otp/*`, `POST me/phone/verification[/confirm]`, `POST me/email/verification`, `GET email/verify/{user}/{hash}`.
- States: `phone_verified_at` / `email_verified_at` timestamps only. Guards: OTP validity and throttle. Note: OTP is currently lifted by design, and demo OTP 123456 is published (audit A2).
- Notify: OTP SMS; no email resend in the app, no countdown. Audit: no OTPVerified event.
- Status C **PARTIAL** — needs OTP enforced in production, email verification in the app, and an OTPVerified audit event.

**WF-003 Individual KYC** — KYC · C, A, B
- Trigger/Pre: a transaction needs verified identity (the proposal needs it, but it is not currently enforced as a gate).
- Screens: C `m:onboarding/kyc.tsx` (single form + docs), `m:account/profile.tsx`; A `m:agent/clients/new.tsx`, `m:agent/clients/[id].tsx` (shows `kyc_status`); B none (`F:Customers` view only). Missing: C Identity Details / ID Capture (retake) / Address / Review / Status; B "KYC Review Queue" + "KYC Decision" (Filament page and mobile `m:broker/kyc/index.tsx`).
- Actions: C save draft, upload, submit (exist); respond to remediation (missing). B approve / reject / request info (missing).
- API: exist `GET|PATCH mobile/kyc/profile`, `POST mobile/kyc/documents`, `POST mobile/kyc/submission`, `POST mobile/agent/clients`, [WIP] `POST mobile/partner/agent/clients`. Missing: `POST /kyc/{id}/review` (decision), `GET /kyc/queue`, `POST /customers/{id}/kyc/documents` for agent-on-behalf.
- States: DRAFT, SUBMITTED only (§2).
- Guards: document malware scan (CLEAN/INFECTED) exists. Missing: mandatory doc set per ID type, maker-checker on review, ID expiry check.
- Notify: none. Needed: submitted, approved, rejected/more-info to C and the owning agent.
- Audit/Events: `kyc_submission.submitted`. Missing: KYCApproved, per-field change audit, reviewer decision.
- Failures: upload failure → resumable uploads exist (`mobile/uploads/*`); rejection has no path.
- Final: approved → customer activated.
- Status C **PARTIAL**, A **PARTIAL**, B **MISSING** — needs a KYC review domain (states, queue, decision, maker-checker) and a KYC gate before proposal submission.

**WF-004 Corporate KYC** — KYC · Corporate C, A, B
- Nothing corporate exists: no organisation party type flow, representatives, registration docs or employees. `parties` supports type, but there is no API or screen.
- Proposed: `m:onboarding/company.tsx`, `m:onboarding/representatives.tsx`, `F:CorporateAccounts`. API `POST /organizations`, `POST /organizations/{id}/representatives`, `POST /organizations/{id}/kyc`.
- Status all **MISSING** — needs a corporate party model, representative authority and KYB document requirements.

### CRM

**WF-005 Create prospect/lead** — CRM · C, A
- C: "Request quote / Request call" has no endpoint or screen. Proposed `m:explore → "Talk to an agent"` → `POST /mobile/leads`.
- A: [WIP] `m:agent/leads/index.tsx`, `new.tsx`, `[id].tsx`; API [WIP] `GET|POST mobile/partner/agent/leads`, `GET|PATCH …/leads/{lead}` (guard `agent.clients.manage`, scoped to tenant + partner).
- States: [WIP] NEW… (§2). Notify: none. Audit: `AgentLeadService` [WIP]; check it writes audit/outbox (it uses direct creates).
- Status C **MISSING**, A **PARTIAL** [WIP] — needs customer-originated leads, source/expected premium fields, and audit + outbox on lead changes.

**WF-006 Lead qualification** — CRM · A, B
- A: [WIP] PATCH lead status (NEW→CONTACTED→QUALIFIED→LOST), `POST …/leads/{lead}/convert` → `AgentClientIntakeService` (consent + attribution).
- B: no pipeline view. Proposed `m:broker/leads.tsx`, `F:Leads`, `GET /mobile/partner/broker/leads`.
- Guards: no transition table (any status patch). Missing: Quote Started / Quote Sent / Dormant; next-activity date.
- Status A **PARTIAL** [WIP], B **MISSING** — needs a lead state machine, activity log and a broker pipeline view.

**WF-007 Lead assignment/reassignment** — CRM · B
- No assignment field (leads belong to the creating partner), no SLA, no reassignment API. Proposed `POST /leads/{id}/assign`, `F:Leads` with Assign action, SLA timer.
- Status B **MISSING**.

### Product

**WF-008 Product discovery** — Product · C, A
- Screens: C `m:(customer)/(tabs)/explore.tsx` [WIP new], `m:quote/product.tsx`, `m:institutions/insurers.tsx`, `m:institutions/insurer/[id].tsx`; A `m:agent/sales/new.tsx`. Missing: Product Details (coverage/exclusions/requirements page), categories Business/Accident/Marine.
- API: `GET catalogue/products[/{id}]`, `GET public/institutions*`, [WIP] `GET mobile/catalogue/lines/{code}/risk-schema`. B product rules: [WIP] `mobile/partner/carrier/products` + status; `F:InsuranceProducts`, `F:CoverageDefinitions`, `F:ExclusionDefinitions`, `web-experiences/marketplace/publications` (broker publications `m:broker/publications.tsx`).
- Guards: product must be ACTIVE/published. Agent product permissions (N3 "broker configures which products its agents distribute"): **missing**.
- Status C **PARTIAL**, A **PARTIAL** — needs a product detail screen driven by coverage/exclusion data, more lines, and agent product authorisation.

**WF-009 Product comparison** — Product · C, A
- C: `m:(customer)/(tabs)/compare.tsx`, [WIP] `m:quote/compare.tsx`, `m:quote/offers.tsx`. API `POST web-experiences/marketplace/comparisons`; offers carry limits/excess/exclusions (server), which the app partly shows (audit §9).
- A: no comparison screen (`m:agent/sales/[id].tsx` shows one sale). Proposed `m:agent/sales/[id]/compare.tsx`.
- Guards: neutral ordering (`TOTAL_ASC_THEN_COVERAGE_DESC`) exists — no misleading ranking. Audit of manual overrides (B): missing.
- Status C **PARTIAL** [WIP], A **MISSING** — needs a side-by-side screen with coverage facts for both roles and a record of the chosen offer.

### Quote

**WF-010 Create quotation** — Quote · C, A
- Screens: C `m:quote/product.tsx` → `risk.tsx` → `questions.tsx` → `offers.tsx` → `terms.tsx`, `m:quotes/index.tsx`, `m:quotes/[id].tsx`; A `m:agent/sales/new.tsx`, `m:agent/quotes.tsx` [WIP]; B `m:broker/quotes.tsx` [WIP], `F:Quotes`. Missing per spec: Applicant, Coverage, Add-ons, Review steps; motor wizard (audit: single 4-field screen; schema-driven risk form [WIP] `RiskSchemaCatalogue`).
- API: `POST quotes`, `POST quotes/{quote}/rate`, `GET quotes/{id}`, `GET mobile/quotes`, `POST mobile/agent/sales`. Missing: `PATCH /quotes/{id}`, `POST /quotes/{id}/generate` (PDF).
- States: strings only (§2). Guards: approved tariff version (`TariffGovernanceService`), product active, [WIP] ownership scope. Eligibility → REFERRED.
- Notify: `LifecycleNotificationProducer::quoteUpdated` [WIP]. Docs: **quotation PDF missing**. Audit/Events: `quote.submitted`, `quote.rated` (tariff version stored on offers).
- Failures: carrier unavailable → FAILED; referral → `m:quote/referral.tsx`; missing tariff → 422.
- Status C **PARTIAL**, A **PARTIAL** — needs a quote state machine (draft→…→expired), PATCH/generate endpoints, the quotation PDF, a full risk wizard, and broker oversight of high-value quotes (N4).

**WF-011 Recalculate quotation** — Quote · C, A
- `POST quotes/{quote}/rate` re-rates; `POST mobile/quotes/{quote}/resume` (resume bug, audit A12, [WIP] mod). No edit-inputs-then-recalc (no PATCH).
- Status C **PARTIAL**, A **PARTIAL** — needs PATCH quote inputs + re-rate that keeps the audit of prior premiums.

**WF-012 Send/share quotation** — Quote · A
- No send/share endpoint, no SENT/VIEWED tracking, no quote PDF/link. The closest is `POST mobile/agent/sales/{id}/payment-request`.
- Proposed: `POST /quotes/{id}/send` (SMS/email/WhatsApp link), public quote view that marks VIEWED, `m:agent/quotes/[id].tsx` "Send" button.
- Status A **MISSING**.

**WF-013 Quote acceptance** — Quote · C
- Screens: `m:quote/offers.tsx` → `m:quote/terms.tsx` → `m:checkout.tsx`. Missing: explicit Coverage Confirmation + Declaration screen before the proposal.
- API: `POST quotes/{quote}/offers/{offer}/accept` (spec: `/quotes/{id}/accept`). Guards: offer OFFERED, validity window, [WIP] ownership. "No material change" check: missing.
- Events: `quote.offer.accepted`. Status C **PARTIAL** — needs a VIEWED state, a declaration step, and an acceptance audit with identity + device.

**WF-014 Quote rejection/loss** — Quote · C, A
- C: `DELETE mobile/quotes/{quote}` → CANCELLED (`quote.cancelled`). No "decline with reason".
- A: no mark-lost or reason code; no lost-quote reporting for B.
- Status C **PARTIAL**, A **MISSING** — needs DECLINED/EXPIRED persisted states with reason codes and an expiry job.

### Proposal & Underwriting

**WF-015 Convert quote to proposal** — Proposal · C, A
- C: `m:quote/terms.tsx`, `m:quote/disclosure.tsx`, [WIP] `m:proposals/index.tsx`, `m:proposals/[id].tsx`. A: `m:agent/sales/[id].tsx`.
- API: `POST proposals` (guard: accepted offer, same party, one live proposal per offer, approved disclosure schema). Snapshot `terms_snapshot` stored.
- Events: `proposal.created`. Status C **PARTIAL**, A **PARTIAL** — needs the applicant/risk review screens (audit §10).

**WF-016 Submit insurance proposal** — Proposal · C, A
- Screens: as WF-015; missing Documents upload, Beneficiaries and Consent/Sign screens in the app.
- API: `PUT proposals/{p}/disclosure/answers`, `POST …/disclosure/submit`, `POST …/disclosures/attest`, `POST …/documents`, `POST …/submit`, `POST …/terms`; [WIP] `GET mobile/proposals`.
- States: DISCLOSURES_PENDING → DOCUMENTS_PENDING → SUBMITTED → UNDER_REVIEW | PAYMENT_PENDING. Guards: answers hash, attestation, mandatory VERIFIED docs. Snapshot versioned (`version`++, `proposal_status_history`).
- Notify: `proposalUpdated` [WIP]. Audit: `proposal.submitted`, `proposal.disclosures.attested`, `proposal.document.reviewed`.
- Failures: missing doc → 422 with the code; the app has no upload path.
- Status C **PARTIAL**, A **PARTIAL** — needs in-app proposal document upload, e-signature/consent capture, and WITHDRAWN in the enum.

**WF-017 Straight-through underwriting** — Underwriting · S
- `ProposalService::submit`: no referral flags → an `UnderwritingDecision` APPROVED with reason `STRAIGHT_THROUGH`, proposal → PAYMENT_PENDING. The rule set is disclosure `referral_values` only; the schema version is recorded.
- Gaps: no risk/sanctions/limit checks, no "conditional" STP outcome, and STP skips the APPROVED state.
- Status S **PARTIAL** — needs a versioned underwriting rule set (limits, age, vehicle, claims history) with the version stored on the decision.

**WF-018 Manual underwriting referral** — Underwriting · B, I
- Screens: B `F:UnderwritingCases`, `F:Proposals`; I `m:carrier/referrals/index.tsx`, `m:carrier/referrals/[id].tsx`, [WIP] `m:carrier/proposals.tsx`.
- API: `POST underwriting/cases/{case}/assign`, `…/decision`, `POST underwriting/referrals/{referral}/resolve`, `GET|POST mobile/carrier/referrals[/{id}/decision]`.
- States: QUEUED → IN_REVIEW → AWAITING_INFORMATION → DECIDED (§2). Guards: carrier authority (`AuthorityChecker`, delegated authorities), `carrier.referrals.decide`. Audit: `underwriting.decided` (rationale in notes; old/new premium only via counter-offer terms).
- Notify: `underwritingCaseUpdated` [WIP]. Broker coordination view in mobile: none.
- Status B **PARTIAL**, I **PARTIAL** — needs ASSIGNED/DECISION_PENDING, a premium-change audit and a broker referral monitor.

**WF-019 Additional information request** — Underwriting · I, B, A, C
- UW case → AWAITING_INFORMATION exists. The proposal does **not** move to information_required, and there is no item list sent to the customer or agent. The app partly handles MORE_INFORMATION (audit §E).
- Proposed: `POST /proposals/{id}/information-requests` {items[]}, `POST /proposals/{id}/resubmit`; screens `m:proposals/[id]/respond.tsx`, `m:agent/sales/[id]/respond.tsx`, `F:UnderwritingCases` "Request info" action.
- Status C **MISSING**, A **MISSING**, B **PARTIAL**, I **PARTIAL** — needs the INFORMATION_REQUIRED→RESUBMITTED proposal loop with an itemised checklist and notifications.

**WF-020 Conditional acceptance** — Underwriting · I, C
- COUNTEROFFERED state + decision conditions exist; [WIP] `POST mobile/proposals/{proposal}/counteroffer/{answer}` and `m:proposals/[id].tsx`; `MobileProposalService` [WIP]. Event `proposal.counteroffer.*`.
- Status C **PARTIAL** [WIP], I **PARTIAL** — needs the adjusted premium/coverage shown against the original, and counter-offer expiry.

**WF-021 Proposal rejection** — Underwriting · I
- Decision DECLINED → proposal DECLINED; customer notification [WIP]. Reason codes are free text; the app does not handle DECLINED (audit §10).
- Status I **PARTIAL** — needs mandatory reason codes, a customer-facing explanation, and supervisor approval where configured.

### Payments

**WF-022 Initiate premium payment** — Payments · C, A
- Screens: C `m:checkout.tsx`, `m:payment.tsx`, `m:confirmation.tsx` [all WIP mod]; A `m:agent/sales/[id].tsx` → payment request.
- API: `POST payments`, `POST payments/{payment}/initiate`, `GET mobile/purchases/{proposal}/status`, `POST mobile/agent/sales/{id}/payment-request`.
- States: `PaymentStatus` (§2). Guards: `Idempotency-Key`, amount from the proposal snapshot, [WIP] ownership. Client retry reuses a new key (audit A10; `client.ts` modified [WIP]).
- Notify: `paymentUpdated` [WIP]. Events: `payment.requested`, `payment.authorization.requested`, `payment.status.changed`.
- Failures: demo stall (A5, [WIP] fix in `MobilePurchaseStatusService`); provider decline → FAILED.
- Status C **PARTIAL**, A **PARTIAL** — needs INITIATED/CANCELLED states, stable idempotency on retry, and demo mode off in production.

**WF-023 Mobile-money payment** — Payments · C
- MTN MoMo + Orange Money adapters, `webhooks/payments/mtn-momo/callback`, `…/orange-money/callback`, `webhooks/payments/{provider}`, `WebhookProcessingService` (RECEIVED/PROCESSED/FAILED), `payments:poll-pending`, `MobileMoneyStatusReconciler`. Ledger posting via `FinancialPostingService`.
- Gaps: `DemoPurchaseSettler` bypasses the provider in production (A2); verify callback signature coverage for both providers; success → issuance is [WIP] `PaymentIssuanceTrigger`.
- Status C **PARTIAL** — needs production credentials, demo off, and an end-to-end test of callback → ledger → issuance.

**WF-024 Bank/card payment** — Payments · C
- No card/bank adapter or UI. Proposed `PaymentAdapter` for a card PSP + bank transfer reference, `m:payment.tsx` method options.
- Status C **MISSING**.

**WF-025 Payment retry** — Payments · C, A
- C: `POST mobile/payments/{payment}/retry`, `m:payments/[id].tsx`. The new attempt is linked to the same intent (good), but see the idempotency flaw.
- A: no assist-retry action.
- Status C **PARTIAL**, A **MISSING** — needs the retry reason shown, a change-method option and an agent "resend payment request" action.

**WF-026 Payment reconciliation** — Payments · B
- `F:Reconciliations`, `F:PaymentAttempts`, `F:PaymentRequests`; `POST reconciliation/imports`, `GET …/imports/{import}`, `POST …/approve`, `POST reconciliation/items/{item}/resolve`; `MatchEngine`; [WIP] `reconciliation:run` hourly.
- States: items MATCHED/EXCEPTION; import PROCESSING → COMPLETED(_WITH_EXCEPTIONS) → APPROVED. Missing: candidate_match, reconciled on the payment.
- Status B **PARTIAL** — needs a RECONCILED payment state, a provider statement feed, and an exceptions queue UI with reason codes (duplicate/short/over/unidentified/reversed).

**WF-027 Unmatched payment resolution** — Payments · B
- `reconciliation/items/{item}/resolve` (approval via import approve). No candidate suggestions or maker-checker on manual match. Agent "customer paid but policy unpaid" flag (N29): missing.
- Status B **PARTIAL**.

### Policy issuance & documents

**WF-028 Policy issuance** — Policy · B, I
- B: `F:PolicyIssuances`, `F:Proposals` (ViewProposal); API `POST policy-issuance-requests`, `…/{issuance}/approve|reject`. I: `m:carrier/issuance.tsx`, [WIP] `POST mobile/partner/carrier/issuance/{issuance}/approve|reject`.
- [WIP] `PaymentIssuanceTrigger::afterPaymentSucceeded` creates the request automatically (idempotent per payment) and notifies.
- States: request PENDING_APPROVAL/APPROVED/REJECTED; policy PAID_PENDING_ISSUANCE → ACTIVE. Events: `policy.issuance.requested`, `policy.issued`, `policy.issuance.rejected`.
- Status B **PARTIAL**, I **PARTIAL** — needs the ISSUING/ISSUANCE_FAILED states, a retry counter, a carrier API adapter, and the WIP trigger committed and verified.

**WF-029 Policy activation** — Policy · S
- ACTIVE is set at issuance approval (no separate ISSUED→ACTIVE on the inception date). [WIP] `policies:expire` daily. No PolicyActivated event.
- Status S **PARTIAL** — needs ISSUED with a future-dated activation job and the event.

**WF-030 Policy document generation** — Documents · S
- [WIP] `PolicyDocumentService::ensure` → POLICY_CERTIFICATE + POLICY_SCHEDULE documents; `resources/views/pdf/policy-certificate|policy-schedule|payment-receipt.blade.php`; signed download `GET mobile/policy-documents/{document}/download`, `GET mobile/payments/{payment}/receipt.pdf`. Documents are versioned (`document_versions`, `document_access_log`).
- Missing documents: quotation, proposal, terms/wording, endorsement (avenant), renewal notice, cancellation notice.
- Status S **PARTIAL** [WIP].

**WF-031 Attestation generation** — Documents · S
- `CertificateService` (templates with approval: `certificate-templates`, `…/approve`; `POST certificates`, `…/void`), `F:CertificateTemplates`, `F:Certificates`; `GET policies/{policy}/certificate`; [WIP] auto-issue at issuance with `OPES_DIGITAL_ATTESTATION` and audit `certificate.issued`.
- Missing: REPLACED/EXPIRED states; the hash/QR payload needs confirming; CIMA/ASAC-format attestation.
- Status S **PARTIAL**.

### Motor stickers

**WF-032 Sticker allocation** — Motor · B, A
- B: `F:StickerBatches`, `F:StickerInventory`; `POST sticker-batches`; `sticker_custody_events` exist. No branch→agent allocation step or API.
- A: no stock view.
- Proposed: `POST /stickers/allocations` {from, to (branch|agent), serial range}, `m:agent/stickers.tsx`.
- Status B **PARTIAL**, A **MISSING** — needs the ALLOCATED state, custody chain per holder and reconciliation.

**WF-033 Sticker issuance** — Motor · A
- Only Filament: ViewPolicy assigns IN_STOCK stickers. No agent scan/serial entry or handover receipt.
- Proposed: `POST /policies/{id}/stickers` {serial}, `m:agent/policies/[id]/sticker.tsx` (scan).
- Status A **MISSING**.

### Policy servicing

**WF-034 View/manage policy** — Policy · C, A, B
- C: `m:(customer)/(tabs)/policies.tsx`, `m:policy/[id].tsx`, `m:wallet/index.tsx`, `m:wallet/policy/[id].tsx`, `m:documents/[id].tsx` (all WIP mod; audit A6 crashes being fixed). API `GET mobile/wallet`, `GET mobile/wallet/policies/{policy}`, `GET policies[/{id}]` ([WIP] `OwnershipScope`, audit A1).
- A: [WIP] `m:agent/policies.tsx`, `GET mobile/partner/agent/policies`. B: [WIP] `m:broker/policies.tsx`, `GET mobile/partner/broker/policies`; `F:Policies`, `F:PolicyTransactions`.
- Missing: contact agent/provider action, exclusions display, B exceptions/service-request queue.
- Status C **PARTIAL**, A **PARTIAL** [WIP], B **PARTIAL** — needs the A1 fix shipped with cross-customer tests, and full detail (insurer, coverage, insured object, claims, payments).

**WF-035 Request policy amendment** — Endorsement · C, A
- C: `m:policy/[id]/service.tsx`, `m:services/new.tsx`, `m:services/[id].tsx`, `m:services/index.tsx`; API `GET|POST mobile/policy-service-requests`, `…/{id}/messages`, `POST policies/{policy}/service-requests`, `POST policies/{policy}/transactions` (type ENDORSEMENT, `premium_delta_minor`).
- Missing: typed change forms (address/vehicle/driver/beneficiary/coverage/insured value), evidence upload, a price-difference preview.
- A: nothing. Proposed `m:agent/policies/[id]/endorse.tsx`.
- Status C **PARTIAL**, A **MISSING**.

**WF-036 Review/approve endorsement** — Endorsement · B, I
- `POST policies/{p}/transactions/{t}/approve|reject` (`PolicyServicingService`), `F:PolicyTransactions`. Policy returns to ACTIVE; no new policy version and **no avenant document**. No insurer referral step.
- Status B **PARTIAL**, I **MISSING** — needs re-rating via the tariff, a policy version, the avenant PDF and an EndorsementIssued event.

**WF-037 Additional premium** — Endorsement · C
- `POST policies/{p}/transactions/{t}/payment-intents` → PENDING_CUSTOMER → payment → approve. The app has no screen for paying an endorsement.
- Status C **PARTIAL**.

**WF-038 Premium refund (endorsement)** — Endorsement · B
- A negative `premium_delta_minor` does not create a refund; only CANCELLATION creates a refund.
- Status B **MISSING** — needs a refund_due path feeding WF-063.

### Renewal

**WF-039 Renewal identification** — Renewal · S
- `POST renewals/seed`, `POST broker/renewals/seed` (manual, `days` param) → `renewal_cases` DUE; [WIP] `policies:notify-expiry` daily + `policy_expiry_reminders` table; `config/lifecycle.php` [WIP].
- Missing: automatic 90/60/30/15/7 windows as a schedule, agent auto-assignment, RenewalDue event.
- Status S **PARTIAL**.

**WF-040 Renewal quotation** — Renewal · A, B
- A: `m:agent/renewals.tsx`, `GET mobile/agent/renewals` (read). B: `m:broker/renewals.tsx`, `GET mobile/broker/renewals`, `F:Renewals`, `POST renewals/{renewal}/quote` (re-rates, → QUOTED), `GET reports/renewals`.
- Missing: an agent "generate renewal" action, contact logging (CONTACTED is set nowhere visible), a 30/60/90 broker dashboard.
- Status A **PARTIAL**, B **PARTIAL**.

**WF-041 Customer renewal acceptance** — Renewal · C
- `m:policy/[id]/renew.tsx` → `POST policies/{policy}/renewal-quote` → normal quote/offer accept. The renewal case is not advanced to ACCEPTED.
- Status C **PARTIAL**.

**WF-042 Renewal payment** — Renewal · C
- Uses the standard payment flow; no link from payment to renewal case (no PAID).
- Status C **PARTIAL**.

**WF-043 Renewal issuance** — Renewal · S, B
- `POST renewals/{renewal}/complete` sets `successor_policy_id`, RENEWED (guards: QUOTED + successor ACTIVE). The prior policy is not marked RENEWED; there is no automatic completion on successor issuance.
- Status S **PARTIAL**, B **PARTIAL** — needs automatic completion, a continuity link on the policy, and a PolicyRenewed event.

### Cancellation / suspension

**WF-044 Cancellation request** — Policy · C, A
- C: via service request or `POST policies/{p}/transactions` type CANCELLATION (effective date, reason). No financial preview screen.
- A: nothing.
- Status C **PARTIAL**, A **MISSING** — needs a cancellation screen with reason, evidence and refund preview (`CancellationCalculator`).

**WF-045 Cancellation approval** — Policy · B
- Approve → `CancellationCalculator` (approved `cancellation_rule_versions`, `F:CancellationRules`) → CANCELLED + refund row. No cancellation notice document, no document revocation, no maker-checker.
- Status B **PARTIAL**.

**WF-046 Suspension** — Policy · B
- SUSPENDED is a state, but **no command or endpoint** suspends a policy.
- Proposed `POST /policies/{id}/suspend` {reason} with a transaction type SUSPENSION.
- Status B **MISSING**.

**WF-047 Reinstatement** — Policy · B
- Transaction type REINSTATEMENT from SUSPENDED/EXPIRED → ACTIVE. No customer "request reinstatement" screen.
- Status B **PARTIAL**.

### Claims

**WF-048 FNOL** — Claims · C, A
- C: `m:claim/new.tsx`, `m:claim/emergency.tsx`, `m:claim/[id].tsx`, `m:claim/[id]/incident.tsx`, `parties.tsx`, `checklist.tsx`; `m:(customer)/(tabs)/claims.tsx`. API `POST mobile/claims`, `GET|PUT mobile/claims/{claim}/incident`, `…/parties`, `…/timeline`, `POST mobile/claims/emergency-assistance`; core `POST claims/fnol`. Reference returned immediately.
- A: no agent FNOL screen (core `claims/fnol` only via API). Customer offline claim drafts cannot sync (A11).
- Events: `claim.fnol.submitted`. Notify: `claimSaved` [WIP].
- Status C **BUILT** (caveats: typed dates, no GPS; status names A9), A **PARTIAL** — needs `m:agent/clients/[id]/claim/new.tsx` and the sync permission fix.

**WF-049 Claim registration** — Claims · B, S
- SUBMITTED → ACKNOWLEDGED via `claims/{id}/transitions`, carrier exchange (`ClaimCarrierExchangeService`, `claims/{id}/carrier-messages`), [WIP] `POST mobile/partner/carrier/claims/{claim}/acknowledge`. `F:Claims`; B mobile [WIP] `m:broker/claims.tsx` (read).
- Status B **PARTIAL**, S **PARTIAL** — needs auto-registration with a registration number and SLA.

**WF-050 Claim coverage validation** — Claims · B, I
- No coverage check result (policy in period, guarantee active, exclusions, payment status) and no coverage_confirmed/question/not_confirmed outcome.
- Proposed `POST /claims/{id}/coverage-check` + a Filament panel.
- Status **MISSING** for both.

**WF-051 Evidence submission** — Claims · C, A
- C: `m:claim/[id]/evidence.tsx`, `checklist.tsx`; `GET mobile/claims/{claim}/evidence-requirements`, `GET|POST …/evidence`, resumable uploads with checksum. Received/rejected shown.
- A: none.
- Status C **BUILT**, A **MISSING**.

**WF-052 Evidence review** — Claims · B
- `POST claims/{id}/evidence/{document}/verify`, `F:Claims`, [WIP] carrier `request-information`. Missing: evidence classification and a missing-items request to the customer as a notification.
- Status B **PARTIAL**.

**WF-053 Expert/adjuster assignment** — Claims · B
- `POST claims/{id}/assign` (handler; `claim.assigned`); customer inspection view + reschedule. No adjuster entity or states.
- Status B **PARTIAL** — needs an adjuster/expert registry and the assignment state machine.

**WF-054 Claim assessment** — Claims · Adjuster, B
- ASSESSMENT state, reserves (`claims/{id}/reserves`, `…/approve`, `F:ClaimReserves`), repair view `m:claim/[id]/repair.tsx`. No assessment report upload, and no separation of recommendation from decision.
- Status B **PARTIAL**, I **PARTIAL**.

**WF-055 Claim investigation** — Claims · B
- `FraudReviewService`, `trust/fraud-alerts`, `risk-alerts`, `F:RiskAlerts`, `F:ComplianceCases`. Not linked to a claim INVESTIGATING state.
- Status B **PARTIAL**.

**WF-056 Claim approval** — Claims · I/B
- `POST claims/{id}/decisions` + `…/decisions/{decision}/approve` (maker-checker on authority), `F:ClaimDecisions`; [WIP] mobile carrier propose/approve. Records amount and rationale; deductible/coverage component need confirming.
- Status B **PARTIAL**, I **PARTIAL** [WIP].

**WF-057 Partial claim approval** — Claims · I/B
- PARTIALLY_APPROVED exists in the machine and decisions. Status B **PARTIAL**, I **PARTIAL**.

**WF-058 Claim rejection** — Claims · I/B
- DECLINED decision via the same route. There is no mandatory reason-code catalogue, and the customer appeal route was broken by the REJECTED name (A9, [WIP] fix).
- Status B **PARTIAL**, I **PARTIAL**.

**WF-059 Claim settlement** — Claims · C, B, I
- C: `m:claim/[id]/settlement.tsx`, `settlement-payment.tsx`; `GET mobile/claims/{claim}/settlement`, `POST …/settlement/decision` (discharge accept). B/I: `claims/{id}/decisions/{d}/payments`, `…/payments/{p}/approve|processing|paid|failed|reverse`, `F:ClaimPayments`.
- Missing: RECONCILED, a receipt to the customer, and a ClaimSettled event name.
- Status C **PARTIAL**, B **PARTIAL**, I **PARTIAL**.

**WF-060 Claim closure** — Claims · B
- CLOSED via transitions. No closure guards (reconciled settlement, tasks resolved, documents complete, final communication).
- Status B **PARTIAL**.

**WF-061 Claim reopening** — Claims · C, B
- `ClaimLifecycle` allows CLOSED→REOPENED, but `ClaimStateMachine` does not. There is no reopen endpoint or screen.
- Proposed `POST /mobile/claims/{id}/reopen-requests`, `POST /claims/{id}/reopen` (B approval).
- Status C **MISSING**, B **MISSING**.

**WF-062 Claim appeal/dispute** — Claims · C, B
- C: `m:claim/[id]/appeal.tsx`, `POST mobile/claims/{claim}/appeals`. B: `POST claims/{id}/disputes`, `…/resolve`; the original and revised decisions are both kept (separate decisions table).
- Status C **PARTIAL** (status-gating bug A9), B **PARTIAL**.

### Money: refund, commission, settlement

**WF-063 Customer refund** — Refund · B (C requests)
- C: `m:payments/[id]/refund.tsx`, `POST mobile/payments/{payment}/refunds` (A7 payload mismatch, [WIP] mod). B: `POST payments/{payment}/refunds`, `POST refunds/{refund}/approve`, `F:FinancialCases`. Sources: only cancellation and manual.
- Status C **PARTIAL**, B **PARTIAL** — needs a refund state machine (calculated→paid→reconciled), payout to MoMo, and sources for duplicate/endorsement/failed issuance.

**WF-064 Agent commission accrual** — Commission · S
- `POST financial-distribution/commissions/accrue` (explicit call), `CommissionCalculator`, approved `commission_rule_versions` (`F:CommissionRules`, `F:CommissionAccruals`), vest/clawback. Not triggered automatically on "policy paid"; this needs confirming.
- Status S **PARTIAL**.

**WF-065 Broker commission accrual** — Commission · S
- Same engine; `GET mobile/broker/commission-accruals`, [WIP] `mobile/partner/broker/commissions`. Status S **PARTIAL**.

**WF-066 Commission approval** — Commission · B
- `partner-statements`, `…/approve`, `…/publish`, `F:PartnerStatements`, `m:broker/receivables.tsx`, statements. Status B **PARTIAL** (no commission adjustment maker-checker).

**WF-067 Commission payment** — Commission · B, A
- `partner-payouts/{p}/approve|process|complete|fail|reverse`, `F:PartnerPayouts`; A `m:agent/wallet.tsx`, `m:agent/withdrawal.tsx`, `GET|POST mobile/agent/withdrawals`, `GET mobile/agent/commissions`.
- Status A **PARTIAL**, B **PARTIAL** — needs a RECONCILED payout and a CommissionSettled event.

**WF-068 Broker-insurer settlement** — Settlement · B
- `carrier-settlements` + submit/approve/paid/fail/reverse, bordereaux (`bordereaux/*`, `F:Bordereaux`, `F:CarrierSettlements`), carrier mobile `m:carrier/settlements.tsx`, `m:carrier/settlement/[id].tsx`, `m:carrier/bordereaux.tsx`; [WIP] `settlements:prepare` weekly.
- Status B **PARTIAL** — needs the canonical batch states and settlement computed from the ledger.

**WF-069 Settlement reconciliation** — Settlement · B
- No RECONCILED step for carrier settlements and no matching of bank confirmation to the batch lines. `GET settlements/{batch}` is read only.
- Status B **MISSING**.

### Support, complaints, notifications

**WF-070 Customer support ticket** — Support · C
- `m:support/index.tsx`, `new.tsx`, `[id].tsx`, [WIP] `faq.tsx`; `GET|POST mobile/support/cases`, `…/messages`, `…/attachments`, [WIP] `…/escalate`; `TicketStateMachine`; `F:SupportTickets`.
- Status C **BUILT** (caveats: no rating, no claim/payment linking).

**WF-071 Complaint registration** — Complaint · C
- The core `support-tickets` accepts `type=COMPLAINT|REGULATORY_COMPLAINT`; the mobile support form has no complaint type.
- Status C **PARTIAL** — needs a separate complaint entity (owner rule) and `m:complaints/new.tsx`.

**WF-072 Complaint resolution** — Complaint · B
- Only the generic ticket transitions (`support-tickets/{t}/transitions`). No acknowledgement SLA, classification, corrective action or regulator escalation.
- Status B **PARTIAL**.

**WF-073 Workflow notification** — Notifications · S
- In-app inbox `m:notifications/index.tsx`, `[id].tsx`, `GET mobile/notifications`; delivery pipeline (`notifications`, templates + approval, `F:NotificationDeliveries`, `notifications:dispatch-pending`); [WIP] `LifecycleNotificationProducer` (quote, proposal, UW case, claim, payment, device, user), `CustomerNotifier` (+SMS), Expo push, `POST mobile/account/push-tokens`.
- Missing: agent/broker recipients, deep-link-to-action on every type (N39 "no dead messages"), and event-driven (outbox) production.
- Status S **PARTIAL** [WIP].

**WF-074 Document expiry remediation** — Documents · C, A
- No expiry tracking on ID/vehicle documents and no alert.
- Status C **MISSING**, A **MISSING**.

**WF-075 KYC remediation** — KYC · C, A, B
- Depends on WF-003 review states, which do not exist.
- Status all **MISSING**.

**WF-076 Corporate insurance onboarding** — Corporate · C, A
- See WF-004. Status **MISSING**.

**WF-077 Vehicle registration** — Motor · C, A
- C: `m:assets/index.tsx`, `new.tsx`, `[id].tsx`, `[id]/scan.tsx`; `GET|POST mobile/assets`, `…/documents`, `…/scan`, `…/scan/{document}/confirm`; `risk-assets` core, `F:RiskAssets`. Saved assets are not reused in quotes (audit §4). Duplicate VIN/plate check: unconfirmed.
- A: none.
- Status C **PARTIAL**, A **MISSING**.

**WF-078 Beneficiary management** — Beneficiary · C, A
- No beneficiary model, API or screen. Status **MISSING**.

### Governance

**WF-079 Agent portfolio transfer** — Portfolio · B
- Per-customer attribution disputes/resolution exist (`attributions`, `attribution-disputes/{d}/resolution`, `F:Attributions`, event `customer.attribution.changed`). No bulk portfolio transfer, impact preview or access revocation.
- Status B **PARTIAL**.

**WF-080 Agent suspension** — Agent · B
- `POST partners/{partner}/status`, membership revoke, `F:Partners`, `F:Memberships`. It is unclear whether active cases get reassigned or new business gets blocked everywhere.
- Status B **PARTIAL**.

**WF-081 Maker-checker approval** — Approval · B
- Approve endpoints exist per domain (claim decisions/reserves/payments, refunds, reconciliation, tariffs, commission rules, statements, payouts, settlements, privileged access, templates). There is no unified approval queue and no uniform maker≠checker enforcement. Manual payment confirmation and premium override have no flow.
- Status B **PARTIAL** — needs a generic `approval_requests` table, `F:Approvals`, and a maker≠checker guard trait.

**WF-082 Public document verification** — Verification · P
- `POST public/certificates/verify`, `POST public/insurance/verify`; [WIP] `public_verification_lookups` log. REPLACED/EXPIRED not distinguished. App links 404 (A13).
- Status P **PARTIAL**.

**WF-083 Expired policy recovery** — Policy · C, A
- C: renew from `m:policy/[id]/renew.tsx`. A: `m:agent/renewals.tsx`. [WIP] EXPIRED→LAPSED. No lapsed dashboard (B).
- Status C **PARTIAL**, A **PARTIAL**.

**WF-084 Failed policy issuance** — Exception · B
- Issuance REJECTED; [WIP] trigger idempotent per payment. No paid-not-issued queue, retry, or customer message "Payment confirmed — issuance being completed".
- Status B **PARTIAL**.

**WF-085 Failed payment recovery** — Exception · C, B
- C: retry (WF-025). B: `F:PaymentAttempts`, `payments:poll-pending`. No failure dashboard by provider error.
- Status C **PARTIAL**, B **PARTIAL**.

**WF-086 Duplicate payment** — Exception · B
- Reconciliation EXCEPTION + `F:FinancialCases` + refunds. No automatic duplicate detection per obligation.
- Status B **PARTIAL**.

**WF-087 Paid renewal, issuance failure** — Exception · B
- No renewal↔payment↔issuance exception linkage; EXPIRED→ACTIVE is allowed (it should never be).
- Status B **MISSING**.

**WF-088 Customer 360** — Customer 360 · A, B
- A: `m:agent/clients/index.tsx`, `[id].tsx`, `GET mobile/agent/clients/{customer}`. B: `m:broker/clients.tsx`, `m:broker/clients/[id].tsx`, `GET mobile/broker/clients/{customer}`, `F:Customers` ViewCustomer, `F:Parties`.
- Missing: unified tabs (leads, quotes, policies, renewals, claims, payments, activities, documents, audit) with links.
- Status A **PARTIAL**, B **PARTIAL**.

**WF-089 Suspicious claim review** — Fraud/Risk · B
- `trust/fraud-alerts`, `…/decision`, `risk-alerts/{a}/decision`, `fraud-rules`, `F:RiskAlerts`. The platform flags and a human decides (matches the owner rule).
- Status B **PARTIAL** — needs claim-linked cases and an investigator assignment.

**WF-090 Operational audit review** — Audit · B/Admin
- `GET compliance/audit-log`, `AuditWriter` everywhere (~175 audit event names), `F:PrivilegedAccess`, `F:RegulatoryReports`. No Filament audit-log browser with filters and export.
- Status B **PARTIAL**.

---

## 4. Proposed build order (dependency-ordered epics)

1. **E0 — Safety floor.** Ship the [WIP] A1 ownership scope with cross-customer tests; turn demo mode off in production and split staging/prod (A2/A3); stable idempotency keys on retry (A10). *Blocks everything customer-facing.*
2. **E1 — State-model foundation.** Add one `StateMachine` per aggregate in `app/Domain` with owner-canonical values (mapping/migration of existing strings): Quote (new), Proposal (+INFORMATION_REQUIRED, RESUBMITTED, WITHDRAWN in the enum), Payment (+INITIATED, CANCELLED, RECONCILED), Policy (+ISSUED, AMENDED, RENEWED; forbid EXPIRED→ACTIVE by renewal), Claim (merge the two machines; +REGISTERED, INVESTIGATING, REJECTED/APPEALED aliases), KYC, Lead, Renewal, Endorsement, Cancellation, Sticker, Refund, Settlement batch. Publish a typed domain-event catalogue (the 26 owner events) emitted through the outbox. *Enables WF-010…087.*
3. **E2 — Event-driven notifications.** Outbox consumers → `CustomerNotifier`/push/SMS for C, A and B, with a deep link per type (WF-073, N39). Replace the model-observer producer [WIP].
4. **E3 — Identity & KYC.** Registration states, server-side consent (WF-001/002), KYC review queue + maker-checker + remediation (WF-003, 075), document expiry (WF-074), KYC gate on proposal submit.
5. **E4 — Quote → proposal completeness.** Motor/schema wizard, PATCH/recalculate, generate quotation PDF, send/share + VIEWED, decline/expire (WF-010…014); comparison for C and A (WF-009); proposal document upload, signature, info-request/resubmit loop, counter-offer (WF-015…021).
6. **E5 — Payment → issuance → documents (go-live path).** Commit and verify `PaymentIssuanceTrigger`; ISSUING/ISSUANCE_FAILED with safe retry and a paid-not-issued queue (WF-028, 084); certificate/schedule/receipt PDFs + attestation states (WF-030/031); card/bank adapter (WF-024); retry/change method (WF-025, 085).
7. **E6 — Reconciliation & refunds.** RECONCILED payment state, exceptions queue with reasons, candidate matching, duplicate detection (WF-026/027/086); refund state machine + MoMo payout + all refund sources (WF-063, 038).
8. **E7 — Generic maker-checker.** `approval_requests` + Filament queue + maker≠checker trait, applied to manual payment, refund, commission adjustment, cancellation, settlement, premium override, document revocation (WF-081). *Needed before E8/E9 go live.*
9. **E8 — Policy servicing.** Typed endorsements with re-rating, policy versions, avenant PDF, additional premium/refund (WF-035…038); cancellation with preview + notice (WF-044/045); suspend command + reinstatement request (WF-046/047); agent servicing screens.
10. **E9 — Renewal engine.** Scheduled 90/60/30/15/7 windows, auto-assignment, renewal states through PAID/RENEWED, auto-complete on successor issuance, lapsed dashboard, renewal-payment exception path (WF-039…043, 083, 087).
11. **E10 — Claims completion.** Coverage validation, adjuster registry + states, assessment report, investigation link, reason-code catalogue, closure guards, reopening, agent FNOL/evidence screens (WF-048…062, 089).
12. **E11 — Distribution CRM.** Lead machine + customer-originated leads + broker assignment/SLA (WF-005…007); Customer 360 (WF-088); portfolio transfer + agent suspension with reassignment (WF-079/080); agent product permissions (WF-008).
13. **E12 — Money distribution.** Auto-accrual on payment success, reversals, commission payment reconciliation (WF-064…067); settlement batches computed from the ledger + reconciliation (WF-068/069).
14. **E13 — Motor stickers.** Allocation chain broker→branch→agent, agent scan/issue/handover, exception states (WF-032/033).
15. **E14 — Complaints, corporate, beneficiaries, audit UI.** Separate complaint entity and lifecycle (WF-071/072); corporate KYB + onboarding (WF-004, 076); beneficiaries (WF-078); vehicle duplicate check + reuse in quotes (WF-077); public verification states + app links (WF-082); audit-log browser (WF-090).
