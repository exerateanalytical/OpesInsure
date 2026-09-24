# OpesInsure Enterprise Screen Register v1.0 — owner specification (2026-09-24)

**Locked: 428 canonical screens (operations). Revised working total 690: + 20 document-administration screens DOC-ADM-001…020 (DOCUMENT_CATALOGUE_BY_POLICY_TYPE_V1.md, document_register_220_2026.json) + 98 finance, reinsurance, co-insurance and provider screens (FINANCE_REINSURANCE_PROVIDER_EXPANSION_V1.md) + 102 setup screens (SETUP_CONFIGURATION_FRAMEWORK_V1.md) + 22 CIMA screens (CIMA_REGULATORY_DICTIONARY_V1.md) + 20 master-data screens (INSTITUTIONAL_MASTER_DATA_CATALOGUE_V1.md).** Extends MASTER_SCREEN_REGISTER_V1.md (220: Customer 52, Agent 56, Broker 92, Shared 20) with 208 enterprise screens.

| Area | Prefix | Screens |
|---|---|---|
| Customer | CUST | 52 |
| Agent | AGT | 56 |
| Broker | BRK | 92 |
| Shared/Public | SHR | 20 |
| Carrier / Insurance Company | CAR | 34 |
| Underwriting | UND | 20 |
| Claims Professional / Adjuster | CLP | 18 |
| Finance & Accounting | FIN | 24 |
| Compliance & Risk | CMP | 20 |
| Branch Management | BRM | 16 |
| Platform Administration | ADM | 34 |
| API / Developer Platform | DEV | 14 |
| Security & Technical Operations | OPS | 20 |
| Regulatory & Executive Reporting | REG | 8 |
| **Total** | | **428** |

## E. Carrier (34)
CAR-001 Carrier Executive Dashboard · 002 Carrier Operations Dashboard · 003 Production Dashboard · 004 Portfolio Dashboard · 005 Claims Performance Dashboard · 006 Broker Production Dashboard · 007 Product Performance Dashboard · 008 Carrier Notifications · 009 Broker Directory · 010 Broker Details · 011 Broker Agreement Details · 012 Agent/Intermediary Overview · 013 Product Catalogue · 014 Product Details · 015 Product Version Management · 016 Coverage Configuration · 017 Exclusions & Conditions · 018 Product Eligibility Rules · 019 Tariff Catalogue · 020 Tariff Details · 021 Tariff Versioning · 022 Rating Rules · 023 Proposal Intake · 024 Proposal Details · 025 Policy Issuance Queue · 026 Policy Details · 027 Policy Amendment Review · 028 Renewal Portfolio · 029 Cancellation/Suspension Review · 030 Carrier Claims Queue · 031 Carrier Claim Details · 032 Broker Settlement Overview · 033 Carrier Documents Centre · 034 Carrier Reports

## F. Underwriting (20)
UND-001 Underwriting Dashboard · 002 Work Queue · 003 New Referrals · 004 Assigned Cases · 005 Case Details · 006 Applicant/Risk Profile · 007 Policy History · 008 Claims History · 009 Underwriting Questionnaire · 010 Supporting Documents · 011 Risk Assessment · 012 Risk Score Details · 013 Coverage Configuration · 014 Pricing & Premium Review · 015 Additional Information Request · 016 Inspection/Medical Requirement · 017 Conditional Acceptance · 018 Underwriting Decision · 019 Supervisor Approval · 020 Performance & SLA
Flow: Proposal Submitted → Automatic Rules → Referral → Queue → Risk Review → Evidence Review → Pricing → Information Request → Decision → Supervisor Approval → back to issuance.

## G. Claims Professional / Adjuster (18)
CLP-001 Adjuster Dashboard · 002 Assignment Queue · 003 Assigned Claims · 004 Claim Assignment Details · 005 Claim Overview · 006 Incident Details · 007 Policy & Coverage View · 008 Evidence Repository · 009 Inspection Scheduling · 010 Inspection Details · 011 Field Inspection Capture · 012 Damage Assessment · 013 Estimate / Valuation · 014 Assessment Report Builder · 015 Additional Evidence Request · 016 Submit Recommendation · 017 Completed Assignments · 018 Adjuster Performance
Rule: an adjuster's estimated loss is never automatically the settlement amount.

## H. Finance & Accounting (24)
FIN-001 Finance Executive Dashboard · 002 Cash & Collections · 003 Receivables · 004 Payables · 005 Reconciliation Dashboard · 006 Financial Exceptions · 007 Premium Receivables · 008 Premium Collection Details · 009 Customer Account Statement · 010 Broker Account Statement · 011 Carrier Account Statement · 012 Agent Account Statement · 013 General Ledger · 014 Journal Entries · 015 Journal Entry Details · 016 Manual Journal Approval · 017 Commission Ledger · 018 Refund Management · 019 Settlement Batches · 020 Settlement Batch Details · 021 Bank/Mobile Money Reconciliation · 022 Unmatched Transaction Workspace · 023 Financial Period Closing · 024 Financial Reports
Trace: Quote → Premium obligation → Payment → Ledger entry → Carrier liability → Broker commission → Agent commission → Settlement → Reconciliation. No unexplained balances.

## I. Compliance & Risk (20)
CMP-001 Compliance Dashboard · 002 Risk Dashboard · 003 Compliance Alerts · 004 KYC Compliance Queue · 005 KYC Case · 006 Corporate Due Diligence · 007 Expired Documentation · 008 Suspicious Activity Queue · 009 Suspicious Activity Case · 010 Fraud Alert Queue · 011 Claim Fraud Review · 012 Payment Risk Review · 013 Policy Exception Review · 014 Underwriting Override Review · 015 Commission Exception Review · 016 Compliance Investigation · 017 Compliance Findings · 018 Corrective Actions · 019 Compliance Reports · 020 Compliance Audit Trail
Rule: risk flags are explainable (reason text), never just a score.

## J. Branch Management (16)
BRM-001 Branch Manager Dashboard · 002 Branch Production · 003 Branch Customers · 004 Branch Agents · 005 Branch Staff · 006 Branch Quotes · 007 Branch Policies · 008 Branch Renewals · 009 Branch Claims · 010 Branch Collections · 011 Branch Commissions · 012 Branch Sticker Inventory · 013 Branch Tasks · 014 Branch Approvals · 015 Branch Compliance · 016 Branch Performance Reports
Rule: branch-scoped unless permission grants more.

## K. Platform Administration (34)
ADM-001 Platform Administrator Dashboard · 002 Tenant Directory · 003 Tenant Details · 004 Create Tenant · 005 Tenant Activation · 006 Tenant Suspension · 007 Tenant Branding · 008 Tenant Configuration · 009 Insurance Company Directory · 010 Insurer Details · 011 Broker Directory · 012 Broker Details · 013 Branch Directory · 014 Branch Details · 015 User Directory · 016 User Details · 017 Create User · 018 Roles · 019 Role Details · 020 Permission Matrix · 021 Organizational Structure · 022 Insurance Classes · 023 Master Product Categories · 024 Master Data Management · 025 Numbering & Sequence Configuration · 026 Document Template Management · 027 Notification Templates · 028 Workflow Configuration · 029 Approval Matrix · 030 Feature Flags · 031 Localization Management · 032 System Configuration · 033 System Audit Logs · 034 Administrative Reports

## L. API / Developer Platform (14)
DEV-001 Developer Portal Home · 002 API Documentation · 003 API Products · 004 API Credentials · 005 Create API Client · 006 OAuth Application Details · 007 Sandbox · 008 API Explorer · 009 Webhook Configuration · 010 Webhook Event Catalogue · 011 Webhook Delivery Logs · 012 API Request Logs · 013 API Usage & Rate Limits · 014 API Versions & Changelog
API products: Customers, Products, Quotes, Policies, Payments, Claims, Documents, Verification, Webhooks — per partner permissions.

## M. Security & Technical Operations (20)
OPS-001 System Health Dashboard · 002 Infrastructure Health · 003 API Health · 004 Database Health · 005 Redis/Cache Health · 006 Queue Dashboard · 007 Failed Jobs · 008 Job Details · 009 Integration Health · 010 Carrier API Monitoring · 011 Payment Provider Monitoring · 012 Webhook Monitoring · 013 Notification Delivery Health · 014 Security Dashboard · 015 Security Alerts · 016 Login Activity · 017 Active Sessions · 018 Backup & Recovery · 019 Deployment / Release History · 020 Incident Management
Correlation requirement: Customer → Payment → Provider transaction → Webhook → Queue job → Ledger posting → Policy issuance, in one place.

## N. Regulatory & Executive Reporting (8)
REG-001 Regulatory Reporting Dashboard · 002 Premium Production Reports · 003 Policy Portfolio Reports · 004 Claims Reports · 005 Intermediary Reports · 006 Commission Reports · 007 Compliance / Audit Reports · 008 Regulatory Report Generation & Export (formats configurable).

## Locked principles
- **Reusable shells:** 428 experiences, not 428 unrelated pages. Record shell = header (status, identity, actions) → KPI summary cards → tabs (overview, documents, communications, timeline, audit) → context panel (owner, SLA, tasks, alerts). Dashboard shell per DASHBOARDS_SPEC.md.
- **Canonical record ownership:** one Customer, Quote, Proposal, Payment, Policy, Endorsement, Claim, Document, Commission entry, Settlement — never per-role copies (no customer_policy / broker_policy / insurer_policy). Roles get views and permissions over the same record.
- **Cross-actor ownership matrix:** as given by the owner (Customer / Agent / Broker / Carrier / Specialist per domain).
- **Reference journey:** motor purchase and later claim across CUST → AGT → BRK → UND → FIN → CAR → CLP → SHR screens, as given by the owner.

## Next layer
Screen Specification Register: for each of the 428 IDs — route, actor, module, layout, fields, cards, tabs, buttons, filters, permissions, APIs, workflow states, documents, notifications, audit events, loading/empty/error states, desktop/mobile behaviour.
