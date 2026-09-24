# OpesInsure — Insurance Control Engines Specification v1.0 (implementation-ready)

Status: DRAFT for owner review · 2026-09-24 · Authoritative input: `INSURANCE_CONTROL_ENGINES_GAPS_V1.md` (owner). This spec comes before any further growth of the screen register. Screens are derived from the engines here (namespace `ENG-`), and the spec says which existing register IDs each screen replaces or extends.

Companion inputs: `ADAPTIVE_OPERATING_MODEL_V1.md` (execution modes and strict controls), `PRODUCT_RULE_ENGINE_SPEC_V1.md` + `PRODUCT_RULE_ENGINE_IMPLEMENTATION_PLAN.md` (expression engine, versioning, explainability), `CIMA_REGULATORY_DICTIONARY_V1.md`, `FINANCE_REINSURANCE_PROVIDER_EXPANSION_V1.md`, `WORKFLOW_REGISTER_SPEC.md`, `DOCUMENT_CATALOGUE_BY_POLICY_TYPE_V1.md`, `INSTITUTIONAL_MASTER_DATA_CATALOGUE_V1.md`, `docs/audit/*`.

---

## 0. Conventions that apply to every engine

### 0.1 The strict and flexible split (from the Adaptive Operating Model)
The engines implement the **strict** layer: tenant isolation, authorization controls, historical version preservation, maker-checker, idempotency, immutable history and audit. They are **never optional**, whatever capability mode an insurer uses. What changes between modes is *where the inputs come from*. For example, a MANUAL insurer's cover comes from an uploaded carrier document plus keyed-in limits, and an OPES_GENERATED policy's cover comes from the product-version snapshot. The *control* that runs on those inputs does not change. Every engine section has a "Behaviour in adaptive modes" subsection.

### 0.2 Common result envelope (deterministic and explainable)
Every engine evaluation returns the same envelope and persists it in `engine_evaluations` (see §0.4):
```
EngineResult {
  engine: string            // TEMPORAL | COVERAGE | AUTHORITY | REGCHANGE | AML | CASE | ACCUMULATION
  outcome: string           // engine-specific enum, e.g. COVERED | NOT_COVERED | REFER | BLOCK
  reference_at: timestamptz // the valid-time instant used for resolution
  recorded_as_of: timestamptz // the knowledge-time instant used (normally now(); a replay uses a past value)
  inputs_hash: sha256       // canonical JSON of the inputs
  trace: [ { rule_code, rule_version, source_table, source_id, condition, input_values, result, message_key } ]
  resolved_versions: { <artifact_type>: <artifact_version_id>, ... }  // from Engine 1
  blocking: bool, reasons: [reason_code], warnings: [reason_code]
}
```
Determinism rule: given the same `inputs_hash`, `reference_at`, `recorded_as_of` and `resolved_versions`, the engine returns a byte-identical `trace`. No engine reads `now()` except through the injected `Clock` (Engine 1).

### 0.3 Bitemporal pattern (used where history must be reconstructed)
- **Valid time**: `valid_from timestamptz NOT NULL` and `valid_to timestamptz NULL` (half-open `[from, to)`). This is when the fact is true in the insurance world.
- **Recorded time**: `recorded_at timestamptz NOT NULL DEFAULT now()` and `superseded_at timestamptz NULL`. This is when the platform knew the fact.
- Rows are never updated in place. A correction closes the old row (`superseded_at = now()`) and inserts a new one. A PostgreSQL trigger rejects `UPDATE` of any column other than `superseded_at` and rejects all `DELETE` (the same pattern as the existing `audit_log`, `regulatory_*` PROTECTED tables).
- Exclusion constraint where overlap is illegal: `EXCLUDE USING gist (key WITH =, tstzrange(valid_from, valid_to) WITH &&) WHERE (superseded_at IS NULL)` (requires `btree_gist`).
- Existing tables use **date-only, uni-temporal** columns: `effective_from`/`effective_until` on `regulatory_*`, `insurer_authorizations`, `intermediary_authorizations`, `fraud_rule_versions`, `regulatory_reference_sets`, `delegated_authority_agreements`, `certificate_templates`, and `cancellation_rule_versions`. Engine 1 treats these as valid time with day granularity, closed-closed `[from, until]`, in the tenant timezone (`Africa/Douala` default). They are **not migrated**. Recorded time for them comes from `created_at` and `updated_at` together with `audit_log`. A later epic (E1.3) adds `recorded_at` and `superseded_at` additively where replay-grade history is needed.

### 0.4 Shared tables (new, additive)
```
engine_evaluations(
  id uuid pk, tenant_id uuid null fk tenants, engine varchar(24), operation varchar(64),
  subject_type varchar(64), subject_id uuid, outcome varchar(32), blocking bool,
  reference_at timestamptz, recorded_as_of timestamptz, inputs_hash char(64),
  resolved_versions jsonb, trace jsonb, reasons jsonb default '[]',
  correlation_id varchar(64), actor_id uuid null fk users, created_at timestamptz
)  -- append-only trigger; index (subject_type, subject_id, engine, created_at desc)

engine_overrides(
  id uuid pk, tenant_id uuid, engine_evaluation_id uuid fk, override_type varchar(48),
  previous_outcome varchar(32), new_outcome varchar(32), reason_code varchar(64), justification text,
  requested_by uuid fk users, approved_by uuid null fk users, approved_at timestamptz null,
  authority_grant_id uuid null, status varchar(24) default 'REQUESTED', created_at timestamptz
)  -- implements "Controlled human override": previous value, new value, reason, user, authority, time, approval above threshold
```
Every override needs maker-checker (`approved_by <> requested_by`, enforced by a CHECK constraint and by the service). The one exception is an override inside the requester's own delegated authority (Engine 3). In that case `authority_grant_id` is recorded and approval is automatic.

### 0.5 Events
All engine events go through the existing outbox (`outbox_messages`) and are mirrored into the `audit_log` hash chain via `App\Application\Audit\AuditWriter` (`previous_hash` → `entry_hash`, sequence ordered). Names follow `<domain>.<noun>.<verb>`. The owner gaps list names no canonical event catalogue. The PRODUCT_RULE_ENGINE spec names 15 product events (ProductCreated … CommissionCalculated). This spec **adds** the engine events listed in each section. **OPEN QUESTION (OQ-0.1):** the owner referred to "required domain events". Confirm whether a separate authoritative list exists that these names must match.

### 0.6 Screen namespace
`ENG-<engine#>-<nnn>`. Existing families referenced: UND-001…020, CLP-001…, CMP-001…020, REG-001…, BRM-…, OPS-…, CAR-…, REI-001…026, FIN-X-…, PRV-… (see `ENTERPRISE_SCREEN_REGISTER_V1.md`, `FINANCE_REINSURANCE_PROVIDER_EXPANSION_V1.md`). By the owner's decision, no new register rows are created until this spec is approved.

### 0.7 Current code state (snapshot 2026-09-24; other agents are editing)
| Area | State | Location |
|---|---|---|
| Policy lifecycle | `PolicyStateMachine` has 10 states (PENDING_PAYMENT … LAPSED, SUSPENDED). Status is a single column. There is no valid-time cover history. `policies.coverage_starts_at/ends_at`, `terms_snapshot`, `terms_hash`, `version`, `previous_policy_id` | `app/Domain/Policies/PolicyStateMachine.php`, core migration |
| Policy transactions | `policy_transactions(type, status, effective_at, terms_before, terms_after, premium_delta_minor)` + `policy_status_history` + `policy_transaction_events`. This is the seed of the contract chronology | wave five migration, `PolicyServicingService` |
| Cancellation | `CancellationCalculator` already resolves `cancellation_rule_versions` by `effective_from/until` against the cancellation date. This is the **only** existing reference-date resolution and the pattern Engine 1 generalises | `app/Application/Policies/CancellationCalculator.php` |
| Claims | `ClaimLifecycle` (DRAFT → SUBMITTED → … → CLOSED/REOPENED), `ClaimStateMachine`, `claims.loss_occurred_at`, `claim_reserve_changes`, `claim_payments`, `claim_recoveries`, `claim_decisions`, `claim_disputes`, `claim_involved_parties`, `claim_evidence_custody_events`. There is **no** coverage-at-loss check: coverage is inferred from `policy.status` | `app/Domain/Claims/*` |
| Underwriting | `underwriting_cases(status, priority, referral_reasons, assigned_to, decision_due_at)`, `underwriting_referral_tasks`, `underwriting_decisions`. There is no authority check and no "return to originator" | wave three migration, `UnderwritingService` |
| Support | `TicketStateMachine` OPEN → TRIAGED → IN_PROGRESS → WAITING_CUSTOMER → ESCALATED → RESOLVED → REOPENED → CLOSED, `support_tickets`, `support_ticket_events` | `app/Domain/Support/TicketStateMachine.php` |
| Compliance/fraud | `compliance_cases(type, subject, status, severity, owner_id, review_due_on, findings jsonb)`, `risk_alerts(alert_type, risk_score, severity, signals, decision)`, `fraud_rule_versions(code, version, status, risk_points, conditions jsonb, rule_hash, effective_from/until)`, `privileged_access_grants`. `ComplianceCaseService` is a 3-line stub. `app/Application/Fraud` is empty | batch five migration |
| Authority | `delegated_authority_agreements(carrier_id, partner_id, permitted_lines, max_policy_premium_minor, max_claim_authority_minor, territories, effective_from/until, status)`. It has **no** enforcement caller and no consumption | batch six migration |
| Register | `insurer_authorizations(carrier_id, reference_year, branch, status, effective_from/until, source_authority, data_origin, is_official_register)`, `intermediary_authorizations(partner_id, intermediary_type, reference_year, status, effective_from/until)`, `partner_licences`, `tenant_branches` | 2026_09_26 migration (committed in dcb367e) |
| Regulatory dictionary | **IN PROGRESS (uncommitted, another agent)**: `regulatory_regimes`, `regulatory_branches`, `…_subclasses`, `microinsurance_branches`, `regulatory_reporting_categories`, `regulatory_terms`, `regulatory_authorities`, `legal_references`, `compulsory_insurance_rules`, `regulatory_class_defaults`, `insurer_regulatory_authorizations`, `insurer_authorized_branches`, `product_regulatory_mappings`, `regulatory_reporting_mappings`. These are versioned with `effective_from/until`, `regulatory_version`, `source_reference`, `status`, `is_seeded`. Services: `CimaAuthorizationService`, `CimaPublicationGuard`, `CimaProductMappingService`, `RegulatoryVersioningService`, `RegulatoryTerminologyService` | `database/migrations/2026_09_27_100001_*`, `app/Application/Regulatory/*` |
| Vehicle master / document engine | **IN PROGRESS (uncommitted)**: `2026_09_28_100001_create_vehicle_master_data`, `2026_09_29_110001_create_document_engine` | — |
| Calendar | `business_calendars(jurisdiction, year, holidays jsonb, timezone)` exists with **no** consumer | batch six migration |
| Regulatory reference | `regulatory_reference_sets(code, version, status, effective_from/until, entries jsonb, content_hash, approved_by)` | batch six migration |
| Audit | `audit_log` hash chain (`sequence`, `previous_hash`, `entry_hash`, `correlation_id`), `AuditWriter` | core migration |
| Reinsurance | **No tables**. Specified only as REI-001…026 in the finance expansion spec | — |
| Document retention | `document_retention_holds(document_id, reason_code, hold_until, released_at)` covers documents only | — |

### 0.8 Alignment with `IMPLEMENTATION_BLUEPRINT_V1.md` and `MASTER_PLATFORM_SPECIFICATION_V1.md` (owner, locked)
Where this spec and the blueprint differ, the blueprint wins. This spec's table names are **working names** and map to the blueprint's canonical tables as follows. Implementers must create or extend the canonical table rather than a parallel one.

| This spec | Canonical (blueprint Part I) | Rule |
|---|---|---|
| `authority_profiles`, `authority_limits` | same names | Capabilities use the blueprint enum: QUOTE, BIND, POLICY_ISSUE, DISCOUNT, PREMIUM_OVERRIDE, UNDERWRITING, CLAIM_RESERVE, CLAIM_APPROVAL, CLAIM_PAYMENT, REFUND, WRITE_OFF, COMMISSION_ADJUSTMENT, JOURNAL_APPROVAL, REINSURANCE_PLACEMENT. The following are **additions proposed to the owner**, not assumed: ENDORSE, BACKDATE, CANCEL, OVERRIDE (see OQ-3.4) |
| `authority_referrals` | `underwriting_cases/referrals` + `cases` | A referral is a case of type REFERRAL (blueprint: "threshold exceeded → CREATE REFERRAL") |
| `contract_chronology_entries`, `contract_terms_versions` | `policy_versions` (snapshot per change) + `endorsements`, `renewals`, `policy_cancellations` | The chronology is implemented **as** `policy_versions` with the bitemporal columns in §0.3. The entry_type maps to the originating endorsement, renewal or cancellation row |
| `contract_insured_objects`, `contract_coverages` | `policy_risks`, `policy_coverages` (risks reference `vehicles`, `properties`, `business_risks`) | — |
| `limit_definitions`, `limit_buckets` | `policy_limits` (consumed/reserved/remaining) | `policy_limits` holds the definition and the bucket projection. `limit_ledger_entries` stays as its append-only movement log (a proposed addition; the blueprint has no movement table for limits) |
| claim coverage `engine_evaluations` link | `claim_coverage_assessments` | CoverageAtLossService writes a `claim_coverage_assessments` row carrying the EngineResult |
| reserve/payment consumption | `claim_reserves/movements`, `claim_decisions`, payments via `financial_obligations` | Limit consumption is triggered by reserve movements and by settlement of claim obligations |
| `ownership_edges`, party relationships | `ownership_interests` (UBO), `party_relationships`, `party_roles` | — |
| `screening_requests`/`screening_hits` | `screening_checks` (+ hits child table) ; `kyc_cases` | — |
| `regulatory_changes`, `regulatory_impact_items` | `regulatory_rules`, `regulatory_impacts` | The lifecycle DRAFT→REVIEWED→APPROVED→EFFECTIVE→SUPERSEDED is unchanged |
| `cases`, `case_tasks`, `sla_clocks`, `case_types.sla_policies` | `cases` (8 types), `tasks`, `sla_definitions` | The blueprint names **8 case types**. The type list in §6.2 must be reconciled with them: the extra codes become sub-types of the owner's 8 (OQ-6.4) |
| `correspondence_register` | `correspondences` | — |
| `treaty_versions`, `facultative_placements` | `reinsurance_treaties/participants/layers`, `risk_cessions`, facultative placements/participants | Engine 7 reads these. It does not define them |
| `insurance_branches` vs dictionary `regulatory_branches` | blueprint `insurance_branches` | **[IN-PROGRESS DEP]**: naming reconciliation needed with the uncommitted dictionary migration (ADR) |
| `audit_log` hash chain | `audit_events` (append-only) | ADR: rename vs view. The hash chain semantics are retained |

Further alignment rules:
- **State machines.** Every state machine touched here (policy chronology states, referral, complaint, treaty, facultative, KYC, case types) is specified in the blueprint Part II format: state, event, from, to, actor, guard, authority, side effect, audit, notification, failure path. Blueprint states win wherever they are listed (§94–114). Engine 6 `case_types.transitions` must carry the same columns.
- **Authority algorithm order.** `AuthorityService::check` implements the blueprint order exactly: permission → scope (OWN…PORTFOLIO) → active membership → product/branch authority → threshold → maker-checker → regulatory guard → valid transition. Delegated authority stays separate from RBAC (LOCK-018). Admin privilege does not grant business-data privilege.
- **APIs.** All endpoints here live under `/api/v1`. Every mutation carries `Idempotency-Key`, `Correlation-ID` and `If-Match`/version, and all errors are RFC 9457 Problem Details (`type, title, status, code, detail, field_errors, correlation_id`). Access uses OAuth 2.1/OIDC scopes. `POST /api/v1/claims/{id}/coverage-evaluations` is exposed **in addition** to the blueprint's canonical `POST /coverage/check`, which is the primary endpoint. Webhooks use the blueprint subscription/delivery/dead-letter model (STR events excluded, §5.8).
- **Database rules.** Money uses integer minor units (already the practice). No deletion is used as business state. Constraints live in the DB. The keys question (UUID PK vs bigint + public UUID) is an open ADR in the blueprint, and this spec's `uuid pk` follows current code until that ADR is decided.
- **Master spec locks honoured.** LOCK-007 (effective-dated rules) → Engine 1. LOCK-009 and LOCK-010 → Engine 2 outcomes are recommendations and fraud indicators produce REVIEW_REQUIRED, never FRAUD_CONFIRMED. LOCK-014 → adaptive modes. LOCK-016 → offline clients cannot bypass Engines 3 and 5. LOCK-017 → Engine 2. LOCK-018 → Engine 3. §108: unknown institutional or legal facts are NULL/UNVERIFIED/PENDING_VERIFICATION (the OPEN QUESTIONS here).
- **Master §103 engine coverage.** This spec covers Temporal Rule, Coverage-at-Loss, Limit/Aggregate, Delegated Authority, Regulatory Change, AML/CFT, Case Management, Task/SLA, Correspondence (hook) and Catastrophe/Accumulation. The other §103 engines are only referenced.

Engine specs below reference these as they exist. Any column this spec depends on inside an in-progress migration is marked **[IN-PROGRESS DEP]** and must be re-verified when that work lands.

---

## Engine 1 — Temporal / Reference-Date Engine (gap 45)

### 1.1 Purpose and invariants
Every decision resolves every versioned artifact against the **correct reference instant**, never against "the current configuration". Artifacts include product version, tariff, eligibility and underwriting rules, CIMA mapping, tax, fee, commission rule, treaty version, provider contract, document template, cancellation rule, authority grant, calendar and FX rate.
- **INV-1.1**: No engine or domain service calls `now()` or `Carbon::now()` directly. Time comes only from `Clock`.
- **INV-1.2**: Each operation type has exactly one defined *reference-date rule* (table below). Resolving against any other instant is a defect.
- **INV-1.3**: Each resolution returns exactly one version per artifact key, or fails with `TEMPORAL_NO_VERSION` / `TEMPORAL_AMBIGUOUS`. It never silently falls back to "latest".
- **INV-1.4**: The resolved version IDs are persisted with the business transaction (`resolved_versions`). A replay with the same `recorded_as_of` reproduces them.
- **INV-1.5**: An artifact version in status EFFECTIVE (or APPROVED for legacy tables) with `valid_from` in the past is immutable.

### 1.2 Reference-date rules (the core table)
| Operation | reference_at for *product/tariff/tax/fee/template* | reference_at for *authority/licence/authorization* | Notes |
|---|---|---|---|
| Quote | quote `requested_at` (valid until `quote_offers` expiry) | `requested_at` | Offer locks `resolved_versions`. Bind within validity reuses them |
| Bind / proposal accept | offer's locked versions; re-check *regulatory/authorization* at bind instant | bind instant | Tariff is locked, authority is live |
| Issue | inception `coverage_starts_at` for cover terms; issue instant for templates | issue instant | OPEN QUESTION OQ-1.1: templates as of issue or inception |
| Endorse (mid-term) | endorsement `effective_at` for rating; the original policy snapshot for unchanged terms | request instant | Back-dated endorsements need Engine 3 authority `may_backdate` |
| Cancel | cancellation `effective_at` (current `CancellationCalculator` behaviour) | request instant | |
| Renew | new inception date | renewal offer instant | Renewal snapshot per the rule-engine spec (governance item 84) |
| Claim FNOL / coverage | `loss_occurred_at` | FNOL instant (for the intermediary acting) | Engine 2 |
| Claim decision / reserve | `loss_occurred_at` for cover; decision instant for authority | decision instant | |
| Settlement / payment | decision instant for amounts; **payment value date** for FX | payment instant | FX historical rates are immutable (gap 34) |
| Commission | premium transaction `effective_at` | — | |
| Reinsurance cession | policy/risk attachment date (risks-attaching) **or** loss date (losses-occurring) per treaty basis | — | Engine 7 |
| Regulatory report | reporting period end (valid) **and** filing `recorded_as_of` | — | Engine 4 / returns engine |
| API access | request instant | request instant | Engine 3 |

### 1.3 Domain model (additive)
```
reference_date_rules(
  id uuid pk, operation varchar(48), artifact_type varchar(48),
  anchor varchar(48),          -- REQUESTED_AT | OFFER_LOCK | INCEPTION | EFFECTIVE_AT | LOSS_OCCURRED_AT | DECISION_AT | VALUE_DATE | PERIOD_END | REQUEST_AT
  version int, status varchar(24) default 'DRAFT', valid_from timestamptz, valid_to timestamptz null,
  approved_by uuid null, approved_at timestamptz null, recorded_at timestamptz, superseded_at timestamptz null,
  unique(operation, artifact_type, version)
)  -- seeded with the table in 1.2; changes are governed (maker-checker)

versioned_artifact_registry(          -- the catalogue of what the resolver knows how to resolve
  artifact_type varchar(48) pk, source_table varchar(64), key_columns jsonb,
  from_column varchar(48), until_column varchar(48), granularity varchar(8),  -- DAY | INSTANT
  status_column varchar(48), effective_statuses jsonb, bitemporal bool
)
```
Seed rows cover the legacy tables listed in §0.3 and the new tables from Engines 2–7.

`transaction_resolved_versions(id, tenant_id, subject_type, subject_id, operation, reference_at, recorded_as_of, versions jsonb, versions_hash char(64), created_at)`. This is append-only. Quote, offer, policy, policy_transaction, claim_decision, settlement and commission_accrual rows get a nullable `resolved_versions_id` FK (additive).

### 1.4 Services and contracts
- `Clock` interface: `now(): CarbonImmutable`, with `SystemClock` and `FrozenClock` (tests, replay). It is bound in a service provider. Migration path: grep-replace `now()` in `app/Application` and `app/Domain` under epic E1.2.
- `ReferenceDateResolver::for(string $operation, object $subject): ReferenceInstant{ reference_at, anchor, rule_version }`.
- `VersionResolver::resolve(string $artifactType, array $key, ReferenceInstant $at, ?CarbonImmutable $recordedAsOf = null): ResolvedVersion{ artifact_type, id, version, valid_from, valid_to }`. It throws `TemporalResolutionException{code: NO_VERSION|AMBIGUOUS, artifact_type, key, at}`. It is pure and read-only.
- `VersionResolver::resolveSet(operation, subject, artifactKeys[]): ResolvedVersionSet` persists `transaction_resolved_versions` and returns the `EngineResult` (engine=TEMPORAL).
- `BusinessCalendar::addBusinessDays(date, n, jurisdiction, branchId?)`, `isBusinessDay`, `businessHoursBetween`. This service is shared with Engine 6.

### 1.5 Integration points
QuoteService (quote/offer creation), ProposalService (bind), policy issuance (PolicyIssuer adapters), PolicyServicingService (endorse/cancel; replaces the inline query in `CancellationCalculator` with `VersionResolver` without changing behaviour), renewal (`renewal_work_items` → renewal offer), claims FNOL/decision (via Engine 2), settlements, commissions, BordereauService (currently stamps `effective_at => now()`; must use policy transaction effective date), CimaPublicationGuard, and regulatory reporting.

### 1.6 Configuration and governance
Changes to `reference_date_rules` go DRAFT → APPROVED → EFFECTIVE → SUPERSEDED with maker-checker (platform compliance + platform technical). The `versioned_artifact_registry` is code-owned: it changes by migration only.

### 1.7 Adaptive modes
In MANUAL and REMOTE_API modes the carrier may supply the version ("tariff per carrier letter 2026-03"). The resolver still records an `artifact_type = EXTERNAL_TERMS` entry: the carrier document ID plus its hash, with `valid_from` taken from the document. Manual mode never means "unversioned".

### 1.8 Failure and escalation
`NO_VERSION` blocks the transaction and raises a CONFIGURATION task (Engine 6, queue `config-gaps`) for the product owner. `AMBIGUOUS` is a data-integrity defect: it blocks, raises an OPS alert, and should be impossible once the exclusion constraints exist.

### 1.9 Events
`temporal.versions.resolved` (high volume: audit_log only on a blocking result, otherwise `engine_evaluations` only) · `temporal.resolution.failed` · `temporal.rule.changed`.

### 1.10 APIs
`GET /api/v1/admin/temporal/resolve?operation=&subject_type=&subject_id=&as_of=` (diagnostic, admin) · `GET /api/v1/{subject}/{id}/resolved-versions`.

### 1.11 Screens
- ENG-1-001 Reference-Date Rules (admin, versioned list plus maker-checker).
- ENG-1-002 Resolution Inspector: "what applied to transaction X and why". This extends the rule-engine "test sandbox / explainability" screen and is embedded as a tab in the record shells.
- ENG-1-003 Business Calendars (extends the existing `business_calendars`, feeds Engine 6).

### 1.12 Acceptance tests
1. A tariff v1 is valid until 2026-06-30 and v2 from 2026-07-01. A quote requested on 2026-06-30 23:30 Africa/Douala and bound on 2026-07-02 within offer validity is priced on v1.
2. A back-dated endorsement effective 2026-05-01 is rated on the tariff valid on 2026-05-01.
3. A claim with a loss on 2026-04-10 resolves the product-version terms snapshot of the policy term in force on that date, not the renewed term.
4. Two EFFECTIVE versions overlap: the insert is rejected by the exclusion constraint.
5. With `FrozenClock`, a replay of an issuance with `recorded_as_of` equal to the original gives an identical `versions_hash`.
6. Static analysis: no `now()` calls remain in `app/Domain` or `app/Application` (arch test).

### 1.13 Open questions
OQ-1.1 Templates: as of issue date or inception? · OQ-1.2 The day boundary for date-only legacy versions: tenant timezone or carrier timezone (cross-border CIMA carriers)? · OQ-1.3 Losses-occurring vs risks-attaching default per treaty class (needs reinsurance input).

---

## Engine 2 — Coverage-at-Loss, Contract Chronology Registry, Limit/Aggregate Accumulator (gaps 8, 9, 10)

### 2.1 Purpose and invariants
Reproduce exactly what cover existed at `loss_occurred_at`: effective period, suspension, cancellation, endorsements, insured object, territory, limits, sub-limits, exclusions, waiting periods, deductibles, and limits already exhausted.
- **INV-2.1**: Coverage decisions **never** read `policies.status`. They read only the chronology.
- **INV-2.2**: The chronology is append-only and bitemporal. A correction (for example a late-notified cancellation) is a new recorded version, and a claim decided under the old knowledge keeps its `recorded_as_of`.
- **INV-2.3**: For every limit bucket, `sum(consumption) - sum(release) <= limit_amount`, enforced under a row lock and never allowed to go negative. An overrun is possible only through an explicit `OVERRIDE` entry with Engine 3 authority.
- **INV-2.4**: `policies.status` becomes a **projection** of the chronology, so current-state reads keep working.

### 2.2 Domain model (additive)
```
contract_chronology_entries(          -- the legal chronology (gap 8)
  id uuid pk, tenant_id uuid, policy_id uuid fk, policy_transaction_id uuid null fk,
  sequence int,                        -- per policy, gapless
  entry_type varchar(32),              -- INCEPTION | ENDORSEMENT | SUSPENSION | REINSTATEMENT | CANCELLATION | LAPSE | RENEWAL | EXPIRY | CORRECTION | PORTFOLIO_TRANSFER
  valid_from timestamptz, valid_to timestamptz null,
  recorded_at timestamptz, superseded_at timestamptz null, supersedes_id uuid null,
  cover_state varchar(16),             -- IN_FORCE | SUSPENDED | CANCELLED | LAPSED | EXPIRED
  terms_version_id uuid fk contract_terms_versions,
  source varchar(24),                  -- OPES_GENERATED | CARRIER_DOCUMENT | CARRIER_API | IMPORT
  source_document_id uuid null fk documents, source_hash char(64),
  actor_id uuid null, reason_code varchar(64), created_at timestamptz,
  unique(policy_id, sequence)
)  -- EXCLUDE overlapping IN_FORCE rows per policy where superseded_at IS NULL

contract_terms_versions(              -- normalized immutable terms (from policy snapshot / carrier document)
  id uuid pk, policy_id uuid, terms_hash char(64) unique,
  product_version_id uuid null, territory jsonb, currency char(3),
  waiting_periods jsonb, exclusions jsonb, conditions jsonb, created_at timestamptz
)
contract_insured_objects(id, terms_version_id, object_type varchar(32) /*VEHICLE|PERSON|LOCATION|VESSEL|CARGO|FLEET*/, object_ref_type, object_ref_id, identifiers jsonb /*VIN, plate, address, geo*/, sum_insured_minor, attributes jsonb)
contract_coverages(id, terms_version_id, coverage_code, insured_object_id null, cima_branch_code [IN-PROGRESS DEP regulatory_branches], deductible jsonb /*{type: FIXED|PERCENT|FRANCHISE, amount_minor, pct_bp, min, max}*/, waiting_period_days int null)

limit_definitions(                    -- the limit structure (gap 10)
  id uuid pk, terms_version_id uuid fk, coverage_id uuid null fk,
  limit_code varchar(64), limit_type varchar(24),   -- PER_OCCURRENCE | AGGREGATE | PER_PERSON | PER_LOCATION | PER_VEHICLE | PER_EVENT_CAT | SUBLIMIT | COMBINED_SINGLE
  parent_limit_id uuid null,           -- sublimit hierarchy; COMBINED links via limit_group
  limit_group varchar(64) null, amount_minor bigint, currency char(3),
  period_basis varchar(16),            -- POLICY_TERM | ANNUAL | LIFETIME | PER_CLAIM
  reinstatement jsonb null, created_at
)
limit_buckets(                        -- runtime counter per (definition, dimension key, period)
  id uuid pk, tenant_id, policy_id, limit_definition_id, dimension_key varchar(200) /*'' | person:<id> | location:<id> | vehicle:<id> | event:<cat_event_id>*/,
  period_start date, period_end date, limit_amount_minor bigint, consumed_minor bigint default 0, reserved_minor bigint default 0,
  version int /*optimistic lock*/, unique(limit_definition_id, dimension_key, period_start)
)
limit_ledger_entries(                 -- append-only; buckets are a projection
  id uuid pk, bucket_id uuid fk, entry_type varchar(16) /*RESERVE|CONSUME|RELEASE|REINSTATE|OVERRIDE|ADJUST*/,
  amount_minor bigint, claim_id uuid null, claim_payment_id uuid null, claim_reserve_change_id uuid null,
  policy_transaction_id uuid null, engine_evaluation_id uuid, idempotency_key varchar(120) unique, actor_id, created_at
)
```
Existing tables, unchanged apart from nullable FKs: `claims.coverage_evaluation_id`, `claim_reserve_changes.limit_ledger_entry_id`, `claim_payments.limit_ledger_entry_id`.

### 2.3 Services and contracts
- `ContractChronologyService::append(policyId, entryType, validFrom, termsVersionId, source, ...)`: validates sequence and overlap, then updates the `policies.status` projection. It is called by every PolicyStateMachine transition, so the state machine becomes chronology-driven (epic E2.1).
- `ContractChronologyService::stateAt(policyId, CarbonImmutable $validAt, ?CarbonImmutable $recordedAsOf): ChronologySlice{ cover_state, terms_version, entry_id }`.
- `CoverageAtLossService::evaluate(ClaimContext{ policy_id, loss_occurred_at, loss_location, object_ref, peril/coverage codes, claimant_person_id?, cat_event_id?, recorded_as_of? }): EngineResult(engine=COVERAGE)`. The outcome is one of `COVERED | NOT_COVERED | PARTIALLY_COVERED | REFER | INSUFFICIENT_DATA`. The trace includes chronology entry, cover state, object match, territory check, waiting-period check, exclusion hits, applicable deductible, and available limit per bucket (with the whole limit chain for sublimits).
- `LimitAccumulator::reserve|consume|release|reinstate(bucketChain, amount, ref, idempotencyKey)`. It locks all buckets in the chain (`SELECT … FOR UPDATE` ordered by id, the same pattern as the advisory lock in `BordereauService`). It checks every level: sublimit, parent, aggregate, combined group, and per-event cat. It returns `{approved_amount, capped_by_limit_code, remaining_per_bucket}`.
- Consumption and release rules: a claim reserve change reserves the delta. A claim payment converts reserve to consumed. A claim close releases the unused reserve. A recovery (`claim_recoveries` RECEIVED) releases consumed only when the product rule `recovery_reinstates_limit` = true. An endorsement that raises a limit creates a new `limit_definitions` row and carries existing consumption forward to the new bucket as a `ADJUST` entry. A reinstatement premium triggers `REINSTATE`.

### 2.4 Integration points
Issue (INCEPTION entry plus limit definitions from the snapshot), endorse (ENDORSEMENT entry), suspend/cancel/lapse/expire/renew (the existing PolicyStateMachine transitions), claim FNOL (evaluate → claim `coverage_evaluation_id`; a NOT_COVERED result becomes a *recommendation*, not an auto-decline), claim decision (re-evaluate at decision), reserve changes, settlement/claim payments (consume), recoveries (release), certificates (`policy_certificates` status follows chronology), public verify (reads `stateAt(now)`), bordereaux (transaction type from entry_type), and reinsurance recoveries (Engine 7 reads consumption).

### 2.5 Configuration and governance
Limit structures come from product versions (rule-engine spec coverage items 11–16) and go through that spec's governance workflow. Manual corrections to the chronology (a CORRECTION entry) need maker-checker plus a reason code, and they emit an event to compliance when the correction changes the cover state for a date that already has a claim.

### 2.6 Adaptive modes
- **MANUAL_UPLOAD issuance**: the chronology entry is created from the uploaded carrier document. Limits and deductibles are keyed into a structured "cover summary" form, and the completeness gate (gap 14) blocks activation until the minimum set exists: period, object, territory, and at least one limit per coverage. **OPEN QUESTION OQ-2.1:** what is the minimum mandatory cover-summary set per class?
- **INSURER_API**: entries come from carrier messages (`carrier_exchange_messages`) with `source=CARRIER_API` and `source_hash = payload_hash`.
- **Claims BROKER_ASSISTED / MANUAL_CARRIER**: the engine still evaluates. The result is advisory to the carrier, who records the decision. The platform records any disagreement (engine NOT_COVERED vs carrier APPROVED) as a data point, not a block.
- **Legacy/imported portfolios**: an `IMPORT` source is allowed, with `INSUFFICIENT_DATA` as a valid outcome that routes to a REFER task.

### 2.7 Failure and escalation
INSUFFICIENT_DATA or REFER creates a claims coverage-review task (Engine 6, case type `CLAIM_COVERAGE_REVIEW`). Limit exhaustion caps the approved amount and notifies the claim handler and the reinsurance function (Engine 7 large-loss check). Any conflict between the chronology and the carrier document is escalated to the carrier's operations queue.

### 2.8 Events
`policy.chronology.appended` · `policy.chronology.corrected` · `claim.coverage.evaluated` · `claim.coverage.overridden` · `limit.reserved` · `limit.consumed` · `limit.released` · `limit.exhausted` (a bucket reaches 100%) · `limit.threshold_reached` (configurable, e.g. 80%).

### 2.9 APIs
`GET /api/v1/policies/{id}/chronology?as_of=&recorded_as_of=` · `GET /api/v1/policies/{id}/cover-at?at=` · `POST /api/v1/claims/{id}/coverage-evaluations` · `GET /api/v1/policies/{id}/limits` (buckets plus ledger) · carrier API: `POST /api/v1/carriers/{carrier}/policies/{policy}/chronology-entries` (idempotent).

### 2.10 Screens
- ENG-2-001 Policy Chronology Timeline: a record-shell tab that extends the policy detail and replaces the ad-hoc status history view.
- ENG-2-002 Cover-at-Date Inspector.
- ENG-2-003 Claim Coverage Evaluation panel: extends the CLP claim overview/assessment screens and replaces any "policy active" badge.
- ENG-2-004 Limits & Aggregates panel (policy and claim).
- ENG-2-005 Manual Cover Summary capture (MANUAL_UPLOAD mode).

### 2.11 Acceptance tests
1. A policy active on 2026-01-01 is suspended 03-01 → 03-15, and the loss date is 03-10. Outcome NOT_COVERED with trace entry SUSPENSION, even though `policies.status` is now ACTIVE.
2. A cancellation is recorded on 04-20, back-dated to 04-01, and a claim with a 04-10 loss was decided on 04-15. A replay with `recorded_as_of`=04-15 gives COVERED, and a replay as of now gives NOT_COVERED. The claim is flagged for review and not auto-reversed.
3. A per-occurrence limit of 10M within an aggregate of 15M. Claims of 8M and 9M give a second approval of 7M, `capped_by=AGGREGATE`.
4. A concurrent payment race on the same bucket: the total never exceeds the limit (parallel test).
5. A sublimit (theft 2M) under a combined single limit: both are decremented.
6. A waiting period of 30 days with a loss on day 20 gives NOT_COVERED, reason WAITING_PERIOD.
7. A recovery with `recovery_reinstates_limit`=false does not change the bucket.
8. An idempotent retry of consume with the same key creates a single ledger entry.

### 2.12 Open questions
OQ-2.1 Minimum cover-summary set per class (manual mode). · OQ-2.2 Default treatment of claims-made vs occurrence triggers (liability classes). · OQ-2.3 Does a recovery reinstate the aggregate by default? · OQ-2.4 Premium-to-cover rule (gap 31): is cover suspended or never incepted when premium is unpaid? This must follow the applicable CIMA Code provision, which the owner must cite. This spec does not assert its content.

---

## Engine 3 — Authority & Delegation (gaps 5, 6, 7)

### 3.1 Purpose and invariants
Every act by a person or an organisation is checked against a **live, dated authorization chain**: regulator → carrier authorization (branch) → intermediary authorization/licence → carrier mandate (DA agreement) → product distribution right → branch/jurisdiction → individual delegated limit. Acts include quote, bind, issue, endorse, cancel, refund, claim approval, override and API calls. When a limit is exceeded, the act becomes a **referral** that returns to the originator after the decision.
- **INV-3.1**: A missing link means DENY. There is no implicit authority.
- **INV-3.2**: Checks run at the Engine 1 reference instant for the operation (authority is always *live*, §1.2).
- **INV-3.3**: Consumption of cumulative authorities (e.g. a monthly bind capacity) is ledgered and locked like Engine 2 buckets.
- **INV-3.4**: No one approves their own referral, and no one approves beyond their own grant (checker authority ≥ requested amount).
- **INV-3.5**: The referral decision is attached to the originating transaction, which resumes from the step where it paused.

### 3.2 Domain model
Existing, reused as-is: `insurer_authorizations`, `intermediary_authorizations`, `partner_licences`, `delegated_authority_agreements`, `tenant_branches`, `privileged_access_grants`, `integration_clients(scopes)`, and **[IN-PROGRESS DEP]** `insurer_regulatory_authorizations` and `insurer_authorized_branches`.

Additive tables:
```
authority_profiles(                   -- reusable limit templates (e.g. "Branch UW Level 2")
  id, tenant_id null, carrier_id null, code, name, version int, status varchar(24),
  valid_from timestamptz, valid_to timestamptz null, recorded_at, superseded_at, approved_by, approved_at
)
authority_limits(
  id, authority_profile_id fk, capability varchar(32),   -- QUOTE|BIND|ISSUE|ENDORSE|BACKDATE|CANCEL|REFUND|CLAIM_APPROVE|CLAIM_RESERVE|SETTLE|OVERRIDE|DISCOUNT|COMMISSION_ADJUST|WRITE_OFF
  line_code null, cima_branch_code null, product_id null,
  max_sum_insured_minor null, max_premium_minor null, max_discount_bp null, max_amount_minor null,
  max_backdate_days null, cumulative_period varchar(8) null /*DAY|MONTH|YEAR*/, cumulative_cap_minor null,
  currency char(3), conditions jsonb null /*rule-engine expression*/
)
authority_grants(                     -- who holds which profile, where, and when
  id, tenant_id, grantee_type varchar(16) /*USER|PARTNER|BRANCH|INTEGRATION_CLIENT*/, grantee_id uuid,
  authority_profile_id fk, carrier_id null, delegated_authority_agreement_id null fk,
  branch_id null fk tenant_branches, territories jsonb, granted_by uuid, approved_by uuid,
  valid_from timestamptz, valid_to timestamptz null, revoked_at null, revoked_by null, revocation_reason null,
  recorded_at, superseded_at
)
authority_consumption_ledger(id, authority_grant_id, capability, period_key varchar(16), amount_minor, subject_type, subject_id, idempotency_key unique, created_at)
authority_referrals(
  id, tenant_id, referral_number unique, subject_type, subject_id, originating_step varchar(48),
  originator_user_id, capability, requested_value jsonb, deficit jsonb /*which limits failed*/,
  referral_reason_codes jsonb, recommendation text, recommended_outcome varchar(24),
  target_level int, current_assignee_id null, case_id uuid fk cases /*Engine 6*/,
  status varchar(24) /*OPEN|IN_REVIEW|INFO_REQUESTED|APPROVED|APPROVED_WITH_CONDITIONS|DECLINED|ESCALATED|WITHDRAWN|EXPIRED|RETURNED*/,
  decision_by null, decision_at null, decision_conditions jsonb null, decider_grant_id null,
  returned_to_originator_at null, resumed_at null, engine_evaluation_id
)
```
Additive columns: `delegated_authority_agreements.authority_profile_id` (nullable), `may_quote`, `may_bind`, `may_issue`, `may_refund`, `may_override` (booleans, default false, with a backfill from the existing semantics: `max_policy_premium_minor` maps to BIND/ISSUE `max_premium_minor` and `max_claim_authority_minor` maps to CLAIM_APPROVE `max_amount_minor`).

### 3.3 Services and contracts
- `AuthorityService::check(AuthorityRequest{ actor (user|partner|client), tenant_id, carrier_id, capability, product_id, line/branch code, branch_id, territory, amounts{sum_insured, premium, discount_bp, amount, backdate_days}, reference_at }): EngineResult(engine=AUTHORITY)`. The outcome is `ALLOW | REFER | DENY`, and the trace lists each chain link with its source row and dates. DENY covers structural failures such as no licence or no carrier authorization for the branch. REFER covers only limit breaches.
- `AuthorityService::consume(result, subject, idempotencyKey)` runs on commit of the act.
- `ReferralService::open(result, originatingStep, recommendation)` → creates `authority_referrals` plus an Engine 6 case of type `AUTHORITY_REFERRAL`, routed to the lowest grant holder whose limits cover the deficit.
- `ReferralService::decide(referralId, decision, conditions, decider)`: re-checks the decider's authority. It emits the decision and returns the referral to the originator's queue. The originating service implements `ResumableStep::resume(referral)`: quote/bind/issue/claim-approve continue from `originating_step` with the conditions applied.
- `IntermediaryAuthorizationGate::assertCanTransact(partner, carrier, branchCode, at)` is the reusable subset for transaction flows (it wraps the partner-side logic of `CimaAuthorizationService`).

### 3.4 Integration points
QuoteService (QUOTE), ProposalService/bind (BIND plus DISCOUNT), issuance (ISSUE), PolicyServicingService (ENDORSE, BACKDATE, CANCEL, REFUND), UnderwritingService (UW decision within limit; replaces the implicit role checks), claims decision and reserve (CLAIM_APPROVE, CLAIM_RESERVE), settlements and claim payments (SETTLE, plus existing maker-checker), commissions (COMMISSION_ADJUST), `engine_overrides` (OVERRIDE), publication (CimaPublicationGuard already checks the *carrier* branch authorization; Engine 3 adds the *distributor* rights), and the API (integration_clients: scope check plus AUTHORITY check for the partner behind the client; gap 41 consent attaches here).

### 3.5 Governance
Profiles and grants go DRAFT → APPROVED → EFFECTIVE → SUPERSEDED/REVOKED. Maker-checker applies, and the approver must hold a higher profile or a `GRANT_ADMIN` role. A carrier's approval is required for any grant under a DA agreement (`approved_by_carrier`, already on the agreement). A grant expiring within N days raises a renewal task.

### 3.6 Adaptive modes
When a carrier's UNDERWRITING mode is MANUAL or REMOTE_INSURER, BIND and ISSUE authority for the broker is normally absent. The engine outputs REFER with target = CARRIER. The referral becomes the "send to insurer → receive response" step of Mode 1. The carrier's response, whether keyed in or received by API, is the decision. The same mechanism serves every mode.

### 3.7 Failure and escalation
REFER goes to the next level. If there is no decision within the SLA (Engine 6), the referral escalates to the next level, and at the top level it goes to carrier management. Unexpected DENY patterns (e.g. an expired licence on an active partner) create a CMP alert and suspend the partner's transact capability (with a status change on the partner and maker-checker to lift it).

### 3.8 Events
`authority.checked` (engine_evaluations only, unless DENY) · `authority.denied` · `authority.referral.opened` · `authority.referral.decided` · `authority.referral.returned` · `authority.consumed` · `authority.grant.changed` · `authority.grant.expiring` · `intermediary.authorization.lapsed`.

### 3.9 APIs
`POST /api/v1/authority/check` (dry run, used by UIs to pre-disable actions) · `GET /api/v1/me/authority` · `GET/POST /api/v1/referrals`, `POST /api/v1/referrals/{id}/decision` · admin CRUD `/api/v1/admin/authority-profiles`, `/authority-grants`.

### 3.10 Screens
- ENG-3-001 Authority Profiles & Limits.
- ENG-3-002 Authority Grants (per user/partner/branch). This extends the partner and DA-agreement admin screens.
- ENG-3-003 My Authority (self-service view).
- ENG-3-004 Referral Inbox & Decision. It **replaces** UND-003 New Referrals and UND-019 Supervisor Approval, and it extends CMP-014 Underwriting Override Review and CMP-015 Commission Exception Review as filtered views.
- ENG-3-005 Authorization Chain Inspector (per transaction).

### 3.11 Acceptance tests
1. A broker with an expired `intermediary_authorizations` row (valid_until yesterday) tries to quote: DENY, with the chain link identified.
2. A carrier not authorized for branch X at issue date: DENY on ISSUE even though the quote was earlier ALLOWed.
3. A user with bind limit 50M binds 60M: REFER to a level-2 holder. Approval resumes the bind, and the originator is notified. The originator cannot approve.
4. Monthly cumulative cap 200M, with three binds of 80M: the third refers.
5. A decider whose own limit is 55M cannot approve the 60M referral (it escalates).
6. An API client with scope `quotes:write` belonging to an unlicensed partner is denied.
7. A back-dated endorsement beyond `max_backdate_days` refers.

### 3.12 Open questions
OQ-3.1 The default referral ladder per carrier (levels and titles). · OQ-3.2 Can a broker hold BIND authority on non-compulsory lines without a DA agreement? This is a legal question under the CIMA Code intermediary provisions; the owner must cite the article. · OQ-3.3 Cumulative periods: calendar or rolling?

---

## Engine 4 — Regulatory-Change Registry with Impact Analysis (gap 2)

### 4.1 Purpose and invariants
Register each regulatory change as a governed object (CIMA regulations 2024/2025/2026, national texts, circulars). Compute its **impact** on products, documents, tariffs, workflows, reports, authorizations and master data, and drive the remediation work to completion before the effective date.
- **INV-4.1**: Lifecycle DRAFT → REVIEWED → APPROVED → EFFECTIVE → SUPERSEDED (plus WITHDRAWN). Only APPROVED items can become EFFECTIVE, and the transition happens automatically at `valid_from` (a scheduler through `Clock`).
- **INV-4.2**: The platform never authors legal content. Every rule stores `source_reference` (official text reference and document) and the text is entered by a named, accountable user.
- **INV-4.3**: A change that alters a `regulatory_*` dictionary row creates a **new version** of that row. It never edits the row (the existing PROTECTED trigger on those tables is retained).
- **INV-4.4**: A product cannot be published or stay published past an EFFECTIVE change that flags it BLOCKING without a remediation or a documented waiver.

### 4.2 Domain model
Reuses **[IN-PROGRESS DEP]** `legal_references`, `regulatory_authorities`, all `regulatory_*` versioned tables, `product_regulatory_mappings`, `regulatory_reporting_mappings`, and `regulatory_reference_sets`.

Additive tables:
```
regulatory_changes(
  id, change_number unique, jurisdiction varchar(8), authority_id fk regulatory_authorities,
  legal_reference_id fk legal_references, title, summary text, text_document_id fk documents,
  change_type varchar(32) /*NEW_RULE|AMENDMENT|REPEAL|CIRCULAR|REPORTING_FORMAT|TARIFF_FLOOR|CAPITAL|AML|ICT*/,
  status varchar(24), valid_from timestamptz, valid_to null, transitional_until timestamptz null,
  supersedes_change_id null, recorded_at, superseded_at, drafted_by, reviewed_by null, approved_by null
)
regulatory_change_rules(              -- machine-checkable parts (optional)
  id, regulatory_change_id, rule_code, target_domain varchar(32) /*PRODUCT|TARIFF|DOCUMENT|WORKFLOW|REPORT|AUTHORIZATION|MASTER_DATA|AML|CLAIMS*/,
  expression jsonb null /*rule-engine expression*/, parameter_values jsonb, severity varchar(16) /*BLOCKING|WARNING|INFO*/
)
regulatory_impact_assessments(id, regulatory_change_id, run_number, status, run_at, run_by, summary jsonb)
regulatory_impact_items(
  id, assessment_id, target_type varchar(48), target_id uuid, carrier_id null, tenant_id null,
  impact_kind varchar(24) /*NON_COMPLIANT|NEEDS_REVIEW|NEW_VERSION_REQUIRED|REPORT_CHANGE|NO_IMPACT*/,
  severity, evidence jsonb /*rule trace*/, remediation_case_id null fk cases, status /*OPEN|REMEDIATED|WAIVED|NOT_APPLICABLE*/,
  waiver_reason null, waived_by null
)
```

### 4.3 Services and contracts
- `RegulatoryChangeService`: lifecycle transitions with maker-checker. The reviewer and approver must differ from the drafter.
- `RegulatoryImpactAnalyzer::run(changeId): EngineResult(engine=REGCHANGE)`. It uses the dependency graph (rule-engine governance "dependency graph") to find targets by mapping: products via `product_regulatory_mappings`, reports via `regulatory_reporting_mappings`, document templates by class, workflows by class and step, and carriers via `insurer_regulatory_authorizations`. It evaluates `regulatory_change_rules` expressions against each target's *future* version as of `valid_from` (Engine 1 with a future reference instant). It is deterministic and re-runnable, and each run is kept.
- `RegulatoryGate::assert(target, at)` is called by CimaPublicationGuard, the issuance gate, and report generation. It blocks when an open BLOCKING impact item exists and `at >= valid_from` (or after `transitional_until` if set).

### 4.4 Integration points
Product publication (CimaPublicationGuard), tariff activation, template activation, issuance (compulsory insurance rules), regulatory reports and returns (gap 37), AML rules (Engine 5 loads its parameters from EFFECTIVE AML changes), workflow definitions (WORKFLOW_REGISTER), and authority (new licence categories).

### 4.5 Governance
Roles: Regulatory Analyst (draft), Compliance Officer (review), Head of Compliance / platform regulatory owner (approve). Each carrier's compliance officer must *acknowledge* impact items for their products. Waivers need maker-checker plus a mandatory justification and expiry.

### 4.6 Adaptive modes
For manual insurers the impact items for their products become tasks for the carrier's compliance user (or for the broker's product admin if the broker onboarded the product). If no user exists, the task goes to the platform's regulatory desk. Nothing is auto-changed in any mode. Configured insurers additionally get auto-drafted new product and tariff versions (DRAFT only).

### 4.7 Failure and escalation
Any impact item still OPEN at T-30, T-7 or T-0 days escalates (Engine 6 SLA). At T-0 BLOCKING items take effect through the gate, and the affected products show "sales suspended: regulatory" to distributors without CIMA jargon (Adaptive model, CIMA visibility rule).

### 4.8 Events
`regulatory.change.drafted|reviewed|approved|effective|superseded|withdrawn` · `regulatory.impact.assessed` · `regulatory.impact.item.opened|remediated|waived` · `regulatory.gate.blocked`.

### 4.9 APIs
`/api/v1/admin/regulatory-changes` (CRUD plus transitions) · `POST /{id}/impact-assessments` · `GET /{id}/impact-items?carrier=` · the carrier view `GET /api/v1/carriers/{carrier}/regulatory-impacts`.

### 4.10 Screens
- ENG-4-001 Regulatory Change Register.
- ENG-4-002 Change Detail & Approval.
- ENG-4-003 Impact Assessment (matrix by target, re-run, diff between runs).
- ENG-4-004 Carrier Regulatory Impacts (carrier workspace).

These extend the existing `CimaRegulatoryDashboard` Filament page (in progress) and the REG family. ENG-4-003 also **replaces** any static "CIMA updates" notice screen in REG.

### 4.11 Acceptance tests
1. A change that is only DRAFT or REVIEWED cannot become EFFECTIVE.
2. An approved change with valid_from=D: the scheduler flips it to EFFECTIVE at D in `Africa/Douala`, driven by a FrozenClock test.
3. The impact run identifies every product mapped to the affected branch, and nothing else.
4. A BLOCKING open item blocks publication after D and does not block it before D.
5. A waiver expires and the block resumes.
6. The same inputs give identical impact items across re-runs.

### 4.12 Open questions
OQ-4.1 The list of CIMA regulations 2024–2026 to register, with official references and texts. The owner must supply them. This spec contains no legal content. · OQ-4.2 Who is the platform-level approver (OpesInsure regulatory owner vs each carrier)? · OQ-4.3 Should the national regulator (MINFI/DNA or equivalent) be modelled as an authority alongside CIMA? This needs the owner's legal input.

---

## Engine 5 — AML/CFT & Compliance Engine (gaps 1, 18)

### 5.1 Purpose and invariants
Customer and beneficial-owner due diligence throughout the relationship, covering onboarding, trigger events and periodic reviews. It includes screening against PEP, sanctions and watchlists, source of funds/wealth, explainable risk rating, EDD, rescreening, alerts → cases, STR case management, and evidence retention. It is configurable against **CIMA Regulation No. 003-25 on AML/CFT procedures**, whose specific requirements are **OPEN QUESTIONS** (§5.12).
- **INV-5.1**: No vendor lock-in. Screening runs behind `ScreeningProvider` adapters. List data is versioned, dated and attributed to its source.
- **INV-5.2**: Every risk rating is explainable: factors, weights, values, rule version and model version (Engine 1).
- **INV-5.3**: A customer is never auto-cleared of a potential sanctions match. Clearing needs a human decision with a reason, and a second reviewer for true or partial matches.
- **INV-5.4**: STR cases have restricted visibility (need-to-know). The subject and the distributor must never be alerted (tipping-off). The UI and notifications must not leak case existence to non-authorised roles, and customer-facing flows get a neutral "pending" state.
- **INV-5.5**: Evidence (screening responses, documents, decisions) is immutable and retained per policy. Legal hold overrides destruction (gap 20).

### 5.2 Domain model
Existing tables reused: `compliance_cases` (extended), `risk_alerts` (extended), `fraud_rule_versions` (a pattern for rule versioning), `parties`, `party_contacts`, KYC tables (`app/Application/Kyc`), `documents`, and `document_retention_holds`.

Additive tables:
```
screening_list_sources(id, code, name, list_type /*SANCTIONS|PEP|ADVERSE_MEDIA|INTERNAL_WATCHLIST|LAW_ENFORCEMENT*/, publisher, jurisdiction, provider_adapter varchar(48), refresh_cron, status)
screening_list_versions(id, source_id, version_label, retrieved_at, valid_from, content_hash, entry_count, storage_key, status)  -- immutable
screening_list_entries(id, list_version_id, external_id, entity_type /*PERSON|ORG|VESSEL*/, names jsonb, dob jsonb, nationalities jsonb, identifiers jsonb, programmes jsonb, raw jsonb)  -- only for sources loaded locally; remote-only providers skip it
screening_requests(id, tenant_id, subject_type /*PARTY|UBO|PAYEE|PAYER|INTERMEDIARY*/, subject_id, trigger /*ONBOARDING|PERIODIC|EVENT|LIST_UPDATE|MANUAL|PAYMENT|CLAIM_PAYEE*/, provider_adapter, request_hash, status, requested_at, completed_at, idempotency_key unique)
screening_hits(id, screening_request_id, list_version_id null, list_entry_ref, match_score_bp, match_fields jsonb, disposition /*PENDING|FALSE_POSITIVE|POSSIBLE|TRUE_MATCH*/, disposition_by, disposition_at, second_reviewer_id null, reason_code, notes)
ownership_edges(                      -- UBO graph (bitemporal)
  id, tenant_id null, owner_party_id, owned_party_id, relation_type /*SHAREHOLDER|CONTROLLER|DIRECTOR|TRUSTEE|NOMINEE|BENEFICIARY|SIGNATORY*/,
  ownership_bp int null, voting_bp int null, evidence_document_id null,
  valid_from, valid_to null, recorded_at, superseded_at, verified_by null
)
aml_risk_models(id, code, version, status /*DRAFT→REVIEWED→APPROVED→EFFECTIVE→SUPERSEDED*/, valid_from, valid_to, factors jsonb /*[{factor_code, weight, scoring_table}]*/, bands jsonb /*LOW|MEDIUM|HIGH|PROHIBITED thresholds*/, regulatory_change_id null, approved_by, content_hash)
aml_risk_assessments(id, tenant_id, party_id, model_id, score int, band, factors_detail jsonb /*per factor: raw value, points, weight, source*/, drivers jsonb, assessed_at, valid_until, override_id null fk engine_overrides, engine_evaluation_id)
due_diligence_profiles(id, party_id, level /*SIMPLIFIED|STANDARD|ENHANCED*/, source_of_funds jsonb, source_of_wealth jsonb, purpose_of_relationship, expected_activity jsonb, pep_status, approved_by null /*senior management approval for EDD*/, approved_at, next_review_on, status)
rescreening_schedules(id, party_id, band, frequency_days, next_due_on, last_run_at)  -- derived; regenerated on band change
aml_monitoring_rules(id, code, version, status, valid_from, valid_to, scope /*PAYMENT|PREMIUM|REFUND|CLAIM_PAYMENT|CANCELLATION|POLICY_CHANGE*/, expression jsonb, risk_points, threshold_params jsonb, regulatory_change_id null)
str_reports(id, compliance_case_id, report_number, fiu_reference null, status /*DRAFT|REVIEWED|APPROVED|FILED|ACKNOWLEDGED*/, content_document_id, filed_by, filed_at, acknowledgement_document_id null)
```
Additive columns:
- `compliance_cases`: `case_id uuid fk cases` (Engine 6 link), `confidentiality varchar(16) default 'NORMAL'` (`STR_RESTRICTED`), `legal_hold bool`.
- `risk_alerts`: `rule_code`, `rule_version`, `engine_evaluation_id`, `compliance_case_id`.

### 5.3 Services and contracts
- `ScreeningProvider` interface: `screen(ScreeningSubject{names[], dob?, nationality?, identifiers[], entity_type}): ScreeningResponse{provider_ref, hits[{list_code, list_version_label, entry_ref, score_bp, matched_fields}], raw_hash}`, plus `supportsBulk()` and `listVersions()`. Adapters: `LocalListScreeningProvider` (fuzzy match against `screening_list_entries`; algorithm = normalized token + transliteration + Jaro-Winkler, thresholds configurable) and `RemoteScreeningProvider` (generic HTTP; concrete vendors are added later with no core change). **No vendor is chosen here.**
- `ScreeningService::screen(subject, trigger)`: idempotent, and it persists the raw response hash into evidence.
- `UboGraphService::beneficialOwners(partyId, at, thresholdBp)`: a traversal with cycle detection that multiplies indirect ownership. It returns the path explanation, and screening covers every returned UBO plus controllers.
- `AmlRiskScoringService::assess(partyId, at): EngineResult(engine=AML)`. Factors come from the EFFECTIVE model: customer type, geography, product risk (life/savings vs motor), channel, PEP, screening disposition, UBO opacity, payment method, and transaction behaviour. The output is band plus score plus per-factor contribution.
- `TransactionMonitoringService::evaluate(event)`: runs `aml_monitoring_rules` on payment, refund, cancellation-with-refund, claim payments to third parties and premium overpayment, and emits `risk_alerts`.
- `AlertTriageService` promotes an alert to a `compliance_cases` case. Related alerts (same party, 30-day window) are merged.
- `StrCaseService`: restricted case, report drafting, approval (MLRO), filing record, and acknowledgement. No external filing integration is assumed (OQ-5.4).
- `ComplianceGate::assert(party, operation)` → ALLOW | HOLD | BLOCK. Examples: PROHIBITED band gives BLOCK, and a pending TRUE_MATCH disposition gives HOLD on bind, issue and pay-out.

### 5.4 Integration points
Customer onboarding / KYC completion, UBO capture for corporate customers, quote (soft check), bind/issue (ComplianceGate), payment receipt (monitoring: third-party payer, cash thresholds), refunds and cancellations, claim payee and settlement (screen the payee, gate the payment), commission payees (intermediaries), and partner onboarding (screen the partner and its UBOs). Screening list updates trigger delta rescreening of the portfolio. Engine 6 carries every alert/case.

### 5.5 Governance
Risk models and monitoring rules: DRAFT → REVIEWED → APPROVED → EFFECTIVE → SUPERSEDED, with maker-checker (compliance officer / MLRO). A model version can be linked to the `regulatory_change_id` that motivated it (Engine 4). Threshold values are data, never code.

### 5.6 Adaptive modes
AML responsibility sits with the *obliged entity*. This can be the carrier, the broker, or both, set per tenant and carrier in the capability profile. **OPEN QUESTION OQ-5.1** asks who the obliged entity is under Reg. 003-25 for broker-distributed business. Manual insurers can register their own external screening outcome as `RemoteScreeningProvider(MANUAL_ATTESTATION)`: an uploaded evidence document plus the attestor. It is recorded, explained and dated like any other screening.

### 5.7 Failure and escalation
If the provider is unavailable, the request is queued with retries. Bind/issue for HIGH-band parties goes on HOLD. For others, the operation is allowed with a "screening pending" condition that must clear before pay-out (configurable; OQ-5.5). Unresolved hits escalate to the MLRO after the SLA. A STR decision deadline follows the regulatory timeline (OQ-5.3).

### 5.8 Events
`aml.screening.requested|completed|failed` · `aml.hit.dispositioned` · `aml.risk.assessed` · `aml.risk.band_changed` · `aml.edd.required|approved` · `aml.rescreening.due` · `aml.alert.raised` · `aml.case.opened|closed` · `aml.str.approved|filed` · `aml.gate.held|blocked`. STR events are written to `audit_log` with `metadata` redacted to case ID only, and are **not** published to webhooks or integration subscriptions.

### 5.9 APIs
Internal/admin only for STR. `POST /api/v1/compliance/screenings` · `GET /api/v1/parties/{id}/aml-profile` · `GET/POST /api/v1/parties/{id}/ownership` · `/api/v1/compliance/alerts`, `/cases` (permissioned) · list-source admin. No partner-facing API exposes hits or STR state.

### 5.10 Screens
Reuse and redefine the CMP family rather than add to it:
- ENG-5-001 Screening Hit Review (**replaces** CMP-003 Compliance Alerts for screening).
- ENG-5-002 Party AML Profile (risk factors, EDD, UBO graph visualization). **Extends** CMP-005 KYC Case and CMP-006 Corporate Due Diligence.
- ENG-5-003 Risk Model & Monitoring Rules admin.
- ENG-5-004 Watchlist Sources & Versions.
- ENG-5-005 STR Case (restricted). **Replaces** CMP-008 and CMP-009 Suspicious Activity Queue/Case.
- ENG-5-006 Rescreening Schedule.

CMP-010 Fraud Alert Queue remains fraud-specific (fraud_rule_versions) but runs on the Engine 6 framework.

### 5.11 Acceptance tests
1. A corporate customer with 30% indirect ownership through two layers: the UBO is found and the path explanation is shown.
2. A sanctions hit with score above threshold puts bind on HOLD, and clearing it as FALSE_POSITIVE needs a reason code. For TRUE_MATCH, a second reviewer is required.
3. A risk model v2 becomes EFFECTIVE, and an assessment dated before valid_from still reproduces v1 (Engine 1).
4. A list update adds a new name: delta rescreening flags only the matching parties.
5. A STR case is invisible to broker users and to the carrier's claims users, and there is no webhook.
6. A payment from a third-party payer above the threshold raises an alert with rule code and version.
7. A provider outage leads to retry, and the HIGH-band party is held.

### 5.12 Open questions (legal inputs; the owner must supply them)
- OQ-5.1 Obliged entity or entities under **CIMA Reg. 003-25** for broker-intermediated business, and the split of duties.
- OQ-5.2 Reg. 003-25 thresholds: cash/transaction reporting thresholds, UBO ownership threshold, simplified-DD eligibility, and EDD triggers including PEP definitions (domestic/foreign/international organisations) and the family/close-associate scope.
- OQ-5.3 The STR filing recipient (national FIU, e.g. ANIF for Cameroon), format, deadline, and whether filing is electronic.
- OQ-5.4 The record-retention period for CDD and transaction records.
- OQ-5.5 Periodic review frequency per risk band.
- OQ-5.6 Mandatory sanctions lists (UN Security Council consolidated, national/CEMAC lists, others) and any licensing constraints on using them.
- OQ-5.7 Is a single policy of a certain product type (e.g. compulsory motor TPL) eligible for simplified DD?

---

## Engine 6 — Case, Task, Diary & SLA Engine + Business Calendar + Operational Queues (gaps 24, 25, 26, 43)

### 6.1 Purpose and invariants
One framework for every investigative or approval workflow: underwriting referral, authority referral, claim coverage review, suspicious claim, complaints with regulatory escalation, recoveries, litigation, provider disputes, compliance and AML cases, regulatory impact remediation, configuration gaps, and document intake exceptions. The existing specialised tables (`underwriting_cases`, `compliance_cases`, `claim_disputes`, `support_tickets`) keep their business semantics and **link** to a generic `cases` row (strangler pattern). No big-bang migration.
- **INV-6.1**: Each case has exactly one accountable owner (user or queue) at any time, and each ownership change is recorded.
- **INV-6.2**: Case type definitions (states, transitions, SLA, auto-tasks) are versioned. A case runs on the type version it was opened with (Engine 1).
- **INV-6.3**: SLA clocks use business time from the calendar (jurisdiction plus branch hours) and pause only in states flagged `pauses_sla`.
- **INV-6.4**: Case events are append-only. Decisions are immutable, and a reversal is a new decision.
- **INV-6.5**: Confidentiality levels (NORMAL, RESTRICTED, STR_RESTRICTED) filter visibility at the query layer (global scope), not only in the UI.

### 6.2 Domain model (additive)
```
case_types(id, code /*UW_REFERRAL|AUTHORITY_REFERRAL|CLAIM_COVERAGE_REVIEW|CLAIM_INVESTIGATION|COMPLAINT|RECOVERY|LITIGATION|PROVIDER_DISPUTE|AML_ALERT|STR|COMPLIANCE_INVESTIGATION|REG_IMPACT|CONFIG_GAP|DOC_INTAKE_EXCEPTION*/, version, status, valid_from, valid_to,
  states jsonb /*[{code, terminal, pauses_sla}]*/, transitions jsonb /*[{from,to,guard_expr,required_role,requires_decision}]*/,
  sla_policies jsonb /*[{metric: FIRST_RESPONSE|RESOLUTION|STAGE:<state>, target_business_minutes, warn_at_pct, escalate_to}]*/,
  auto_tasks jsonb /*[{on: event|state, task_template_code, due_offset_business_minutes, assignee_rule}]*/,
  default_confidentiality, approved_by, unique(code, version))
cases(id, tenant_id, carrier_id null, case_type_id fk /*pinned version*/, case_number unique, title, status, priority, confidentiality,
  subject_type, subject_id, parent_case_id null, owner_user_id null, queue_id null, branch_id null,
  opened_at, opened_by, due_at null, closed_at null, outcome varchar(32) null, legal_hold bool default false)
case_participants(id, case_id, party_type /*USER|PARTY|PARTNER|CARRIER|EXTERNAL*/, party_ref, role /*OWNER|REVIEWER|APPROVER|COMPLAINANT|CLAIMANT|COUNSEL|EXPERT|OBSERVER*/, added_at, removed_at)
case_events(id, case_id, seq, type, from_status null, to_status null, actor_id null, payload jsonb, occurred_at)  -- append-only
case_tasks(id, case_id, template_code null, title, status /*OPEN|IN_PROGRESS|BLOCKED|DONE|CANCELLED*/, assignee_user_id null, queue_id null, due_at, completed_at, completed_by, result jsonb)
case_decisions(id, case_id, decision_type, outcome, rationale text, conditions jsonb, decided_by, authority_grant_id null /*Engine 3*/, reverses_decision_id null, decided_at)
case_evidence(id, case_id, document_id fk documents, evidence_type, hash char(64), added_by, added_at, custody jsonb)  -- same model as claim_evidence_custody_events
case_correspondence(id, case_id, correspondence_id fk correspondence_register /*gap 23*/)
diary_entries(id, case_id null, subject_type, subject_id, author_id, entry_type /*NOTE|CALL|MEETING|FOLLOW_UP*/, body, follow_up_at null, visibility, created_at)  -- append-only; edits = new entry referencing previous
sla_clocks(id, case_id, metric, target_business_minutes, started_at, paused_total_business_minutes, paused_since null, due_at, warned_at null, breached_at null, stopped_at null)
queues(id, tenant_id null, carrier_id null, code, name, case_type_codes jsonb, branch_id null, routing_rule jsonb /*round-robin|least-loaded|skill*/, active)
queue_members(id, queue_id, user_id, skills jsonb, capacity int, active)
calendar_business_hours(id, jurisdiction, branch_id null, weekday smallint, opens time, closes time, valid_from date, valid_to date null)
calendar_exceptions(id, jurisdiction, branch_id null, date, kind /*HOLIDAY|CLOSURE|EXTRA_DAY*/, label, source_reference)  -- normalizes business_calendars.holidays; the existing table stays as seed input
```
Links (additive nullable FKs): `underwriting_cases.case_id`, `compliance_cases.case_id`, `claim_disputes.case_id`, `claim_recoveries.case_id`, `support_tickets.case_id`, `renewal_work_items.case_id` (optional), and `underwriting_referral_tasks.case_task_id`.

### 6.3 Services and contracts
- `CaseService::open(typeCode, subject, attrs)`: pins the type version, creates SLA clocks and auto-tasks, and routes to a queue. `transition(caseId, to, actor, payload)`: guard expressions via the rule-engine expression infrastructure, role check, and decision required when flagged. `decide(caseId, decision)`: Engine 3 authority check for the decision type.
- `SlaService::tick()`: scheduled every minute through `Clock`. It computes warn and breach using `BusinessCalendar` and emits escalations (reassign to `escalate_to` queue or role, notify).
- `QueueRouter::route(case|task)`: an explainable result (why this queue or user).
- `BusinessCalendar` (shared with Engine 1): jurisdiction plus branch hours plus exceptions, in the tenant timezone.
- `DiaryService`: append-only notes and follow-ups, which appear as tasks in "My Work".
- Adapters for existing flows: `UnderwritingCaseBridge`, `ComplianceCaseBridge`, `ClaimDisputeBridge`, `TicketBridge`. They mirror state into `cases` and `case_events`, so queues, SLAs and dashboards are unified while `TicketStateMachine` and `ClaimLifecycle` remain the source of truth for their own states during migration.

### 6.4 Integration points
Engine 3 referrals, Engine 2 coverage reviews, Engine 4 impact items, Engine 5 alerts, cases and STR, claims (investigation, fraud from `risk_alerts`, recoveries, litigation), complaints (from support tickets flagged as complaint), provider disputes (PRV family), document intake exceptions, and data-quality gate failures (gap 14).

**Complaint regulatory escalation (gap 4).** The COMPLAINT case type has the states RECEIVED → ACKNOWLEDGED → INVESTIGATING → RESPONDED → (CLOSED | ESCALATED_NATIONAL → ESCALATED_CIMA). Each regulatory hop records the authority (`regulatory_authorities`), the reference and the dates. **OPEN QUESTION OQ-6.1** covers the legal response deadlines and the escalation path per CIMA/national rules.

### 6.5 Governance
Case type versions: DRAFT → APPROVED → EFFECTIVE → SUPERSEDED, with maker-checker (ops owner plus compliance for regulated types: COMPLAINT, AML, STR). Calendars are maintained by platform ops per jurisdiction, and branches can override hours with manager approval.

### 6.6 Adaptive modes
Small brokers get a single default queue with themselves as the member. Case types still apply, but routing is trivial. Carriers without platform users receive cases through the partner workspace or email and act through a secure link. The action is recorded as an EXTERNAL participant decision with the evidence uploaded. API-integrated carriers receive `case.*` webhooks and post decisions back by API.

### 6.7 Failure and escalation
Built in: SLA warn, breach, escalation chain, and finally a management dashboard. Orphaned cases (inactive owner) are automatically returned to the queue with an event. A case type version whose definition fails validation cannot be approved.

### 6.8 Events
`case.opened|assigned|transitioned|decided|reopened|closed` · `case.task.created|completed|overdue` · `sla.warned|breached|paused|resumed` · `queue.routed` · `complaint.escalated_regulator` · `diary.follow_up_due`.

### 6.9 APIs
`/api/v1/cases` (filtered by permission and confidentiality) · `/cases/{id}/transitions|tasks|decisions|evidence|diary` · `/api/v1/me/work` (unified work list) · `/api/v1/queues/{id}/next` (pull model) · `/api/v1/admin/case-types`, `/calendars`.

### 6.10 Screens
One shell, parameterised by case type. This is the largest consolidation, and the case shell becomes the register's "record shell".
- ENG-6-001 My Work (tasks, cases, follow-ups). **Replaces** UND-002 Work Queue and UND-004 Assigned Cases, CLP-002 Assignment Queue and CLP-003 Assigned Claims as filtered views.
- ENG-6-002 Queue Board (per queue, with capacity).
- ENG-6-003 Case Workspace (generic shell with type-specific panels). **Replaces** UND-005 Case Details, CMP-016 Compliance Investigation, and the complaint, recovery, litigation and provider-dispute detail screens.
- ENG-6-004 SLA & Performance. **Replaces** UND-020 and the per-module SLA dashboards.
- ENG-6-005 Case Type Designer (admin, versioned).
- ENG-6-006 Business Calendar & Hours (merges ENG-1-003).
- ENG-6-007 Complaint Register with the regulatory escalation view.

### 6.11 Acceptance tests
1. An SLA of 16 business hours opened Friday 16:00 with Mon–Fri 08:00–17:00 hours and Monday a holiday: due Wednesday 15:00.
2. Pausing in WAITING_CUSTOMER stops the clock, and resuming continues it.
3. A breach escalates to the configured queue and emits `sla.breached` once (idempotent).
4. A case opened under type v1 keeps v1 transitions after v2 is approved.
5. An STR_RESTRICTED case is invisible via the API to unauthorised roles (query-level test).
6. An underwriting referral created through `UnderwritingService` appears in My Work, and its decision resumes the proposal (with Engine 3).
7. The bridge keeps `support_tickets.status` and `cases.status` consistent under the ticket state machine.

### 6.12 Open questions
OQ-6.1 Complaint handling deadlines and escalation path (national authority → CIMA): the legal texts are needed. · OQ-6.2 Official holiday source per CIMA jurisdiction, including moving religious holidays (who publishes, and when). · OQ-6.3 Default SLA targets per case type (owner business decision).

---

## Engine 7 — Accumulation & Catastrophe Engine (gaps 11, 12, 44)

### 7.1 Purpose and invariants
Aggregate exposure by zone, building, industrial area, flood zone, port, employer and event, both gross and net of reinsurance. Run a capacity check during underwriting (outcomes: `WITHIN_RETENTION | COVERED_BY_TREATY | CAPACITY_EXCEEDED | FACULTATIVE_REQUIRED`) and manage catastrophe events, including loss aggregation and recoveries.
- **INV-7.1**: Exposure is derived from the Engine 2 chronology and insured objects at a reference date. It is never a separately maintained counter that can drift. Materialised snapshots are reproducible.
- **INV-7.2**: Net exposure uses the treaty version valid at the Engine 1 anchor for the treaty basis.
- **INV-7.3**: A CAPACITY_EXCEEDED or FACULTATIVE_REQUIRED outcome blocks bind until an Engine 3 referral or a recorded facultative placement resolves it.
- **INV-7.4**: A catastrophe event aggregates claims by event window and geography, and per-event limits (Engine 2 `PER_EVENT_CAT`) and treaty event limits apply per the event.

### 7.2 Domain model
Reinsurance tables do not exist yet. The minimum needed by this engine is defined here and aligns with REI-001…026 in the finance expansion spec; the full reinsurance module extends these rows.
```
accumulation_zones(id, jurisdiction, zone_type /*CRESTA_LIKE|FLOOD|SEISMIC|INDUSTRIAL_AREA|PORT|CITY|ADMIN_REGION|CUSTOM*/, code, name, geometry geography null /*PostGIS optional; fallback bbox jsonb*/, parent_zone_id null, valid_from, valid_to, source_reference)
risk_locations(id, tenant_id, normalized_address jsonb, lat numeric(9,6) null, lng numeric(9,6) null, geocode_quality, building_ref varchar(120) null, port_code null, employer_party_id null)
exposure_links(id, insured_object_id fk contract_insured_objects, risk_location_id null, zone_ids jsonb /*resolved at attach, re-resolved on zone version change*/, employer_party_id null, vessel_ref null, valid_from, valid_to, recorded_at, superseded_at)
reinsurance_programmes(id, carrier_id, code, year, status)
treaty_versions(id, programme_id, treaty_code, treaty_type /*QUOTA_SHARE|SURPLUS|XOL_RISK|XOL_CAT|STOP_LOSS*/, basis /*RISKS_ATTACHING|LOSSES_OCCURRING*/, lines jsonb /*classes/branches*/, retention_minor, capacity_minor, cession_bp null, lines_count null, layers jsonb /*[{attachment, limit, reinstatements}]*/, event_definition jsonb /*hours clause etc.*/, currency, version, status /*DRAFT→APPROVED→EFFECTIVE→SUPERSEDED*/, valid_from, valid_to, approved_by, recorded_at, superseded_at)
facultative_placements(id, policy_id, treaty_version_id null, share_bp, reinsurer_party_ids jsonb, slip_document_id, status /*REQUESTED|QUOTED|PLACED|DECLINED*/, placed_at)
accumulation_limits(id, carrier_id, dimension /*ZONE|BUILDING|PORT|EMPLOYER|EVENT*/, dimension_ref, measure /*GROSS_SI|NET_SI|PML*/, limit_minor, warn_pct, version, status, valid_from, valid_to, approved_by)
exposure_snapshots(id, carrier_id, as_of timestamptz, recorded_as_of timestamptz, dimension, dimension_ref, gross_si_minor, net_si_minor, policy_count, pml_minor null, inputs_hash, created_at)  -- reproducible materialisation
cat_events(id, jurisdiction, code, name, peril, starts_at, ends_at, affected_zone_ids jsonb, declared_by, status /*MONITORING|DECLARED|CLOSED*/, event_clause_treaty_version_ids jsonb)
cat_event_claims(id, cat_event_id, claim_id, linked_by, linked_at, basis /*AUTO_GEO_TIME|MANUAL*/)
```

### 7.3 Services and contracts
- `ExposureAggregator::aggregate(carrierId, dimension, ref, asOf, recordedAsOf): {gross, net, count, contributors[]}`. It is pure over the chronology plus exposure links plus treaty versions and is reproducible.
- `NetOfReinsuranceCalculator::net(policyOrRisk, amount, at)` applies quota share, then surplus lines, then XoL per the treaty version (order per programme configuration). It returns the ceded breakdown trace.
- `CapacityCheckService::check(quote/proposal risk, at): EngineResult(engine=ACCUMULATION)`. It computes post-bind gross and net per affected dimension against `accumulation_limits` and treaty capacity, and returns one of the four outcomes with a per-dimension trace.
- `CatEventService`: declare an event, auto-link claims by (loss_occurred_at within the event window) ∧ (location within the affected zones) as *proposed* links for human confirmation, compute event losses vs XoL-cat layers and reinstatements, and trigger recoveries (hand-off to the reinsurance module).
- `LargeLossNotifier`: when a claim reserve crosses a treaty threshold, it notifies the reinsurance function (case type RECOVERY or REI notification).

### 7.4 Integration points
Underwriting and bind (CapacityCheck: before BIND and before an ISSUE that increases SI), endorsements that change SI or location, renewal (re-check), claims FNOL (cat event suggestion), reserve changes (large-loss notification), Engine 2 (per-event limits), the reinsurance module (cession bordereaux, recoveries, REI-*), and reporting (exposure dashboards, regulatory returns on concentration if required, OQ-7.3).

### 7.5 Governance
Treaty versions and accumulation limits go DRAFT → APPROVED → EFFECTIVE → SUPERSEDED with maker-checker (reinsurance manager plus CFO/chief underwriter). Zone datasets are versioned with `source_reference`. Cat event declaration requires a named declarer and a second approver to set DECLARED.

### 7.6 Adaptive modes
When the carrier keeps reinsurance off-platform (manual insurer), net exposure is `UNKNOWN_NET`. Gross accumulation still runs, and capacity checks use carrier-declared gross limits only. The outcome space degrades to `WITHIN_LIMIT | LIMIT_EXCEEDED | NOT_CONFIGURED`, and NOT_CONFIGURED is informational, never silently "OK". Brokers see only the outcome, never the carrier's treaty details.

### 7.7 Failure and escalation
Geocoding failure means the location is linked to its administrative-region zone with low quality, flagged for data quality (gap 14). CAPACITY_EXCEEDED produces an Engine 3 referral to the chief underwriter. FACULTATIVE_REQUIRED produces a reinsurance placement task (Engine 6). A cat event declaration opens an event case with tasks: notify reinsurers, reserve review, and claims surge queue.

### 7.8 Events
`accumulation.capacity.checked` · `accumulation.limit.warn|exceeded` · `reinsurance.facultative.required|placed` · `cat_event.declared|closed` · `cat_event.claim_linked` · `reinsurance.large_loss.notified` · `exposure.snapshot.created`.

### 7.9 APIs
`POST /api/v1/underwriting/capacity-checks` · `GET /api/v1/carriers/{carrier}/exposure?dimension=&ref=&as_of=` · `/api/v1/carriers/{carrier}/cat-events` · treaty admin under the REI API family.

### 7.10 Screens
- ENG-7-001 Exposure Explorer (map plus table, gross/net). **Extends** the REI exposure dashboard.
- ENG-7-002 Capacity Check panel embedded in the UW case (**extends** UND-011 Risk Assessment and UND-014 Pricing).
- ENG-7-003 Accumulation Limits admin.
- ENG-7-004 Cat Event Console.
- ENG-7-005 Zone Datasets admin.

Treaty and facultative screens belong to REI-* and are not duplicated here.

### 7.11 Acceptance tests
1. Two warehouses in the same port zone with a gross limit of 5bn: a third risk pushing to 5.2bn gives LIMIT/CAPACITY_EXCEEDED and a referral.
2. A QS of 40% with retention 1bn and SI 3bn: net = 1.8bn and the trace shows the QS step.
3. The same query with `as_of` before a treaty renewal uses the old treaty version.
4. An endorsement moving the location changes the zone contributions from the endorsement effective date.
5. A declared flood event 2026-08-10→12 proposes links only for claims in affected zones inside the window, and the links need confirmation.
6. A snapshot reproduced with the same inputs gives an identical `inputs_hash` and figures.

### 7.12 Open questions
OQ-7.1 The zone dataset to adopt per CIMA country (flood zones, industrial areas, ports) and its source and licence. · OQ-7.2 Is PostGIS available in production? The fallback is bbox or zone codes. · OQ-7.3 Are there CIMA solvency or concentration reporting obligations tied to accumulation (legal input)? · OQ-7.4 The default event hours-clause per peril.

---

## 8. Cross-cutting: how the remaining owner gaps attach to the engines

| Gap | Attaches to | Mechanism |
|---|---|---|
| 16 Golden customer/organization/risk record with roles | E5 (party), E2 (insured objects), E7 (risk_locations) | One `parties` golden record with `party_roles(party_id, role, context_type, context_id, valid_from, valid_to)` (bitemporal). Insured objects and risk locations reference golden IDs. Engines consume only golden IDs |
| 17 Household & corporate relationship graphs | E5 UBO graph | Generalise `ownership_edges` into `party_relationships` (relation_type includes SPOUSE, DEPENDANT, EMPLOYER, GROUP_MEMBER). AML reads the ownership subset. Group/employee benefits and E7 employer accumulation read the others |
| 15 Duplicate entity resolution | E6 | Matching job writes `entity_match_candidates(score, features)`. A probable match opens a DATA_STEWARD case. Merges are maker-checker and non-destructive (`merged_into_id` plus survivorship log). **No auto-merge** |
| 14 Completeness / data-quality gates | E1 + rule-engine expression infra | `completeness_rules` (versioned, per product/class/operation) evaluated at quote, bind, issue and claim. BLOCKING gives an E6 CONFIG_GAP/DATA_QUALITY task. Engine 2 manual cover-summary and Engine 7 geocode quality plug in here |
| 31 Premium-to-cover rule per product/carrier | E2 + E1 | A versioned `premium_cover_rules` row (NO_COVER_UNTIL_PAID / COVER_WITH_GRACE n days / SUSPEND_ON_DEFAULT) drives chronology entries (SUSPENSION/CANCELLATION). The legal basis is OPEN QUESTION OQ-2.4 |
| 33 Payment allocation engine | E1 + E3 + E5 | Allocation order (fees, taxes, oldest instalment…) as a versioned rule. Monitoring on over- and third-party payment (E5). Manual reallocation needs maker-checker (E3 OVERRIDE). Allocation lines are append-only |
| 34 Multi-currency & FX | E1 | `fx_rates(pair, rate, source, valid_on, recorded_at)` immutable. Resolution anchor per §1.2 (value date). Limits (E2) stay in policy currency, and conversion happens at the payment value date with the trace |
| 20 Retention, archive, legal hold, destruction | E6 + E5 | `retention_schedules` per record class (legal periods = OPEN QUESTION). `legal_holds` generalises `document_retention_holds` to any subject, with cases setting holds automatically (STR, litigation). Destruction is an E6 case with maker-checker plus a certificate, and never happens when a hold exists |
| 21 Record immutability & evidentiary integrity | §0.3 triggers + audit_log hash chain | Extend the append-only trigger set to chronology, limit ledger, decisions, evidence and engine_evaluations. A periodic `AuditChainVerifier` recomputes hashes and anchors the daily head hash (external anchoring = OPEN QUESTION) |
| 22 Incoming document intake & classification | E6 + document engine **[IN-PROGRESS DEP 2026_09_29_110001]** | Intake inbox → classify (manual or model) against the 220-document register → link to subject. Low confidence or no subject gives a DOC_INTAKE_EXCEPTION case |
| 23 Correspondence registry | E6 | `correspondence_register(direction, channel, counterparty, subject refs, document_id, sent/received_at, reference_number)`. It is linked from `case_correspondence`, notification deliveries and carrier exchange messages. It gives regulatory proof of dispatch for complaints and notices |
| 3 Market conduct / mis-selling evidence | E1 + E6 | Store the disclosure and suitability artefacts shown at quote (template versions via E1, with the hash) in `proposal_disclosure_responses` (exists). Mis-selling complaints are a COMPLAINT sub-type |
| 4, 27, 29, 30 Complaints, litigation, recoveries, cash-fraud SoD | E6 (+E3) | Case types. SoD comes from E3 capability separation (collect vs reconcile vs refund) |
| 35 Product governance | E4 + rule-engine governance | Product owner, committee, target/prohibited market, review date and retirement as product-version attributes. The review date creates an E6 task. E4 impacts reference them |
| 37 Regulatory returns with lineage | E1 + E4 | Returns are computed with `recorded_as_of` = filing time and store the lineage (engine_evaluations and source row IDs) |
| 39 ICT governance (CIMA Reg. 010-24) | E4 + E6 | Registered as a regulatory change. Obligations become E6 COMPLIANCE tasks. The specific requirements are **OPEN QUESTION (OQ-8.1)**: the owner must supply Reg. 010-24 obligations (incident reporting deadlines, continuity testing, outsourcing notification). None are assumed here |
| 40 Vendor & outsourcing governance | E6 + E3 | Vendor register plus periodic review case type. Screening providers (E5) and carrier APIs are vendors |
| 41 API consent & scoped delegated access | E3 | `integration_clients.scopes` plus authority grants of grantee_type INTEGRATION_CLIENT plus consent records (gap 19 `consents(purpose, subject, valid_from/to)`) checked in the same AuthorityService call |
| 13, 32, 36, 38, 42 | Built on E1/E2 data | Profitability reads the E2 ledgers and E7 net. Failed-issuance compensation is a case type plus the payment allocation reversal. Portfolio transfer is a PORTFOLIO_TRANSFER chronology entry. The inspection workspace is a read-only role over engine_evaluations and audit. The portability export pack is chronology plus terms plus documents |

---

## 9. Dependency-ordered build plan

Order: **E0 → E1 → E6 → E3 → E2 → E4 → E5 → E7**. Reasons: Engines 2–5 need a reference date, and referrals, reviews and alerts all need the case framework. Authority needs cases (referrals). Coverage needs authority (overrides). Reg-change needs the dictionary (in progress). AML needs cases, reg-change parameters and the golden party. Accumulation needs the chronology and treaties.

| Epic | Scope | Acceptance criteria |
|---|---|---|
| **E0 Foundations** | `engine_evaluations`, `engine_overrides`, EngineResult envelope, append-only trigger helper, outbox→audit mirroring for engine events | Envelope persisted for a sample evaluation. UPDATE/DELETE rejected by trigger. Override requires a distinct approver (DB CHECK plus test) |
| **E1.1 Clock & resolver** | `Clock`/`FrozenClock`, `VersionResolver`, `versioned_artifact_registry` seeded for the existing dated tables, `reference_date_rules` seeded from §1.2 | §1.12 tests 1, 2, 4, 5 pass. `CancellationCalculator` refactored with identical results on the existing test suite |
| **E1.2 now() eradication** | Replace direct `now()` in app/Domain and app/Application; `transaction_resolved_versions` wired into quote, offer, issue, endorse and cancel | Arch test (no `now()`) green. Every issued policy has `resolved_versions_id` |
| **E1.3 Bitemporal retrofit** | Add `recorded_at/superseded_at` to tables needing replay (tariffs, product versions, authorizations) | Replay test at a past `recorded_as_of` |
| **E6.1 Case core & calendar** | cases, case_types, events, tasks, decisions, evidence, diary, sla_clocks, queues, calendar tables; SlaService; My Work API | §6.11 tests 1–5. Holiday data imported from `business_calendars` |
| **E6.2 Bridges** | UW, compliance, claim dispute, ticket bridges; ENG-6-001/003/004 screens | §6.11 tests 6–7. UND-002/004/005 retired in favour of ENG-6 views |
| **E3.1 Authority core** | profiles, limits, grants, consumption; AuthorityService with intermediary/carrier chain from the existing register tables; DA agreement backfill | §3.11 tests 1, 2, 4, 6 |
| **E3.2 Referrals & resume** | authority_referrals on E6, ResumableStep in quote, bind, issue, endorse and claim decision | §3.11 tests 3, 5, 7. Referral returns to the originator with conditions applied |
| **E2.1 Chronology** | chronology, terms versions, insured objects; PolicyStateMachine transitions append entries; `policies.status` becomes a projection; backfill from `policy_status_history` + `policy_transactions` | Backfill reconciliation report shows zero unexplained differences. §2.11 tests 1, 2 |
| **E2.2 Coverage-at-loss** | CoverageAtLossService at FNOL and decision; ENG-2-003; manual cover summary + completeness gate | §2.11 tests 1, 2, 6. No claim code path reads `policies.status` for coverage (arch test) |
| **E2.3 Limit accumulator** | definitions, buckets, ledger; wiring into reserves, payments and recoveries | §2.11 tests 3, 4, 5, 7, 8 (including the concurrency test) |
| **E4 Reg-change** | Depends on the **committed** CIMA dictionary (in-progress work); changes, rules, impact analyzer, RegulatoryGate in CimaPublicationGuard | §4.11 tests 1–6. Owner supplies the first real change (OQ-4.1) before go-live |
| **E5.1 Screening** | list sources/versions, ScreeningProvider interface, LocalList adapter, hits disposition, ComplianceGate at bind, issue and pay-out | §5.11 tests 2, 4, 7 |
| **E5.2 Risk, UBO, EDD, monitoring** | ownership graph, risk models, assessments, EDD profiles, rescreening, monitoring rules → alerts → E6 cases | §5.11 tests 1, 3, 6. **Thresholds loaded only after the owner answers OQ-5.2** |
| **E5.3 STR** | restricted STR case type, str_reports, tipping-off controls | §5.11 test 5. Penetration test of confidentiality filters |
| **E7.1 Treaties (minimum) & exposure** | zones, locations, exposure links, programmes/treaty versions (minimum), aggregator, net calculator, snapshots | §7.11 tests 2, 3, 4, 6 |
| **E7.2 Capacity check & cat events** | CapacityCheckService at bind/endorse/renew, referral on exceed, facultative task, cat event console, large-loss notifier | §7.11 tests 1, 5 |
| **X Cross-cutting** | golden record roles, party relationships, entity resolution cases, completeness rules, premium-cover rules, payment allocation, FX, legal holds generalisation, correspondence register, audit chain verifier | Each row of §8 has at least one test proving the engine hook |

### Mapping of epics to blueprint waves (the blueprint's waves govern scheduling)
| Epic | Blueprint wave |
|---|---|
| E0, E1.1–E1.3 | W0 engineering foundation (Temporal = master §105 foundation item 5) |
| E6.1 (case core, calendar, SLA) | W0/W1 foundation for workflow/state; case UI completes in W20 |
| E3.1–E3.2 | W6 underwriting & authority |
| E2.1 | W7 policy administration |
| E2.2–E2.3 | W12 claims foundation / W13 decision & settlement |
| E4 | W2 master data (dictionary) + W23 regulatory reporting |
| E5.1–E5.3 | W3 customer/KYC (screening hook) + W19 compliance & AML |
| E6.2 bridges, complaints | W20 complaints & case management |
| E7.1–E7.2 | W17 reinsurance (treaties) + W22 catastrophe & accumulation |
| X cross-cutting | W3 (golden record, relationships, duplicates), W9 (allocation, FX, premium-to-cover), W8 (intake, retention), W26 (migration) |

Each wave's exit criteria (blueprint) are **in addition to** the epic acceptance criteria above. Traceability follows the blueprint: REQ → domain → workflow → API → screen → test, with ADR-### for the reconciliation items in §0.8 and CR-YYYY-NNNN for changes to this spec.

### Definition of done for every epic
Additive migrations only (no destructive change to existing tables). Every engine decision is persisted as an `EngineResult` with trace. Events are in the outbox and mirrored into the audit hash chain. Adaptive-mode behaviour is tested for MANUAL and CONFIGURED carriers. Verification runs on a real runtime per project practice, not typecheck alone.

## 10. Consolidated open questions for the owner
OQ-0.1 events catalogue · OQ-1.1 template anchor · OQ-1.2 day-boundary timezone · OQ-1.3 treaty basis defaults · OQ-2.1 manual cover-summary minimum set · OQ-2.2 claims-made vs occurrence · OQ-2.3 recovery reinstatement · OQ-2.4 premium-to-cover legal basis (CIMA Code) · OQ-3.1 referral ladders · OQ-3.2 broker bind authority legal basis · OQ-3.3 cumulative period · OQ-3.4 whether to add ENDORSE/BACKDATE/CANCEL/OVERRIDE to the canonical authority enum · OQ-6.4 mapping of engine case types onto the blueprint's 8 case types · ADRs: UUID vs bigint keys, audit_log vs audit_events, regulatory_branches vs insurance_branches · OQ-4.1 list and texts of CIMA regulations 2024–2026 · OQ-4.2 platform regulatory approver · OQ-4.3 national authority modelling · OQ-5.1–5.7 Reg. 003-25 specifics (obliged entity, thresholds, PEP scope, FIU filing, retention, review frequency, mandatory lists, simplified DD) · OQ-6.1 complaint deadlines and escalation path · OQ-6.2 holiday source · OQ-6.3 default SLAs · OQ-7.1 zone datasets · OQ-7.2 PostGIS · OQ-7.3 concentration reporting · OQ-7.4 hours clause · OQ-8.1 Reg. 010-24 ICT obligations · retention periods per record class (gap 20) · external anchoring of the audit hash (gap 21).
