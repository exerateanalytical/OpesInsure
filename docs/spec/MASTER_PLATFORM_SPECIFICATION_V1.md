# OpesInsure Master Platform Specification v1.0 — LOCKED (owner, 2026-09-24)

**Status: formally scoped.** From this point, additions are handled as **CHANGE REQUESTS** against this specification (see CHANGE_REQUEST_LOG.md), not casual architecture changes. The full 120-section text was supplied by the owner in session 2026-09-24; this file is the canonical record and index to the detailed specs.

## Definition and positioning
Multi-tenant insurance operating platform for insurers, brokers, agents, intermediaries, corporate and individual customers, claims professionals, providers, garages, adjusters, reinsurers and partners. Initial market Cameroon; CIMA framework; CEMAC/CIMA expansion. "OpesInsure models the CIMA insurance framework while adapting to each insurer's and broker's existing operating model and digital maturity."

## Architecture principles
API-first / API-as-a-product, multi-tenant, DDD, event-driven where appropriate, CQRS where justified, EN/FR, mobile-ready, auditable, versioned, effective-date aware, security-first, financially reconcilable, regulator-ready, integration-friendly. Laravel + PostgreSQL + Redis + queues + outbox + object storage + workers; Expo/React Native mobile.

## Operating model (§3–5)
Capability profile per insurer with MANUAL / CONFIGURED / HYBRID / REMOTE_API per capability (products, quotation, rating, underwriting, payment, issuance, documents, claims, renewal, commission, accounting, provider management, reinsurance, regulatory reporting). Maturity LEVEL_1_REGISTRY … LEVEL_5_INTEGRATED (not a quality ranking). Execution adapters: QuoteProvider, UnderwritingProvider, PolicyIssuer, ClaimProvider (details: ADAPTIVE_OPERATING_MODEL_V1.md).

## Lifecycle and mutation rule (§6–7)
Need → Lead → Customer/KYC → Discovery → Quote → Proposal → Underwriting → Final terms → Payment → Issuance → Documents → Servicing → Endorsement → Renewal → FNOL → Evidence → Assessment → Decision → Settlement → Closure → Renewal/Expiry/Termination. Mutation: Request → AuthN → AuthZ → Validation → Domain command → Transition guard → Transaction → Persist → Domain event → Outbox → Integration/Notification → Audit → Response. Never button → direct status update.

## Domain rules by section
- Actors (§8); product hierarchy CIMA branch → class → family → carrier product → version → plan → coverage, regulatory ≠ platform category ≠ commercial product (§9); product engine contents (§10); versions DRAFT/REVIEW/APPROVED/PUBLISHED/SUSPENDED/RETIRED, reproducible (§11); Quote ≠ Proposal ≠ Policy (§12); Policyholder ≠ Insured ≠ Beneficiary (§13). Details: PRODUCT_RULE_ENGINE_SPEC_V1.md.
- Master data, fallback rule, vehicles, property, health, life, other insurable objects (§14–20). Details: INSTITUTIONAL_MASTER_DATA_CATALOGUE_V1.md, vehicle and master-data files.
- CIMA layer (invisible to customers/agents), regulatory change engine, temporal engine, coverage-at-loss, limit/aggregate engine (§21–25). Details: CIMA_REGULATORY_DICTIONARY_V1.md, INSURANCE_CONTROL_ENGINES_GAPS_V1.md / _SPEC_V1.md.
- Underwriting decisions and explainable structured rules; delegated authority separate from RBAC; escalation never a bare error (§26–28).
- Payments, allocation (many-to-many), premium-to-cover rules, finance chain, receivables, payables, commission lifecycle, settlements, accounting events mapped to tenant GL, journal states (§29–38). Details: FINANCE_REINSURANCE_PROVIDER_EXPANSION_V1.md.
- Reinsurance incl. capacity check at underwriting (WITHIN_RETENTION / TREATY_COVERED / CAPACITY_EXCEEDED / FACULTATIVE_REQUIRED); co-insurance ≠ reinsurance (§39–41).
- Claims scope, generic ClaimParty, event-based reserves, fraud indicators = REVIEW_REQUIRED never FRAUD_CONFIRMED, recovery EXPECTED/RECEIVED/OUTSTANDING/DISPUTED/CLOSED, litigation (§42–47).
- Health provider network, credentialing, cashless and reimbursement on one benefit engine, versioned provider tariffs (§48–52).
- Documents: 220 canonical types, architecture, packs, governance, security levels, integrity, contract legal timeline (§53–59). Details: DOCUMENT_CATALOGUE_BY_POLICY_TYPE_V1.md, DOCUMENT_REQUIREMENT_MATRIX_V1.md, document_register_220_2026.json.
- AML/CFT, UBO graph, market conduct evidence, complaints with regulatory escalation, case engine, task/SLA engine, business calendar, correspondence registry, golden records, duplicate resolution (no auto-merge), relationship graphs, consent registry, retention/legal hold, evidence integrity, document intake, accumulation & catastrophe, multi-currency FX, product governance, portfolio transfers, regulatory returns with lineage, inspection workspace, ICT governance, vendor management, API scoping, operational queues, failed-transaction recovery (§60–86).
- Reporting families and KPI governance (name, definition, formula, sources, date basis, filters, currency, owner, version) (§87–88).
- Demo data rules (is_demo, DEMONSTRATION watermark) and data classifications REGULATORY / CARRIER_PUBLISHED / PLATFORM_NORMALIZED / DEMO_SYNTHETIC (§89–90). Details: INSTITUTIONAL_SEED_SPEC_V1.md.
- Import (CSV/XLSX/JSON/API; upload → map → validate → duplicates → preview → approve → import → audit), legacy integration, search, RBAC dimensions, segregation of duties, audit fields and append-only high-risk events, idempotency list, offline limits, EN/FR (§91–100).
- Baselines: ~690 canonical screens (planning baseline, reusable shells mandatory) and 220 document types (§101–102).

## 36 shared engines (§103)
Identity & Access · Tenant · Master Data · Product · Rating · Eligibility · Underwriting · Delegated Authority · Workflow/State · Temporal Rule · Coverage-at-Loss · Limit/Aggregate · Payment · Commission · Accounting · Settlement · Claims · Recovery · Reinsurance · Co-insurance · Provider Network · Document · Notification · Case Management · Task/SLA · Correspondence · Consent · AML/CFT · Fraud Indicator · Regulatory Change · Regulatory Reporting · Audit · Search · Import/Migration · Integration Adapter · Catastrophe/Accumulation.

## Build priorities (§105)
Foundation 1–7 (identity/tenant, RBAC/authority, master data, workflow/state, temporal, audit, customer/organization master) → Insurance core 8–15 (product, rating, eligibility, underwriting, quote, proposal, policy, documents) → Money 16–21 (payments, obligations, reconciliation, commission, accounting, settlement) → Claims 22–30 (FNOL, coverage-at-loss, limits, case, reserve, assessment, decision, settlement, recovery) → Ecosystem 31–36 (providers, cashless, reinsurance, co-insurance, complaints, AML) → Enterprise 37–42 (regulatory reporting, catastrophe, migration, APIs, analytics, inspection readiness).

## Never hard-coded (§106) / always strict (§107) / production data rule (§108)
Rates, thresholds, claim authority, commissions, products, numbering, provider tariffs, retention, taxes, regulatory and KYC thresholds, medical requirements, cancellation rules, waiting periods → configurable and versioned. Tenant isolation, authorization, policy/claim identity, payment integrity, audit, versions, provenance, reconciliation, commission traceability, reproducibility, regulatory classification, segregation of duties → mandatory. Unknown institutional facts → NULL / UNVERIFIED / PENDING_VERIFICATION, never invented.

## Activation gates (§109–113)
Product, insurer, broker, provider and reinsurance-treaty gates as listed by the owner.

## Acceptance (§114–115)
A workflow is complete only with happy path + validation + permissions + transitions + failures + retries + audit + notifications + financial effects + documents + reporting + historical reproduction. Production-ready criteria: 17 items (state machines tested, privileged actions authorization-tested, reconciliation, provenance, versioned CIMA mappings, fallback per capability, idempotent mutations, failure handling, valid test policy pack per product, coverage-at-loss reproducible, historical policy reconstruction, payment → settlement/accounting trace, explainable commissions, traceable regulatory figures, tenant leakage tests, DR restore tests, immutable critical audit).

## Locked decisions (§116)
LOCK-001 CIMA-aligned, insurer-adaptive · LOCK-002 manual/configured/hybrid/API first-class · LOCK-003 master data before free text · LOCK-004 EN/FR throughout · LOCK-005 Quote, Proposal, Policy separate · LOCK-006 Policyholder, Insured, Beneficiary separate · LOCK-007 all regulatory/product/tariff/template rules effective-dated · LOCK-008 financial results from transactions · LOCK-009 human-governed claim judgment · LOCK-010 fraud indicators ≠ fraud determination · LOCK-011 documents versioned and non-destructively replaced · LOCK-012 220 document types baseline · LOCK-013 ~690 screens planning baseline · LOCK-014 adapters isolate carrier maturity · LOCK-015 Excel/CSV/manual supported · LOCK-016 offline cannot bypass financial/regulatory controls · LOCK-017 temporal coverage-at-loss · LOCK-018 delegated authority separate from RBAC · LOCK-019 reinsurance capacity in underwriting · LOCK-020 one financial truth.

## External verification before production (§117)
Insurer catalogues, tariffs, underwriting rules, claims authority, commission agreements, statutory tax/levy rates, official templates, certificate numbering requirements, provider tariffs, treaty values, charts of accounts, actuarial tables, provider licences, intermediary authorizations, waiting periods, cancellation/refund formulas.

## Next phase (§120)
Master specification → **Domain data model** → State machines → RBAC + authority matrix → API contracts → Screen specifications → Document template specifications → Test matrix → Implementation waves.
