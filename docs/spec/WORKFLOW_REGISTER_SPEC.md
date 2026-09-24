# OpesInsure canonical Workflow Register — owner specification (2026-09-24)

Supersedes the 1–50 numbering in WORKFLOWS_SPEC.md (that file remains the narrative source for customer/agent/broker steps).

Pattern for every workflow: Workflow ID → Module → Actor → Trigger → Preconditions → Screens → Actions → API → States → Guards → Notifications → Documents → Audit Events → Failure Paths → Completion State.

## The 90 canonical workflows

| ID | Module | Workflow | Primary actors |
|---|---|---|---|
| WF-001 | Identity | Customer registration | Customer |
| WF-002 | Identity | Phone/email verification | Customer |
| WF-003 | KYC | Individual KYC | Customer, Agent, Broker |
| WF-004 | KYC | Corporate KYC | Corporate Customer, Agent, Broker |
| WF-005 | CRM | Create prospect/lead | Customer, Agent |
| WF-006 | CRM | Lead qualification | Agent, Broker |
| WF-007 | CRM | Lead assignment/reassignment | Broker |
| WF-008 | Product | Product discovery | Customer, Agent |
| WF-009 | Product | Product comparison | Customer, Agent |
| WF-010 | Quote | Create quotation | Customer, Agent |
| WF-011 | Quote | Recalculate quotation | Customer, Agent |
| WF-012 | Quote | Send/share quotation | Agent |
| WF-013 | Quote | Quote acceptance | Customer |
| WF-014 | Quote | Quote rejection/loss | Customer, Agent |
| WF-015 | Proposal | Convert quote to proposal | Customer, Agent |
| WF-016 | Proposal | Submit insurance proposal | Customer, Agent |
| WF-017 | Underwriting | Straight-through underwriting | System |
| WF-018 | Underwriting | Manual underwriting referral | Broker, Insurer |
| WF-019 | Underwriting | Additional information request | Underwriter, Broker, Agent, Customer |
| WF-020 | Underwriting | Conditional acceptance | Underwriter, Customer |
| WF-021 | Underwriting | Proposal rejection | Underwriter |
| WF-022 | Payments | Initiate premium payment | Customer, Agent |
| WF-023 | Payments | Mobile-money payment | Customer |
| WF-024 | Payments | Bank/card payment | Customer |
| WF-025 | Payments | Payment retry | Customer, Agent |
| WF-026 | Payments | Payment reconciliation | Broker |
| WF-027 | Payments | Unmatched payment resolution | Broker |
| WF-028 | Policy | Policy issuance | Broker, Insurer |
| WF-029 | Policy | Policy activation | System |
| WF-030 | Documents | Policy document generation | System |
| WF-031 | Documents | Attestation generation | System |
| WF-032 | Motor | Sticker allocation | Broker, Agent |
| WF-033 | Motor | Sticker issuance | Agent |
| WF-034 | Policy | View/manage policy | Customer, Agent, Broker |
| WF-035 | Endorsement | Request policy amendment | Customer, Agent |
| WF-036 | Endorsement | Review/approve endorsement | Broker, Insurer |
| WF-037 | Endorsement | Additional premium | Customer |
| WF-038 | Endorsement | Premium refund | Broker |
| WF-039 | Renewal | Renewal identification | System |
| WF-040 | Renewal | Renewal quotation | Agent, Broker |
| WF-041 | Renewal | Customer renewal acceptance | Customer |
| WF-042 | Renewal | Renewal payment | Customer |
| WF-043 | Renewal | Renewal issuance | System, Broker |
| WF-044 | Policy | Cancellation request | Customer, Agent |
| WF-045 | Policy | Cancellation approval | Broker |
| WF-046 | Policy | Suspension | Broker |
| WF-047 | Policy | Reinstatement | Broker |
| WF-048 | Claims | First notification of loss | Customer, Agent |
| WF-049 | Claims | Claim registration | Broker/System |
| WF-050 | Claims | Claim coverage validation | Broker, Insurer |
| WF-051 | Claims | Evidence submission | Customer, Agent |
| WF-052 | Claims | Evidence review | Broker |
| WF-053 | Claims | Expert/adjuster assignment | Broker |
| WF-054 | Claims | Claim assessment | Adjuster, Broker |
| WF-055 | Claims | Claim investigation | Broker |
| WF-056 | Claims | Claim approval | Insurer/Broker |
| WF-057 | Claims | Partial claim approval | Insurer/Broker |
| WF-058 | Claims | Claim rejection | Insurer/Broker |
| WF-059 | Claims | Claim settlement | Broker/Insurer |
| WF-060 | Claims | Claim closure | Broker |
| WF-061 | Claims | Claim reopening | Customer, Broker |
| WF-062 | Claims | Claim appeal/dispute | Customer, Broker |
| WF-063 | Refund | Customer refund | Broker |
| WF-064 | Commission | Agent commission accrual | System |
| WF-065 | Commission | Broker commission accrual | System |
| WF-066 | Commission | Commission approval | Broker |
| WF-067 | Commission | Commission payment | Broker |
| WF-068 | Settlement | Broker-insurer settlement | Broker |
| WF-069 | Settlement | Settlement reconciliation | Broker |
| WF-070 | Support | Customer support ticket | Customer |
| WF-071 | Complaint | Complaint registration | Customer |
| WF-072 | Complaint | Complaint resolution | Broker |
| WF-073 | Notifications | Workflow notification | System |
| WF-074 | Documents | Document expiry remediation | Customer, Agent |
| WF-075 | KYC | KYC remediation | Customer, Agent, Broker |
| WF-076 | Corporate | Corporate insurance onboarding | Corporate Customer, Agent |
| WF-077 | Motor | Vehicle registration | Customer, Agent |
| WF-078 | Beneficiary | Beneficiary management | Customer, Agent |
| WF-079 | Portfolio | Agent portfolio transfer | Broker |
| WF-080 | Agent | Agent suspension | Broker |
| WF-081 | Approval | Maker-checker approval | Broker |
| WF-082 | Verification | Public document verification | Public |
| WF-083 | Policy | Expired policy recovery | Customer, Agent |
| WF-084 | Exception | Failed policy issuance | Broker |
| WF-085 | Exception | Failed payment recovery | Customer, Broker |
| WF-086 | Exception | Duplicate payment | Broker |
| WF-087 | Exception | Paid renewal but issuance failure | Broker |
| WF-088 | Customer 360 | Customer master view | Agent, Broker |
| WF-089 | Fraud/Risk | Suspicious claim review | Broker |
| WF-090 | Audit | Operational audit review | Broker/Admin |

## Detailed specifications given by the owner

- **WF-001 Registration.** Trigger Create Account; precondition no active account for the same verified identity. Screens Welcome → Register → Phone/Email Verification → Personal Details → Consent → Success → Dashboard. APIs POST /auth/register, /auth/otp/request, /auth/otp/verify, /customers/profile. States initiated → contact_pending → contact_verified → profile_incomplete → registered. Guards uniqueness, OTP validity, password rules, consent mandatory. Notifications OTP, account created. Audit CustomerRegistrationStarted, OTPVerified, CustomerRegistered. Failures duplicate, OTP expired, OTP limit, API unavailable.
- **WF-003 Individual KYC.** Trigger transaction needing verified identity. Screens Overview → Identity Details → ID Capture → Address → Review → Submission → Status. Data legal name, DOB, nationality, ID type/number, issue/expiry, address, phone, documents. Actions save draft, upload, retake, submit, respond to remediation. APIs POST /customers/{id}/kyc, /customers/{id}/kyc/documents, /kyc/{id}/submit, /kyc/{id}/review. States not_started → draft → submitted → reviewing → approved; exceptions more_information_required, rejected, expired. Guards mandatory docs, validated fields, maker-checker on review. Audit every field/document change, reviewer, decision.
- **WF-010 Quotation.** Screens Product → Applicant → Risk → Coverage → Add-ons → Review → Premium → Summary. APIs POST /quotes, PATCH /quotes/{id}, POST /quotes/{id}/calculate, /quotes/{id}/generate. States draft → rating → calculated → generated → sent, viewed, accepted, declined, expired. Guards valid product version, active tariff, required fields, eligibility. Document quotation PDF. Audit tariff version, inputs, premium, overrides. Failures unsupported risk, carrier unavailable, missing tariff, invalid data.
- **WF-013 Acceptance.** Quote Details → Coverage Confirmation → Declaration → Continue to Proposal; POST /quotes/{id}/accept; viewed → accepted; guards unexpired, pricing version valid, no material change; audit timestamp + identity.
- **WF-016 Proposal submission.** Accepted Quote → Application → Risk Declaration → Documents → Beneficiaries → Review → Consent → Submit; draft → complete → submitted → straight_through_review | underwriting_referral; submitted snapshot immutable/versioned.
- **WF-017 STP.** Submitted → Rules → Risk Checks → approved / conditional / referred; record rule set/version used.
- **WF-018 Manual UW.** Queue → Application → Risk Summary → Documents → Pricing → Decision; referred → assigned → reviewing → decision_pending → approved/conditional/declined; audit underwriter, rationale, old/new premium, evidence.
- **WF-019 Info request.** Underwriter → Broker → Agent/Customer → data → Agent reviews → Broker submits → UW resumes; information_required → customer_action → received → resubmitted → reviewing; request lists exact missing items.
- **WF-022 Payment initiation.** Summary → Method → Provider Confirmation → Processing → Result; POST /payments, /payments/{id}/initiate; created → initiated → pending → successful; failed, expired, cancelled, reversed; idempotency key, immutable amount, reference, callback verification.
- **WF-023 Mobile money.** Created → MTN/Orange → Number → Provider Request → Authorization → Callback → Verify Signature → Successful → Ledger Posting; client never authoritative.
- **WF-026 Reconciliation.** Provider transactions → import/webhook → auto match → exceptions queue → investigation → approved match → reconciled; unmatched → candidate_match → matched → reconciled; reasons duplicate, unidentified payer, wrong reference, over/under payment, reversal.
- **WF-028 Issuance.** Approved proposal + payment → Issue Request → Carrier/Policy Engine → Number → Record → Documents → Activation; issuance_pending → issuing → issued → active; issuance_failed; idempotent.
- **WF-031 Attestation.** Policy → approved template version → populate → PDF → QR/identifier → store → publish; requested → generated → active; revoked, replaced, expired; audit template version, hash, number, issuer.
- **WF-032 Sticker allocation.** Batch → Broker Inventory → Branch → Agent → Policy; unique serial, one active assignment, full custody history.
- **WF-035/036 Endorsement.** Policy → change type → details → evidence → recalculation → submit; draft → submitted → review → approved (→ additional_premium_required | refund_due); approval validates rules, UW if needed, financial adjustment, avenant, new policy version, history preserved.
- **WF-039/040/043 Renewal.** Configurable 90/60/30/15/7-day windows create renewal cases, assign agent, notify; renewal quote always re-rated with current tariffs; renewal issuance creates new period/version and documents, archives prior as renewed, keeps continuity link.
- **WF-044/045 Cancellation.** Policy → Cancellation → Reason → Evidence → Financial Preview → Confirm (requested → reviewing); approval determines effective date, calculates premium/refund, approves, cancels, revokes superseded documents, issues notice.
- **WF-048 FNOL.** Select Policy → Incident Type → Details → Location → Persons/Assets → Description → Evidence → Review → Submit; POST /claims; draft → submitted; immediate reference; snapshot audited.
- **WF-050 Coverage validation.** Policy existed, in period, risk covered, guarantee active, exclusions, payment status; coverage_confirmed / coverage_question / coverage_not_confirmed; no automatic rejection without authorized review.
- **WF-051 Evidence.** Checklist → capture/upload → classify → submit; keep uploader, time, filename, MIME, hash, claim, version.
- **WF-053 Adjuster.** assignment_pending → assigned → scheduled → inspection_complete → report_submitted. **WF-054** recommendation is not the decision. **WF-055** investigation preserves indicators; no fraud label without authorized decision.
- **WF-056 Approval.** decision_pending → approved; records rationale, authority, amount, coverage component, deductible, adjustments. **WF-058 Rejection** reason codes, explanation, supervisor approval, customer notified with appeal route.
- **WF-059 Settlement.** settlement_preparation → awaiting_authorization → payment_pending → paid → reconciled; discharge required. **WF-060 Closure** only after final decision, reconciled settlement, tasks resolved, documents complete, final communication; settled → closed.
- **WF-062 Appeal.** Original and revised decisions both kept.
- **WF-063 Refund.** Sources cancellation, duplicate, endorsement, overpayment, failed issuance; candidate → calculate → review → approve → pay → reconcile; no bypass.
- **WF-064/067 Commission.** Accrual stores policy, premium basis, rule/version, rate, amount, effective date; payment: statement → review → approval → settlement → paid → reconciled; reversals on cancellation/refund.
- **WF-068 Settlement.** Collected premiums → period → carrier payable → reconcile → batch → approval → transfer → confirm → reconcile; never from dashboard totals.
- **WF-070 Support / WF-071 Complaint.** Complaints separate from support: Submitted → Acknowledged → Classified → Assigned → Investigated → Resolution → Communicated → Closed/Escalated; retained independently.
- **WF-074 Expiring document, WF-075 KYC remediation** (original failed submission retained), **WF-077 Vehicle** (registration, VIN/chassis, make/model, year, use, owner, documents; duplicate check), **WF-079 Portfolio transfer** (preview impact, approve, historical ownership visible), **WF-080 Agent suspension** (no broken references), **WF-081 Maker-checker** (manual payment confirmation, refund, commission adjustment, cancellation, settlement, premium override, document revocation, sensitive permission change; maker ≠ checker).
- **WF-082 Public verification.** VALID, EXPIRED, REVOKED, REPLACED, NOT_FOUND; minimal disclosure.
- **WF-084 Paid but not issued.** Customer sees "Payment confirmed — policy issuance being completed"; broker sees customer, payment, proposal, carrier, reason, retries, last attempt, escalation; safe retry / manual / controlled refund; never charge again.
- **WF-085** new attempt reference linked to the same obligation. **WF-086** duplicate funds never left unattributed. **WF-087** old policy never reactivated by a renewal payment.
- **WF-088 Customer 360** — agent and broker views as listed; every item links to its record.

## Cross-role rule
One record, one ID across Customer, Agent, Broker and Insurer (quote, policy, claim). No per-role copies.

## Canonical state models
- Quotation: draft → calculated → generated → sent → viewed → accepted; declined, expired, cancelled.
- Proposal: draft → submitted → reviewing → information_required → resubmitted → approved / declined.
- Payment: created → initiated → pending → successful → reconciled; failed, expired, cancelled, reversed, refunded.
- Policy: issuance_pending → issued → active → expired; amended, suspended, cancelled, renewed.
- Claim: draft → submitted → registered → reviewing → assessment → decision_pending → approved → settlement_pending → paid → closed; information_required, investigating, partially_approved, rejected, appealed, reopened.

## Screen-level build rule
Each screen: Screen ID, name, module, actor, route, entry points, header, data blocks, buttons, form fields, validation, permissions, API endpoint, loading/empty/error/success states, offline behaviour, audit event (mutations), notifications, next screens.

## Backend rule
Request → Authentication → Authorization → Validate → Domain Command → Guard Transition → Transaction → Persist → Domain Event → Outbox → Async integrations/notifications → Audit → Response. Never button → direct status update.

## Required domain events
CustomerRegistered, KYCSubmitted, KYCApproved, QuoteCalculated, QuoteAccepted, ProposalSubmitted, UnderwritingApproved, PaymentInitiated, PaymentSucceeded, PaymentFailed, PolicyIssued, PolicyActivated, AttestationGenerated, EndorsementIssued, RenewalDue, PolicyRenewed, ClaimReported, ClaimEvidenceReceived, ClaimAssigned, ClaimApproved, ClaimRejected, ClaimSettled, RefundApproved, CommissionAccrued, CommissionSettled, SettlementCompleted.

## Next layer
Master Screen Register: the 90 workflows converted into every Customer, Agent and Broker screen with IDs, routes, fields, cards, tables, buttons, modals, filters, permissions, APIs, states and transitions — giving the true canonical screen count.
