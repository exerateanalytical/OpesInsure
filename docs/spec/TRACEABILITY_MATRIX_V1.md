# OpesInsure Requirements Traceability Matrix v1.0

Generated 2026-09-24 against branch `master` at `dcb367e` plus the uncommitted working tree (other agents editing). Read-only audit: no code changed.

**Chain:** REQ → domain (table/class) → workflow (WF-###) → API (route) → screen (ID) → test. Machine-readable copy: `docs/spec/traceability_matrix_v1.json`.

## Legend
- **EXISTS**: implemented and wired (evidence given). **PARTIAL**: some implementation, gap stated. **WIP**: implemented only in uncommitted files (`git status` untracked/modified at audit time), owned by an agent currently working. **MISSING**: nothing in code. **DUPLICATE**: the same capability is implemented, or being implemented, more than once; the row names the single canonical choice.
- Waves W0–W27 follow IMPLEMENTATION_BLUEPRINT_V1 Part V.
- Source abbreviations: MPS MASTER_PLATFORM_SPECIFICATION_V1 · BP IMPLEMENTATION_BLUEPRINT_V1 · AOM ADAPTIVE_OPERATING_MODEL_V1 · PRE PRODUCT_RULE_ENGINE_SPEC_V1 · PREP PRODUCT_RULE_ENGINE_IMPLEMENTATION_PLAN · CIMA CIMA_REGULATORY_DICTIONARY_V1 · SEED INSTITUTIONAL_SEED_SPEC_V1 · MDC INSTITUTIONAL_MASTER_DATA_CATALOGUE_V1 · FRP FINANCE_REINSURANCE_PROVIDER_EXPANSION_V1 · DCP DOCUMENT_CATALOGUE_BY_POLICY_TYPE_V1 · DRM DOCUMENT_REQUIREMENT_MATRIX_V1 · ICG/ICE INSURANCE_CONTROL_ENGINES_GAPS_V1 / _SPEC_V1 · WRS WORKFLOW_REGISTER_SPEC (+WORKFLOW_REGISTER) · ESR ENTERPRISE_SCREEN_REGISTER_V1 · MSR MASTER_SCREEN_REGISTER_V1 · MSG MASTER_SCREEN_REGISTER_GAP · SSR SCREEN_SPECIFICATION_REGISTER_V1 · SCF SETUP_CONFIGURATION_FRAMEWORK_V1 · DSH docs/audit/DASHBOARDS_SPEC · AUD docs/audit/2026-09-24-checklist-audit · STA SCREEN_TO_API_MATRIX_V1 · LOCK-### MPS §116.
- Screen-level status is not repeated per screen ID: MASTER_SCREEN_REGISTER_GAP.md is the screen-level trace (428 IDs: 46 FULL / 161 PARTIAL / 221 MISSING). Requirements here reference screen families.

## 1. Summary

**277 requirements** (254 functional + 23 consolidation rows). EXISTS 18 · PARTIAL 132 · WIP 26 · MISSING 78 · DUPLICATE 23. 24 clusters of restated requirements across the owner specs were merged (section 3.1).

| Wave | Name | EXISTS | PARTIAL | WIP | MISSING | DUPLICATE | Total |
|---|---|---|---|---|---|---|---|
| W0 | Engineering foundation | 2 | 12 | 1 | 5 | 3 | 23 |
| W1 | Identity, tenancy, security | 4 | 15 | 0 | 3 | 1 | 23 |
| W2 | Master data & organization setup | 2 | 9 | 15 | 4 | 3 | 33 |
| W3 | Customer, corporate, KYC | 2 | 6 | 0 | 7 | 0 | 15 |
| W4 | Product & rule engine | 0 | 15 | 1 | 7 | 1 | 24 |
| W5 | Quote & proposal | 0 | 8 | 0 | 3 | 1 | 12 |
| W6 | Underwriting & authority | 0 | 4 | 0 | 4 | 1 | 9 |
| W7 | Policy administration | 0 | 10 | 1 | 3 | 2 | 16 |
| W8 | Document engine | 0 | 4 | 6 | 2 | 4 | 16 |
| W9 | Billing & payments | 2 | 6 | 0 | 8 | 0 | 16 |
| W10 | Commission & settlement | 1 | 4 | 0 | 0 | 2 | 7 |
| W11 | Accounting | 0 | 3 | 0 | 2 | 0 | 5 |
| W12 | Claims foundation | 0 | 7 | 0 | 4 | 1 | 12 |
| W13 | Claim decision & settlement | 0 | 3 | 0 | 0 | 0 | 3 |
| W14 | Recovery, salvage, litigation | 0 | 1 | 0 | 2 | 0 | 3 |
| W15 | Provider network | 0 | 1 | 1 | 2 | 0 | 4 |
| W16 | Health cashless | 0 | 0 | 0 | 4 | 0 | 4 |
| W17 | Reinsurance | 0 | 0 | 0 | 4 | 0 | 4 |
| W18 | Co-insurance | 0 | 0 | 0 | 1 | 0 | 1 |
| W19 | Compliance & AML | 1 | 2 | 0 | 3 | 1 | 7 |
| W20 | Complaints & case management | 1 | 1 | 0 | 2 | 1 | 5 |
| W21 | Fraud / anomaly | 0 | 1 | 0 | 1 | 0 | 2 |
| W22 | Catastrophe & accumulation | 0 | 0 | 0 | 3 | 0 | 3 |
| W23 | Regulatory & management reporting | 0 | 4 | 0 | 2 | 0 | 6 |
| W24 | Developer platform | 2 | 5 | 0 | 1 | 0 | 8 |
| W25 | Mobile / Expo | 0 | 6 | 1 | 0 | 1 | 8 |
| W26 | Migration & legacy data | 0 | 1 | 0 | 1 | 0 | 2 |
| W27 | Production hardening | 1 | 4 | 0 | 0 | 1 | 6 |
| **All** | | **18** | **132** | **26** | **78** | **23** | **277** |

Reading: the platform has a broad but shallow base through W0–W13 (mostly PARTIAL). W14–W22 (recovery, providers, health, reinsurance, co-insurance, AML, complaints, catastrophe) is almost entirely MISSING. Four large WIP streams (CIMA dictionary, master data, vehicle master, document engine/catalogue) are uncommitted, and two of them overlap (REQ-DUP-004).

### 1.1 Canonical model delta (blueprint Part I vs database/migrations)

65 blueprint table groups: EXISTS 15 · DIFFERENT NAME 22 · MISSING 15 · PARTIAL 7 · WIP 6. About 245 tables are declared in migrations today, including the uncommitted document-catalogue migration.

| Blueprint table(s) | Code table(s) | Verdict |
|---|---|---|
| tenants | tenants | EXISTS (type not constrained) |
| organizations | carriers, partners | DIFFERENT NAME |
| branches | tenant_branches | DIFFERENT NAME (no hierarchy) |
| departments | — | MISSING |
| users, memberships | users, tenant_memberships, membership_roles | EXISTS |
| roles, permissions, role_permissions | roles (permissions jsonb) | PARTIAL (permissions, role_permissions MISSING) |
| authority_profiles, authority_limits | delegated_authority_agreements | DIFFERENT NAME / PARTIAL |
| parties, persons | parties (+party_contacts) | EXISTS (persons folded into parties) |
| party_roles | tenant_customers, claim_involved_parties | PARTIAL |
| party_relationships, ownership_interests | — | MISSING |
| addresses, identity_documents | party_addresses, party_identifiers | DIFFERENT NAME |
| kyc_cases, screening_checks | kyc_submissions / — | DIFFERENT NAME / MISSING |
| master data tables (+vehicle tables) | master_data_* (9), vehicle_* (10) | WIP |
| vehicles, properties, business_risks | risk_assets, risk_asset_vehicles | DIFFERENT NAME (properties/business MISSING) |
| regulatory_regimes | regulatory_regimes | WIP |
| insurance_branches | regulatory_branches (+subclasses, microinsurance_branches) | WIP, DIFFERENT NAME (ADR) |
| insurer_authorizations (+branches) | insurer_authorizations; insurer_regulatory_authorizations, insurer_authorized_branches | EXISTS + WIP (DUP-017) |
| insurance_products | insurance_products | EXISTS (also acts as version) |
| product_versions | — | MISSING |
| product_regulatory_mappings | product_regulatory_mappings | WIP |
| product_plans | — | MISSING |
| coverages, product_coverages | coverage_definitions, product_coverages | DIFFERENT NAME |
| exclusions | exclusion_definitions, product_exclusions | DIFFERENT NAME |
| question_definitions, product_questions | disclosure_schema_versions (+ PHP schemas) | DIFFERENT NAME / PARTIAL |
| eligibility_rules | insurance_products.eligibility_rules jsonb | PARTIAL |
| tariff_versions, rating_rules | tariff_versions (rules jsonb), tax_levy_versions, fee_schedule_versions | EXISTS / PARTIAL |
| underwriting_rules | — | MISSING |
| leads | partner_leads | DIFFERENT NAME |
| quotes, quote_risks | quotes (risk_facts), quote_offers | EXISTS / quote_risks MISSING |
| pricing_snapshots | rating_runs, quote_offers.calculation_breakdown | DIFFERENT NAME / PARTIAL |
| proposals | proposals | EXISTS |
| underwriting_cases/referrals | underwriting_cases, underwriting_referral_tasks, underwriting_decisions | EXISTS |
| policies | policies | EXISTS |
| policy_versions | policies.version + policy_transactions | MISSING |
| policy_parties, policy_risks, policy_coverages, policy_limits | — | MISSING |
| endorsements, policy_cancellations | policy_transactions | DIFFERENT NAME |
| renewals | renewal_cases, renewal_work_items | DIFFERENT NAME |
| financial_obligations | — | MISSING |
| payments | payment_intents, payment_attempts, payment_events | DIFFERENT NAME |
| payment_allocations | — | MISSING |
| refunds | refunds | EXISTS |
| commission_entries | commission_accruals, commission_movements | DIFFERENT NAME |
| settlement_batches/lines | settlement_batches, settlement_items | DIFFERENT NAME (lines→items) |
| accounting_events | financial_posting_profiles, financial_distribution_events | PARTIAL |
| journals/lines | journals, journal_lines, ledger_accounts | EXISTS |
| claims | claims | EXISTS |
| claim_parties | claim_involved_parties | DIFFERENT NAME |
| claim_coverage_assessments | — | MISSING |
| claim_reserves/movements | claim_reserve_changes | DIFFERENT NAME |
| claim_assessments | — (claim_assignments only) | MISSING |
| claim_decisions | claim_decisions | EXISTS |
| recoveries | claim_recoveries | DIFFERENT NAME |
| provider profiles/facilities/networks/memberships/contracts | health_providers (WIP) | PARTIAL / MISSING |
| medical_services, provider_tariffs, health_eligibility_checks, preauthorizations, provider_claims | — | MISSING |
| reinsurer_profiles, reinsurance_treaties/participants/layers, risk_cessions, facultative placements/participants | — | MISSING |
| coinsurance arrangements/participants | — | MISSING |
| document_types (220) | document_types (WIP) + JSON register | WIP (DUP-004) |
| document_templates/versions | document_templates (WIP), certificate_templates | WIP (DUP-005) |
| documents | documents, document_versions | EXISTS |
| cases (8 types), tasks, sla_definitions | compliance_cases, support_tickets, underwriting_referral_tasks, renewal_work_items | PARTIAL / MISSING (DUP-022) |
| correspondences | communication_logs | DIFFERENT NAME / PARTIAL |
| complaints | — | MISSING |
| fraud_indicators | fraud_rule_versions, risk_alerts | DIFFERENT NAME / PARTIAL |
| regulatory_rules/impacts | regulatory_reference_sets | PARTIAL |
| audit_events (append-only) | audit_log (hash chain) | DIFFERENT NAME (DUP-016) |

Tables in code with no blueprint counterpart (keep, map in ADR): sticker_batches/stock/custody (motor stickers), fulfilment_orders/couriers (logistics), bordereaux, partner_statements/payouts, marketplace_publications, saved_comparisons, release_* , mobile_* , sync_operations, upload_sessions, telemetry_events, carrier_exchange_messages, external_record_mappings, business_calendars.

### 1.2 State machine delta (blueprint Part II, 21 machines)

Result: PARTIAL 14 · MISSING 6 · WIP 1. No machine meets the Part II standard (state, event, from, to, actor, guard, authority, side effect, audit, notification, failure path): only Policy, Claim (twice), Ticket and Fulfilment have classes, and Payment/Tenant/Customer use TRANSITIONS constants. Generic engine = REQ-WFL-001.

| Machine | Blueprint states (abridged) | Code | Status | Gap |
|---|---|---|---|---|
| Quote | draft…accepted/declined/expired/cancelled | quotes.status strings (SUBMITTED, REFERRED, OFFERED, ACCEPTED…); no class | PARTIAL | Formal machine + canonical states |
| Proposal | draft→submitted→reviewing→information_required→resubmitted→approved/declined | app/Domain/Underwriting/ProposalStatus (9) + ProposalService | PARTIAL | info_required/resubmitted/withdrawn |
| Underwriting (+referral) | referred→assigned→reviewing→decision_pending→approved/conditional/declined | underwriting_cases.status, underwriting_referral_tasks | PARTIAL | Authority + return to originator |
| Payment | created→initiated→pending→successful→reconciled; failed/expired/cancelled/reversed/refunded | PaymentStatus (10) + WebhookProcessingService::TRANSITIONS | PARTIAL | CANCELLED; reconciled as state |
| Policy | issuance_pending→issued→active→expired; amended/suspended/cancelled/renewed | PolicyStateMachine (10) | PARTIAL | Chronology; naming |
| Policy issuance failure | issuance_failed → retry/manual/refund | policy_issuance_requests status; PAID_PENDING_ISSUANCE | PARTIAL | Failure machine + queue |
| Endorsement | draft→submitted→review→approved(→additional_premium_required/refund_due) | policy_transactions.status | PARTIAL | Type rules, versions |
| Renewal | identified→assigned→contacted→quoted→accepted→payment_pending→renewed; lost/lapsed | renewal_cases.status, renewal_work_items | PARTIAL | Canonical states |
| Cancellation | requested→reviewing→approved→cancelled | policy_transactions + CANCELLATION_PENDING | PARTIAL | Approver queue |
| Claim | draft→…→closed (+info_required, investigating, partially_approved, rejected, appealed, reopened) | ClaimStateMachine (12) + ClaimLifecycle (duplicate) | PARTIAL | Unify (DUP-006) |
| Provider claim | DRAFT…DISPUTED | — | MISSING | W16 |
| Preauthorization | request→decision→guarantee→utilised/expired | — | MISSING | W16 |
| Commission | SALE→CALCULATED→ACCRUED→EARNED→APPROVED→PAYABLE→PAID (+reversal states) | commission_accruals (accrue/vest/clawback) | PARTIAL | Canonical states |
| Refund | candidate→calculated→review→approved→paid→reconciled | refunds + approve | PARTIAL | Full chain |
| Settlement | Draft→Calculated→Review→Approved→Processing→Settled→Reconciled | settlement_batches + carrier-settlements submit/approve/paid/fail/reverse | PARTIAL | Reconciled |
| Treaty | draft→active→expired/commuted (versions) | — | MISSING | W17 |
| Facultative placement | draft→offered→signed→bound | — | MISSING | W17 |
| Reinsurance recovery | ESTIMATED…CLOSED | — | MISSING | W17 |
| Complaint | Submitted→Acknowledged→Classified→Assigned→Investigated→Resolution→Communicated→Closed/Escalated | — (support TicketStateMachine is separate) | MISSING | W20 |
| KYC | not_started→draft→submitted→reviewing→approved; more_info/rejected/expired | kyc_submissions.status | PARTIAL | Machine + review |
| Document | DRAFT…VALID/SUPERSEDED/REPLACED/REVOKED/EXPIRED/CANCELLED | DocumentStatusService + document_status_changes (WIP) | WIP | Commit + maker-checker |

### 1.3 Authority / RBAC delta (blueprint Part III)

| Blueprint element | Code | Status |
|---|---|---|
| Permission naming module.resource.action | Dotted strings in roles.permissions jsonb; config/permissions.php documents ~16 sensitive ones | PARTIAL (REQ-RBAC-001) |
| permissions / role_permissions with conditions | — | MISSING |
| Scopes OWN…PORTFOLIO (9) | OWN (OwnershipScope, customers only) + TENANT (TenantContext) | PARTIAL 2/9 (REQ-RBAC-002) |
| Role profiles (15 in BP + SCF carrier roles + FRP provider roles) | 13 roles in RoleCatalogue; no UNDERWRITER, SENIOR_UNDERWRITER, ADJUSTER, BRANCH_MANAGER, REINSURANCE_OFFICER, PROVIDER_*, DEVELOPER | PARTIAL (REQ-RBAC-003) |
| Admin privilege ≠ business-data privilege | SYSTEM_ADMIN bypasses every check; demo accounts get * | MISSING (REQ-RBAC-004) |
| Authority check algorithm (8 steps) → referral | AuthorityChecker (DA agreement: line/territory/period/premium) with no enforcement caller | MISSING (REQ-AUTH-002) |
| authority_profiles/limits, 14 types, consumption | delegated_authority_agreements (max premium, max claim authority) | PARTIAL (REQ-AUTH-001) |
| Maker-checker / SoD | Per-feature on tariffs, products, rules, templates, reserves, privileged access, DA agreements | PARTIAL (REQ-RBAC-005) |
| Central approval matrix | — | MISSING (REQ-RBAC-006) |
| Step-up for sensitive actions | step_up_grants, step-up middleware (e.g. CLAIM_SETTLEMENT_DECISION) | EXISTS |
| Filament panel access | canAccessPanel lets BROKER_STAFF/AGENT in; CARRIER_* refused; resource policies decide | PARTIAL (REQ-UI-001) |

### 1.4 API delta (blueprint Part IV + SCREEN_TO_API_MATRIX families)

Cross-cutting: /api/v1 base EXISTS (448 api routes, 161 under mobile/*). Idempotency-Key PARTIAL (per-route middleware). Correlation-ID PARTIAL (stored, not propagated). If-Match/optimistic concurrency MISSING. Problem Details MISSING. OAuth 2.1 PARTIAL (Passport tables, no OIDC). Webhooks with retry/dead-letter/replay EXISTS. Existing routes are mapped with EXISTS_AS rather than duplicated (STA rule 1).

| Family | Spec source | Existing /api/v1 route(s) | Status | Gap |
|---|---|---|---|---|
| Auth / identity | BP §136; SSR CUST-003..007 | auth/mobile/*, public/accounts, me/* | EXISTS_AS auth/mobile/* | Web OAuth/OIDC flows |
| Tenants / branches / memberships | BP | tenants*, branches, memberships, invitations | EXISTS_AS | Departments, organizations |
| Customers / parties / KYC | WRS WF-003 (/customers/{id}/kyc, /kyc/{id}/submit/review) | customers*, parties*, mobile/kyc/*, documents/{d}/review | PARTIAL (EXISTS_AS mobile/kyc/*) | Staff KYC queue/review API; UBO; screenings |
| Leads | WRS WF-005..007 | mobile/partner/agent/leads* | PARTIAL | Broker lead directory/assignment |
| Master data | MDC | master-data/*, public/master-data/*, public/vehicles/* | EXISTS_AS (WIP) | Admin CRUD/merge/import API |
| Regulatory / CIMA | CIMA; BP | public/regulatory/cima/*, configuration/regulatory-reference-sets | PARTIAL (WIP) | Authorization/mapping admin API; regulatory-rules impact |
| Product admin | PRE §91 /carriers/{carrier}/products, /product-versions/{v}/… | catalogue/lines*, catalogue/products*(submit/publish), tariffs*, underwriting/disclosure-schemas, mobile/partner/carrier/products | PARTIAL (EXISTS_AS catalogue/*) | Versions, plans, coverages v2, questions, rules |
| Eligibility / rating runtime | PRE POST /insurance/eligibility/check, /insurance/rate | quotes, quotes/{q}/rate, mobile/quotes | PARTIAL (EXISTS_AS quotes/{q}/rate) | Eligibility check with outcomes |
| Quotes / comparisons | WRS WF-010 (/quotes, PATCH, /calculate, /generate, /accept); STA quote-comparisons | quotes, quotes/{q}/rate, quotes/{q}/offers/{o}/accept, web-experiences/marketplace/comparisons | PARTIAL (EXISTS_AS) | PATCH, generate/PDF, send |
| Proposals | WRS WF-016 | proposals*, disclosure*, documents*, submit, terms | EXISTS_AS proposals/* | info-request/resubmit |
| Underwriting / authority | BP; STA authority-profiles/referrals; PRE /underwriting/evaluate | underwriting/cases/{c}/assign/decision, underwriting/referrals/{r}/resolve, carrier/delegated-authorities* | PARTIAL | authority-profiles, referrals queue, evaluate |
| Policies / servicing | WRS; BP | policies*, transactions*, service-requests, renewal-quote, policy-issuance-requests*, renewals* | EXISTS_AS | Failed-issuance queue, cancellation/suspension queues |
| Documents | DCP; BP | documents*, document-templates*, documents/{d}/status-changes, policies/{p}/carrier-documents, public/document-types, mobile/policies/{p}/documents(/pack) | PARTIAL (WIP) | Intake, retention |
| Coverage check | BP POST /coverage/check | — | MISSING | Build |
| Payments / refunds / reconciliation | WRS WF-022..027; STA reconciliation, refunds | payments*, payments/{p}/refunds, refunds/{r}/approve, reconciliation/imports*, reconciliation/items/{i}/resolve, chargebacks | EXISTS_AS (partial) | Obligations, allocations, refund queue, unmatched list |
| Commission / settlement | WRS WF-064..069 | financial-distribution/*, partner-statements*, partner-payouts*, carrier-settlements*, bordereaux* | EXISTS_AS | Duplicated families (DUP-008/011) |
| Ledger / journals | STA journals/trial-balance | ledger/accounts, ledger/journals*, reverse | PARTIAL (EXISTS_AS ledger/journals) | trial-balance, period close, manual journal approval |
| Claims | WRS WF-048..062; STA set-reserve | claims/fnol, claims/{id}/(assign/decisions/evidence/disputes/payments/recoveries/reserves/transitions/carrier-messages), mobile/claims/* | EXISTS_AS | coverage, assessments, limits |
| Providers / networks / preauth | FRP IV; STA | — | MISSING | Build (W15–W16) |
| Reinsurance / co-insurance | FRP II–III; STA | — | MISSING | Build (W17–W18) |
| Screenings / UBO | ICE E5; STA | — | MISSING | Build (W19) |
| Compliance / fraud / privacy | ESR CMP | compliance/*, trust/*, risk-alerts*, fraud-rules, consents* | EXISTS_AS (duplicated DUP-009) | Case API |
| Complaints | WRS WF-071 | — | MISSING | Build (W20) |
| Dashboards | DSH; STA dashboards/* | mobile/{agent,broker,carrier}/dashboard, web-experiences/{portal}/dashboard | PARTIAL (EXISTS_AS) | Metric contract with drill-down |
| Reports | ESR REG | reports/insurance-portfolio, reports/renewals, trust/regulatory-report-* | PARTIAL | KPI catalogue |
| Operations / exceptions | STA operations/exceptions | integrations/health, integrations/delivery-attempts/{a}/replay, release-assurance/* | PARTIAL | Unified exceptions queue |
| Developer / webhooks | BP §156–157; STA developer/clients/webhooks | integrations/clients*, integrations/clients/{c}/webhooks, webhooks/* | EXISTS_AS integrations/* | Portal, OpenAPI, usage |
| Search | MPS §94 | — | MISSING | Build (W3) |

## 2. Requirements by wave

### W0 — Engineering foundation

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-ARC-001 | Layered structure Domain/Application/Infrastructure/Http; app/Models is not the architecture | BP XII §173; ICE 0.7 | PARTIAL | app/Domain has 19 files (state machines, rating, Money); logic lives in app/Application services + controllers | Domain layer thin; no Infrastructure adapters folder convention; migrate incrementally per bounded context | — | app/Domain/*, app/Application/*, app/Infrastructure/* |
| REQ-ARC-002 | Mutation pipeline Request→AuthN→AuthZ→Validate→Command→Guard→Tx→Persist→Event→Outbox→Audit→Response; never button→status update | MPS §7; WRS backend rule; SSR §24–33; BP IV | PARTIAL | AuditWriter + OutboxWriter used in services (e.g. WebhookProcessingService); ClaimCommandGuard; some Filament actions update models directly | No shared command bus/guard; Filament and several controllers mutate status directly; no arch test | REQ-WFL-001 | app/Application/Bus, app/Domain/Shared, Filament actions |
| REQ-ARC-003 | Transactional outbox + async dispatcher | MPS arch; WRS backend rule; ICE 0.5 | EXISTS | outbox_messages, inbox_messages; integration:dispatch-outbox scheduled every minute | — | — | app/Application/Events |
| REQ-ARC-004 | Canonical domain event catalogue (26 WRS events + 15 product events + engine events), versioned schemas | WRS required events; PRE §85–92; BP VII; ICE 0.5 | PARTIAL | canonical_event_schemas table; events emitted as dotted names (payment.status.changed, claim.*) | Names differ from WRS PascalCase list; no catalogue doc/registry test; OQ-0.1 open | REQ-ARC-003 | app/Application/Events, canonical_event_schemas seeder |
| REQ-ARC-005 | Keys: bigint internal + public UUID/ULID (ADR) ; never expose integer PKs | BP X §175; SEED rule 2 | PARTIAL | All tables use UUID PKs; canonical IDs CM-INS-*/CM-BRK-* on register | ADR-001 needed (keep UUID PKs). docs/adr/ is empty | — | docs/adr |
| REQ-ARC-006 | Money as integer minor units / DECIMAL, never float | BP X §175; PRE §26 | EXISTS | *_minor columns throughout; app/Domain/Shared/Money.php | — | — | — |
| REQ-ARC-007 | Effective dating, no deletion as business state, DB constraints | BP X; MPS §106–107; LOCK-007 | PARTIAL | effective_from/until on tariff/tax/fee/cancellation/commission versions; softDeletes on core tables | softDeletes used as business state on tenants/users; many rules as jsonb without DB checks | REQ-TMP-001 | migrations (additive) |
| REQ-ARC-008 | Build control: ADR-###, CR-YYYY-NNNN, traceability REQ→domain→workflow→API→screen→test | BP XIII; MPS status; STA rule 4 | WIP | CHANGE_REQUEST_LOG.md (untracked); this matrix | No ADR files; no test-to-REQ tagging | — | docs/adr, docs/spec, tests (@covers REQ tags) |
| REQ-AUD-001 | Append-only audit_events with hash chain for high-risk events | BP I (audit_events); MPS §98; ICE 0.5, gap 21 | PARTIAL | audit_log (sequence, previous_hash, entry_hash, correlation_id) + AuditWriter | Name differs (ADR audit_log vs audit_events); no DB trigger blocking UPDATE/DELETE; no AuditChainVerifier | REQ-ARC-008 | app/Application/Audit, migration trigger |
| REQ-AUD-002 | Audit fields actor, tenant, branch, entity, action, old/new, reason, source, IP/device, approval | SCF §86; DSH #20; AOM override; FRP VI monetary audit | PARTIAL | audit_log payload jsonb; privileged_access_events; security_events | Old/new + reason + device not systematically captured; no branch | REQ-AUD-001 | AuditWriter callers |
| REQ-TMP-001 | Temporal / reference-date engine: Clock, VersionResolver, reference_date_rules, transaction_resolved_versions | ICE E1 (gap 45); MPS §23; LOCK-007/017 | MISSING | Only CancellationCalculator resolves cancellation_rule_versions by date; now() used everywhere | Build E1.1–E1.2; arch test banning now() in Domain/Application | REQ-ENG-001 | app/Domain/Shared/Clock, app/Application/Temporal (new) |
| REQ-TMP-002 | Bitemporal replay (recorded_at/superseded_at) on tariffs, product versions, authorizations | ICE §0.3, E1.3 | MISSING | — | Additive columns + replay test | REQ-TMP-001 | migrations on tariff_versions, insurance_products, insurer_authorizations |
| REQ-ENG-001 | EngineResult envelope: engine_evaluations (append-only) + engine_overrides (maker-checker CHECK) | ICE E0 §0.2–0.4 | MISSING | — | Tables, envelope DTO, append-only trigger helper | REQ-AUD-001 | app/Domain/Shared/EngineResult, migrations |
| REQ-OVR-001 | Controlled human override (previous/new value, reason, user, authority, timestamp, approval above threshold) | AOM override; PREP A6; ICE 0.4; SSR BRK-035 | MISSING | Ad-hoc approve endpoints only | value_overrides/engine_overrides + OverrideService; no silent edits (arch test) | REQ-ENG-001, REQ-AUTH-001 | app/Application/Overrides (new) |
| REQ-WFL-001 | Generic workflow/state engine with Part II columns (state, event, from, to, actor, guard, authority, side effect, audit, notification, failure path) | BP II; MPS §103 Workflow/State; SSR §24 | PARTIAL | 5 state classes (Policy, Claim x2, Ticket, Fulfilment) + TRANSITIONS consts in Payment/Tenant/Customer services | No shared definition/table; 21 machines inconsistent (see state machine delta) | REQ-ARC-002 | app/Domain/Shared/StateMachine (new), per-domain definitions |
| REQ-CAS-001 | Case engine core: cases (8 types), tasks, sla_definitions, diary, queues, SlaService, "My Work" | BP I; ICE E6.1 (gaps 24–26, 43); MPS §60–86 | MISSING | Fragments: underwriting_referral_tasks, renewal_work_items, compliance_cases, support_tickets | Unified case/task/SLA tables; bridges later (REQ-CAS-002) | REQ-WFL-001, REQ-CAL-001 | app/Application/Cases (new), migrations |
| REQ-CAL-001 | Business calendar (weekends, holidays, branch hours) consumed by SLA | ICE E6; SCF §83 | PARTIAL | business_calendars table (no consumer) | Consumer in SlaService; holiday data source OQ | REQ-CAS-001 | app/Application/Cases/Sla |
| REQ-IDM-001 | Idempotency on all mutations and financial/integration actions | BP IV; MPS §99; FRP VI; AOM strict | PARTIAL | idempotency_keys; idempotency middleware on 16 route files (e.g. mobile.claims.fnol) | Not on all mutations; mobile retry regenerated keys (AUD A10) — verify fixed in app 1.2.2 | — | app/Http/Middleware, mobile app/src/api/client.ts |
| REQ-TST-001 | Test matrix: unit, domain, API, financial, claims, reinsurance, document, security, tenant-leakage, RBAC | BP VI; MPS §115; SSR §34 | PARTIAL | 128 feature test files (tests/Feature/Wave*, Security, Regulatory…) | No tenant-leakage suite per route; mobile tests are regex checks; no REQ tagging | REQ-ARC-008 | tests/ |
| REQ-NFR-001 | Observability: correlation ID end-to-end (customer→payment→webhook→job→ledger→issuance) | BP XI; ESR OPS rule | PARTIAL | correlation_id on audit_log, journals, engine spec | No request middleware propagating Correlation-ID to jobs/webhooks; no trace view | REQ-API-002 | app/Http/Middleware, jobs |
| REQ-DUP-016 | Audit store vs blueprint name | Coordinator scope (zero duplication); code inspection | DUPLICATE | audit_log (hash chain) vs blueprint audit_events | Canonical: Keep audit_log physically (ADR), expose audit_events view; no second table | REQ-AUD-001 | docs/adr, migrations |
| REQ-DUP-019 | Tables declared in two migrations | Coordinator scope (zero duplication); code inspection | DUPLICATE | support_tickets, notification_templates, notification_deliveries, couriers, communication_preferences (+ support_ticket_events, fulfilment_events, fulfilment_orders) created in batch_five and again in wave_eight/core behind hasTable guards | Canonical: Leave history; document single owner migration; add schema test | — | database/migrations (no edits to applied migrations) |
| REQ-DUP-022 | Fragmented work items | Coordinator scope (zero duplication); code inspection | DUPLICATE | underwriting_referral_tasks, renewal_work_items, compliance_cases, support_tickets, financial cases | Canonical: cases + tasks (REQ-CAS-001) with bridges; keep tables as projections during migration | REQ-CAS-001 | app/Application/Cases |

### W1 — Identity, tenancy, security

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-TEN-001 | Tenants typed PLATFORM, INSURER, BROKER, REINSURER, PROVIDER_NETWORK, CORPORATE | BP I | PARTIAL | tenants.type varchar(24) | Enum not constrained; no REINSURER/PROVIDER_NETWORK/CORPORATE usage | — | tenants migration, TenantLifecycleService |
| REQ-TEN-002 | Organizations, branches (hierarchy with capabilities), departments (managers, limits, queues) | BP I; SCF §5–6, §31–32; ESR BRM | PARTIAL | carriers, partners (= organizations), tenant_branches; branches API GET/POST | No organizations table, no branch hierarchy/capabilities, no departments | REQ-TEN-001 | app/Application/Tenancy, migrations |
| REQ-TEN-003 | Strict tenant isolation + row ownership; tenant-leakage tests | AOM strict; MPS §107; AUD A1 | PARTIAL | TenantContext; OwnershipScope (customer owner scoping, dcb367e); tests/Feature/Security | Only OWN/TENANT; broker/branch book scoping absent; mobile/* coverage to verify | REQ-RBAC-002 | app/Application/Identity/OwnershipScope |
| REQ-TEN-004 | Tenant lifecycle PENDING→ACTIVE→SUSPENDED→CLOSED | ESR ADM-004..006 | EXISTS | TenantLifecycleService TRANSITIONS; tenants/{t}/status route; tenant_status_history | — | — | — |
| REQ-IAM-001 | Users + memberships (active membership check), invitations | BP I; ESR ADM-015..017 | EXISTS | users, tenant_memberships, membership_roles, tenant_invitations; invitations routes; memberships/{m}/revoke | — | — | — |
| REQ-IAM-002 | Registration + phone/email verification (WF-001/002) | WRS WF-001/002; MSR CUST-001..008 | EXISTS | auth/mobile/otp/*, public/accounts, me/phone/verification, email/verify; otp_deliveries (ETECH/Twilio) | OTP countdown/resend UX gaps (AUD §3) | — | MobileAuthController |
| REQ-IAM-003 | Password, MFA, step-up, device sessions, sign-out everywhere | MSR SHR-019; SSR CUST-003 | EXISTS | mfa_methods, step_up_grants, user_devices, auth/mobile/logout-all, me/devices | — | — | — |
| REQ-IAM-004 | OAuth 2.1 / OIDC with scopes for API clients | BP IV | PARTIAL | Passport oauth_* tables; integration_clients lifecycle | No OIDC discovery; scopes not mapped to permissions | REQ-RBAC-001 | config/auth, integration clients |
| REQ-RBAC-001 | Permission naming module.resource.action; roles/permissions/role_permissions with conditions | BP III; SSR ADM-020 | PARTIAL | roles.permissions jsonb; config/permissions.php catalogue; middleware permission:* | No permissions/role_permissions tables; no conditions; naming mixed (carrier.claims.read ok) | — | app/Application/Identity, migrations |
| REQ-RBAC-002 | Scopes OWN, TEAM, BRANCH, REGION, ORGANIZATION, TENANT, ASSIGNED, PRODUCT, PORTFOLIO | BP III; SCF RBAC scopes; ESR BRM rule | PARTIAL | OWN (OwnershipScope) + TENANT (TenantContext) | 7 scopes missing; nothing branch-scoped (MSG) | REQ-RBAC-001, REQ-TEN-002 | app/Application/Identity/Scopes (new) |
| REQ-RBAC-003 | Role profiles incl. UNDERWRITER, SENIOR_UW, ADJUSTER/EXPERT, BRANCH_MANAGER, REINSURANCE_OFFICER, PROVIDER_* roles, DEVELOPER, CARRIER_SUPER_ADMIN…CUSTOMER_SERVICE | BP III; SCF §7; FRP IV–V; MSG | PARTIAL | RoleCatalogue: 13 roles (AGENT, BROKER_ADMIN/STAFF, CARRIER_ADMIN/STAFF, CLAIMS_MANAGER/OFFICER, COMPLIANCE_ADMIN, CUSTOMER, FINANCE_ADMIN/MANAGER, PLATFORM_ADMIN, SYSTEM_ADMIN) | Missing specialist roles block UND/CLP/BRM/DEV/PRV screens | REQ-RBAC-001 | app/Application/Identity/RoleCatalogue |
| REQ-RBAC-004 | Admin privilege ≠ business-data privilege | BP III | MISSING | SYSTEM_ADMIN bypasses every check (User::hasPermission); demo accounts get * | Remove bypass for business data; break-glass via privileged_access_grants | REQ-RBAC-001 | app/Models/User, DatabaseSeeder |
| REQ-RBAC-005 | Segregation of duties & maker-checker (maker ≠ checker) on the WF-081 list + FRP VI list | WRS WF-081; FRP VI; AOM strict; MPS §97 | PARTIAL | Maker-checker on tariffs, products, cancellation/commission rules, templates, reserves, privileged access, DA agreements | Not generic; missing on manual payment confirmation, document revocation, permission change, write-off | REQ-RBAC-006 | app/Application/Approvals (new) |
| REQ-RBAC-006 | Central approval matrix (workflow, action, amount, role, branch, product, insurer) | SCF §58; ESR ADM-029 | MISSING | — | Table + resolver used by REQ-AUTH-002 | REQ-RBAC-001 | app/Application/Approvals |
| REQ-SEC-001 | Security events, privileged access, security findings, login activity | ESR OPS-014..017 | PARTIAL | security_events, privileged_access_grants/events, security_findings; trust/privileged-access routes | No OPS screens | — | Filament OPS pages |
| REQ-SEC-002 | Demo/production separation: seed-demo refused in production, staging env, prod demo mode off, env banner | SEED §9, §46; AUD A2/A3; OQ Q4 | PARTIAL | Regulatory/master seeders production-safe; demo:seed; config/demo.php | Production runs demo mode; single EAS target; no refusal guard verified | — | app/Console/Commands, config/demo.php, eas.json |
| REQ-SEC-003 | Consent & purpose-of-use registry; data-subject requests | ICG 19; SCF privacy | PARTIAL | consents, consent_events, data_subject_requests(+events); consents routes | No purpose-of-use enforcement at data access; API consent (gap 41) missing | — | app/Application/Privacy |
| REQ-SEC-004 | Feature flags scoped by environment/country/tenant/branch/product | SCF §82 | PARTIAL | platform_settings, public/capabilities | No scoping dimensions | REQ-TEN-002 | app/Application/Settings |
| REQ-SEC-005 | Mobile hardening (MASVS: root detection, pinning, attestation, secure storage) | AUD §19 | PARTIAL | Secure storage ok; attestation fake | MASVS 0/9 closed | — | mobile app/src/security |
| REQ-NOT-001 | Notification engine: approved templates, SMS/email/push deliveries, attempts, workflow notifications (WF-073), delivery health | WRS WF-073; SCF §78–81; ESR OPS-013 | PARTIAL | notification_templates(+approve), notification_deliveries/attempts, user_push_tokens, LifecycleNotificationProducer, notifications:dispatch-pending | Few events notify; push sender limited (AUD A14); no catalogue | REQ-ARC-004 | app/Application/Notifications |
| REQ-UI-001 | Web experience shells for 14 experiences (/agent, /broker, /carrier, /underwriting, /adjuster, /finance, /compliance, /branch, /admin, /developers…) | SSR §3, §37; ESR | MISSING | /portal/{portal} is one placeholder template (wave10); only Filament /admin works | Decide surface per experience (Filament panels vs SPA); build shell + RBAC nav | REQ-RBAC-002 | resources/views, app/Providers/Filament (new panels) |
| REQ-UI-002 | Reusable record shell + dashboard shell; per-screen DoD (states, EN/FR, audit, RBAC tests) | ESR locked principles; SSR §2, §34; DSH framework | PARTIAL | mobile app/src/components; no backend record-summary/metric contract | Shared RecordSummary + Metric API contracts | REQ-UI-001 | app/Application/WebExperiences, mobile components |
| REQ-DUP-003 | Communication preference routes registered twice | Coordinator scope (zero duplication); code inspection | DUPLICATE | NotificationController@preference on PUT communication-preferences and PUT communications/preferences | Canonical: Keep `communication-preferences` | — | routes/* |

### W2 — Master data & organization setup

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-MDM-001 | Master data engine: Category→Subcategory→Value→Attributes→Aliases→Source→Status→Effective, EN/FR, canonical codes | MDC; MPS §14–15; LOCK-003 | WIP | master_data_domains/lists/values/aliases/changes (2026_09_29_100001, untracked); MasterDataCatalogue | Commit + tests | — | app/Application/MasterData, app/Models/MasterData |
| REQ-MDM-002 | 38 domains seeded in owner seed order | MDC seed order | WIP | database/data/master_data/{core,reference,specialty}_2026.json (73 lists); opesinsure:seed-master-data | Verify coverage vs 38 domains; FRP Part VIII finance/reinsurance/provider lists | REQ-MDM-001 | database/data/master_data |
| REQ-MDM-003 | "Other / Not listed" fallback never blocks; review queue SUBMITTED…ARCHIVED with duplicates and frequency | MDC fallback; MPS §15 | WIP | master_data_review_queue; POST master-data/suggestions; MasterDataReviewService | Review screen (MDM-008/009) missing | REQ-MDM-001 | app/Application/MasterData |
| REQ-MDM-004 | Provenance (source_type, reference, verified_at/by), INACTIVE never delete | MDC provenance; SEED rule 1 | WIP | master_data_values provenance columns (untracked) | — | REQ-MDM-001 | — |
| REQ-MDM-005 | Search: aliases, EN/FR, accent-insensitive, typo-tolerant, priority, cascading selects | MDC search | WIP | MasterDataSearch; GET master-data/{domain}/search | Typo tolerance to verify | REQ-MDM-001 | MasterDataSearch |
| REQ-MDM-006 | Tenant overrides (hide/alias/private/map, never change meaning); carrier & broker mappings | MDC ownership | WIP | master_data_tenant_overrides, carrier_/broker_master_data_mappings | No API/screens (MDM-015/016) | REQ-MDM-001 | app/Application/MasterData |
| REQ-MDM-007 | Duplicate detection, maker-checker merge, import/export CSV/XLSX/JSON, version history | MDC operations | PARTIAL | master_data_imports table; master_data_changes | No XLSX parser, merge or export | REQ-IMP-001 | app/Application/MasterData/Import |
| REQ-MDM-008 | Expo offline cache with catalog_version sync | MDC; SSR offline | WIP | GET master-data/versions, MasterDataCache; mobile master-data route | App-side cache to verify | REQ-MDM-001 | mobile app/src/masterData |
| REQ-MDM-009 | MDM screens MDM-001…020 incl. data quality dashboard | MDC screens | MISSING | Only vehicle master Filament resources | 20 staff screens (Filament) | REQ-MDM-001..007 | app/Filament/Admin/Resources/MasterData* (new) |
| REQ-VEH-001 | Vehicle master: makes, models, generations, variants, aliases, reference values, review queue; cascading make→model | MPS §16; MDC vehicles; STA rule 2 | WIP | vehicle_* tables (2026_09_28_100001), 5 Filament resources, public/vehicles/*, mobile/vehicles/master-review | Generations/variants optional per STA rule 2 | REQ-MDM-001 | app/Application/Vehicles |
| REQ-CIMA-001 | Regulatory dictionary: regimes, Art.328 branches + subclasses, Art.717 micro-branches, Art.411 categories, terms EN/FR, authorities, legal references, compulsory insurance | CIMA; MPS §21; PRE §4 | WIP | 14 tables (2026_09_27_100001), cima_regulatory_master_2026.json, opesinsure:seed-cima, public/regulatory/* | Commit; naming ADR regulatory_branches vs insurance_branches | — | app/Application/Regulatory, app/Models/Regulatory |
| REQ-CIMA-002 | Insurer authorization → authorized branches; publication gate AUTHORIZED / NOT_AUTHORIZED / EXPIRED / SUSPENDED / MAPPING_REQUIRED | CIMA; PRE §4–5; ICE E3; LOCK-001 | WIP | insurer_regulatory_authorizations, insurer_authorized_branches, CimaAuthorizationService, CimaPublicationGuard | No insurer has authorized branches recorded (OQ Q2) → new versions blocked | REQ-CIMA-001 | app/Application/Regulatory |
| REQ-CIMA-003 | Product regulatory mappings PRIMARY/ACCESSORY/COMPLEMENTARY, effective-dated, Art.328-1 accessory rule | CIMA; PRE §4 | WIP | product_regulatory_mappings, CimaProductMappingService, CimaProductMappings resource | Open mappings OQ Q1 | REQ-CIMA-001, REQ-PRD-001 | app/Application/Regulatory |
| REQ-CIMA-004 | RegulatoryTerminologyService (regime, locale, term)→label; CIMA invisible to customers | CIMA; AOM CIMA visibility | WIP | RegulatoryTerminologyService, regulatory_term_translations | Wire into customer-facing labels | REQ-CIMA-001 | — |
| REQ-CIMA-005 | CIMA screens PLT-CIMA-001…012, INS-SET-CIMA-001…006, BRK-SET-CIMA-001…004 | CIMA screens | WIP | CimaRegulatoryDashboard + 10 Cima* Filament resources (PLT) | INS-SET-CIMA and BRK-SET-CIMA screens missing | REQ-CIMA-002, REQ-SET-002 | app/Filament/Admin |
| REQ-CIMA-006 | CIMA-ready 28-item checklist; Art.557 intermediary measures | CIMA checklist | WIP | CimaComplianceReport (untracked) | Checklist evaluation + report | REQ-CIMA-001..003 | app/Application/Regulatory |
| REQ-SEED-001 | Provenance on seeded records: data_origin REGULATORY / CARRIER_PUBLISHED / PLATFORM_NORMALIZED / DEMO_SYNTHETIC, source_authority, reference_year, is_demo | SEED 1; MPS §89–90 | PARTIAL | data_origin on carriers/partners/insurance_classes (2026_09_26); is_demo in 10 files | Not on transactional demo data consistently | — | seeders, migrations |
| REQ-SEED-002 | Official register: 29 insurers (CM-INS-IARD/LIFE-*), 123 brokers (CM-BRK-2026-*), idempotent opesinsure:seed-regulatory | SEED 2, 9; MPS §108 | EXISTS | SeedRegulatoryRegister, cameroon_insurance_register_2026.json, public/institutions | Broker enrichment (123) open | — | — |
| REQ-SEED-003 | Effective-dated insurer/intermediary authorizations and name/brand history, never overwrite a year | SEED 6 | PARTIAL | insurer_authorizations, intermediary_authorizations, partner_licences | No name/brand history table | REQ-SEED-002 | migrations |
| REQ-SEED-004 | carrier_broker_agreements + product permissions (can_quote/can_bind/can_collect_premium/requires_carrier_approval) + per-agreement commission | SEED 7; SCF §23–25; PRE §57–62 | PARTIAL | delegated_authority_agreements (permitted_lines, max premium, territories); commission_rule_versions per carrier/product/partner | No agreement-product permission table | REQ-SEED-002 | app/Application/CarrierOperations |
| REQ-SEED-005 | Demo layer: DEMO brokerage + 5 branches, DEMO tariffs, .invalid identities, watermark, DEMO_VALID QR, banner, one record per workflow state, end-to-end chain | SEED 8, 10 | PARTIAL | demo:seed, DatabaseSeeder demo accounts, DemoPurchaseSettler | Coverage per state and CUS-DEMO-0001 chain not verified; watermark only in WIP engine-document view | REQ-SEC-002 | app/Application/Demo, seeders |
| REQ-SEED-006 | Unknown institutional facts stay NULL/UNVERIFIED/PENDING_VERIFICATION | MPS §108; SEED 3 | EXISTS | Seeders leave null; OWNER_OPEN_QUESTIONS lists unknowns | — | — | — |
| REQ-SET-001 | Platform setup: country CM/XAF/Africa-Douala, geography, languages, currency; PLT-SET-001…034 + wizard | SCF §51–86 | PARTIAL | platform_settings + PlatformSettingsPage | Most PLT-SET screens and wizard missing | REQ-MDM-002 | app/Filament/Admin/Pages |
| REQ-SET-002 | Insurer setup lifecycle DRAFT→REGULATORY_REVIEW→CONFIGURATION→TESTING→READY_FOR_APPROVAL→ACTIVE (+SUSPENDED/INACTIVE/TERMINATED), 23-item activation gate, INS-SET-001…038 | SCF lifecycles; MPS §109–113 | MISSING | carriers.status only | Lifecycle machine, checklist evaluator, screens | REQ-CIMA-002, REQ-AOM-001 | app/Application/CarrierOperations/Setup (new) |
| REQ-SET-003 | Broker setup lifecycle DRAFT→REVIEW→CONFIGURATION→TESTING→ACTIVE, 19-item gate, BRK-SET-001…030 | SCF | PARTIAL | partners status + partner_status_history + licence decisions | Checklist + screens missing | REQ-SEED-004 | app/Application/Partners/Setup (new) |
| REQ-SET-004 | Configuration inheritance & precedence Platform→Insurer→Broker agreement→Broker internal→Branch→User (only more restrictive) | SCF governing rules | MISSING | — | Resolver + guard | REQ-TEN-002 | app/Application/Configuration |
| REQ-SET-005 | Configuration governance: Draft→Review→Approved→Published, maker-checker, audit, effective-dated | SCF; PRE §74 | PARTIAL | approve endpoints on tariffs, templates, rules, reference sets | No uniform config_approvals | REQ-RBAC-005 | app/Application/Configuration |
| REQ-SET-006 | Numbering engine: per-tenant sequences, server-side only (e.g. AXA-MOT-2026-000001) | SCF §59; DCP numbering | WIP | document_numbering_families, document_number_counters, DocumentNumberAllocator | Policy numbers still supplied by caller (PREP) | — | app/Application/Documents/Engine |
| REQ-ORG-001 | Broker agent hierarchy (branch→supervisor→agent→sub-agent), agent types, licensing history, suspension without broken refs (WF-080) | SCF §32–33; WRS WF-080 | PARTIAL | partners, partner_licences, partner_status_history, AgentPartnerResolver | No hierarchy/supervisor; no suspension workflow | REQ-TEN-002 | app/Application/Agents, Partners |
| REQ-AOM-001 | Insurance Company Capability Profile: mode per capability (MANUAL/CONFIGURED/HYBRID/REMOTE_API), maturity L1–L5, pinning on transactions | AOM; MPS §3–5; PREP A1; LOCK-002 | MISSING | — | Profile tables, CapabilityResolver, pinning | REQ-SET-002 | app/Application/Capabilities (new) |
| REQ-DUP-013 | Master-data suggestion posted on two paths | Coordinator scope (zero duplication); code inspection | DUPLICATE | MasterDataController@suggest on master-data/suggestions and mobile/master-data/review; mobile/vehicles/master-review separate | Canonical: Keep `master-data/suggestions` (vehicles as a domain) | REQ-MDM-003 | routes/master_data.php, routes/vehicles.php |
| REQ-DUP-017 | Two insurer authorization models | Coordinator scope (zero duplication); code inspection | DUPLICATE | insurer_authorizations (IARD/LIFE per register year, committed) vs insurer_regulatory_authorizations + insurer_authorized_branches (WIP) | Canonical: insurer_regulatory_authorizations + authorized_branches canonical; insurer_authorizations kept as register-year source feeding it | REQ-CIMA-002 | app/Application/Regulatory, migrations |
| REQ-DUP-018 | Three classification taxonomies | Coordinator scope (zero duplication); code inspection | DUPLICATE | insurance_lines (flat, product line_code), insurance_classes (register), regulatory_branches/regulatory_class_defaults (WIP); blueprint name insurance_branches | Canonical: ADR: regulatory_branches = blueprint insurance_branches; insurance_classes = platform class; insurance_lines mapped then retired | REQ-PRD-002 | app/Application/Catalogue, Regulatory |

### W3 — Customer, corporate, KYC

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-PTY-001 | Parties PERSON/ORGANIZATION, persons, addresses, contacts, identity documents (encrypted) | BP I; MDC identity | EXISTS | parties, party_addresses, party_contacts, party_identifiers; parties API | — | — | — |
| REQ-PTY-002 | Golden record with party_roles (bitemporal), 16 CIMA person roles; policyholder ≠ insured ≠ beneficiary | BP I; CIMA roles; ICE gap 16; LOCK-006 | PARTIAL | tenant_customers, claim_involved_parties, users.party_id | No party_roles; roles implicit per table | REQ-PTY-001 | app/Application/Customers, migrations |
| REQ-PTY-003 | party_relationships (household, employer, group) + ownership_interests (UBO graph) | BP I; ICE gap 17, E5 | MISSING | — | Tables + graph API | REQ-PTY-002 | app/Application/Customers/Relationships (new) |
| REQ-PTY-004 | Duplicate entity resolution: probable-match review, no auto-merge, survivorship log (BRK-017) | ICE gap 15; MPS §60–86 | MISSING | — | entity_match_candidates + DATA_STEWARD case | REQ-CAS-001 | app/Application/Customers/Matching (new) |
| REQ-KYC-001 | Individual KYC (WF-003): not_started→draft→submitted→reviewing→approved; more_information_required, rejected, expired; maker-checker review | WRS WF-003; BP II KYC; MSR CUST-009..016 | PARTIAL | kyc_submissions(+documents), MobileKycService, mobile/kyc/*, documents/{d}/review | No kyc_cases state machine; no review queue (BRK-020) | REQ-WFL-001 | app/Application/Kyc |
| REQ-KYC-002 | Corporate KYC / due diligence / corporate onboarding (WF-004, WF-076) | WRS; ESR CMP-006; MSR BRK-024 | MISSING | — | Needs REQ-PTY-003 UBO | REQ-PTY-003, REQ-KYC-001 | app/Application/Kyc |
| REQ-KYC-003 | KYC remediation & expiring documents (WF-074/075), original submission retained | WRS | MISSING | — | Remediation state + CUST-016/BRK-022/023 | REQ-KYC-001 | app/Application/Kyc |
| REQ-KYC-004 | Screening hook (screening_checks) at onboarding | BP I; ICE E5.1 | MISSING | — | See REQ-AML-001 | REQ-AML-001 | — |
| REQ-CRM-001 | Leads NEW→CONTACTED→QUALIFIED→QUOTE→NEGOTIATION→WON/LOST; assignment rules (WF-005..007) | BP I leads; WRS; SCF §40 | PARTIAL | partner_leads; mobile/partner/agent/leads (+convert) | No broker lead directory/assignment API (BRK-009..011) | — | app/Application/Agents, PartnerWorkspace |
| REQ-CRM-002 | Customer 360 single record across roles (WF-088) | WRS WF-088; ESR ownership | PARTIAL | mobile agent/broker client detail | No broker/web 360; no timeline aggregation | REQ-UI-002 | app/Application/Customers/Customer360 (new) |
| REQ-CRM-003 | Customer attribution/ownership and portfolio transfer (WF-079) with preview and history | WRS WF-079; ICE gap 36 | PARTIAL | customer_attributions, attribution_disputes, attributions API | Portfolio transfer missing | REQ-RBAC-002 | app/Application/Attribution |
| REQ-CRM-004 | Beneficiary engine: primary/contingent, allocation = 100%, history (WF-078) | PRE §67; WRS WF-078 | MISSING | Mentions only in risk-fact schemas | Tables + validation | REQ-PTY-002 | app/Application/Customers/Beneficiaries (new) |
| REQ-CUS-001 | Customer status ACTIVE/SUSPENDED/ARCHIVED | — | EXISTS | CustomerService TRANSITIONS; customers/{c}/status | — | — | — |
| REQ-RSK-001 | Insured objects: vehicles (WF-077 with duplicate check), properties, business risks referencing golden IDs | BP I; MPS §16–20 | PARTIAL | risk_assets, risk_asset_vehicles, risk_asset_documents/events, RiskAssetVehicleSync | No properties/business_risks tables; VIN duplicate check to verify | REQ-VEH-001 | app/Application/Risks |
| REQ-SRC-001 | Global & advanced search (SHR-001/002) | MPS §94; MSG top-20 | MISSING | — | Search API + index | REQ-RBAC-002 | app/Application/Search (new) |

### W4 — Product & rule engine

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-PRD-001 | Product/version split; version states DRAFT/REVIEW/APPROVED/PUBLISHED/SUSPENDED/RETIRED; versions never edited live | PRE §7–10; MPS §11; PREP 2.1 | PARTIAL | insurance_products row = version (version int); DRAFT→IN_REVIEW→ACTIVE; product_status_history | Create product_versions; keep compatibility FKs | — | app/Application/Catalogue, migrations |
| REQ-PRD-002 | Hierarchy CIMA branch→class→family→carrier product→version→plan→coverage; class taxonomy (6 groups) | PRE layers, classes; MPS §9; SEED 5 | PARTIAL | insurance_lines (flat), insurance_classes; regulatory_class_defaults (WIP) | Family/plan levels missing | REQ-CIMA-001 | app/Application/Catalogue |
| REQ-PRD-003 | Carrier product attributes: EN/FR names/descriptions, customer type, currency, market | PRE §7; SCF §8 | MISSING | Single name column | — | REQ-PRD-001 | migrations |
| REQ-PRD-004 | Plans/packages (TP/TP+/…; Bronze…Platinum) with coverage sets and pricing reference | PRE §10 | MISSING | — | product_plans | REQ-PRD-001 | app/Application/Catalogue |
| REQ-PRD-005 | Coverages v2: limit types (10), deductible types incl. 5% min 50k, waiting periods, territory, mandatory/optional/default | PRE §11–13; SCF §9 | PARTIAL | coverage_definitions, product_coverages (single ints) | Typed limits/deductibles | REQ-PRD-001 | app/Application/Catalogue |
| REQ-PRD-006 | Exclusions at PRODUCT/PLAN/COVERAGE/CUSTOMER/RISK/CLAIM with versioned legal text; extensions | PRE §14–15 | PARTIAL | exclusion_definitions, product_exclusions | Levels + legal text versions | REQ-PRD-005 | — |
| REQ-PRD-007 | Product governance workflow DRAFT→CONFIGURATION→TECHNICAL_REVIEW→COMPLIANCE_REVIEW→BUSINESS_APPROVAL→READY→PUBLISHED; completeness checklist; diff; scheduled publish; rollback; dependency graph; governance attributes (owner, target/prohibited market, review date) | PRE §74–84, §97–98; ICE gap 35 | PARTIAL | submit/publish with maker-checker; publish requires regulatory_reference + approved tariff | Most steps absent | REQ-PRD-001, REQ-CIMA-002 | app/Application/Catalogue/Governance |
| REQ-PRD-008 | Test sandbox + portfolio simulation with full trace, no side effects | PRE §76–78; SCF sandbox; PREP B10 | MISSING | — | — | REQ-RAT-001, REQ-RUL-002 | app/Application/Sandbox (new) |
| REQ-PRD-009 | Simple product onboarding: 5 questions with background CIMA mapping | AOM; PREP A2 | MISSING | — | — | REQ-AOM-001, REQ-CIMA-003 | app/Application/Catalogue/Onboarding |
| REQ-PRD-010 | Product Builder UI (CAR-013..022, 19-step wizard, dashboard, completeness, blocking vs warnings) | PRE §93–99; ESR CAR | PARTIAL | Filament staff CRUD (InsuranceProducts, CoverageDefinitions, TariffVersions…); mobile carrier products list/status | Carrier-facing builder missing | REQ-PRD-001..007, REQ-UI-001 | app/Filament or carrier web |
| REQ-RUL-001 | Configurable questions per product version (12 types, validation, visibility, risk factor, effects) | PRE §17–18; SCF §10 | WIP | RiskSchemaCatalogue hard-coded (modified, uncommitted), NonMotorRiskSchemas, MotorRiskSchema, disclosure_schema_versions | Version-scoped question_definitions/product_questions | REQ-PRD-001 | app/Application/Catalogue |
| REQ-RUL-002 | Shared structured expression engine (operators EQUAL…NOT, priority, stop_processing, trace, no uploaded code, determinism) | PRE §19–22, §85; PREP §3 | MISSING | QuoteService::eligible (EQUALS/IN/BETWEEN, AND only) | Build B3 | — | app/Domain/Rules (new) |
| REQ-RUL-003 | Eligibility outcomes ELIGIBLE/INELIGIBLE/CONDITIONAL/REFER/MORE_INFO with explanation | PRE §19; SCF §10 | PARTIAL | Boolean eligibility; ineligible products silently skipped | Outcome enum + trace | REQ-RUL-002 | app/Application/Quotes |
| REQ-RUL-004 | Completeness / data-quality gates (blocking rules at quote, bind, issue, claim) | ICE gap 14 | MISSING | — | completeness_rules | REQ-RUL-002 | app/Application/Rules |
| REQ-RAT-001 | Rating v2: premium build-up, 13 methods, tables/bands, min premium, discount caps, loadings, rounding | PRE §26–33; PREP §5 | PARTIAL | DeterministicRatingEngine (base + bp factors + one tax + one fee) | Coverage-level premium, tables, caps | REQ-RUL-002 | app/Domain/Rating |
| REQ-RAT-002 | Tariff versions workflow DRAFT→REVIEW→APPROVAL→SCHEDULED→ACTIVE→EXPIRED, immutable history | PRE §75; SSR CAR-019 | PARTIAL | tariff_versions + TariffGovernanceService (DRAFT→IN_REVIEW→APPROVED, overlap guard, hash) | SCHEDULED/ACTIVE/EXPIRED states | REQ-TMP-001 | app/Application/Rating |
| REQ-RAT-003 | Tax & statutory charge engine (charge code, jurisdiction, basis, rate/fixed, effective, classes); no hard-coded rates | PRE §33; FRP premium components; MPS §106 | PARTIAL | tax_levy_versions, fee_schedule_versions (one blob per line); rates DEMO (OQ-9) | Charge-code catalogue | REQ-RAT-001 | app/Application/Rating |
| REQ-RAT-004 | Immutable pricing snapshot storing tariff, tax, fee and rule versions | PRE §34; BP I pricing_snapshots; WRS WF-010 | PARTIAL | rating_runs (stores only tariff_version_id — bug), quote_offers.calculation_breakdown | Store all versions; formal pricing_snapshots | REQ-RAT-001 | app/Application/Quotes/QuoteService |
| REQ-RAT-005 | Per-CIMA-branch premium allocation (Art.411) | PREP §5.5; OQ-24 | MISSING | — | — | REQ-CIMA-003, REQ-RAT-001 | app/Application/Rating |
| REQ-DST-001 | Sellability: product ACTIVE + version PUBLISHED + agreement ACTIVE + authorization ACTIVE + branch/agent allowed; channels; territory | PRE §57–62; SCF §24 | PARTIAL | AuthorityChecker (DA agreement lines/territory/period), marketplace_publications | Version/authorization/channel checks | REQ-SEED-004, REQ-AUTH-003 | app/Application/Distribution (new) |
| REQ-DST-002 | Broker catalogue derived from carrier product + agreement + authorization; broker cannot override cover/tariff | SCF §36–37 | PARTIAL | marketplace publications toggled by broker | Derivation rule | REQ-DST-001 | — |
| REQ-DST-003 | Marketplace comparison on normalized dimensions (limits, excess, exclusions, tax/fees) | PRE §99; MSR CUST-024; AUD §9; STA quote-comparisons | PARTIAL | POST web-experiences/marketplace/comparisons, saved_comparisons; server sends dimensions | App shows total only | REQ-RAT-004 | mobile app compare screens |
| REQ-AOM-002 | Execution adapters QuoteProvider / UnderwritingProvider / PolicyIssuer / ClaimProvider / PaymentExecution | AOM adapters; MPS §3; PREP A0/A3; LOCK-014 | PARTIAL | PaymentProviderAdapter + PaymentAdapterRegistry only | Wrap current services as OPES adapters | REQ-AOM-001 | app/Application/*/Adapters |
| REQ-DUP-020 | Four risk-question sources | Coordinator scope (zero duplication); code inspection | DUPLICATE | RiskSchemaCatalogue (hard-coded, WIP edit), NonMotorRiskSchemas, MotorRiskSchema, insurance_lines.risk_schema, disclosure_schema_versions | Canonical: product_questions per product version (REQ-RUL-001); PHP schemas become seed data | REQ-RUL-001 | app/Application/Catalogue, Vehicles |

### W5 — Quote & proposal

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-QUO-001 | Quote state machine draft→rating→calculated→generated→sent→viewed→accepted; declined, expired, cancelled | BP II Quote; WRS canonical states; SSR AGT-027 | PARTIAL | quotes.status strings (SUBMITTED, REFERRED, OFFERED, ACCEPTED…), no enum | Adopt canonical states; app mapping | REQ-WFL-001 | app/Application/Quotes |
| REQ-QUO-002 | Configurable quote validity → QUOTE_EXPIRED | PRE §34 | PARTIAL | expires_at hard-coded +7d | Per product version | REQ-PRD-001 | QuoteService |
| REQ-QUO-003 | Quote risks + multi-carrier offers (quote_risks) | BP I | PARTIAL | quotes.risk_facts jsonb, quote_offers | quote_risks table referencing insured objects | REQ-RSK-001 | — |
| REQ-QUO-004 | Send/share quote, quote PDF, viewed tracking, lost quote (WF-012, WF-014) | WRS; MSR AGT-026/028 | MISSING | — | — | REQ-DOC-002 | app/Application/Quotes |
| REQ-QUO-005 | Premium override approval with authority and permanent audit (BRK-035) | SSR BRK-035; BP authority PREMIUM_OVERRIDE | MISSING | — | — | REQ-OVR-001, REQ-AUTH-002 | — |
| REQ-QUO-006 | Manual quotation (Mode 1): carrier quote request/response, broker-on-behalf with evidence, carrier queue, SLA/expiry | AOM Mode 1; PREP A3 | PARTIAL | carrier_exchange_messages; mobile carrier referrals | carrier_quote_requests/responses + record-offer | REQ-AOM-002 | app/Application/CarrierOperations |
| REQ-PRP-001 | Proposal state machine draft→submitted→reviewing→information_required→resubmitted→approved/declined; immutable submitted snapshot | BP II Proposal; WRS WF-015/016; CIMA Art.6 | PARTIAL | ProposalStatus (9 states incl. COUNTEROFFERED, PAYMENT_PENDING), proposal_status_history, terms_snapshot | information_required/resubmitted/withdrawn missing | REQ-WFL-001 | app/Application/Underwriting/ProposalService |
| REQ-PRP-002 | Declarations, consent, beneficiaries and market-conduct evidence (disclosure hash) | PRE §35; ICE gap 3; WRS WF-016 | PARTIAL | proposal_disclosure_responses, disclosures/attest | Beneficiaries, suitability artefacts | REQ-CRM-004 | ProposalService |
| REQ-PRP-003 | Risk-dependent document requirements MISSING/UPLOADED/REVIEWING/ACCEPTED/REJECTED/EXPIRED | PRE §36; DRM | PARTIAL | document_requirement_versions, proposal_documents(+review) | Unify with product document requirements (see DUP-004) | REQ-DOC-005 | app/Application/Underwriting/DocumentRequirementService |
| REQ-PRP-004 | POLICY_ISSUABLE gate (approved + payment condition + documents + UW + approvals) | PRE §37 | PARTIAL | PAYMENT_PENDING → issuance request | Explicit PolicyIssuabilityService | REQ-PRP-001, REQ-POL-008 | app/Application/Policies |
| REQ-PRP-005 | Effective-date rules, durations, instalment plans (SINGLE…CUSTOM) | PRE §38–42 | MISSING | — | — | REQ-PAY-006 | app/Application/Policies |
| REQ-DUP-007 | Parallel mobile vs core application services | Coordinator scope (zero duplication); code inspection | DUPLICATE | MobileQuoteService/QuoteService, MobileProposalService/ProposalService, MobileClaimService+MobileClaimEvidenceService/ClaimLifecycleService+ClaimEvidenceService, MobileKycService, MobileDocumentService, MobilePolicyServiceController on two routes | Canonical: Core services are canonical; mobile controllers become thin adapters/presenters (no business rules in Mobile* services) | REQ-ARC-002 | app/Application/{Quotes,Underwriting,Claims,Kyc,Documents} |

### W6 — Underwriting & authority

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-UW-001 | Underwriting case (+referral) machine referred→assigned→reviewing→decision_pending→approved/conditional/declined | BP II; WRS WF-018; ESR UND | PARTIAL | underwriting_cases, underwriting_decisions, underwriting_referral_tasks; assign/decision/resolve routes; mobile carrier referral decision | Canonical states; UW info-request loop | REQ-WFL-001, REQ-CAS-001 | app/Application/Underwriting |
| REQ-UW-002 | Rule-driven decisions AUTO_ACCEPT/REFER/CONDITIONAL_ACCEPT/DECLINE/REQUEST_INFO/INSPECTION/MEDICAL; STP records rule set version (WF-017) | PRE §23–25; WRS WF-017; AOM UW modes | MISSING | Referrals from disclosure flags only | UnderwritingDecisionService | REQ-RUL-002 | app/Application/Underwriting |
| REQ-UW-003 | Additional information request loop with exact items, return to originator (WF-019) | WRS WF-019; MSR BRK-042/AGT-033 | MISSING | Info request exists for claims only | — | REQ-UW-001 | — |
| REQ-UW-004 | Explainable risk score (factor, weight, input, contribution) | PRE §25; ESR UND-012 | MISSING | — | — | REQ-RUL-002 | — |
| REQ-UW-005 | Underwriting workspace UND-001…020 + UNDERWRITER role | ESR UND; SSR UND-005/018 | PARTIAL | Mobile carrier referrals; Filament UnderwritingCases (no actions) | Role + screens | REQ-RBAC-003, REQ-UI-001 | — |
| REQ-AUTH-001 | Authority profiles/limits for 14 types (QUOTE…REINSURANCE_PLACEMENT), grants, consumption | BP III; ICE E3; LOCK-018 | PARTIAL | delegated_authority_agreements + AuthorityChecker; carrier/delegated-authorities(+check/approve) | Profiles/limits/consumption; OQ-3.4 extra types | REQ-RBAC-001 | app/Application/Authority (new), CarrierOperations |
| REQ-AUTH-002 | Authority check algorithm (permission→scope→membership→product/branch→threshold→maker-checker→regulatory→transition); threshold exceeded → referral; never bare error | BP III; MPS §26–28; ICE E3.2 | MISSING | AuthorityChecker has no enforcement callers | AuthorityService::check + ResumableStep | REQ-AUTH-001, REQ-CAS-001, REQ-RBAC-002 | app/Application/Authority |
| REQ-AUTH-003 | Intermediary authorization enforcement (current, mandate, product, branch, jurisdiction, dates) at quote/bind/issue | ICE gaps 5–7; SEED 6 | PARTIAL | intermediary_authorizations, partner_licences | Not enforced | REQ-AUTH-002 | — |
| REQ-DUP-023 | Agreement/authority tables overlapping | Coordinator scope (zero duplication); code inspection | DUPLICATE | delegated_authority_agreements + AuthorityChecker vs seed-spec carrier_broker_agreements vs blueprint authority_profiles/limits | Canonical: delegated_authority_agreements → carrier_broker_agreements (distribution) + authority_limits (authority); one AuthorityService | REQ-AUTH-001, REQ-SEED-004 | app/Application/CarrierOperations, Authority |

### W7 — Policy administration

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-POL-001 | Policy machine issuance_pending→issued→active→expired; amended, suspended, cancelled, renewed | BP II Policy; WRS canonical | PARTIAL | PolicyStateMachine (10 states); policy_status_history; policies:expire | Canonical naming alignment | REQ-WFL-001 | app/Domain/Policies |
| REQ-POL-002 | policy_versions (bitemporal chronology per change), policy_parties, policy_risks, policy_coverages, policy_limits | BP I; ICE E2.1 | PARTIAL | policies.version, terms_snapshot/hash, policy_transactions(+events) | Structured tables + backfill | REQ-TMP-002, REQ-PTY-002 | app/Application/Policies, migrations |
| REQ-POL-003 | Immutable issuance snapshot (§84: version, coverages, limits, deductibles, premium, taxes, fees, risk, rules, documents, beneficiaries) | PRE §83–84; MPS §115 | PARTIAL | terms_snapshot + terms_hash + authority_snapshot | Structure per §84 | REQ-POL-002 | PolicyIssuanceService |
| REQ-POL-004 | Payment→issuance automation, idempotent; failed/paid-not-issued queue (WF-028/029/084); customer message | WRS; BP II issuance failure; AUD A4; MSR BRK-058 | PARTIAL | PaymentIssuanceTrigger (dcb367e), policy_issuance_requests(+approve/reject), mobile carrier issuance | No failed-issuance queue API/screen, retry/escalate/refund actions | REQ-PAY-001 | app/Application/Policies |
| REQ-POL-005 | Manual issuance with carrier original documents (provenance, sha256, immutable) | AOM carrier docs; PREP A4 | WIP | CarrierDocumentService; POST policies/{p}/carrier-documents (untracked document_engine routes) | Commit + tests | REQ-DOC-003 | app/Application/Documents/Engine |
| REQ-POL-006 | Suspension & reinstatement (WF-046/047) | WRS; PRE §48 | PARTIAL | SUSPENDED state | No reinstatement workflow/queue (BRK-063) | REQ-POL-001 | PolicyServicingService |
| REQ-POL-007 | Motor sticker chain carrier→broker→branch→agent→policy with custody (WF-032/033) | WRS; SCF §47 | PARTIAL | sticker_batches, sticker_stock, sticker_custody_events; POST sticker-batches | Allocation/handover/reconciliation APIs | REQ-TEN-002 | app/Application/Logistics or Motor |
| REQ-POL-008 | Premium-to-cover rule per product/carrier (NO_COVER_UNTIL_PAID / GRACE / SUSPEND_ON_DEFAULT) | ICE gap 31; MPS §29–38 | MISSING | — | premium_cover_rules | REQ-TMP-001 | — |
| REQ-POL-009 | Portfolio transfer and policy portability export pack | ICE gaps 36, 42 | MISSING | — | — | REQ-POL-002 | — |
| REQ-POL-010 | Lapse/grace and expired policy recovery (WF-083) | WRS | PARTIAL | LAPSED state | Grace config + recovery flow | REQ-POL-008 | — |
| REQ-END-001 | Endorsement machine + rules per type; avenant; rerate; additional premium/refund (WF-035..038) | BP II; PRE §43–44; WRS; CIMA avenant | PARTIAL | policy_transactions + approve/reject/payment/payment-intents routes; mobile policy-service-requests | Endorsement-type rules; new policy_version | REQ-POL-002, REQ-RAT-001 | PolicyServicingService |
| REQ-REN-001 | Renewal machine; windows 90/60/30/15/7; rerate on current version; continuity link; paid-renewal issuance failure (WF-039..043, WF-087) | BP II; WRS; PRE §45 | PARTIAL | renewal_cases, renewal_work_items, policy_expiry_reminders, policies:notify-expiry, renewals routes | Renewal rule versions; exception queue (BRK-068) | REQ-POL-002 | RenewalService |
| REQ-CAN-001 | Cancellation machine: request→review→approve; pro-rata/short-rate refund; notice; revoke documents (WF-044/045) | BP II; PRE §46; WRS | PARTIAL | CancellationCalculator + cancellation_rule_versions; CANCELLATION_PENDING | Approver queue (BRK-062); carrier/product scope | REQ-END-001 | app/Application/Policies |
| REQ-PRD-011 | Life & special products: life rules, surrender, group master policy + members, fleet schedule, cargo declarations, construction, agriculture | PRE §66–73; DCP classes | MISSING | — | Schedules + life engine interface (actuarial values from carrier) | REQ-POL-002, REQ-CRM-004 | app/Application/Policies/Special (new) |
| REQ-DUP-010 | Renewal seeding twice | Coordinator scope (zero duplication); code inspection | DUPLICATE | POST renewals/seed (RenewalController) and POST broker/renewals/seed (BrokerOperationsController) | Canonical: Keep `renewals/seed` (scheduled job); remove broker variant | REQ-REN-001 | routes, BrokerOperations |
| REQ-DUP-014 | Policy service request on two paths | Coordinator scope (zero duplication); code inspection | DUPLICATE | MobilePolicyServiceController@store on mobile/policy-service-requests and policies/{policy}/service-requests | Canonical: Keep `policies/{policy}/service-requests` | REQ-END-001 | routes |

### W8 — Document engine

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-DOC-001 | 220 canonical document types register | MPS §53, §102; DCP register; LOCK-012 | WIP | document_types (2026_09_29_100001_create_document_catalogue, untracked) + document_register_220_2026.json + DocumentRegister (engine reads JSON) | Two parallel catalogue implementations — see DUP-004 | — | app/Models/DocumentCatalogue, app/Application/Documents/Engine |
| REQ-DOC-002 | Templates + versions per product version; ownership PLATFORM/INSURER/BROKER/REGULATORY; DRAFT→REVIEW→APPROVED→PUBLISHED→RETIRED; EN/FR/bilingual | DCP; PRE §63–65; SCF §21 | WIP | document_templates + DocumentTemplateService; POST document-templates/{t}/{action}; legacy certificate_templates | Merge certificate_templates (DUP-005) | REQ-DOC-001 | app/Application/Documents/Engine |
| REQ-DOC-003 | Generated document record (template/product/policy version, hash, verification code, issuer, language); statuses VALID/SUPERSEDED/REPLACED/REVOKED/EXPIRED/CANCELLED; never delete | DCP; LOCK-011; WRS WF-031 | WIP | documents, document_versions, document_status_changes, DocumentStatusService, documents/{d}/status-changes | — | REQ-DOC-002 | — |
| REQ-DOC-004 | Document packs (e.g. MOTOR_NEW_BUSINESS_PACK) and one Download Policy Pack action | DCP | WIP | document_packs/items (catalogue) and document_pack_manifests (engine); mobile/policies/{p}/documents/pack | Two pack models — see DUP-004 | REQ-DOC-001 | — |
| REQ-DOC-005 | Requirement matrix per product version (M/C/O/I/T by class and stage) + 16-point product document gate | DRM §3–42; DCP gate | WIP | document_requirement_matrix + product_document_requirements (catalogue), product_document_rules + ProductDocumentGate (engine), legacy document_requirement_versions | Three requirement stores (DUP-004); DRM §42+ pending from owner | REQ-DOC-001, REQ-PRD-001 | — |
| REQ-DOC-006 | Per-tenant numbering families; CIMA continuous numbering policy→endorsement 001→renewal | DCP registry; SCF §59 | WIP | document_numbering_families, document_number_counters | Policy/endorsement numbering not routed through allocator | REQ-SET-006 | — |
| REQ-DOC-007 | Motor attestation + certificate (Arts 213/214/221), QR public verification VALID/EXPIRED/REVOKED/REPLACED/NOT_FOUND, minimal disclosure (WF-031, WF-082) | DCP motor; WRS; MSR SHR-005/006 | PARTIAL | policy_certificates, certificates API, public_verification_lookups, /verify page; PublicVerificationService (modified, WIP) | Certificate at issuance for all motor policies; DEMO_VALID | REQ-DOC-003 | app/Application/Certificates |
| REQ-DOC-008 | Document origin (INSURER…SYSTEM) and stage; third-party evidence stored separately | DCP origin vs issuer | PARTIAL | documents, claim_documents, kyc_submission_documents, risk_asset_documents | No origin/stage columns; evidence tables fragmented | REQ-DOC-003 | — |
| REQ-DOC-009 | Security levels, access log, retention schedules, legal hold (any subject), destruction case | DCP; ICE gap 20; MPS §60–86 | PARTIAL | document_access_log, document_retention_holds | Generalized legal_holds, retention_schedules | REQ-CAS-001 | — |
| REQ-DOC-010 | Incoming document intake & classification against the 220 register | ICE gap 22 | MISSING | — | — | REQ-DOC-001, REQ-CAS-001 | — |
| REQ-DOC-011 | Document admin screens DOC-ADM-001…020 | DCP register | PARTIAL | Filament DocumentRequirements, CertificateTemplates | 18 screens missing | REQ-DOC-001..005 | app/Filament/Admin |
| REQ-DOC-012 | E-signature | REMAINING_WORKSTREAMS 6 | MISSING | — | — | REQ-DOC-003 | — |
| REQ-DUP-004 | Three document catalogue / requirement / pack models being built in parallel | Coordinator scope (zero duplication); code inspection | DUPLICATE | WIP: 2026_09_29_100001_create_document_catalogue (document_types, document_packs, document_pack_items, document_requirement_matrix, product_document_requirements; DocumentCatalogueServiceProvider not in bootstrap/providers.php) vs WIP 2026_09_29_110001_create_document_engine (product_document_rules, document_pack_manifests; DocumentRegister reads JSON) vs committed document_requirement_versions + DocumentRequirementService | Canonical: One catalogue: document_types + document_packs/items + product_document_requirements (definitions); document engine consumes them; document_pack_manifests only for generated pack instances; retire product_document_rules and document_requirement_versions via data migration | REQ-DOC-001, REQ-DOC-005 | app/Application/Documents/*, app/Models/DocumentCatalogue, migrations |
| REQ-DUP-005 | Certificate subsystem parallel to the document engine | Coordinator scope (zero duplication); code inspection | DUPLICATE | certificate_templates, policy_certificates, certificates API, CertificateController vs document_templates/documents (engine) | Canonical: Certificates/attestations become document types issued by the engine; certificate tables kept read-only for history | REQ-DOC-002, REQ-DOC-007 | app/Application/Certificates, Documents/Engine |
| REQ-DUP-015 | Three public verification entry points | Coordinator scope (zero duplication); code inspection | DUPLICATE | POST public/certificates/verify (CertificateController), POST public/insurance/verify, GET /verify (web) | Canonical: One PublicVerificationService + one API `public/verify` used by the web page | REQ-DOC-007 | app/Application/Certificates, routes |
| REQ-DUP-021 | Five evidence/document stores | Coordinator scope (zero duplication); code inspection | DUPLICATE | documents vs claim_documents, kyc_submission_documents, risk_asset_documents, proposal_documents | Canonical: documents canonical (with origin/stage); others become subject link tables | REQ-DOC-008 | app/Application/Documents |

### W9 — Billing & payments

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-OBL-001 | Universal financial_obligations (receivables/payables, debtor, source, due, outstanding, aging, status) | BP I; FRP I; MPS §29–38 | MISSING | — | Foundation of the money chain | REQ-ACC-001 | app/Application/Finance/Obligations (new) |
| REQ-PAY-001 | Payment machine created→initiated→pending→successful→reconciled; failed, expired, cancelled, reversed, refunded | BP II; WRS WF-022 | PARTIAL | payment_intents + PaymentStatus (10) + WebhookProcessingService TRANSITIONS; payment_events | CANCELLED missing; reconciled is a timestamp | REQ-WFL-001 | app/Domain/Payments |
| REQ-PAY-002 | Mobile money MTN/Orange with signed callback verification; client never authoritative (WF-023) | WRS WF-023 | EXISTS | webhooks/payments/mtn-momo/orange-money/{provider}, HMAC verify, webhook_inbox, payments:poll-pending | — | — | — |
| REQ-PAY-003 | Bank/card payments (WF-024) | WRS | MISSING | — | Adapter | REQ-AOM-002 | app/Application/Payments/Adapters |
| REQ-PAY-004 | payment_allocations many-to-many + allocation engine (versioned order rule) | BP I; ICE gap 33; MPS §29 | MISSING | — | — | REQ-OBL-001 | app/Application/Finance |
| REQ-PAY-005 | Premium components and premium status (NOT_DUE…WRITTEN_OFF) separate from payment status | FRP I | MISSING | — | — | REQ-OBL-001 | — |
| REQ-PAY-006 | Instalment schedules and non-payment consequences | PRE §42; FRP I | MISSING | — | — | REQ-OBL-001 | — |
| REQ-PAY-007 | Reconciliation: MATCHED/PARTIAL/UNMATCHED/DUPLICATE/OVER/UNDER, exceptions queue, unmatched workspace, duplicate payment (WF-026/027/086) | WRS; FRP I; MSR BRK-050/051 | PARTIAL | reconciliation_imports/items, MatchEngine, reconciliation:run, items/{i}/resolve | Queue/list APIs and screens | REQ-PAY-004 | app/Application/Reconciliation |
| REQ-PAY-008 | Failed payment retry linked to the same obligation (WF-025/085) | WRS | PARTIAL | payment_attempts; initiate route | Obligation link | REQ-OBL-001 | — |
| REQ-PAY-009 | Refund engine: candidate→calculate→review→approve→pay→reconcile, maker-checker (WF-063) | WRS; BP II Refund; FRP I | PARTIAL | refunds; payments/{p}/refunds; refunds/{r}/approve | Refund queue/list; sources (cancellation, duplicate, failed issuance) | REQ-OBL-001 | app/Application/Payments |
| REQ-PAY-010 | Cashier sessions (open, collections, closing count, variance, supervisor approval) | FRP I; ICE gap 30 | MISSING | — | — | REQ-RBAC-005 | — |
| REQ-PAY-011 | Mobile-money clearing (provider success ≠ bank settlement) and suspense account | FRP I | MISSING | — | — | REQ-ACC-001 | — |
| REQ-PAY-012 | Chargebacks | — | EXISTS | chargebacks; payments/{p}/chargebacks; chargebacks/{c}/resolve | — | — | — |
| REQ-PAY-013 | Multi-currency & immutable FX rates | ICE gap 34; MPS §60–86 | MISSING | — | fx_rates | REQ-TMP-001 | — |
| REQ-PAY-014 | Payment execution modes BROKER_COLLECTION / INSURER_COLLECTION / MOBILE_MONEY / BANK / EXTERNAL | AOM | PARTIAL | PaymentAdapterRegistry, payment_provider_connections | Collection-mode semantics | REQ-AOM-002 | — |
| REQ-PAY-015 | Customer/broker/carrier/agent account statements derived from transactions | FRP I; ESR FIN-009..012 | PARTIAL | partner_statements(+items) | Customer/carrier statements | REQ-OBL-001 | — |

### W10 — Commission & settlement

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-COM-001 | Commission machine SALE→CALCULATED→ACCRUED→EARNED→APPROVED→PAYABLE→PAID (+REVERSED, CLAWED_BACK, ADJUSTED, DISPUTED) | BP II; FRP I; WRS WF-064..067 | PARTIAL | commission_accruals, commission_movements; accrue/vest/clawback | Canonical states | REQ-WFL-001 | app/Application/FinancialDistribution |
| REQ-COM-002 | Commission rules per carrier+agreement+version+transaction type; tiers/volume/hybrid; internal split = 100% | PRE §57–60; SCF §25, §38 | PARTIAL | commission_rule_versions (basis_points, holdback, conditions) + approve | Transaction type, tiers, split | REQ-SEED-004 | — |
| REQ-COM-003 | Commission statements, approval, payment, adjustments with maker-checker | WRS WF-066/067; FRP VI | PARTIAL | partner_statements(+approve/publish/payouts), partner_payout_* lifecycle | — | REQ-COM-001 | — |
| REQ-STL-001 | Broker–insurer settlement Draft→Calculated→Review→Approved→Processing→Settled→Reconciled, from ledger (WF-068/069) | WRS; SSR FIN-019; FRP I | PARTIAL | settlement_batches/items/approvals, carrier-settlements lifecycle routes, settlements:prepare | Reconciled step; ledger derivation | REQ-ACC-002 | app/Application/Settlements |
| REQ-STL-002 | Bordereaux (prepare/submit/approve/acknowledge) | FRP II | EXISTS | bordereaux, bordereau_items; bordereaux routes | Duplicate route families (DUP-008) | — | — |
| REQ-DUP-008 | Bordereaux handled by three controllers | Coordinator scope (zero duplication); code inspection | DUPLICATE | bordereaux/* (FinancialDistributionController), broker/bordereaux/* (BrokerOperationsController), carrier/bordereaux/{b}/decision (CarrierOperationsController) | Canonical: One `bordereaux` resource; broker/carrier actions authorized by role/scope, not separate paths | REQ-STL-002 | routes, app/Application/FinancialDistribution |
| REQ-DUP-011 | Settlement data behind two controllers | Coordinator scope (zero duplication); code inspection | DUPLICATE | GET settlements/{batch} (SettlementController) and carrier-settlements/* (FinancialDistributionController); partner_statements/payouts for commission | Canonical: carrier-settlements for carrier; partner-statements for commission; drop `settlements/{batch}` after verifying same table | REQ-STL-001 | routes, app/Application/Settlements |

### W11 — Accounting

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-ACC-001 | Accounting events mapped to tenant GL (stable business events) | BP I; FRP I; SCF §73 | PARTIAL | financial_posting_profiles, financial_distribution_events, ledger_accounts | Event catalogue + per-tenant mapping | REQ-ARC-004 | app/Application/Ledger |
| REQ-ACC-002 | Journals debit=credit, DRAFT→VALIDATED→APPROVED→POSTED→REVERSED; manual journal maker-checker | FRP I; ESR FIN-014..016 | PARTIAL | journals (status default POSTED), journal_lines, ledger/journals + reverse | Draft/approval states; trial balance | REQ-ACC-001 | app/Domain/Ledger |
| REQ-ACC-003 | Period close with privileged reopening | FRP I; ESR FIN-023 | MISSING | — | — | REQ-ACC-002 | — |
| REQ-ACC-004 | Technical accounting (written/earned/unearned, claims incurred/paid/outstanding), UPR/IBNR stored/imported, life values from carrier actuarial engines | FRP I | MISSING | — | — | REQ-ACC-001 | — |
| REQ-ACC-005 | Aging buckets, finance exception centre, 20 finance reports, FIN-001..024 & FIN-X-001..034 | FRP I; ESR FIN | PARTIAL | Filament list/view (Journals, PaymentAttempts, Reconciliations, CarrierSettlements…) | Actions, reports, exception centre | REQ-OBL-001 | app/Filament/Admin |

### W12 — Claims foundation

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-CLM-001 | Claim machine per BP/WRS (draft→…→closed; information_required, investigating, partially_approved, rejected, appealed, reopened) | BP II Claim; WRS canonical; AUD A9 | PARTIAL | ClaimStateMachine (12 states) + ClaimLifecycle (second definition) ; claims/{id}/transitions | DECLINED vs rejected, PAID vs settled naming; two definitions (DUP-006) | REQ-WFL-001 | app/Domain/Claims |
| REQ-CLM-002 | FNOL incl. agent-assisted FNOL; immediate reference; snapshot (WF-048/049) | WRS; MSR AGT-052 | PARTIAL | claims/fnol, mobile/claims (+incident, emergency-assistance) | Agent-scoped FNOL | REQ-RBAC-002 | app/Application/Claims |
| REQ-CLM-003 | Coverage-at-loss engine + contract chronology; outcomes COVERAGE_CONFIRMED/REVIEW_REQUIRED/POTENTIAL_EXCLUSION/OUTSIDE_COVERAGE; POST /coverage/check | ICE E2.2; PRE §49; MPS §24; WRS WF-050; LOCK-017; STA coverage/check | MISSING | Coverage inferred from policy.status | CoverageAtLossService, claim_coverage_assessments | REQ-POL-002, REQ-TMP-001, REQ-ENG-001 | app/Application/Claims/Coverage (new) |
| REQ-CLM-004 | Limit/aggregate exhaustion engine (policy_limits consumed/reserved/remaining + movement ledger, concurrency-safe) | ICE E2.3; MPS §25 | MISSING | — | — | REQ-POL-002, REQ-CLM-008 | app/Application/Claims/Limits (new) |
| REQ-CLM-005 | Evidence rules per claim type; evidence metadata (uploader, MIME, hash, version); checklist; review (WF-051/052) | PRE §51; WRS | PARTIAL | claim_documents, claim_evidence_custody_events, evidence/{d}/verify, mobile evidence-requirements | Configurable evidence rules | REQ-RUL-002 | ClaimEvidenceService |
| REQ-CLM-006 | Generic ClaimParty model | MPS §43; ICE gap 28; BP I claim_parties | PARTIAL | claim_involved_parties (+mobile parties) | Link to golden party | REQ-PTY-002 | — |
| REQ-CLM-007 | Claim types per product, reporting deadlines, late-claim approval | PRE §50, §52 | MISSING | — | — | REQ-PRD-001 | — |
| REQ-CLM-008 | Event-based reserves (INITIAL/CURRENT/FINAL), movements, maker-checker | PRE §53; FRP I; BP I | PARTIAL | claim_reserve_changes; claims/{id}/reserves(+approve) | Reserve types | REQ-AUTH-002 | — |
| REQ-CLM-009 | Expert/adjuster assignment lifecycle assignment_pending→…→report_submitted; ADJUSTER role; CLP screens (WF-053) | WRS; ESR CLP | PARTIAL | claim_assignments; claims/{id}/assign; mobile inspection | Adjuster role + lifecycle | REQ-RBAC-003 | — |
| REQ-CLM-010 | Assessment (recommendation ≠ decision) and investigation preserving indicators (WF-054/055) | WRS; ESR CLP rule | MISSING | — | claim_assessments | REQ-CLM-009 | — |
| REQ-CLM-011 | Claims execution modes (BROKER_ASSISTED, MANUAL_CARRIER, INSURER_PORTAL, API_SYNCHRONIZED, FULLY_DIGITAL) | AOM | PARTIAL | claims/{id}/carrier-messages, mobile partner carrier claim actions | ClaimProvider adapter | REQ-AOM-002 | — |
| REQ-DUP-006 | Two claim state definitions | Coordinator scope (zero duplication); code inspection | DUPLICATE | app/Domain/Claims/ClaimStateMachine.php and ClaimLifecycle.php | Canonical: Single ClaimStateMachine on the generic engine (REQ-WFL-001) using blueprint states; app status vocabulary aligned | REQ-CLM-001 | app/Domain/Claims, mobile app claim screens |

### W13 — Claim decision & settlement

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-CLM-012 | Decision: approve/partial/reject with reason codes, rationale, authority, supervisor approval; appeal keeps both decisions (WF-056..058, WF-062) | WRS; LOCK-009 | PARTIAL | claim_decisions + approve; claim_disputes + resolve; mobile appeals | Authority routing; broker appeal screen (BRK-087) | REQ-AUTH-002 | app/Application/Claims |
| REQ-CLM-013 | Settlement calculator (covered − excluded − deductible − prior ± adj ≤ remaining limit); settlement lifecycle; discharge; payment via obligations (WF-059) | PRE §55; WRS; FRP I | PARTIAL | claim_payments lifecycle routes; mobile settlement decision (step-up) | Calculator + obligation link | REQ-CLM-004, REQ-OBL-001 | ClaimPaymentService |
| REQ-CLM-014 | Closure rules and reopening (WF-060/061) | WRS | PARTIAL | CLOSED/REOPENED in ClaimLifecycle | Closure guard checklist | REQ-CLM-001 | — |

### W14 — Recovery, salvage, litigation

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-REC-001 | Recovery/subrogation/salvage EXPECTED/RECEIVED/OUTSTANDING/DISPUTED/CLOSED | MPS §46; PRE §56 | PARTIAL | claim_recoveries + receipts | Salvage/subrogation types, statuses | REQ-CLM-013 | — |
| REQ-REC-002 | Litigation/legal management | ICE gap 27; MPS §47 | MISSING | — | Case type | REQ-CAS-001 | — |
| REQ-REC-003 | Recovery & debt collection engine | ICE gap 29 | MISSING | — | — | REQ-OBL-001, REQ-CAS-001 | — |

### W15 — Provider network

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-PRV-001 | Canonical provider→facility→specialty→service; credentialing PROSPECT…TERMINATED | FRP IV; MDC partners | WIP | health_providers (master data engine migration, untracked) | Facilities, credentialing machine | REQ-MDM-001 | app/Application/Providers (new) |
| REQ-PRV-002 | Networks, memberships, contracts, medical service catalogue, versioned provider tariffs | FRP IV; BP I | MISSING | — | — | REQ-PRV-001 | — |
| REQ-PRV-003 | Provider portal + roles (PROVIDER_ADMIN, FRONT_DESK, DOCTOR, BILLING, PHARMACY, LAB, FINANCE) PRV-001..030 | FRP V | MISSING | — | — | REQ-PRV-002, REQ-RBAC-003 | — |
| REQ-PRV-004 | Garages/adjusters/experts as canonical partners with explicit relationships | MDC ownership; MPS §8 | PARTIAL | partners (types), couriers | Relationship tables | REQ-PTY-003 | — |

### W16 — Health cashless

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-HLT-001 | Health eligibility (ELIGIBLE…REVIEW_REQUIRED) + digital health card QR | FRP IV | MISSING | — | — | REQ-PRV-002, REQ-CLM-003 | — |
| REQ-HLT-002 | Preauthorization + guarantee of payment; admission/outpatient/pharmacy/lab workflows | FRP IV; BP II Preauth | MISSING | — | — | REQ-HLT-001 | — |
| REQ-HLT-003 | Provider claims DRAFT…DISPUTED, EOB, statements, disputes, provider settlement | FRP IV; BP II Provider claim | MISSING | — | — | REQ-HLT-002, REQ-OBL-001 | — |
| REQ-HLT-004 | Benefit accumulator; cashless + reimbursement on one benefit engine | FRP IV; MPS §48–52 | MISSING | — | — | REQ-CLM-004 | — |

### W17 — Reinsurance

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-REI-001 | Reinsurer profiles; treaties (QS, surplus, XL layers, stop loss) with effective versions and participants | FRP II; BP I; MPS §39 | MISSING | — | — | REQ-PTY-001 | app/Application/Reinsurance (new) |
| REQ-REI-002 | Risk cessions, gross/net exposure, ceded premium/commission/brokerage/taxes, bordereaux | FRP II | MISSING | — | — | REQ-REI-001, REQ-POL-002 | — |
| REQ-REI-003 | Facultative placement (slip, participants, signed share validation) | FRP II; BP II Facultative | MISSING | — | — | REQ-REI-001 | — |
| REQ-REI-004 | Reinsurance recoveries ESTIMATED…CLOSED, large-loss notification, reinstatement premium, settlement, accounting events; REI-001..026 screens | FRP II; BP II | MISSING | — | — | REQ-REI-002, REQ-CLM-013 | — |

### W18 — Co-insurance

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-COI-001 | Co-insurance arrangements (apériteur + participants = 100%), premium/claim/commission/reserve/settlement shares; never reinsurance; COI-001..008 | FRP III; MPS §41 | MISSING | — | — | REQ-POL-002, REQ-OBL-001 | app/Application/Coinsurance (new) |

### W19 — Compliance & AML

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-AML-001 | Screening (PEP/sanctions/watchlists), list sources/versions, hits disposition, ComplianceGate at bind/issue/payout | ICE E5.1; MPS §60 | MISSING | — | — | REQ-PTY-001, REQ-CAS-001 | app/Application/Compliance/Aml (new) |
| REQ-AML-002 | Risk rating, EDD, source of funds/wealth, periodic rescreening, monitoring rules → alerts | ICE E5.2 | PARTIAL | risk_alerts (+decision) | Rules/thresholds pending OQ-5.2 | REQ-AML-001, REQ-PTY-003 | — |
| REQ-AML-003 | STR cases (restricted), tipping-off controls (Reg. 003-25) | ICE E5.3 | MISSING | — | — | REQ-AML-002 | — |
| REQ-CMP-001 | Compliance cases, investigations, findings, corrective actions (CMP-001..020) | ESR CMP | PARTIAL | compliance_cases(+events), trust/compliance-cases(+transition); ComplianceCaseService is a 3-line stub | Service + screens | REQ-CAS-001 | app/Application/Compliance |
| REQ-CMP-002 | Privacy DSR & privileged access | — | EXISTS | data_subject_requests, privileged_access_grants; trust/* routes | Duplicate route family (DUP-009) | — | — |
| REQ-CMP-003 | ICT governance (Reg. 010-24) and vendor/outsourcing governance | ICE gaps 39, 40 | MISSING | — | Owner input OQ-8.1 | REQ-CAS-001 | — |
| REQ-DUP-009 | Compliance endpoints duplicated under trust/* | Coordinator scope (zero duplication); code inspection | DUPLICATE | compliance/privileged-access vs trust/privileged-access; compliance/data-subject-requests vs trust/data-subject-requests; risk-alerts vs trust/fraud-alerts (ComplianceController, RiskAlertController, Wave9Controller) | Canonical: Keep `compliance/*` (+ `risk-alerts`); retire trust/* equivalents and Wave9Controller | REQ-CMP-002 | routes, app/Interfaces/Http/Controllers/Api/V1/Trust |

### W20 — Complaints & case management

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-CPL-001 | Complaints separate from support: Submitted→Acknowledged→Classified→Assigned→Investigated→Resolution→Communicated→Closed/Escalated; regulatory escalation (WF-071/072, SHR-013/014) | WRS; BP II Complaint; ICE gap 4 | MISSING | Support tickets only | Complaint case type + deadlines (OQ) | REQ-CAS-001 | app/Application/Complaints (new) |
| REQ-SUP-001 | Support tickets OPEN→TRIAGED→…→CLOSED (WF-070) | WRS WF-070 | EXISTS | TicketStateMachine, support_tickets(+events); support routes | Duplicate route family (DUP-001) | — | — |
| REQ-CAS-002 | Case UI + bridges (UW referral, claim dispute, compliance, ticket), operational queues | ICE E6.2; MPS §60–86 | MISSING | — | — | REQ-CAS-001 | — |
| REQ-COR-001 | Correspondence registry (direction, channel, counterparty, reference, proof of dispatch) | ICE gap 23; BP I correspondences | PARTIAL | communication_logs, notification_deliveries | Registry table | REQ-CAS-001 | — |
| REQ-DUP-001 | Support ticket routes registered twice | Coordinator scope (zero duplication); code inspection | DUPLICATE | SupportController@store/@transition on POST support-tickets and POST support/tickets (+/{ticket}/transitions) | Canonical: Keep `support-tickets`; delete `support/tickets` after mobile client switch | — | routes/api.php (+ wave route files), mobile app/src/api |

### W21 — Fraud / anomaly

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-FRD-001 | Fraud indicators → REVIEW_REQUIRED, never FRAUD_CONFIRMED; explainable; suspicious claim review (WF-089) | MPS §45; LOCK-010; ESR CMP rule | PARTIAL | fraud_rule_versions, risk_alerts, trust/fraud-alerts; app/Application/Fraud empty | Evaluator + review case | REQ-RUL-002, REQ-CAS-001 | app/Application/Fraud |
| REQ-FRD-002 | Cash-fraud controls & SoD (collect vs reconcile vs refund) | ICE gap 30 | MISSING | — | — | REQ-RBAC-005, REQ-PAY-010 | — |

### W22 — Catastrophe & accumulation

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-CAT-001 | Exposure zones, risk locations, accumulation gross/net of reinsurance, snapshots | ICE E7.1 | MISSING | — | — | REQ-POL-002, REQ-REI-002 | app/Application/Accumulation (new) |
| REQ-CAT-002 | Capacity check at underwriting: WITHIN_RETENTION / TREATY_COVERED / CAPACITY_EXCEEDED / FACULTATIVE_REQUIRED | MPS §39–40; ICE E7.2; LOCK-019 | MISSING | — | — | REQ-CAT-001, REQ-AUTH-002 | — |
| REQ-CAT-003 | Catastrophe event management + large-loss notifier | ICE E7.2 | MISSING | — | — | REQ-CAT-001 | — |

### W23 — Regulatory & management reporting

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-RPT-001 | Regulatory returns with lineage; report definitions/runs submit/approve/acknowledge; Art.411/557 mappings | MPS §87; ICE gap 37; CIMA | PARTIAL | regulatory_report_definitions/runs, trust/regulatory-report-* routes; regulatory_reporting_mappings (WIP) | Lineage; templates | REQ-CIMA-001 | app/Application/Compliance/RegulatoryReportingService (stub) |
| REQ-RPT-002 | Regulatory change engine: regulatory_rules DRAFT→REVIEWED→APPROVED→EFFECTIVE→SUPERSEDED with impact analysis | ICE E4 (gap 2); BP I | PARTIAL | regulatory_reference_sets(+approve); RegulatoryVersioningService (WIP) | Impacts table + analyzer | REQ-CIMA-001, REQ-CAS-001 | app/Application/Regulatory |
| REQ-RPT-003 | KPI governance (name, definition, formula, sources, date basis, filters, currency, owner, version) | MPS §88; FRP IX | MISSING | report_definitions | KPI catalogue | — | app/Application/Reporting (empty) |
| REQ-RPT-004 | 26 dashboards with the 20-component framework and record drill-down | DSH; STA dashboards/* | PARTIAL | mobile/{agent,broker,carrier}/dashboard, web-experiences/{portal}/dashboard, dashboard_snapshots | Drill-down fails everywhere (MSG) | REQ-RPT-003, REQ-UI-002 | — |
| REQ-RPT-005 | Reporting families REG-001..008 + finance reports | ESR REG | PARTIAL | reports/insurance-portfolio, reports/renewals, report_exports | — | REQ-RPT-003 | — |
| REQ-RPT-006 | Regulatory inspection workspace (read-only over evaluations/audit); policy-level profitability | ICE gaps 38, 13 | MISSING | — | — | REQ-ENG-001 | — |

### W24 — Developer platform

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-API-001 | Versioned base /api/v1 | BP IV | EXISTS | 448 api routes under api/v1 | — | — | — |
| REQ-API-002 | Mutation headers: Idempotency-Key, Correlation-ID, If-Match/version (optimistic concurrency) | BP IV; STA rule 3; SSR stale state | PARTIAL | Idempotency middleware on some routes; no If-Match anywhere | Concurrency + correlation middleware | REQ-IDM-001 | app/Http/Middleware |
| REQ-API-003 | RFC 9457 Problem Details errors (type, title, status, code, detail, field_errors, correlation_id) | BP IV; STA rule 3 | MISSING | Laravel default validation JSON | Exception renderer | — | bootstrap/app.php |
| REQ-API-004 | Endpoint families BP §136–155 and owner product/runtime routes (see API delta) | BP IV; PRE §91; STA rule 5 | PARTIAL | See API delta table | Missing families listed there | various | routes/* |
| REQ-API-005 | Webhooks: subscriptions, deliveries, retry, dead-letter, replay | BP IV §156–157; ESR DEV-009..011 | EXISTS | integration_webhook_subscriptions, integration_delivery_attempts, delivery-attempts/{a}/replay | — | — | — |
| REQ-API-006 | OpenAPI + developer portal DEV-001..014, sandbox, usage/rate limits, API consent & scoped delegated access | ESR DEV; ICE gap 41 | PARTIAL | docs/api/openapi*.yaml (per wave, not generated), integration_clients lifecycle | Unified generated OpenAPI; portal | REQ-IAM-004 | docs/api, app/Application/Integrations |
| REQ-API-007 | Carrier integration framework: connector config, signing, retries, manual fallback, external_record_mappings sync/conflicts | PREP C1–C3; AOM L4–5 | PARTIAL | carrier_exchange_messages, external_record_mappings, partner/record-mappings | Connector config + fallback | REQ-AOM-002 | app/Application/Integrations |
| REQ-API-008 | Pagination contract for lists | AUD §23 | PARTIAL | Paginated responses on some lists; mobile expected arrays (AUD A6) | Uniform envelope | — | — |

### W25 — Mobile / Expo

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-MOB-001 | Customer mobile journey complete (home, categories, quote, compare, proposal, pay, wallet, renewals, claims) per checklist | AUD C; MSR CUST | PARTIAL | 140 expo-router screens; audit verdict not production-ready | See AUD H order | many | mobile app/app |
| REQ-MOB-002 | Motor wizard with vehicle master cascading make→model | AUD C; MPS §16 | WIP | mobile app assets/new.tsx modified (uncommitted in mobile repo) | — | REQ-VEH-001 | mobile app/app/assets |
| REQ-MOB-003 | Offline: drafts for FNOL/evidence/inspection only; never finalize financial/regulatory actions offline; customer drafts sync | SSR offline; LOCK-016; AUD A11 | PARTIAL | sync_operations, mobile/sync, offline queue | Customer sync permission | REQ-IDM-001 | mobile app/src/offline |
| REQ-MOB-004 | EN/FR everywhere (titles, statuses, notifications, documents) | LOCK-004; SSR localization; AUD §21 | PARTIAL | ~4/121 app screens translated; backend lang files | Full i18n | REQ-CIMA-004 | mobile app/src/i18n, resources/lang |
| REQ-MOB-005 | Partner role workspaces complete (agent, broker, insurer menus with actions) | AUD D; MSG | PARTIAL | mobile/agent, mobile/broker (read-only), mobile/carrier + mobile/partner/* | Broker actions, agent proposal/claims | REQ-RBAC-002 | mobile app/app/{agent,broker,carrier} |
| REQ-MOB-006 | Route namespace decision (SSR /app/... vs Expo paths) | SSR open decision | PARTIAL | Deep links map /app/... to in-app paths | Owner decision | — | — |
| REQ-MOB-007 | Release: app links/AASA, iOS, crash reporting, staging profile | AUD A3, A13, §25 | PARTIAL | APK sideload; eas.json single target | — | REQ-SEC-002 | mobile app/eas.json, public/.well-known |
| REQ-DUP-012 | Three mobile partner route families | Coordinator scope (zero duplication); code inspection | DUPLICATE | mobile/agent/* (wave14) + wave12_agentmode + mobile/partner/agent/* (wave16); mobile/broker/* vs mobile/partner/broker/*; mobile/carrier/* vs mobile/partner/carrier/* | Canonical: Single `mobile/partner/{role}/*` family; keep one controller per role | REQ-MOB-005 | routes/wave12_*, wave14_mobile, wave16_partner; mobile app/src/api |

### W26 — Migration & legacy data

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-IMP-001 | Import framework CSV/XLSX/JSON/API: upload→map→validate→duplicates→preview→approve→import→audit for products, tariffs, portfolio, customers, vehicles, claims, renewals, commissions, agreements, agents (pull forward per AOM) | AOM Excel import; MPS §91; MDC operations; PREP A7; LOCK-015 | PARTIAL | master_data_imports, reconciliation_imports (entity-specific) | Generic pipeline; XLSX | REQ-MDM-001 | app/Application/Import (new) |
| REQ-IMP-002 | Legacy integration & data migration with reconciliation reports | BP W26; MPS §92 | MISSING | — | — | REQ-IMP-001 | — |

### W27 — Production hardening

| ID | Requirement | Sources | Status | Evidence | Gap | Depends on | Owner area |
|---|---|---|---|---|---|---|---|
| REQ-OPS-001 | Operations console OPS-001..020 (health, queues, failed jobs, integration/payment/webhook monitoring, correlation view, incidents) | ESR OPS | PARTIAL | IntegrationHealth page, failed_jobs, telemetry_events, mobile_issue_reports | Horizon not configured; most screens missing | REQ-NFR-001 | app/Filament/Admin/Pages |
| REQ-OPS-002 | DR/backup with RPO/RTO and restore tests | BP XI; MPS §115 | PARTIAL | recovery_exercises, release-assurance routes | Automated restore test | — | — |
| REQ-OPS-003 | Release assurance gates | — | EXISTS | release_candidates, release_gate_results, certify route | — | — | — |
| REQ-OPS-004 | Production-ready 17 criteria + acceptance rule (happy path…historical reproduction) | MPS §114–115; PRE §101 DoD (38 items) | PARTIAL | Partial per module | Tracked through this matrix | all | — |
| REQ-OPS-005 | Failed-transaction recovery & operational exception queues | MPS §86; STA operations/exceptions | PARTIAL | integration delivery replay, webhook_inbox FAILED status | Unified exceptions API | REQ-CAS-001 | — |
| REQ-DUP-002 | Fulfilment routes registered twice | Coordinator scope (zero duplication); code inspection | DUPLICATE | FulfilmentController@store/@transition on fulfilment-orders and fulfilments | Canonical: Keep `fulfilment-orders` | — | routes/* |

## 3. Duplicates detected

### 3.1 Requirements restated across owner specs (merged into one REQ)

| Topic | Where it is restated | Merged into | Canonical choice |
|---|---|---|---|
| Maker-checker action lists | WRS WF-081; FRP VI; AOM strict; SCF governing rules; MPS §97 | REQ-RBAC-005 | Union of all lists, enforced via one approval matrix |
| Import pipeline | AOM; MDC operations; MPS §91; PREP A7 | REQ-IMP-001 (+REQ-MDM-007) | MDC flow (upload→map→validate→duplicates→preview→approve→import→audit) |
| Document statuses | DCP (VALID…CANCELLED); DCP register (DRAFT…CANCELLED); WRS WF-031 (requested/generated/active/revoked/replaced/expired) | REQ-DOC-003 | DCP register set; WRS names are UI labels |
| Claim / quote / proposal / payment / policy states | WRS canonical state models vs BP Part II §94–114 | REQ-CLM-001, QUO-001, PRP-001, PAY-001, POL-001 | Blueprint states win (ICE §0.8) |
| Eligibility outcomes | PRE §19 (5 outcomes) vs SCF §11 (ELIGIBLE/INELIGIBLE/UNDERWRITING_REFERRAL/REQUIRES_DOCUMENT) | REQ-RUL-003 | PRE set; REQUIRES_DOCUMENT → MORE_INFORMATION_REQUIRED |
| Coverage validation outcomes | WRS WF-050 (coverage_confirmed/question/not_confirmed) vs PRE §49 / ICE E2 | REQ-CLM-003 | PRE/ICE set (COVERAGE_CONFIRMED, REVIEW_REQUIRED, POTENTIAL_EXCLUSION, OUTSIDE_COVERAGE) |
| Reinsurance capacity check | MPS §39–40; ICE E7; LOCK-019 | REQ-CAT-002 | Single CapacityCheckService |
| Commission lifecycle | FRP I (SALE…PAID) vs SSR AGT-055 (Accrued, Earned, Pending, Paid, Reversed) | REQ-COM-001 | FRP states; SSR tabs are filters |
| Settlement lifecycle | SSR FIN-019 vs WRS WF-068 | REQ-STL-001 | SSR states |
| Product version vs governance states | MPS §11/PRE §8 (DRAFT…RETIRED) vs PRE §74 (7-step workflow) vs SCF (Draft→Review→Approved→Published) | REQ-PRD-001, REQ-PRD-007 | Version status = PRE §8; review steps = governance sub-status |
| Tariff workflow | PRE §75 vs SCF tariff rules | REQ-RAT-002 | PRE §75 |
| Authority types | BP III (14 types) vs ICE E3 (+ENDORSE, BACKDATE, CANCEL, OVERRIDE; OQ-3.4) vs SCF (may_quote…may_override) | REQ-AUTH-001 | BP 14 until owner answers OQ-3.4 |
| Case types | BP I (8 types) vs ICE §6.2 list | REQ-CAS-001 | BP 8 + sub-types (OQ-6.4) |
| Role profiles | BP III vs SCF §7 carrier roles vs FRP V provider roles vs ESR actors | REQ-RBAC-003 | One RoleCatalogue |
| Screen baselines | MSR 220 → ESR 428 → SCF 530 → CIMA 552 → MDC 572 → FRP 670 → DCP 690 | REQ-UI-002 | ESR working total 690; MPS §101 "~690" |
| Coverage-check endpoint | BP POST /coverage/check vs ICE POST /claims/{id}/coverage-evaluations vs PRE §91 POST /claims/coverage/evaluate | REQ-CLM-003 | POST /api/v1/coverage/check (others aliases only if needed) |
| Reserve endpoint | STA: /claims/{id}/set-reserve, POST /claims/{id}/reserves, /claim-reserves/{r}/increase/reduce | REQ-CLM-008 | Existing POST claims/{id}/reserves (+/approve) with movement type |
| Claim decision endpoint | STA POST /claims/{id}/decision vs existing claims/{id}/decisions | REQ-CLM-012 | Existing claims/{id}/decisions + /approve |
| Rating endpoint | PRE POST /insurance/rate vs existing POST quotes/{quote}/rate and mobile/quotes | REQ-RAT-001 | Existing quotes/{quote}/rate; /insurance/rate only as stateless preview |
| Underwriting evaluate | PRE POST /underwriting/evaluate vs underwriting/cases/{c}/decision | REQ-UW-002 | Add evaluate (system) ; decision stays human |
| Domain event names | WRS (PascalCase 26) vs PRE (15) vs ICE (<domain>.<noun>.<verb>) vs code (dotted) | REQ-ARC-004 | Dotted code names with WRS name as alias in catalogue |
| Renewal windows | WRS WF-039 vs SSR AGT-044 (90/60/30/15/7) | REQ-REN-001 | Same; configurable |
| Temporal / effective dating | MPS §23, LOCK-007/017; PRE; ICE E1; SCF | REQ-TMP-001 | ICE E1 |
| Controlled override | AOM; ICE §0.4; PREP A6; SSR BRK-035 | REQ-OVR-001 | engine_overrides/value_overrides single table |

### 3.2 Duplicated code in the repository (status DUPLICATE)

| ID | Duplicate | Evidence | Canonical choice | Files |
|---|---|---|---|---|
| REQ-DUP-001 | Support ticket routes registered twice | SupportController@store/@transition on POST support-tickets and POST support/tickets (+/{ticket}/transitions) | Keep `support-tickets`; delete `support/tickets` after mobile client switch | routes/api.php (+ wave route files), mobile app/src/api |
| REQ-DUP-002 | Fulfilment routes registered twice | FulfilmentController@store/@transition on fulfilment-orders and fulfilments | Keep `fulfilment-orders` | routes/* |
| REQ-DUP-003 | Communication preference routes registered twice | NotificationController@preference on PUT communication-preferences and PUT communications/preferences | Keep `communication-preferences` | routes/* |
| REQ-DUP-004 | Three document catalogue / requirement / pack models being built in parallel | WIP: 2026_09_29_100001_create_document_catalogue (document_types, document_packs, document_pack_items, document_requirement_matrix, product_document_requirements; DocumentCatalogueServiceProvider not in bootstrap/providers.php) vs WIP 2026_09_29_110001_create_document_engine (product_document_rules, document_pack_manifests; DocumentRegister reads JSON) vs committed document_requirement_versions + DocumentRequirementService | One catalogue: document_types + document_packs/items + product_document_requirements (definitions); document engine consumes them; document_pack_manifests only for generated pack instances; retire product_document_rules and document_requirement_versions via data migration | app/Application/Documents/*, app/Models/DocumentCatalogue, migrations |
| REQ-DUP-005 | Certificate subsystem parallel to the document engine | certificate_templates, policy_certificates, certificates API, CertificateController vs document_templates/documents (engine) | Certificates/attestations become document types issued by the engine; certificate tables kept read-only for history | app/Application/Certificates, Documents/Engine |
| REQ-DUP-006 | Two claim state definitions | app/Domain/Claims/ClaimStateMachine.php and ClaimLifecycle.php | Single ClaimStateMachine on the generic engine (REQ-WFL-001) using blueprint states; app status vocabulary aligned | app/Domain/Claims, mobile app claim screens |
| REQ-DUP-007 | Parallel mobile vs core application services | MobileQuoteService/QuoteService, MobileProposalService/ProposalService, MobileClaimService+MobileClaimEvidenceService/ClaimLifecycleService+ClaimEvidenceService, MobileKycService, MobileDocumentService, MobilePolicyServiceController on two routes | Core services are canonical; mobile controllers become thin adapters/presenters (no business rules in Mobile* services) | app/Application/{Quotes,Underwriting,Claims,Kyc,Documents} |
| REQ-DUP-008 | Bordereaux handled by three controllers | bordereaux/* (FinancialDistributionController), broker/bordereaux/* (BrokerOperationsController), carrier/bordereaux/{b}/decision (CarrierOperationsController) | One `bordereaux` resource; broker/carrier actions authorized by role/scope, not separate paths | routes, app/Application/FinancialDistribution |
| REQ-DUP-009 | Compliance endpoints duplicated under trust/* | compliance/privileged-access vs trust/privileged-access; compliance/data-subject-requests vs trust/data-subject-requests; risk-alerts vs trust/fraud-alerts (ComplianceController, RiskAlertController, Wave9Controller) | Keep `compliance/*` (+ `risk-alerts`); retire trust/* equivalents and Wave9Controller | routes, app/Interfaces/Http/Controllers/Api/V1/Trust |
| REQ-DUP-010 | Renewal seeding twice | POST renewals/seed (RenewalController) and POST broker/renewals/seed (BrokerOperationsController) | Keep `renewals/seed` (scheduled job); remove broker variant | routes, BrokerOperations |
| REQ-DUP-011 | Settlement data behind two controllers | GET settlements/{batch} (SettlementController) and carrier-settlements/* (FinancialDistributionController); partner_statements/payouts for commission | carrier-settlements for carrier; partner-statements for commission; drop `settlements/{batch}` after verifying same table | routes, app/Application/Settlements |
| REQ-DUP-012 | Three mobile partner route families | mobile/agent/* (wave14) + wave12_agentmode + mobile/partner/agent/* (wave16); mobile/broker/* vs mobile/partner/broker/*; mobile/carrier/* vs mobile/partner/carrier/* | Single `mobile/partner/{role}/*` family; keep one controller per role | routes/wave12_*, wave14_mobile, wave16_partner; mobile app/src/api |
| REQ-DUP-013 | Master-data suggestion posted on two paths | MasterDataController@suggest on master-data/suggestions and mobile/master-data/review; mobile/vehicles/master-review separate | Keep `master-data/suggestions` (vehicles as a domain) | routes/master_data.php, routes/vehicles.php |
| REQ-DUP-014 | Policy service request on two paths | MobilePolicyServiceController@store on mobile/policy-service-requests and policies/{policy}/service-requests | Keep `policies/{policy}/service-requests` | routes |
| REQ-DUP-015 | Three public verification entry points | POST public/certificates/verify (CertificateController), POST public/insurance/verify, GET /verify (web) | One PublicVerificationService + one API `public/verify` used by the web page | app/Application/Certificates, routes |
| REQ-DUP-016 | Audit store vs blueprint name | audit_log (hash chain) vs blueprint audit_events | Keep audit_log physically (ADR), expose audit_events view; no second table | docs/adr, migrations |
| REQ-DUP-017 | Two insurer authorization models | insurer_authorizations (IARD/LIFE per register year, committed) vs insurer_regulatory_authorizations + insurer_authorized_branches (WIP) | insurer_regulatory_authorizations + authorized_branches canonical; insurer_authorizations kept as register-year source feeding it | app/Application/Regulatory, migrations |
| REQ-DUP-018 | Three classification taxonomies | insurance_lines (flat, product line_code), insurance_classes (register), regulatory_branches/regulatory_class_defaults (WIP); blueprint name insurance_branches | ADR: regulatory_branches = blueprint insurance_branches; insurance_classes = platform class; insurance_lines mapped then retired | app/Application/Catalogue, Regulatory |
| REQ-DUP-019 | Tables declared in two migrations | support_tickets, notification_templates, notification_deliveries, couriers, communication_preferences (+ support_ticket_events, fulfilment_events, fulfilment_orders) created in batch_five and again in wave_eight/core behind hasTable guards | Leave history; document single owner migration; add schema test | database/migrations (no edits to applied migrations) |
| REQ-DUP-020 | Four risk-question sources | RiskSchemaCatalogue (hard-coded, WIP edit), NonMotorRiskSchemas, MotorRiskSchema, insurance_lines.risk_schema, disclosure_schema_versions | product_questions per product version (REQ-RUL-001); PHP schemas become seed data | app/Application/Catalogue, Vehicles |
| REQ-DUP-021 | Five evidence/document stores | documents vs claim_documents, kyc_submission_documents, risk_asset_documents, proposal_documents | documents canonical (with origin/stage); others become subject link tables | app/Application/Documents |
| REQ-DUP-022 | Fragmented work items | underwriting_referral_tasks, renewal_work_items, compliance_cases, support_tickets, financial cases | cases + tasks (REQ-CAS-001) with bridges; keep tables as projections during migration | app/Application/Cases |
| REQ-DUP-023 | Agreement/authority tables overlapping | delegated_authority_agreements + AuthorityChecker vs seed-spec carrier_broker_agreements vs blueprint authority_profiles/limits | delegated_authority_agreements → carrier_broker_agreements (distribution) + authority_limits (authority); one AuthorityService | app/Application/CarrierOperations, Authority |

Rule for builders: before creating a table, route, service or Filament resource, search this section and section 1.1. Where a canonical choice exists, extend it. Do not add a parallel one.

## 4. Recommended batch plan

Rules: batches run in order, and the agents inside a batch run in parallel. Each agent has its own file territory, so no two agents in a batch touch the same files. Batches contain only consolidation (DUPLICATE) and MISSING/PARTIAL work. WIP streams stay with their current owners until committed. Batch 3 starts only after they land. Shared files are serialized: `routes/api.php`, `bootstrap/providers.php`, `app/Models/User.php` and `database/seeders/DatabaseSeeder.php` belong to the one agent named in the batch. Everyone else registers routes through their own route file and service provider (the pattern `routes/regulatory.php` already uses). Every agent ships additive migrations, tests tagged with REQ IDs, and verification on a real runtime.

### Batch 1 — W0 foundations (5 agents)
| Agent | REQs | Territory |
|---|---|---|
| 1A Temporal | REQ-TMP-001, REQ-TMP-002 | app/Domain/Shared/Clock*, app/Application/Temporal (new), CancellationCalculator refactor |
| 1B Engine envelope + audit | REQ-ENG-001, REQ-OVR-001, REQ-AUD-001, REQ-AUD-002, REQ-DUP-016 | app/Application/Audit, app/Application/Overrides (new), new migrations (append-only triggers) |
| 1C State engine + events | REQ-WFL-001, REQ-ARC-004 | app/Domain/Shared/StateMachine (new), app/Application/Events catalogue (definitions only; domains migrate in their own waves) |
| 1D API conventions | REQ-API-002, REQ-API-003, REQ-API-008, REQ-IDM-001, REQ-NFR-001 | bootstrap/app.php (exception renderer), app/Http/Middleware (correlation, If-Match, idempotency coverage) |
| 1E ADRs + governance | REQ-ARC-005, REQ-ARC-008, REQ-ARC-007, REQ-DUP-018 (ADR only), REQ-DUP-019, REQ-TST-001 | docs/adr, tests/Architecture (arch tests: no now(), no direct status writes) |

### Batch 2 — W0/W1 platform core (5 agents)
| Agent | REQs | Territory |
|---|---|---|
| 2A Case/task/SLA core | REQ-CAS-001, REQ-CAL-001 | app/Application/Cases (new), migrations |
| 2B RBAC | REQ-RBAC-001, REQ-RBAC-002, REQ-RBAC-003, REQ-RBAC-004, REQ-TEN-003 | app/Application/Identity, app/Models/User.php, config/permissions.php, DatabaseSeeder (demo grants) |
| 2C Approvals | REQ-RBAC-005, REQ-RBAC-006, REQ-SET-005 | app/Application/Approvals (new) |
| 2D Organization | REQ-TEN-001, REQ-TEN-002, REQ-ORG-001, REQ-SEC-004 | app/Application/Tenancy, app/Application/Partners, app/Application/Settings |
| 2E Route consolidation + env separation | REQ-DUP-001, -002, -003, -010, -014, REQ-SEC-002 | routes/api.php + wave route files (owner of shared route files this batch), config/demo.php, mobile app/eas.json + src/api (path switch) |

### Batch 3 — W2 setup (after the CIMA, master-data, vehicle and document WIP is committed) (5 agents)
| Agent | REQs | Territory |
|---|---|---|
| 3A CIMA reconciliation | REQ-DUP-017, REQ-CIMA-005 (INS/BRK setup screens), REQ-CIMA-006, REQ-SEED-003 | app/Application/Regulatory, app/Filament/Admin/Resources/Cima* |
| 3B Master data completion | REQ-MDM-006, REQ-MDM-007, REQ-MDM-009, REQ-DUP-013, REQ-IMP-001 (generic pipeline, pulled forward per AOM) | app/Application/MasterData, app/Application/Import (new), app/Filament/Admin/Resources/MasterData* |
| 3C Document model consolidation | REQ-DUP-004, REQ-DUP-005, REQ-DUP-021, REQ-SET-006 | app/Application/Documents, app/Models/DocumentCatalogue, app/Application/Certificates |
| 3D Capability profile + setup lifecycles | REQ-AOM-001, REQ-SET-002, REQ-SET-003 | app/Application/Capabilities (new), app/Application/CarrierOperations/Setup (new), app/Application/Partners/Setup (new) |
| 3E Platform config + agreements | REQ-SET-001, REQ-SET-004, REQ-SEED-004, REQ-SEED-001, REQ-SEED-005, REQ-DUP-023 (agreement split) | app/Application/Configuration, app/Application/CarrierOperations/Agreements, app/Application/Demo, seeders |

### Batch 4 — W3 customer + experience shells (5 agents)
| Agent | REQs | Territory |
|---|---|---|
| 4A Golden record | REQ-PTY-002, REQ-PTY-003, REQ-PTY-004 | app/Application/Customers (roles, relationships, matching) |
| 4B KYC | REQ-KYC-001, REQ-KYC-002, REQ-KYC-003 | app/Application/Kyc |
| 4C CRM | REQ-CRM-001, REQ-CRM-002, REQ-CRM-003, REQ-CRM-004 | app/Application/Agents, PartnerWorkspace, Attribution, Customers/Beneficiaries (new) |
| 4D Insured objects + search | REQ-RSK-001, REQ-SRC-001 | app/Application/Risks, app/Application/Search (new) |
| 4E Web experience shell | REQ-UI-001, REQ-UI-002 | resources/views, app/Providers/Filament (new panels), app/Application/WebExperiences |

### Batch 5 — W4 product & rules (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 5A Product model | REQ-PRD-001…006 | app/Application/Catalogue (products, versions, plans, coverages, exclusions), migrations |
| 5B Rules engine | REQ-RUL-001…004, REQ-DUP-020 | app/Domain/Rules (new), RiskSchemaCatalogue/NonMotorRiskSchemas → seed data |
| 5C Rating v2 | REQ-RAT-001…005 | app/Domain/Rating, app/Application/Rating, QuoteService rating call only |
| 5D Distribution + adapters | REQ-DST-001, REQ-DST-002, REQ-AOM-002 | app/Application/Distribution (new), app/Application/*/Adapters registries |

### Batch 6 — W4/W5 governance, quote, proposal (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 6A Product governance | REQ-PRD-007…010 | app/Application/Catalogue/Governance, Sandbox (new), product builder screens |
| 6B Quote | REQ-QUO-001…005, REQ-DST-003, REQ-DUP-007 (quotes part) | app/Application/Quotes (QuoteService + MobileQuoteService merge) |
| 6C Manual quotation (Mode 1) | REQ-QUO-006 | app/Application/CarrierOperations/QuoteRequests (new) |
| 6D Proposal | REQ-PRP-001…005, REQ-DUP-007 (proposal part) | app/Application/Underwriting/ProposalService, MobileProposalService |

### Batch 7 — W6/W7 authority, underwriting, policy core (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 7A Authority | REQ-AUTH-001…003, REQ-DUP-023 (authority part) | app/Application/Authority (new), CarrierOperations/AuthorityChecker |
| 7B Underwriting | REQ-UW-001…005 | app/Application/Underwriting (except ProposalService) |
| 7C Policy chronology | REQ-POL-001, REQ-POL-002, REQ-POL-003 | app/Domain/Policies, PolicyIssuanceService snapshot, migrations |
| 7D Issuance ops | REQ-POL-004, REQ-POL-007 | PaymentIssuanceTrigger, policy_issuance_requests queue API, sticker allocation (Logistics/Motor) |

### Batch 8 — W7/W8 servicing + documents (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 8A Endorsement/cancellation/suspension | REQ-END-001, REQ-CAN-001, REQ-POL-006 | PolicyServicingService, CancellationCalculator |
| 8B Renewal + premium-to-cover | REQ-REN-001, REQ-POL-008, REQ-POL-010 | RenewalService, premium_cover_rules |
| 8C Special products + transfer | REQ-PRD-011, REQ-POL-009 | app/Application/Policies/Special (new), PortfolioTransfer (new) |
| 8D Document completion | REQ-DOC-007…012, REQ-DUP-015 | app/Application/Documents, Certificates, public verification routes |

### Batch 9 — W9 money chain (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 9A Obligations | REQ-OBL-001, REQ-PAY-004, REQ-PAY-005, REQ-PAY-006 | app/Application/Finance/Obligations (new) |
| 9B Payments | REQ-PAY-001, REQ-PAY-003, REQ-PAY-008, REQ-PAY-014 | app/Domain/Payments, app/Application/Payments (adapters, WebhookProcessingService) |
| 9C Reconciliation + refunds | REQ-PAY-007, REQ-PAY-009, REQ-PAY-011 | app/Application/Reconciliation, Refunds (new service) |
| 9D Cash, FX, statements | REQ-PAY-010, REQ-PAY-013, REQ-PAY-015 | app/Application/Finance/Cashier, Fx, Statements (new) |

### Batch 10 — W10/W11 commission, settlement, accounting (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 10A Commission | REQ-COM-001…003 | app/Application/FinancialDistribution (commission), Commissions |
| 10B Settlement consolidation | REQ-STL-001, REQ-DUP-008, REQ-DUP-011 | app/Application/Settlements, bordereaux routes/controllers |
| 10C Ledger | REQ-ACC-001…004 | app/Domain/Ledger, app/Application/Ledger |
| 10D Finance screens | REQ-ACC-005 | app/Filament/Admin/Resources (finance), finance reports |

### Batch 11 — W12 claims foundation (5 agents)
| Agent | REQs | Territory |
|---|---|---|
| 11A Claim machine | REQ-CLM-001, REQ-CLM-002, REQ-CLM-014, REQ-DUP-006, REQ-DUP-007 (claims part) | app/Domain/Claims, ClaimLifecycleService, MobileClaimService, mobile claim screens (status names) |
| 11B Coverage-at-loss | REQ-CLM-003 | app/Application/Claims/Coverage (new) |
| 11C Limits + reserves | REQ-CLM-004, REQ-CLM-008 | app/Application/Claims/Limits (new), reserve endpoints |
| 11D Evidence, types, parties | REQ-CLM-005, REQ-CLM-006, REQ-CLM-007 | ClaimEvidenceService, claim types config |
| 11E Adjusters + assessment | REQ-CLM-009, REQ-CLM-010, REQ-CLM-011 | app/Application/Claims/Assessment (new), ClaimCarrierExchangeService |

### Batch 12 — W13/W14/W20/W21 decisions, recovery, cases (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 12A Decision + settlement | REQ-CLM-012, REQ-CLM-013 | ClaimPaymentService, claim decisions |
| 12B Recovery + litigation | REQ-REC-001…003 | app/Application/Claims/Recovery (new), case type definitions |
| 12C Cases UI + complaints | REQ-CAS-002, REQ-CPL-001, REQ-COR-001, REQ-DUP-022 | app/Application/Cases (bridges), Complaints (new), Correspondence (new) |
| 12D Fraud | REQ-FRD-001, REQ-FRD-002 | app/Application/Fraud |

### Batch 13 — W15/W17/W18 ecosystem foundations (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 13A Provider master + network | REQ-PRV-001, REQ-PRV-002, REQ-PRV-004 | app/Application/Providers (new) |
| 13B Provider portal | REQ-PRV-003 | provider roles + portal screens |
| 13C Treaties + cessions | REQ-REI-001, REQ-REI-002 | app/Application/Reinsurance (new) |
| 13D Co-insurance | REQ-COI-001 | app/Application/Coinsurance (new) |

### Batch 14 — W16/W17 cashless + reinsurance operations (3 agents)
| Agent | REQs | Territory |
|---|---|---|
| 14A Eligibility + preauth | REQ-HLT-001, REQ-HLT-002 | app/Application/Health (new) |
| 14B Provider claims + accumulator | REQ-HLT-003, REQ-HLT-004 | app/Application/Health/ProviderClaims, Benefits |
| 14C Facultative + recoveries | REQ-REI-003, REQ-REI-004 | app/Application/Reinsurance (placements, recoveries) |

### Batch 15 — W19/W22 compliance + accumulation (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 15A Screening | REQ-AML-001, REQ-KYC-004 | app/Application/Compliance/Aml (screening) |
| 15B AML risk + STR | REQ-AML-002, REQ-AML-003 (thresholds only after OQ-5.2) | app/Application/Compliance/Aml (risk, str) |
| 15C Compliance cases | REQ-CMP-001, REQ-CMP-003, REQ-DUP-009 | app/Application/Compliance, trust/* route retirement |
| 15D Accumulation + capacity | REQ-CAT-001…003 | app/Application/Accumulation (new) |

### Batch 16 — W23/W24 reporting + developer platform (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 16A Regulatory returns + change engine | REQ-RPT-001, REQ-RPT-002, REQ-RPT-006 | app/Application/Regulatory (change), Compliance/RegulatoryReportingService |
| 16B KPIs + dashboards | REQ-RPT-003, REQ-RPT-004, REQ-RPT-005 | app/Application/Reporting (empty today) |
| 16C Developer platform | REQ-API-006, REQ-API-007, REQ-IAM-004 | app/Application/Integrations, docs/api (generated OpenAPI) |
| 16D Missing API families | REQ-API-004 | routes per family (coverage/check, search, complaints…) mapped in 1.4 |

### Batch 17 — W25–W27 mobile, migration, hardening (4 agents)
| Agent | REQs | Territory |
|---|---|---|
| 17A Customer app | REQ-MOB-001, REQ-MOB-003, REQ-MOB-004, REQ-NOT-001 | mobile app/app (customer), src/i18n, app/Application/Notifications |
| 17B Partner app | REQ-MOB-005, REQ-DUP-012 | mobile app/app/{agent,broker,carrier}, routes/wave12_*/wave14/wave16 |
| 17C Legacy migration | REQ-IMP-002 | app/Application/Import (legacy adapters) |
| 17D Hardening | REQ-OPS-001, REQ-OPS-002, REQ-OPS-004, REQ-OPS-005, REQ-SEC-001, REQ-SEC-003, REQ-SEC-005, REQ-MOB-006, REQ-MOB-007 | app/Filament/Admin/Pages (ops), mobile security, release config |

Not batched: EXISTS rows. WIP rows (REQ-CIMA-001…004, REQ-MDM-001…005/008, REQ-VEH-001, REQ-DOC-001…006, REQ-POL-005, REQ-RUL-001 edit, REQ-MOB-002, REQ-PRV-001) stay with the agents currently holding them. Batch 3 then consolidates them. REQ-ARC-001/002 (layering and the mutation pipeline) are carried by every agent as they touch each domain, with arch tests from 1E.

## 5. Owner inputs blocking requirements
OQ Q1 CIMA mappings (REQ-CIMA-003) · Q2 insurer authorized branches (REQ-CIMA-002, blocks publication) · OQ-9 tax/levy rates (REQ-RAT-003) · OQ-24 branch premium split (REQ-RAT-005) · DRM §42+ (REQ-DOC-005) · OQ-3.4 authority types (REQ-AUTH-001) · OQ-6.4 case types (REQ-CAS-001) · Reg. 003-25 / 010-24 texts (REQ-AML-*, REQ-CMP-003) · complaint deadlines (REQ-CPL-001) · premium-to-cover legal basis (REQ-POL-008) · staging vs demo-off decision (REQ-SEC-002) · SSR route namespace (REQ-MOB-006) · ADRs UUID keys / audit_events / insurance_branches naming (REQ-ARC-005, REQ-DUP-016, REQ-DUP-018).

## Addendum 2026-09-25 (owner)
| REQ | Requirement | Status | Batch |
|---|---|---|---|
| REQ-TMP-003 | Timezone is configurable. Platform default Africa/Douala. Each tenant (insurer/broker/organization) chooses its timezone in Settings; branch timezone (tenant_branches.timezone, exists) overrides tenant; users may choose a display timezone. BusinessTime/BusinessCalendar resolve business dates in the tenant/branch timezone, not a hard-coded Douala; storage stays UTC timestamptz with explicit offsets (fix Eloquent offset-less writes). Settings screen (admin + mobile profile) with IANA timezone picker from master data geography timezones. | PARTIAL (branch column + engine default only) | Batch 2 agent 2D |
