# OpesInsure Master Screen Register v1.0 — owner specification (2026-09-24)

Locked scope: Customer + Agent + Broker + Shared = **220 canonical screens** (52 + 56 + 92 + 20). Excludes transient UI (dialogs, toasts, sheets, dropdowns, components, generic error pages). Not the full enterprise total: Carrier, Underwriter, Claims adjuster/expert, Finance, Branch manager, Compliance, Platform/tenant admin, API/developer portal, System operations, Security admin, Regulatory reporting and Master-data configuration surfaces are still to be enumerated.

## A. Customer (52)
Identity & onboarding: CUST-001 Splash · 002 Welcome · 003 Sign In · 004 Registration · 005 OTP Verification · 006 Forgot Password · 007 Reset Password · 008 Account Created
Profile & KYC: 009 Onboarding Overview · 010 Personal Information · 011 Contact & Address · 012 Identity Document · 013 ID Capture · 014 KYC Review · 015 KYC Status · 016 KYC Remediation
Home: 017 Customer Dashboard · 018 Insurance Portfolio · 019 Action Centre · 020 Notifications Centre
Marketplace: 021 Insurance Marketplace · 022 Product Search · 023 Product Details · 024 Product Comparison · 025 Needs Assessment
Quotation: 026 Start Quote · 027 Risk Details · 028 Coverage Selection · 029 Quote Calculation · 030 Quote Summary · 031 My Quotes · 032 Quote Details · 033 Quote Acceptance · 034 Insurance Proposal · 035 Proposal Documents
Payment: 036 Checkout · 037 Payment Method · 038 Payment Processing · 039 Payment Result · 040 Payments & Receipts
Policy: 041 My Policies · 042 Policy Details · 043 Policy Documents · 044 Request Policy Change · 045 Endorsement Status · 046 Renewal · 047 Renewal Payment · 048 Cancellation Request
Claims: 049 Claims Dashboard · 050 Make a Claim / FNOL · 051 Claim Evidence · 052 Claim Details & Timeline

## B. Agent (56)
Access: AGT-001 Agent Sign In · 002 Agent MFA · 003 Workspace Selection · 004 Agent Dashboard · 005 Action Centre · 006 Notifications
CRM: 007 Lead Pipeline · 008 Lead Details · 009 Create Lead · 010 My Customers · 011 Customer Search · 012 Create Customer · 013 Customer 360 · 014 Customer KYC · 015 KYC Document Capture · 016 Customer Activities
Products & quotes: 017 Product Catalogue · 018 Product Details · 019 Needs Assessment · 020 New Quote · 021 Quote Risk Details · 022 Coverage Configuration · 023 Quote Calculation · 024 Quote Comparison · 025 Quote Details · 026 Send Quote · 027 Quote Pipeline · 028 Lost Quote
Proposal & UW: 029 Proposal Builder · 030 Proposal Documents · 031 Proposal Review · 032 Underwriting Status · 033 Information Request · 034 Conditional Offer
Payments: 035 Initiate Payment · 036 Payment Status · 037 Payment Assistance · 038 Customer Payment History
Policies: 039 My Policy Portfolio · 040 Policy Details · 041 Policy Documents · 042 Endorsement Request · 043 Endorsement Tracking · 044 Renewal Queue · 045 Renewal Details · 046 Cancellation Assistance
Motor: 047 Vehicle Profile · 048 Vehicle Registration · 049 Sticker Assignment · 050 Sticker Handover
Claims: 051 Claims Portfolio · 052 Assisted FNOL · 053 Claim Details · 054 Claim Evidence Assistance
Earnings & work: 055 Commission Dashboard · 056 Tasks & Follow-Ups

## C. Broker (92)
Executive: BRK-001 Executive Dashboard · 002 Operations · 003 Sales & Production · 004 Customer · 005 Renewal · 006 Claims · 007 Finance · 008 Compliance & Risk
CRM: 009 Lead Directory · 010 Lead Details · 011 Lead Assignment · 012 Customer Directory · 013 Customer 360 · 014 Individual Customer Details · 015 Corporate Customer Details · 016 Customer Activity Timeline · 017 Duplicate Customer Review · 018 Customer Portfolio Transfer
KYC: 019 KYC Dashboard · 020 KYC Review Queue · 021 KYC Case Details · 022 KYC Remediation · 023 Expiring Documents · 024 Corporate Due Diligence
Products: 025 Product Catalogue · 026 Product Details · 027 Insurer Product Mapping · 028 Product Eligibility Rules · 029 Coverage & Guarantee Configuration · 030 Product Documents/Requirements
Quotes: 031 Quote Dashboard · 032 Quote Directory · 033 Quote Details · 034 Exceptional Quote Review · 035 Premium Override Approval · 036 Quote Conversion Analytics
Proposals & UW: 037 Proposal Queue · 038 Proposal Details · 039 Proposal Completeness Review · 040 Underwriting Queue · 041 Underwriting Case · 042 Information Request Management · 043 Conditional Offer Review · 044 Declined Proposal Review
Payments: 045 Payment Dashboard · 046 Payment Directory · 047 Payment Details · 048 Pending Payments · 049 Failed Payments · 050 Unmatched Payments · 051 Duplicate Payment Review · 052 Refund Queue · 053 Refund Details/Approval
Policy admin: 054 Policy Dashboard · 055 Policy Directory · 056 Policy Details · 057 Policy Issuance Queue · 058 Failed Issuance Queue · 059 Issuance Exception Details · 060 Endorsement Queue · 061 Endorsement Details · 062 Cancellation Queue · 063 Suspension/Reinstatement Queue
Renewals: 064 Renewal Pipeline · 065 Renewal Case · 066 Renewal Assignment · 067 Lapsed Policies · 068 Paid Renewal Issuance Exceptions
Documents & motor: 069 Document Centre · 070 Document Details · 071 Document Generation Queue · 072 Revoked/Replaced Documents · 073 Sticker Inventory · 074 Sticker Batch Details · 075 Sticker Allocation · 076 Sticker Reconciliation
Claims: 077 Claims Work Queue · 078 Claim Details · 079 Coverage Review · 080 Evidence Review · 081 Expert Assignment · 082 Assessment Review · 083 Claim Investigation · 084 Claim Decision · 085 Settlement Preparation · 086 Claim Payment Tracking · 087 Claim Appeal · 088 Claim Reopening
Finance: 089 Commission Dashboard · 090 Commission Statement / Adjustments · 091 Carrier Settlement Dashboard · 092 Settlement Batch / Reconciliation

## D. Shared (20)
SHR-001 Global Search · 002 Advanced Search · 003 Notification Details · 004 Document Viewer · 005 Document Verification Result · 006 Public Verification · 007 Payment Receipt · 008 Audit Timeline · 009 File Upload Manager · 010 Camera / Document Capture · 011 Support Centre · 012 Support Ticket Details · 013 Complaint Form · 014 Complaint Details · 015 Claim Settlement Acceptance · 016 Claim Appeal Submission · 017 Communication Centre · 018 User Profile & Settings · 019 Security & Sessions · 020 Language & Accessibility

## Screen chains (one record underneath each role view)
- Policy — Customer: CUST-030 → 033 → 034 → 036 → 037 → 038 → 039 → 041 → 042 → 043. Agent: AGT-025 → 029 → 032 → 035 → 036 → 039 → 040 → 041. Broker: BRK-033 → 038 → 040 → 041 → 046 → 057 → 056 → 069.
- Claim — Customer: CUST-049 → 050 → 051 → 052 → SHR-015 (or SHR-016 appeal). Agent: AGT-051 → 052 → 054 → 053. Broker: BRK-077 → 078 → 079 → 080 → 081 → 082 → 084 → 085 → 086 → close; branches BRK-083 investigation, BRK-087 appeal → revised decision.

## Mandatory specification per screen
Identity (ID, name, actor, module, platform, route, workflow IDs) · Navigation (entry points, previous, allowed next, deep link, role guards) · UI (title, breadcrumb, cards, sections, tabs, tables, filters, search, status badges, primary/secondary actions) · Data (fields, source entity, derived metrics, masking, currency/date formatting, EN/FR labels) · Forms (fields, types, required, validation, dependencies, conditional fields, attachments) · Actions (label, permission, command/API, confirmation, maker-checker, resulting state, audit event) · API (endpoint, method, request/response DTO, validation errors, authorization, idempotency) · States (loading, skeleton, empty, populated, validation failure, API failure, permission denied, stale, offline, sync, success) · Governance (RBAC, tenant boundary, sensitivity, audit, retention, integrity).

Reference full specs given by the owner: **CUST-042 Policy Details** (header, premium/coverage/validity/claims cards, tabs Overview/Coverage/Insured/Payments/Claims/Documents/Timeline, status-dependent actions, no impossible actions) and **BRK-078 Claim Details** (header, context actions Assign Officer…Close Claim, tabs Overview/Coverage/Evidence/Assessment/Investigation/Financials/Communications/Documents/Timeline/Audit, right-side operational panel with owner, SLA, age, next action, pending documents, approvals, tasks, alerts).

Module-to-actor coverage matrix: as given by the owner (Customer / Agent / Broker participation per module, from Identity to Audit).
