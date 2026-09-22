# OpesInsure Canonical Module Register

This register is the scope authority. A module is not complete until its commands, queries, policies, events, API schemas, authorization, audit trail, localization, observability and tests are complete.

## Experience layers

1. Public/B2C: discovery, identity, consent, customer profile, insured assets, quote comparison, proposal, checkout, payment, policies, renewals, endorsements, cancellations, claims, documents, delivery, support and notifications.
2. Agent: onboarding/KYB, licensing, agent mode, assisted customer registration, document capture/OCR review, quotes, client payment requests, portfolio, renewals, commission wallet, withdrawals, reversals, disputes and performance.
3. Broker ERP: tenant setup, branches, staff/RBAC, customer ledger, fleets/groups, products and negotiated tariffs, quotes, proposals, policies, receivables, claims, renewals, commissions, accounting exports, marketplace publishing, reporting and audit.
4. Carrier: onboarding, contracts, products, tariffs, underwriting rules, availability, quote adapter, policy issuance adapter, endorsements, cancellations, claims exchange, bordereaux, reconciliation and settlements.
5. Operations/Admin: partner approval, catalogue governance, compliance, customer support, payment operations, financial operations, fulfilment, complaints, fraud/risk, audit, configuration, analytics and incident operations.

## Bounded domains

| # | Domain | Required capabilities |
|---:|---|---|
| 01 | Tenancy | Tenant lifecycle, branch hierarchy, data scope, branding, configuration, suspension |
| 02 | Identity & Access | OAuth2/OIDC-ready flows, MFA, device/session control, RBAC/ABAC, service clients |
| 03 | Party & Customer | People/organizations, contacts, addresses, KYC, consent, dependants, duplicates |
| 04 | Partner & Licensing | Agents, brokers, carriers, licences, contracts, appointments, compliance review |
| 05 | Attribution | Immutable origin registration, renewal attribution, exceptions, disputes, expiry rules |
| 06 | Insurance Catalogue | Lines, products, coverages, exclusions, add-ons, documents, eligibility, versions |
| 07 | Tariff & Rating | Versioned tariff tables, formula inputs, taxes/levies, partner overrides, explainability |
| 08 | Asset & Risk | Vehicles, drivers, property, travellers, health members, inspections, risk facts |
| 09 | Quote | Quote requests, carrier offers, comparison, validity, referrals, decline reasons |
| 10 | Proposal & Underwriting | Proposal, disclosures, documents, referral tasks, decisions, counteroffers |
| 11 | Policy | Issuance, certificates, schedules, status, coverage periods, co-insurance references |
| 12 | Policy Servicing | Renewals, endorsements, cancellations, reinstatement, refunds, no-claim history |
| 13 | Payment | Intent, provider collection, webhook inbox, idempotency, timeout, refund, chargeback |
| 14 | Accounting Ledger | Double-entry journals, immutable postings, suspense, escrow liability, fees, balances |
| 15 | Commission | Rule versions, accrual, vesting, clawback, split, statement, payout and dispute |
| 16 | Reconciliation | Provider statement import, matching, exceptions, investigation and closure |
| 17 | Settlement | Carrier payable, approved batches, payment evidence, acknowledgement and reversal |
| 18 | Claims | FNOL, claimant, loss, evidence, assignment, carrier handoff, status and communications |
| 19 | Documents | Upload, malware scan, OCR, human verification, classification, retention, access log |
| 20 | Certificate & Sticker | Template/version, number allocation, QR verification, print, stock, custody, voiding |
| 21 | Logistics | Fulfilment order, SLA, dispatch, courier, tracking, delivery OTP/POD, failed delivery |
| 22 | Notifications | Templates, preference/consent, SMS/email/push/WhatsApp adapters, retries, receipts |
| 23 | Support & Complaints | Ticket, complaint, SLA, ombuds/regulatory escalation, evidence and resolution |
| 24 | Fraud & Risk | Velocity rules, sanctions/PEP adapter, duplicate detection, alerts, review, decisions |
| 25 | Audit & Compliance | Append-only audit, data access, retention/legal hold, regulatory exports, maker-checker |
| 26 | Reporting & Analytics | Operational dashboards, portfolios, conversion, loss/claims status, finance, exports |
| 27 | Configuration | Fees, calendars, geography, feature flags, numbering, translations, reference data |
| 28 | Integration Platform | Partner API clients, webhooks, signing keys, scopes, sandbox, quotas, usage analytics |

## Mandatory lifecycle states

- Quote: `DRAFT → SUBMITTED → RATED → REFERRED|OFFERED|DECLINED → ACCEPTED|EXPIRED|WITHDRAWN`.
- Proposal: `DRAFT → DISCLOSURES_PENDING → DOCUMENTS_PENDING → SUBMITTED → UNDER_REVIEW → APPROVED|COUNTEROFFERED|DECLINED → PAYMENT_PENDING`.
- Payment: `CREATED → PENDING_CUSTOMER → PROCESSING → SUCCEEDED|FAILED|EXPIRED`; exceptional: `REFUND_PENDING → REFUNDED`, `CHARGEBACK_OPEN → CHARGED_BACK`.
- Policy: `PENDING_PAYMENT → PAID_PENDING_ISSUANCE → ACTIVE → EXPIRING → EXPIRED`; servicing branches: `ENDORSEMENT_PENDING`, `CANCELLATION_PENDING`, `CANCELLED`, `SUSPENDED`.
- Claim: `DRAFT → SUBMITTED → ACKNOWLEDGED → EVIDENCE_PENDING|ASSESSMENT → CARRIER_REVIEW → APPROVED|PARTIALLY_APPROVED|DECLINED → PAID|CLOSED`; may enter `DISPUTED`.
- Delivery: `CREATED → READY_FOR_PICKUP → ASSIGNED → PICKED_UP → IN_TRANSIT → DELIVERED`; exceptions: `FAILED_ATTEMPT`, `RETURNING`, `RETURNED`, `CANCELLED`.
- Settlement: `DRAFT → RECONCILED → PENDING_APPROVAL → APPROVED → INSTRUCTED → PAID → ACKNOWLEDGED`; exceptions: `REJECTED`, `FAILED`, `REVERSED`.

## Explicit non-goals for initial release

- OpesInsure does not act as an insurer or make claims decisions.
- A database “escrow” balance does not establish a legally licensed escrow account.
- Tariffs are not hard-coded until approved carrier/CIMA schedules and effective dates are supplied.
- Instant agent withdrawals are not unconditional: earned/vested status, settlement risk, reversals, KYC and limits apply.

