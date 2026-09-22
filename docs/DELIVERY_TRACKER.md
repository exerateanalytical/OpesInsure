# Delivery Tracker

Status legend: `FOUNDATION`, `PLANNED`, `BLOCKED_EXTERNAL`, `IMPLEMENTED`, `VERIFIED`.

| Workstream | Status | Acceptance gate |
|---|---|---|
| Product/module register | VERIFIED | B2C, agent, broker, carrier and operations scope mapped |
| Domain architecture | VERIFIED | Boundaries, CQRS, events, tenancy and financial rules documented |
| Design system | VERIFIED | Color, typography, grid, icon and component rules documented |
| Containerized Laravel repository | FOUNDATION | Composer/Docker/bootstrap/config executable after dependency install |
| Identity/OAuth2/RBAC | IMPLEMENTED_BATCH_1 | Registration, profile, password rotation, Passport guard, tenant roles and permission middleware; MFA ceremony remains a production gate |
| Tenancy | IMPLEMENTED_BATCH_1 | Organization lifecycle API, membership resolution and tenant scoping; PostgreSQL RLS remains a production gate |
| Party/customer/attribution | IMPLEMENTED_BATCH_1 | Customer creation/search/detail, consent, duplicate protection, attribution and dispute workflow |
| Catalogue/rating/quote | IMPLEMENTED_BATCH_2 | Versioned products/tariffs, risk assets, deterministic rating runs, explainable offers, expiry and acceptance |
| Proposal/underwriting/policy | IMPLEMENTED_BATCH_3 | Paid issuance, lifecycle history and controlled servicing transactions added |
| Payments | IMPLEMENTED_BATCH_3 | Idempotent intent, HMAC/replay-checked webhooks, refunds and chargeback records |
| Ledger/commission | IMPLEMENTED_BATCH_3 | Balanced journals, reversal-only corrections, rule versions, accrual movements and balances |
| Reconciliation/settlement | IMPLEMENTED_BATCH_4 | Duplicate-safe imports, exact matching, exceptions, itemized carrier batches and maker-checker approval |
| Claims | IMPLEMENTED_BATCH_4 | FNOL, evidence links, guarded lifecycle, carrier review decisions and disputes |
| Documents/OCR | IMPLEMENTED_BATCH_4 | Hash/version foundation, scan/OCR review state, access audit and retention holds; engines adapter-gated |
| Certificate/sticker | IMPLEMENTED_BATCH_4 | Versioned templates, token verification, voiding, serialized stock and custody history |
| Logistics | IMPLEMENTED_BATCH_5 | Assignment, custody transitions, tracking, OTP/POD, failed attempts and returns |
| Notifications/support | IMPLEMENTED_BATCH_5 | Purpose preferences, delivery queue, SLA tickets, complaints and escalation |
| Certificate/sticker/logistics | PLANNED | Stock custody, QR, dispatch, tracking and POD |
| Notifications/support | PLANNED | Preferences, templates, receipts, tickets and complaints |
| Fraud/compliance/audit | IMPLEMENTED_BATCH_5 | Versioned risk rules, human alert decisions, time-bound privileged access, data requests and audit explorer |
| Reporting & analytics | IMPLEMENTED_BATCH_6 | Tenant portfolio, GWP, claims, estimated loss ratio and renewal reporting |
| Regulatory configuration | IMPLEMENTED_BATCH_6 | Versioned effective-dated reference sets, hashes, calendars and approvals |
| Integration platform | IMPLEMENTED_BATCH_6 | Scoped clients, one-time secrets, IP/rate controls and webhook subscriptions |
| Broker operations | IMPLEMENTED_BATCH_6 | Bordereaux, book-of-business measures and renewal workbench |
| Carrier operations | IMPLEMENTED_BATCH_6 | Bordereaux decisions, exchange foundation and delegated-authority controls |
| OpenAPI | FOUNDATION | Contract skeleton with standard errors/idempotency |
| Test suite | FOUNDATION | Unit, feature, architecture, isolation and financial properties |
| External payment adapters | BLOCKED_EXTERNAL | Credentials, webhook specification and sandbox access |
| Carrier issuance/rating adapters | BLOCKED_EXTERNAL | Signed product/tariff rules and sandbox contracts |
| Regulatory/legal validation | BLOCKED_EXTERNAL | Licensed counsel, MINFI/CIMA and banking operating model confirmation |
