# Launch screen gaps — 2026-09-29 (agent P10, launch QA)

Launch of v1: Friday 2026-10-02. Sources: `docs/spec/MASTER_SCREEN_REGISTER_V1.md` (220), `docs/spec/ENTERPRISE_SCREEN_REGISTER_V1.md` (428 locked, 690 working total), `docs/spec/SCREEN_SPECIFICATION_REGISTER_V1.md` (routes/content), `docs/spec/MASTER_SCREEN_REGISTER_GAP.md` (per-screen status on 2026-09-24).
Method: every screen that the 2026-09-24 gap register marked **MISSING** was re-checked against the code of today (`php artisan route:list` GET routes of `/admin`, `/insurer`, `/broker`, `/provider`, `/account/*`; `app/Filament/**`; `app/Application/Providers/Workspace/Filament`; `resources/views/public/account/pages`). PARTIAL screens from the 2026-09-24 register are not repeated here (they have *a* screen). The 262 expansion screens of the 690 working total (DOC-ADM, finance/reinsurance/provider expansion, setup, CIMA, master data) have their own registers and dedicated admin areas (document engine, risk transfer, CIMA dictionary, master data, health) and are not re-audited here.

## Counts

| | Screens |
|---|---|
| Specified (locked enterprise register) | **428** |
| Implemented FULL or PARTIAL on 2026-09-24 | 207 (46 FULL + 161 PARTIAL) |
| MISSING on 2026-09-24 | 221 (84 staff/admin areas FIN, CMP, BRM, ADM, DEV, OPS, REG + 137 role/portal areas CUST, AGT, BRK, SHR, CAR, UND, CLP) |
| Staff/admin MISSING now implemented by other batches (existing admin screen) | 20 |
| Staff/admin MISSING now covered PARTIALLY by an existing admin screen | 25 |
| **Built by P10 (this file's batch)** | **8** closed (+ 4 PARTIAL upgraded: FIN-006, FIN-010, FIN-012, OPS-001) |
| Owned by portal agents P1–P9 (role/portal screens) | 137 (re-verify: those agents are building now) |
| Not buildable for launch (no API/service/role to back a read-only screen) | 31 |
| **Implemented after this batch** | 207 + 20 + 25 + 8 = **260 of 428 (60.7%)** + whatever P1–P9 close of the 137 |

## 1. Built by P10 — new admin screens (read-only, tenant-scoped, gated by the API route's permission, EN+FR in `launch_screens`)

| Screen ID(s) | Admin URL | Backed by | Permission (same as API route) |
|---|---|---|---|
| OPS-001 System health (upgrade), OPS-002 Infrastructure health, OPS-004 Database health | `/admin/operations/system-health` | `SystemHealthService::summary` (GET `operations/health`) | `operations.console.view` |
| OPS-018 Backup & recovery | `/admin/operations/backup-recovery` | `RecoveryExercise` BACKUP_RESTORE + DR targets (GET `operations/restore-verifications`) | `operations.console.view` |
| FIN-006 Financial exceptions (upgrade) | `/admin/finance/exception-centre` | `FinanceExceptionCentre::summary` (GET `finance/exception-centre`) | `finance.exceptions.view` |
| FIN-009 Customer, FIN-010 Broker, FIN-011 Carrier, FIN-012 Agent account statement | `/admin/finance/account-statements` | `AccountStatementService::build` (GET `finance/statements/{type}/{id}`) | `statements.read` |
| CMP-020 Compliance audit trail, ADM-033 System audit logs | `/admin/compliance/audit-trail` | `audit_log` (GET `compliance/audit-log`), tenant-scoped, STR tipping-off guard applied | `audit.read` |
| OPS-016 Login activity | `/admin/security/login-activity` | `login_activities` of tenant members (GET `security-centre/login-activity`) | `security.centre.read` |

Files: `app/Filament/Admin/Pages/Launch/*.php`, `resources/lang/{en,fr}/launch_screens.php`.

## 2. Staff/admin screens MISSING on 2026-09-24 that now exist (no action)

FIN-002 Cash & collections → `/admin/collections` · FIN-016 Manual journal approval → `/admin/finance/operations` (manual journal queues + actions) · FIN-022 Unmatched transactions → `/admin/reconciliation/exceptions` · FIN-024 Financial reports → `/admin/reports` (FinanceReportRegistry) · CMP-004 KYC queue, CMP-005 KYC case → `/admin/kyc-submissions` · CMP-017 Findings, CMP-018 Corrective actions → `/admin/compliance-cases` (ComplianceCaseService) · ADM-024 Master data → `/admin/master-data` · ADM-025 Numbering → `/admin/document-engine/numbering` · ADM-027 Notification templates → `/admin/operations/notification-templates` · ADM-029 Approval matrix → `/admin/approvals/matrix` · ADM-034 Administrative reports → `/admin/reports` · OPS-006 Queue dashboard, OPS-008 Job details → `/horizon` · OPS-007 Failed jobs → `/admin/operations/failed-jobs` · OPS-015 Security alerts → `/admin/security-findings` · OPS-019 Release history → `/admin/release-assurance` · OPS-020 Incident management → `/admin/governance-registers` (ICT incidents) · REG-003 Policy portfolio reports → `/admin/reports`.

## 3. Staff/admin screens now PARTIAL (a screen exists; not the full spec)

| ID | Existing screen | What is missing |
|---|---|---|
| FIN-005 Reconciliation dashboard | `/admin/reconciliations`, `/admin/reconciliation/exceptions` | KPI dashboard |
| CMP-002 Risk dashboard, CMP-011 Claim fraud review | `/admin/risk-alerts` | dashboard KPIs; fraud-specific review layout |
| CMP-012 Payment risk, CMP-013 Policy exception, CMP-014 UW override, CMP-015 Commission exception review | `/admin/approvals/inbox` | dedicated review queues per exception type |
| ADM-007 Tenant branding | `/admin/organisation-settings`, `/admin/document-engine/letterhead-designer` | logo/colour branding editor |
| ADM-018 Roles, ADM-019 Role details | `/admin/memberships` (Access & roles) | role catalogue viewer |
| ADM-021 Organisational structure | `/admin/branches` | tree view |
| ADM-023 Master product categories | `/admin/insurance-lines` | — |
| ADM-028 Workflow configuration | `/admin/case-types`, `/admin/work-queues` | workflow designer |
| ADM-030 Feature flags | `/admin/platform-settings` | per-flag screen |
| ADM-031 Localisation management | `/admin/document-engine/localization` | UI string management |
| OPS-010 Carrier API monitoring, OPS-011 Payment provider monitoring | `/admin/integration-health` | per-provider monitoring |
| OPS-014 Security dashboard | `/admin/security-findings` | dashboard KPIs |
| REG-001, 002, 004, 005, 006 Regulatory / premium / claims / intermediary / commission reports | `/admin/reports`, `/admin/regulatory-reports/regulatory-report-runs`, `/admin/cima-regulatory-dictionary` | per-report screens |
| DEV-005 Create API client, DEV-009 Webhook configuration | `/admin/integration-clients`, `/admin/risk-transfer/developer-keys` | partner self-service |

## 4. Not buildable for launch (reason)

| IDs | Reason |
|---|---|
| FIN-003 Receivables | no finance-wide receivables API (only broker-scoped `mobile/broker/receivables`) |
| FIN-023 Financial period closing | `AccountingPeriodService` has no HTTP route or GET permission (console `ledger:open-periods` only); period actions exist in LedgerOperationsActions |
| CMP-006 Corporate due diligence, CMP-007 Expired documentation | no API / service |
| ~~BRM-001..004, 006..016~~ | BUILT: BRM-001/004/005/010/012/013 `BranchOverviewPage` (Q10); BRM-002/003/006..009/011/014..016 `app/Filament/Shared/Pages/Branch/*` (S2, 2026-09-29) after branch_id was added to quotes/proposals/policies/claims/renewal_cases/commission_accruals (migration 2026_11_13_200001, stamped at creation + backfilled) |
| ADM-020 Permission matrix | permissions live in `config/permissions.php`; no API to read or change them |
| DEV-001, 002, 003, 007, 008, 010, 012, 013, 014 | partner-facing developer portal (`/developers/...`) is a separate surface with no backend (no request logs, usage, sandbox or changelog API) |
| OPS-003 API health, OPS-005 Redis/cache health | `SystemHealthService` has no API or cache check |

## 5. Owned by portal agents P1–P9 (MISSING on 2026-09-24; P10 did not build these)

Re-verify against the current portal work. The web account area now has `/account/{dashboard, policies, quotes, claims, payments, documents, vehicles, requests, profile, kyc, privacy, notifications, support, buy, customers, leads, commissions, claims-desk, book, staff, reports}`. Many of the IDs below may now be covered by those pages or by the insurer/broker panels.

- **Customer (7)**: CUST-008 Account Created · 009 Onboarding Overview · 015 KYC Status · 016 KYC Remediation · 019 Action Centre · 023 Product Details · 025 Needs Assessment.
- **Agent (33)**: AGT-005 Action Centre · 014 Customer KYC · 015 KYC Document Capture · 016 Customer Activities · 018 Product Details · 019 Needs Assessment · 022 Coverage Configuration · 024 Quote Comparison · 026 Send Quote · 028 Lost Quote · 029 Proposal Builder · 030 Proposal Documents · 031 Proposal Review · 032 Underwriting Status · 033 Information Request · 034 Conditional Offer · 037 Payment Assistance · 038 Customer Payment History · 040 Policy Details · 041 Policy Documents · 042 Endorsement Request · 043 Endorsement Tracking · 045 Renewal Details · 046 Cancellation Assistance · 047 Vehicle Profile · 048 Vehicle Registration · 049 Sticker Assignment · 050 Sticker Handover · 051 Claims Portfolio · 052 Assisted FNOL · 053 Claim Details · 054 Claim Evidence Assistance · 056 Tasks & Follow-Ups.
- **Broker (48)**: BRK-002 Operations Dashboard · 004 Customer Dashboard · 006 Claims Dashboard · 009 Lead Directory · 010 Lead Details · 011 Lead Assignment · 016 Customer Activity Timeline · 017 Duplicate Customer Review · 018 Customer Portfolio Transfer · 019 KYC Dashboard · 020 KYC Review Queue · 021 KYC Case Details · 022 KYC Remediation · 023 Expiring Documents · 024 Corporate Due Diligence · 028 Product Eligibility Rules · 031 Quote Dashboard · 034 Exceptional Quote Review · 035 Premium Override Approval · 036 Quote Conversion Analytics · 039 Proposal Completeness Review · 042 Information Request Management · 043 Conditional Offer Review · 044 Declined Proposal Review · 045 Payment Dashboard · 048 Pending Payments · 049 Failed Payments · 051 Duplicate Payment Review · 054 Policy Dashboard · 058 Failed Issuance Queue · 059 Issuance Exception Details · 062 Cancellation Queue · 063 Suspension/Reinstatement Queue · 066 Renewal Assignment · 067 Lapsed Policies · 068 Paid Renewal Issuance Exceptions · 071 Document Generation Queue · 072 Revoked/Replaced Documents · 075 Sticker Allocation · 076 Sticker Reconciliation · 079 Coverage Review · 080 Evidence Review · 081 Expert Assignment · 082 Assessment Review · 083 Claim Investigation · 085 Settlement Preparation · 087 Claim Appeal · 088 Claim Reopening.
- **Shared (6)**: SHR-001 Global Search · 002 Advanced Search · 008 Audit Timeline · 013 Complaint Form · 014 Complaint Details · 017 Communication Centre.
- **Carrier / insurer portal (14)**: CAR-002 Operations Dashboard · 003 Production Dashboard · 004 Portfolio Dashboard · 005 Claims Performance Dashboard · 006 Broker Production Dashboard · 007 Product Performance Dashboard · 011 Broker Agreement Details · 012 Agent/Intermediary Overview · 015 Product Version Management · 018 Product Eligibility Rules · 022 Rating Rules · 029 Cancellation/Suspension Review · 033 Documents Centre · 034 Reports.
- **Underwriting (insurer panel, 15)**: UND-001 Dashboard · 004 Assigned Cases · 006 Applicant/Risk Profile · 007 Policy History · 008 Claims History · 009 Questionnaire · 010 Supporting Documents · 011 Risk Assessment · 012 Risk Score Details · 013 Coverage Configuration · 014 Pricing & Premium Review · 015 Additional Information Request · 016 Inspection/Medical Requirement · 019 Supervisor Approval · 020 Performance & SLA. (Existing: `/insurer/underwriting-cases`, `/insurer/referrals`.)
- **Claims professional / adjuster (insurer panel, 14)**: CLP-001 Dashboard · 004 Assignment Details · 006 Incident Details · 007 Policy & Coverage View · 008 Evidence Repository · 009 Inspection Scheduling · 010 Inspection Details · 011 Field Inspection Capture · 012 Damage Assessment · 013 Estimate/Valuation · 014 Assessment Report Builder · 016 Submit Recommendation · 017 Completed Assignments · 018 Adjuster Performance. (Existing: `/insurer/claims`, `/admin/adjuster/assignments`.)

## 6. Launch crawl (tests/Feature/Web/LaunchReadinessCrawlTest.php)

Every role in `RoleCatalogue::LABELS` (except CUSTOMER, crawled on `/account`) signs in with its governed default permissions and opens `/admin`, `/insurer`, `/broker` and `/provider`. On each panel it can enter, it requests every sidebar link and the first row of each list in EN, then every link in FR.
Hard failures: 5xx, 403/404 behind a visible link, and raw translation keys. French leaks are reported below. A French leak is a label or H1 that stays identical in EN and FR when `fr.json` has no entry for it. French leaks fail the test only when `LAUNCH_STRICT_FR=1`.
`/account` is crawled as a guest and as CUSTOMER, AGENT, BROKER_ADMIN, BROKER_STAFF and CLAIMS_OFFICER, in EN and FR.

Results: see section 7 (final run).

## 7. Final crawl results (2026-09-29)

Run: `LAUNCH_CRAWL_DUMP=<dir> DB_DATABASE=opesinsure_test_p10 vendor/bin/pest tests/Feature/Web/LaunchReadinessCrawlTest.php`. There are 37 staff/provider roles plus the /account crawl.

**Hard failures (5xx, 403/404 behind a visible link, raw translation keys): none.**
- The first run showed `FR /provider → 302` for all 13 provider roles. This was a fault in the test: `/provider` only redirects to `/provider/dashboard`. The test was fixed and those roles re-ran green (PROVIDER_ADMIN: 19 links).
- Pages crawled, EN and FR:

| Panel | Roles and link counts |
|---|---|
| admin | PLATFORM_ADMIN 210, COMPLIANCE_ADMIN 179, SYSTEM_ADMIN 173, FINANCE_ADMIN 116, CLAIMS_MANAGER 115, FINANCE_MANAGER 112, CLAIMS_OFFICER 32 |
| insurer | CLAIMS_MANAGER 37, CARRIER_SUPER_ADMIN 32, CARRIER_ADMIN 26, CARRIER_STAFF 19, FINANCE_OFFICER 12, REINSURANCE_OFFICER 10, CLAIMS_OFFICER 9, SENIOR_UNDERWRITER 9, UNDERWRITER 8, ADJUSTER 4, CUSTOMER_SERVICE 4 |
| broker | BROKER_ADMIN 15, BROKER_STAFF 14, BROKER_SUPERVISOR 14, BRANCH_MANAGER 5 |
| provider | all provider roles |

- AGENT, CASHIER, DEVELOPER and REGULATOR have no panel. They are refused cleanly, and AGENT works on /account.
- /account passed for a guest and for CUSTOMER, AGENT, BROKER_ADMIN, BROKER_STAFF and CLAIMS_OFFICER, in EN and FR.

**Missing French labels.** These strings are still English for FR users and `fr.json` has no entry for them. They are reported here only; fr.json was not edited. Owning area in brackets.
- [Document engine, admin] These H1 headings are hard-coded English, and the ones that carry "(DOC-ADM-nnn)" show the screen ID in the title:
  - "Dashboard (DOC-ADM-001)"
  - "Document types (DOC-ADM-002/003)"
  - "Document families (DOC-ADM-004)"
  - "Product mapping (DOC-ADM-010)"
  - "Signature configuration (DOC-ADM-013)"
  - "QR configuration (DOC-ADM-014)"
  - "Localization (DOC-ADM-018)"
  - "Audit (DOC-ADM-019)"
  - "Quality & missing configuration (DOC-ADM-020)"
  - "Letterhead designer"
  - "Signing keys and numbering families"
  - nav "Signing keys & numbering"
- [Integrations, admin] Heading "Integration health": the nav label is translated but the page title is not.
- [Master data / CIMA / data, admin] Headings:
  - "Master data quality"
  - "Document requirement matrix"
  - "Data readiness"
  - "CIMA Regulatory Dictionary"
  - "Insurer CIMA setup"
  - "Broker CIMA setup"
- [Insurer portal] "Letterhead & logo" (nav and heading) on `/insurer/letterhead`.

Suggested fix: move these titles to `__()` keys, or add the strings to fr.json, and remove the screen IDs from the page titles.
