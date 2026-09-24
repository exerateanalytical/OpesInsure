# OpesInsure Product & Insurance Rule Engine: Implementation Plan v1.0

Status: DRAFT for owner review (2026-09-24).
Governs against: `docs/spec/PRODUCT_RULE_ENGINE_SPEC_V1.md` (owner master spec, locked; "§n" below means that spec's section n).
Related specs: `CIMA_REGULATORY_DICTIONARY_V1.md` + `database/data/cima_regulatory_master_2026.json`, `INSTITUTIONAL_SEED_SPEC_V1.md`, `SETUP_CONFIGURATION_FRAMEWORK_V1.md`, `WORKFLOW_REGISTER_SPEC.md`, `SCREEN_SPECIFICATION_REGISTER_V1.md`, `MASTER_SCREEN_REGISTER_GAP.md`, `REMAINING_WORKSTREAMS.md` (workstream 2).

Also governs against: `docs/spec/ADAPTIVE_OPERATING_MODEL_V1.md`, which **overrides any reading of the rule engine as mandatory**. The configured rule engine (Part B, §1–§12) is one optional execution mode per capability. It is chosen through each insurer's Capability Profile (Part A) and built after manual/assisted mode, carrier documents and import. Epics are ordered in §13.

Legend. **EXISTS**: usable as-is. **EXTEND**: the table or service exists and gets additive changes. **NEW**: it has to be built. Every migration is additive (new tables, new nullable columns, new constraints validated after backfill). No existing column is dropped or renamed while old rows depend on it. Values marked **DEMO** are synthetic and must never be shown as an insurer's quote. **OPEN QUESTION (OQ-n)** marks an input the owner or an insurer still has to give.

Concurrency note: the CIMA dictionary and register-seeding workstreams are in flight. This plan uses their specs (dictionary tables `cima_*`, `product_regulatory_mappings`, `insurer_authorizations`, `insurance_classes`). It does not rely on their in-progress code. Where they define a table, this plan names it and consumes it but does not redefine it.

---

## 0. Today's baseline: what exists in code

| Area | Code today (paths) | Assessment |
|---|---|---|
| Lines | `insurance_lines` (id, code, name, description, status, risk_schema jsonb). `app/Application/Catalogue/CatalogueService::createLine` | One flat level. No CIMA branch, class, or family. The taxonomy in `cameroon_insurance_register_2026.json` is not linked to it. |
| Classes / authorizations | `insurance_classes` (parent_id, branch, code, name, sort_order, provenance) and `insurer_authorizations` (carrier_id, reference_year, branch IARD/LIFE, status, effective dates). Migration `2026_09_26_090001_*` | Here "branch" means IARD, LIFE or CAPITALIZATION. It is not the Art. 328 branch number. The CIMA workstream adds branch-level authorization. |
| Products | `insurance_products` (carrier_id, line_code, code, name, version int, effective_from/until, status, coverages jsonb, eligibility_rules jsonb, created_by, published_by, published_at, regulatory_reference). `product_status_history`. `CatalogueService::createProduct/submit/publish` | The product row is also the version row. Status flow is DRAFT→IN_REVIEW→ACTIVE. Maker-checker exists. Publish requires `regulatory_reference` text and an approved tariff. It has no EN/FR fields, plans, customer type or CIMA gate. |
| Coverage | `coverage_definitions` (line-scoped: code, name, description, limit_type, mandatory, status). `product_coverages` pivot (default_limit_minor, default_deductible_minor, configuration jsonb, is_optional, display_order) | Limits and deductibles are single integers. It has no limit basis, deductible type, waiting period, territory, or per-plan set. |
| Exclusions | `exclusion_definitions` (line-scoped) and `product_exclusions` (configuration) | Exclusions exist only at product level. There is no legal text versioning. |
| Eligibility | `insurance_products.eligibility_rules` = `{conditions:[{fact,operator,value}]}`, evaluated by the private method `QuoteService::eligible()` (EQUALS/IN/BETWEEN, AND only) | The result is a boolean only. There is no trace, and the engine silently skips ineligible products. |
| Questions | `RiskSchemaCatalogue` (hard-coded PHP: MOTOR, HEALTH, TRAVEL, HOME, LIFE, BUSINESS, ACCIDENT), copied to `insurance_lines.risk_schema`. `disclosure_schema_versions` (per line, questions jsonb, hash, maker-checker) with `proposal_disclosure_responses` (answers, referral_flags) | Questions exist at two unversioned or line-level places. They are not product-version scoped and have no effects model. |
| Rating | `app/Domain/Rating/DeterministicRatingEngine` (18 lines): base_premium_minor, then sequential basis-point factors (EQUALS/IN/BETWEEN), then one tax at basis_points on the premium, then one fixed fee. `RatingResult` | The engine is deterministic and in minor units, which is good. It has no coverage-level premium, bands/tables, min premium, discount cap, rounding policy, statutory charges, or branch allocation. |
| Tariffs | `tariff_versions` (product, version, effective dates, status, input_schema, rules, rules_hash, created_by, approved_by, approval_reason, regulatory_reference). `tariff_status_history`. `app/Application/Rating/TariffGovernanceService` (DRAFT→IN_REVIEW→APPROVED, maker-checker, hash check, overlap guard) | Governance is solid. The workflow states are fewer than §75. |
| Taxes/fees | `tax_levy_versions` (jurisdiction, line_code, version, effective, status, rules, hash) and `fee_schedule_versions` (tenant_id nullable, code, rules). `QuoteService::activeRules()` picks the latest APPROVED row | Each is a single rule blob per line. There is no charge-code catalogue, no basis per charge, and no accessory/statutory split. |
| Rating trace | `rating_runs` (quote, tariff/fee/tax version ids, input_hash, input/output snapshot, status, failure_reason) | This is good for reproducibility. Note that `QuoteService::rate` currently writes only `tariff_version_id`: fee and tax version ids are not stored. That is a bug to fix in E3. |
| Quote | `quotes` (risk_facts, expires_at +7d hard-coded, rated_at, comparison_context) and `quote_offers` (premium/tax/fee/total_minor, calculation_breakdown, coverage_snapshot, valid_until, comparison_rank). `QuoteService`, `MobileQuoteService` | Validity is hard-coded. Offers do not record plan or rule versions. |
| Proposal/UW | `proposals` (terms_snapshot, disclosure_schema_version_id). `ProposalService`, `MobileProposalService`, `UnderwritingService`, `underwriting_cases` (referral_reasons), `underwriting_decisions`, `underwriting_referral_tasks`. `document_requirement_versions` (line_code, code, rules, mandatory) with `DocumentRequirementService`. `proposal_documents` (requirement_code, status, verified_by) | Referrals come from disclosure flags. There is no rule-driven decision service and no authority matrix by amount. |
| Issuance | `PolicyIssuanceService` (terms_snapshot + terms_hash, authority_snapshot, previous_policy_id, policy_number passed in) | A snapshot exists but it is not structured to §84. Numbering is supplied by the caller. |
| Renewal | `RenewalService` + `renewal_cases`, `renewal_work_items`. Renewal re-submits a quote with old facts | There is no renewal rule version (window, rerate policy). |
| Cancellation | `cancellation_rule_versions` (line_code, basis, short_rate_basis_points, admin_fee_minor, maker-checker) via `PolicyConfigurationController` | EXTEND to carrier/product scope with reasons and notice. |
| Endorsement | `policy_transactions`, `policy_transaction_events` | No endorsement-type rule table. |
| Claims | `claims`, `claim_documents`, `ClaimEvidenceService` (attach only), `claim_reserve_changes`, `claim_decisions`, `claim_payments`, `claim_recoveries` | No claim types, evidence rules, deadlines, coverage evaluation or settlement calculator. |
| Commission | `commission_rule_versions` (carrier, product, partner, basis_points, vesting_days, holdback, conditions, rule_hash) | EXTEND for transaction type, tiers and internal split. |
| Distribution | `delegated_authority_agreements` (permitted_lines, max premium, territories), `marketplace_publications` | The seed spec's `carrier_broker_agreements` and product permissions come from the register workstream. |
| Documents | `certificate_templates`, `document_versions`, `notification_templates` | No per-product-version template binding. |
| Admin UI | Filament `app/Filament/Admin/Resources/{InsuranceLines,InsuranceProducts,CoverageDefinitions,ExclusionDefinitions,TariffVersions,DisclosureSchemas,DocumentRequirements,CancellationRules,CommissionRules,...}` | Staff-only CRUD. The carrier-facing product builder is missing (see the CAR-015/018/022 rows in the gap register). |
| APIs | `routes/api.php` L162–207: `catalogue/lines`, `catalogue/products` (+submit/publish), `tariffs` (+submit/approve), `underwriting/disclosure-schemas`, `underwriting/document-requirements`, `configuration/cancellation-rules` | Keep these as compatibility routes and add the owner's routes (§8). |

Main structural gap: **one product row is both the carrier product and its version, and each rule type has its own ad-hoc JSON dialect.** The plan (a) separates product from version without breaking existing FKs, and (b) moves every rule onto one expression engine.

A second structural gap comes from the adaptive model: **today's runtime assumes Opes does everything.** `QuoteService::rate` always rates through the engine and silently skips products without an approved tariff. `PolicyIssuanceService` always issues from `proposal.terms_snapshot` and generates the documents itself (`PolicyDocumentService::ensure`). Claims assume Opes adjudication. There is no way to record an insurer-supplied premium, or to store the insurer's own policy/attestation as the authoritative document. The existing assets to build on are:
- `carrier_exchange_messages` (carrier_id, direction, message_type, correlation_id, external_reference, status, payload, payload_hash, sent_at, acknowledged_at, failure_reason), which is the natural transport log for quote requests and responses.
- `policy_issuance_requests.carrier_reference`.
- `external_record_mappings` (source_of_truth, sync status), for API mode.
- `documents`/`document_versions` (storage_key, scan_status, verification_status, uploaded_by).
- The adapter pattern already proven in `app/Application/Payments/Adapters/PaymentProviderAdapter` + `PaymentAdapterRegistry`.

---

# PART A — Adaptive operating layer (built first)

## A1. Strict core (unchanged regardless of mode)
Tenant isolation, RBAC, audit (`AuditWriter`), CIMA master data, the insurer authorization gate (§4.3, which applies in **every** mode, including manual), historical version preservation, payment integrity, document provenance, policy and claim identity (Opes `policy_number` always exists; the carrier number is stored alongside), commission traceability, maker-checker, idempotency (`idempotency_keys`), immutable transactions, and reconciliation. Each adapter below must pass the same integrity checks before its result is accepted into the workflow.

## A2. Insurance Company Capability Profile
NEW migration `A-M01_capability_profiles`:
- `carrier_capability_profiles`: id, carrier_id, version, status (DRAFT/APPROVED/ACTIVE/SUPERSEDED), effective_from, effective_until, maturity_level (1–5, derived and cached), created_by, approved_by, approved_at, notes. Maker-checker. Versioned and never overwritten.
- `carrier_capability_modes`: profile_id, capability (enum below), mode (enum per capability), scope_product_id null (product-level override), scope_class_code null, config jsonb (e.g. SLA hours for manual response, API endpoint ref → `integration_clients`), fallback_mode null (e.g. CARRIER_API → MANUAL on outage).

Capabilities and modes follow the owner's table:

| capability | modes |
|---|---|
| PRODUCT_CATALOGUE | BASIC, CONFIGURED, CARRIER_API |
| PRICING_SOURCE | MANUAL_PREMIUM, UPLOADED_TARIFF, EXCEL_IMPORT, CONFIGURED_RULES, CARRIER_API |
| QUOTATION | MANUAL, CONFIGURED, REMOTE_API |
| UNDERWRITING | NOT_REQUIRED, MANUAL, RULE_ENGINE, REMOTE_INSURER, HYBRID |
| PAYMENT | BROKER_COLLECTION, INSURER_COLLECTION, MOBILE_MONEY, BANK, EXTERNAL_PROVIDER |
| POLICY_ISSUANCE | MANUAL_UPLOAD_CARRIER, MANUAL_UPLOAD_BROKER, OPES_GENERATED, INSURER_API, HYBRID |
| DOCUMENT_GENERATION | CARRIER_ORIGINAL, OPES_TEMPLATE, HYBRID |
| ENDORSEMENT, RENEWAL | MANUAL, CONFIGURED, REMOTE_API |
| CLAIMS_INTAKE | BROKER_ASSISTED, INSURER_PORTAL, FULLY_DIGITAL, API_SYNCHRONIZED |
| CLAIMS_DECISION, CLAIMS_SETTLEMENT | MANUAL_CARRIER, OPES_WORKFLOW, API_SYNCHRONIZED |
| COMMISSION | MANUAL_STATEMENT, CONFIGURED_RULES, IMPORTED |
| ACCOUNTING | OPES_LEDGER, EXPORT_ONLY |
| REGULATORY_REPORTING | OPES_GENERATED, CARRIER_SUPPLIED |
| API_INTEGRATION | NONE, SELECTED, FULL |

Resolution: `CapabilityResolver::mode(carrier, capability, product?, on)`. It takes the product-level override first, then the class-level one, then the carrier default. The resolved mode and profile version are **pinned** on the quote, proposal, issuance request and claim (`capability_profile_version_id`, `execution_mode` columns added by A-M02). This keeps replays and audits reproducible after a carrier changes profile.

Maturity level (derived, a descriptor, not a gate or grade):
- 1 Registry: identity, CIMA branches, profile, and basic catalogue exist.
- 2 Operational: manual quote/issuance/renewal/claims/commission modes are active.
- 3 Configured: any CONFIGURED/RULE_ENGINE/OPES_* mode is active.
- 4 Connected: any API mode is active.
- 5 Integrated: quote, issuance, claims and payment are all API.

Validation: some mode combinations are incoherent and are rejected. Examples are CONFIGURED_RULES pricing without an ACTIVE tariff, RULE_ENGINE underwriting without a published rule set, and INSURER_API without a healthy `integration_clients` row. The configured-mode prerequisites in §4.4 apply **only** to capabilities set to configured modes.

## A3. Execution adapters (§ adapter architecture)
Interfaces in `app/Application/Execution/Contracts`. Each has a registry (the same pattern as `PaymentAdapterRegistry`) selected by the resolved mode.

| Interface | Implementations | Contract (all idempotent on `correlation_id`) |
|---|---|---|
| `QuoteProvider` | `ManualQuoteProvider`, `ConfiguredRatingQuoteProvider` (wraps Part B RatingService, or today's `DeterministicRatingEngine` for v1 tariffs), `CarrierApiQuoteProvider` | `requestQuote(QuoteRequest): QuoteRequestHandle` (sync result or PENDING) and `acceptResponse(handle, CarrierQuoteResponse): QuoteOffer` |
| `UnderwritingProvider` | `NotRequired`, `ManualUnderwriting` (today's `UnderwritingService` case/decision), `RuleEngineUnderwriting`, `CarrierApiUnderwriting`, `HybridUnderwriting` (rules pre-screen, then manual/remote for REFER) | `evaluate(Proposal): UnderwritingOutcome{decision, conditions, source, evidence_ref}` |
| `PolicyIssuer` | `ManualUploadIssuer` (carrier or broker), `OpesIssuer` (today's `PolicyIssuanceService` + `PolicyDocumentService`), `CarrierApiIssuer` | `issue(IssuanceRequest): IssuanceResult{policy, carrier_policy_number, documents[], provenance}` |
| `ClaimProvider` | `ManualCarrierClaims`, `OpesClaims` (today's `ClaimLifecycleService`), `CarrierApiClaims` | `submit`, `recordDecision`, `recordSettlement`, with the source recorded on every event |
| `PaymentExecution` | `BrokerCollection`, `CarrierCollection`, `MobileMoney` (existing MTN/Orange adapters), `Bank`, `ExternalGateway` | Wraps `PaymentInitiationService`. Broker/carrier collection records an off-platform receipt with evidence and requires reconciliation |

The workflow services (quote, proposal, issuance, claims) are refactored to call the resolved adapter. The state machines stay the same. Adapter results that change money or legal state go through the same guards: tenant scope, authorization gate, maker-checker thresholds, idempotency, and audit.

## A4. Mode 1: manual/assisted (first epic)
Flow (the broker is the primary actor, and the carrier may act through the carrier portal or not at all):
1. **Broker creates the quote request**: customer, product (from the broker's authorized catalogue, §9 distribution), risk facts (question set; for a basic product only a minimal schema per class from `RiskSchemaCatalogue`), and requested cover. `quotes.status = SUBMITTED`, `execution_mode = MANUAL`.
2. **Send to insurer**: this creates `carrier_quote_requests` (NEW: id, quote_id, carrier_id, product_id, correlation_id, channel PORTAL/EMAIL/WHATSAPP/PHONE/IN_PERSON/API, sent_at, sla_due_at, status SENT/ACKNOWLEDGED/RESPONDED/DECLINED/EXPIRED/WITHDRAWN) and a `carrier_exchange_messages` row (direction OUT). If the carrier has portal users, it appears in their queue. Otherwise the broker marks it "sent by <channel>", and the platform sends nothing externally unless a channel integration is configured.
3. **Insurer responds**. There are two paths:
   - (a) A carrier user enters the response in the portal.
   - (b) The broker records the response on the carrier's behalf with **evidence**: the insurer's quote PDF, email, or signed note uploaded as a document.
   The response is stored as `carrier_quote_responses` (NEW: id, request_id, responded_by_actor_type CARRIER_USER/BROKER_ON_BEHALF/API, responder_id, premium components: net_premium_minor, accessories_minor, taxes_minor, levies_minor, fees_minor, total_minor, currency, valid_until, conditions text, coverage summary jsonb, carrier_quote_reference, evidence_document_id (required when BROKER_ON_BEHALF), payload_hash).
4. **Broker records the approved premium**: this creates a `quote_offers` row with `pricing_source = MANUAL_PREMIUM`, `carrier_quote_response_id`, and `pricing_snapshot` (schema 2 with `source:"CARRIER_MANUAL"`, no tariff ids). CIMA branch allocation:
   - If the carrier gave a split, use it.
   - Otherwise the allocation comes from the product's default mapping. It is flagged `allocation_basis = DEFAULT_MAPPING` for reporting and needs a later true-up (OQ-24).
   - Any change between the carrier's response and the recorded offer is a **controlled override** (A7).
5. Proposal, KYC and payment follow the existing flow, with the PaymentExecution mode taken from the profile.
6. **Issuance, MANUAL_UPLOAD**: the carrier or broker uploads the carrier's original policy, attestation, cover note or receipt. This creates a `policy_issuance_requests` row with `carrier_reference` = the carrier policy number. `PolicyIssuer::ManualUploadIssuer` creates the Opes `policies` row with `issuance_source = CARRIER_ORIGINAL`, snapshot kind ISSUANCE (source-agnostic schema: carrier_policy_number, carrier premium, document ids). It **does not** generate Opes documents unless the DOCUMENT_GENERATION mode is HYBRID/OPES_TEMPLATE. Motor attestations still link to sticker stock where applicable (OQ-25).
7. **Delivery**: the customer sees the carrier's documents in the app and web, through existing `documents` access control and notification templates. Public verification returns the carrier document's provenance status (VERIFIED_BY_CARRIER / UPLOADED_BY_BROKER / DEMO_VALID).

Integrity in manual mode: the authorization gate is still enforced at request time. A policy cannot be recorded without a carrier document **and** a confirmed payment (or a carrier-attested credit arrangement, OQ-26). Broker-on-behalf uploads above a premium threshold require carrier confirmation or second broker approval, following the authority matrix.

## A5. Carrier original documents (provenance)
EXTEND `documents` with A-M03 (all additive):
- `issuer_type` (CARRIER/BROKER/OPES/CUSTOMER/THIRD_PARTY) and `issuer_id`
- `document_type` (canonical CIMA document codes from the dictionary: POLICY, ATTESTATION, COVER_NOTE, ENDORSEMENT, RECEIPT, GENERAL_CONDITIONS, SPECIAL_CONDITIONS, CLAIM_* …)
- `policy_id` null and `claim_id` null
- `issued_on` date, `carrier_document_number`, `document_version` smallint
- `provenance` (CARRIER_PORTAL_UPLOAD / BROKER_UPLOAD_ON_BEHALF / CARRIER_API / OPES_GENERATED / IMPORTED)
- `provenance_evidence` jsonb (uploader, channel, original filename, sha256, received_at, transport message id)
- `verification_status` values extended with CARRIER_CONFIRMED / PENDING_CARRIER_CONFIRMATION / REJECTED
- `supersedes_document_id`

Rules:
- The sha256 is computed at upload, and the stored object is immutable (a new version creates a new `document_versions` row).
- A carrier user may confirm a broker upload, which flips it to CARRIER_CONFIRMED and is audited.
- Customer-facing labels come from `RegulatoryTerminologyService` in EN/FR.
- Existing virus scan and retention holds apply unchanged.

## A6. Simple product onboarding (5 questions)
`SimpleProductOnboardingService::create(carrier, answers)` creates a carrier product with a **BASIC** version.
1. **What do you sell?** Pick a normalized product (MOTOR_THIRD_PARTY, MOTOR_COMPREHENSIVE, HEALTH_INDIVIDUAL, TRAVEL, PROPERTY, TERM_LIFE…) from the register taxonomy. The service auto-creates the `product_regulatory_mappings` from the CIMA dictionary `example_product_mappings` / normalized-product defaults (e.g. MOTOR_COMPREHENSIVE → 10 PRIMARY, 3 PRIMARY, 18 ACCESSORY), then runs the authorization gate at once. If the insurer is not authorized for a required branch, the product is blocked with the reason shown in plain language. CIMA codes stay hidden from sales screens and visible only in admin/compliance views.
2. **How is it priced?** This sets `PRICING_SOURCE` and `QUOTATION` for the product.
3. **How is underwriting done?** This sets `UNDERWRITING`.
4. **How is the policy issued?** This sets `POLICY_ISSUANCE` and `DOCUMENT_GENERATION`.
5. **How are claims handled?** This sets `CLAIMS_*`.

The answers are stored as product-level `carrier_capability_modes` overrides, and the whole thing goes through a normal maker-checker approval. A BASIC version needs only: identity (EN/FR name), mapping + gate AUTHORIZED, a basic coverage list (codes from the class default set, limits optional), optional indicative premium info, and carrier documents (conditions PDF). The Part B completeness checklist is **not** applied. `product_versions.config_level = BASIC | CONFIGURED`. A carrier can upgrade a version to CONFIGURED later by cloning.

## A7. Controlled human override (all modes)
NEW `value_overrides` (generalizes `premium_overrides` in M10): id, subject_type, subject_id, field, previous_value jsonb, new_value jsonb, reason_code, notes, actor_id, authority_matrix_row_id, approval_required bool, approved_by, approved_at, status (APPLIED/PENDING_APPROVAL/REJECTED), created_at. Append-only. The service `OverrideService::apply()` checks the authority matrix (domain OVERRIDE) and applies immediately below the threshold. Above it the override stays PENDING until a different user approves it. It never edits a value in place without a row. It covers premium, discount, valid_until, sum insured, commission, claim reserve and document reclassification.

## A8. Excel/CSV import framework
NEW tables:
- `import_jobs`: id, tenant_id, carrier_id null, entity (PRODUCTS / TARIFFS / POLICY_PORTFOLIO / CUSTOMERS / VEHICLES_FLEET / CLAIMS / RENEWALS / COMMISSIONS / BROKER_AGREEMENTS / AGENTS), status (UPLOADED → MAPPED → VALIDATED → PREVIEWED → APPROVED → IMPORTING → COMPLETED / FAILED / CANCELLED), source_document_id (uploaded file, sha256), mapping_template_id, row_count, error_count, created_by, approved_by (≠ created_by), dry_run_summary jsonb, idempotency_key.
- `import_mapping_templates`: entity, carrier_id null, name, column_map jsonb, value_maps jsonb, for reuse across monthly uploads.
- `import_rows`: job_id, row_number, raw jsonb, normalized jsonb, status (VALID/ERROR/WARNING/DUPLICATE/IMPORTED/SKIPPED), errors jsonb, target_type, target_id.

Pipeline (`app/Application/Imports`):
- `ImportUploadService` (xlsx via PhpSpreadsheet read-only, csv; max size/rows OQ-27).
- `ColumnMappingService` (auto-suggest by header synonyms EN/FR).
- `ImportValidator` per entity, reusing domain validation: canonical IDs, the authorization gate for products, the policy number uniqueness scope, party matching by canonical ID/normalized name/identifiers, and the no-duplicate rule of the seed spec.
- Preview with an error file download, then error resolution (edit row or re-upload), then approval (maker-checker), then `ImportExecutor` (chunked, idempotent upserts keyed on canonical/external IDs, `data_origin = IMPORTED`, each created record audited with the job id).
- Portfolio import creates policies with `issuance_source = IMPORTED` and snapshot kind IMPORT, and never triggers payments or notifications unless explicitly opted in.
- TARIFFS import feeds `UPLOADED_TARIFF`/`EXCEL_IMPORT` pricing: the file becomes a `tariff_tables` grid on a DRAFT tariff version, which then goes through the §4.2 approval.

## A9. Broker flexibility
Broker features are gated by `partner_feature_profiles` (NEW: partner_id, tier SMALL/LARGE/CUSTOM, enabled modules jsonb). These are feature flags scoped per tenant, using the existing flag mechanism (SETUP framework §feature flags). They never loosen any control in A1.

---

# PART B — Configured mode (owner master spec; opt-in per capability)

---

## 1. Section-by-section mapping (owner spec §1–§101)
The epic IDs E0–E12 in §1–§12 are the Part B working IDs. They map to the ordered plan in §13 as E0→A0, E1→B1 (the minimal split is in A2), E2→B2 (the gate is in A2), E3→B3, E4→B4, E5→B5, E6→B6, E7→B7, E8→B8, E9→B9, E10→B10, E11→B11, E12→B12, E13→C4, E14→B13.

| Owner § | Topic | Classification | Target (tables / services) | Epic |
|---|---|---|---|---|
| 1–3 | Kernel principles, layers, hierarchy | NEW (architecture) | Module layout in §9, no insurer-specific branches in controllers | E0 |
| 4–5 | CIMA mapping + regulatory gate | NEW (consumes CIMA workstream) | `product_regulatory_mappings` (CIMA spec), `RegulatoryValidationService` | E2 |
| 6 | Class list | EXTEND | `insurance_classes` + `product_families`, with `insurance_lines` kept as the normalized class | E1 |
| 7 | Carrier product | EXTEND | `insurance_products` gets EN/FR, family, customer_types, currency | E1 |
| 8 | Versions | NEW | `product_versions`. Existing product rows become version 1 | E1 |
| 9 | Activation prerequisites | NEW | `ProductCompletenessService` (checklist, blocking vs warning) | E2 |
| 10 | Plans/packages | NEW | `product_plans`, `plan_coverages` | E1 |
| 11–13 | Coverage, limits, deductibles | EXTEND | `coverage_definitions` + `product_version_coverages` (new, replaces pivot for new versions); `CoverageResolver`, `DeductibleCalculator` | E1/E6 |
| 14 | Exclusions (6 levels) | EXTEND | `exclusion_definitions` + `exclusion_texts` + `product_version_exclusions` (level, target) | E1 |
| 15 | Extensions | NEW | `product_version_coverages.kind = EXTENSION` + effects expressions | E1 |
| 16 | Risk objects | EXTEND | `risk_assets.type` enumerated to §16 list; `risk_object_types` per version | E1 |
| 17–18 | Questions, conditional | EXTEND | `question_sets` / `question_definitions` (per version), superseding `RiskSchemaCatalogue` and `disclosure_schema_versions` for new versions | E4 |
| 19–22 | Eligibility, expressions, priority, stop | NEW | `rule_sets`, `rules`, `ExpressionEvaluator`, `ProductEligibilityService` | E3 |
| 23–25 | UW decisions, authority, score | NEW/EXTEND | `underwriting_rules` via `rule_sets(domain=UNDERWRITING)`, `authority_matrices`, `UnderwritingDecisionService`; `underwriting_cases` extended | E5 |
| 26–33 | Rating, methods, tables, discounts, loadings, charges | EXTEND | `tariff_versions.rules` v2 schema, `tariff_tables`, `charge_definitions` / `charge_versions`, `RatingEngine` (replaces `DeterministicRatingEngine` behind an interface) | E3/E4 |
| 34–36 | Quote sequence, pricing snapshot, validity | EXTEND | `quote_offers.pricing_snapshot`, `quote_offers.product_version_id`/`plan_id`, validity from version | E4 |
| 37–38 | Proposal, document requirements | EXTEND | `document_requirement_versions` scoped to product version and driven by expression | E5 |
| 39 | POLICY_ISSUABLE | NEW | `PolicyIssuabilityService` | E6 |
| 40–42 | Effective date, duration, instalments | NEW | `policy_rule_versions` | E6 |
| 43–44 | Endorsement rules, MTA | NEW | `endorsement_rule_versions`, `EndorsementRatingService` | E7 |
| 45–46 | Renewal | EXTEND | `renewal_rule_versions`, `RenewalRatingService`; `RenewalService` uses them | E7 |
| 47 | Cancellation | EXTEND | `cancellation_rule_versions` + carrier/product scope, reasons, notice | E7 |
| 48 | Suspension/reinstatement | NEW | `suspension_rule_versions` (or `policy_rule_versions.suspension`) | E7 |
| 49–56 | Claims coverage, types, evidence, deadlines, reserves, limits, settlement | NEW/EXTEND | `claim_type_definitions`, `claim_rule_versions`, `ClaimCoverageService`, `ClaimSettlementCalculator`, `claim_limit_ledger` | E8 |
| 57–59 | Commission, clawback, internal split | EXTEND | `commission_rule_versions` + `transaction_type`, `method`, `tiers`; `broker_internal_splits`; `CommissionEngine` | E9 |
| 60–62 | Sellability, channels, territory | NEW | `ProductDistributionService` over agreements/permissions from the seed workstream plus `product_version_channels` | E9 |
| 63–65 | Templates per version | EXTEND | `product_document_templates` binding version → `certificate_templates`/`document_versions` | E6 |
| 66–73 | Life, beneficiaries, group, fleet, cargo, construction, agri | NEW (phased) | §7 of this plan | E10/E11 |
| 74–75 | Product and tariff workflows | EXTEND | Status enums on `product_versions` and `tariff_versions` + `config_approvals` | E2 |
| 76–77 | Sandbox, explainability | NEW | `simulation_runs`, `rule_evaluation_traces` | E3/E10 |
| 78 | Portfolio simulation | NEW | `PortfolioSimulationJob` | E10 |
| 79–82 | Diff, future-dated, rollback, dependency graph | NEW | `ConfigurationDiffService`, scheduled publication, suspend/republish, `config_dependencies` | E2/E10 |
| 83–84 | Policy + renewal snapshot | EXTEND | `policies.terms_snapshot` v2 schema (§6) + `policy_snapshots` | E6 |
| 85–92 | Architecture, services, events, tables, APIs | NEW | §9/§11 | all |
| 93–99 | Product Builder UI | NEW | §12 | E12 |
| 100 | Runtime chain | EXTEND | §10 orchestration | E4–E9 |
| 101 | Definition of Done | — | §13 mapping | — |

---

## 2. Layered data model and migrations

### 2.1 Product vs version split (the key migration)

Decision: **keep `insurance_products` as the carrier product and add `product_versions`.** FKs from `quote_offers.product_id`, `tariff_versions.insurance_product_id`, `commission_rule_versions.product_id`, `product_coverages`, `product_exclusions` stay valid. New FKs `product_version_id` are added beside them. This avoids rewriting every FK.

Backfill: for each existing `insurance_products` row, create `product_versions(version = insurance_products.version, status mapped ACTIVE→PUBLISHED / IN_REVIEW→REVIEW / DRAFT→DRAFT, effective dates copied, eligibility from eligibility_rules)`. Then copy `product_coverages` into `product_version_coverages` and `product_exclusions` into `product_version_exclusions`. Existing rows that share (carrier_id, code) but have different `version` values collapse into one product with several versions. The surviving product row is the lowest id, and the others get `superseded_by_product_id`. OQ-1: confirm there are no production quotes that point at the rows being merged. If there are, keep them as-is and flag the rows `legacy=true`.

### 2.2 Migration list (dependency-ordered, all additive)

`M01_product_families_and_class_links`
- `product_families`: id uuid, class_code (FK `insurance_lines.code`), code unique, name_en, name_fr, sort_order, status, timestamps.
- `insurance_lines` add: `insurance_class_id` uuid null FK `insurance_classes`, `name_fr`, `description_fr`, `class_kind` (NON_LIFE/LIFE), `risk_object_types` jsonb default `[]`.

`M02_carrier_product_extend`
- `insurance_products` add: `family_id` null, `name_en`, `name_fr`, `description_en`, `description_fr`, `customer_types` jsonb (§7 enum list), `currency` char(3) default XAF, `lifecycle_status` (ACTIVE/SUSPENDED/RETIRED) null, `data_origin`, `is_demo` bool default false, `superseded_by_product_id` null, `legacy` bool default false.

`M03_product_versions`
- `product_versions`: id, insurance_product_id FK, version uint, status (§4.1), effective_from date, effective_until null, sales_start_at, sales_end_at, new_business_allowed bool, renewal_allowed bool, quote_validity_days smallint default 7, question_set_id null, config_hash char(64) (canonical hash of the whole resolved config at publication), created_by, submitted_by, approved_by, published_by, published_at, scheduled_publish_at, suspended_at, retired_at, cloned_from_version_id null, change_summary text, timestamps. Unique (insurance_product_id, version). Partial unique index: at most one PUBLISHED version per product whose effective range overlaps a given date. The overlap guard lives in the service, as in `TariffGovernanceService`.
- `product_version_status_history`: same shape as `product_status_history` + `product_version_id`.

`M04_plans_and_coverages`
- `product_plans`: id, product_version_id, code, name_en, name_fr, tier_rank, eligibility_rule_set_id null, pricing_ref (tariff plan key), status, display_order.
- `coverage_definitions` add: `name_fr`, `description_fr`, `default_cima_branch` smallint null, `kind` (BASE/OPTIONAL_GUARANTEE/EXTENSION/ASSISTANCE) default BASE.
- `product_version_coverages`: id, product_version_id, plan_id null (null = all plans), coverage_definition_id, role (MANDATORY/DEFAULT_OPTIONAL/OPTIONAL), `limit_type` (§12 enum), `limit_amount_minor` null, `limit_percent_bp` null, `limit_basis` (SUM_INSURED/LOSS/null), `limit_scope` (PER_EVENT/PER_PERSON/PER_YEAR/PER_POLICY_PERIOD/AGGREGATE), `parent_coverage_id` null (SUB_LIMIT), `sum_insured_source` (FACT key / FIXED / NONE), `deductible` jsonb (§2.4), `waiting_period_days` smallint default 0, `territory_codes` jsonb (ISO / CEMAC / WORLD), `period_rule` null, `cima_branch` smallint not null, `cima_mapping_type` (PRIMARY/ACCESSORY/COMPLEMENTARY), `premium_component_code` (links to tariff output), `effects` jsonb (expression refs for extensions), display_order.

`M05_exclusions_extend`
- `exclusion_texts`: id, exclusion_definition_id, version, text_en, text_fr, legal_reference, effective_from, status.
- `product_version_exclusions`: id, product_version_id, exclusion_definition_id, exclusion_text_id, level (PRODUCT/PLAN/COVERAGE/CUSTOMER/RISK/CLAIM), target_id null (plan or coverage id), condition_rule_id null (for CUSTOMER/RISK/CLAIM-level conditional exclusions).

`M06_rule_engine`
- `rule_sets`: id, owner_type (product_version / tariff_version / platform / carrier), owner_id, domain (ELIGIBILITY / UNDERWRITING / DOCUMENTS / REFERRAL / DECLINE / RATING_FACTORS / CLAIMS_EVIDENCE / CLAIMS_ELIGIBILITY / ENDORSEMENT / DISTRIBUTION / COMMISSION), version, status, content_hash, created_by, approved_by, approved_at, effective_from, effective_until.
- `rules`: id, rule_set_id, code (stable across versions), name_en, name_fr, priority int, stop_processing bool, condition jsonb (expression §3), outcome jsonb, explanation_en, explanation_fr, enabled bool. Unique (rule_set_id, code).
- `fact_dictionary`: id, class_code, fact_key, data_type, unit, source (QUESTION/RISK_OBJECT/PARTY/POLICY/CLAIM/DERIVED/CONTEXT), derivation jsonb null, pii bool, version. Unique (class_code, fact_key, version).
- `rule_evaluation_traces`: id, context_type (quote/rating_run/proposal/underwriting_case/claim/simulation_run), context_id, domain, rule_set_id, rule_set_version, rule_id, rule_code, condition_hash, inputs jsonb (only the facts referenced), result bool, outcome jsonb, evaluated_at, engine_version. Partitioned by month (OQ-2: retention period).

`M07_questions`
- `question_sets`: id, product_version_id null or line_code (platform default), version, status, schema_hash, created_by, approved_by.
- `question_definitions`: id, question_set_id, code, type (§17 enum), label_en, label_fr, help_en, help_fr, step_code, order, required bool, validation jsonb, options jsonb (code, label_en, label_fr), visibility jsonb (expression), fact_key (FK logical to fact_dictionary), stage (QUOTE/PROPOSAL/BOTH), pii bool.
- Backfill: generate a platform question set per line from `RiskSchemaCatalogue::all()` and one per `disclosure_schema_versions` row. Mobile `GET /mobile/catalogue/lines/{code}/risk-schema` keeps its response shape through an adapter.

`M08_tariff_v2`
- `tariff_versions` add: `product_version_id` null, `rules_schema_version` smallint default 1 (1 = legacy DeterministicRatingEngine format, 2 = §5 format), `workflow_status` (§4.2) null, `scheduled_at`, `actuarial_reviewed_by`, `actuarial_reviewed_at`, `is_demo` bool.
- `tariff_tables`: id, tariff_version_id, code, dimensions jsonb (ordered fact keys + band definitions), cells jsonb, interpolation (NONE/LINEAR), hash.

`M09_charges`
- `charge_definitions`: id, code unique (e.g. `CM_TAX_TCA`, `CM_FEE_ACCESSORY`), kind (TAX/STATUTORY_LEVY/ACCESSORY_FEE/POLICY_FEE/STAMP/FUND_CONTRIBUTION), name_en, name_fr, jurisdiction, collected_by (CARRIER/PLATFORM/BROKER/STATE), accounting_event_code.
- `charge_versions`: id, charge_definition_id, version, status, effective_from/until, applies_to jsonb (class codes, cima branches, product ids, channels), basis (NET_PREMIUM / NET_PREMIUM_PLUS_ACCESSORIES / FIXED / PER_POLICY / PER_VEHICLE / PER_PERSON / SUM_INSURED), rate_bp null, fixed_minor null, min_minor, max_minor, order smallint, compounds_on jsonb (other charge codes), rounding, legal_reference, created_by, approved_by. Maker-checker.
- Existing `tax_levy_versions`/`fee_schedule_versions` stay read for `rules_schema_version = 1` tariffs only.

`M10_rating_runs_extend`
- `rating_runs` add: `product_version_id`, `plan_id`, `question_set_id`, `charge_version_ids` jsonb, `rule_set_versions` jsonb, `engine_version` varchar(16), `output_hash`, `simulation_run_id` null, `purpose` (QUOTE/RENEWAL/ENDORSEMENT/SIMULATION/OVERRIDE). Fix: also persist `fee_schedule_version_id` and `tax_levy_version_id` for v1.
- `quote_offers` add: `product_version_id`, `plan_id`, `rating_run_id`, `pricing_snapshot` jsonb, `branch_allocation` jsonb, `eligibility_outcome`, `underwriting_hint`.
- `premium_overrides`: id, rating_run_id, quote_offer_id, original_total_minor, override_total_minor, reason_code, notes, requested_by, approved_by, authority_matrix_row_id, created_at.

`M11_underwriting_authority`
- `authority_matrices`: id, carrier_id, domain (UNDERWRITING/CLAIMS/DISCOUNT/OVERRIDE/ENDORSEMENT/CANCELLATION), version, status, effective dates.
- `authority_matrix_rows`: id, authority_matrix_id, role_code, branch_id null, product_id null, class_code null, metric (SUM_INSURED/PREMIUM/RISK_SCORE/CLAIM_AMOUNT/DISCOUNT_BP), max_value bigint, requires_second_approver bool.
- `underwriting_cases` add: `decision_trace_id`, `risk_score` int null, `risk_score_breakdown` jsonb, `required_authority` jsonb.

`M12_policy_rules`
- `policy_rule_versions`: id, product_version_id, version, status, effective_date_rule (§40 enum), midnight_rule_time, durations jsonb (allowed `P1D…P12M`, custom min/max days), instalment_plans jsonb (frequency, first_payment_pct_bp, loading_bp, fee_minor, grace_days, non_payment_action SUSPEND/CANCEL/NONE), numbering_scheme_code (FK numbering engine), suspension jsonb, reinstatement jsonb, created_by, approved_by.
- `product_document_templates`: id, product_version_id, document_code (QUOTE/PROPOSAL/POLICY_SCHEDULE/GENERAL_CONDITIONS/SPECIAL_CONDITIONS/PARTICULAR_CONDITIONS/ATTESTATION/COVER_NOTE/ENDORSEMENT/RENEWAL_NOTICE/SUMMARY_BOX), locale, template_ref (document_versions id or certificate_templates id), effective_from, status.
- `policies` add: `product_version_id`, `plan_id`, `policy_rule_version_id`, `snapshot_schema_version` smallint, `snapshot_id` null.
- `policy_snapshots`: id, policy_id, kind (ISSUANCE/ENDORSEMENT/RENEWAL), sequence, snapshot jsonb (§6), snapshot_hash, created_at. Append-only (DB trigger rejects UPDATE/DELETE, same pattern as the ledger tables).

`M13_servicing_rules`
- `endorsement_rule_versions`: product_version_id, version, status, types jsonb (per type: allowed, effective_date_rule, requires_underwriting, documents rule_set, rerate bool, adjustment PRO_RATA/SHORT_RATE/NO_ADJUSTMENT/CUSTOM, min_additional_premium_minor, refund_allowed, approval authority domain).
- `renewal_rule_versions`: product_version_id, window_days_before, auto_quote bool, rerate_mode (CURRENT_VERSION/SAME_VERSION_IF_AVAILABLE), risk_review_rule_set_id, kyc_refresh_months, inspection_rule_set_id, auto_renew bool, successor_product_version_id null.
- `cancellation_rule_versions` add: `carrier_id` null, `product_version_id` null, `customer_cancellable` bool, `carrier_cancellable` bool, `reasons` jsonb, `notice_days` smallint, `refund_method` (PRO_RATA/SHORT_RATE/NONE/CUSTOM), `requires_approval` bool, `documents_rule_set_id`, `cooling_off_days` smallint (OQ-3).

`M14_claims_rules`
- `claim_type_definitions`: id, product_version_id, code, name_en, name_fr, coverage_codes jsonb, reporting_deadline_days, deadline_basis (EVENT_DATE/KNOWLEDGE_DATE), late_claim_authority_domain, evidence_rule_set_id, initial_reserve_method (FIXED/AVERAGE_COST/PERCENT_OF_SI), initial_reserve_value.
- `claims` add: `claim_type_code`, `product_version_id` (copied from policy), `coverage_evaluation` jsonb, `coverage_outcome` (§49 enum).
- `claim_limit_ledger`: id, policy_id, coverage_code, period_start, period_end, limit_minor, paid_minor, reserved_minor, source claim_payment_id; append-only.

`M15_distribution_commission`
- `product_version_channels`: product_version_id, channel (§61 enum), territorial_scope (COUNTRY/REGION/CITY/BRANCH), territory_codes jsonb.
- `commission_rule_versions` add: `product_version_id` null, `agreement_id` null (FK carrier_broker_agreements from seed workstream), `transaction_type` (NEW_BUSINESS/RENEWAL/ENDORSEMENT), `method` (PCT_GROSS/PCT_NET/FIXED/TIER/VOLUME/HYBRID), `fixed_minor`, `tiers` jsonb, `clawback_rule` jsonb, `cima_branch` null.
- `broker_internal_splits`: partner_id, product_id null, version, effective, split jsonb (broker/agent/supervisor/branch_pool bp; must sum to 10000), maker-checker.

`M16_governance_simulation`
- `config_approvals`: id, subject_type, subject_id, step (TECHNICAL_REVIEW/COMPLIANCE_REVIEW/ACTUARIAL_REVIEW/BUSINESS_APPROVAL), decision, actor_id, notes, decided_at. Actor must differ from maker and from the previous step's approver (OQ-4: minimum distinct approvers per carrier).
- `config_dependencies`: from_type, from_id, to_type, to_id, kind (materialized on submit).
- `simulation_runs`: id, carrier_id, product_version_id, mode (SINGLE/PORTFOLIO), input jsonb or source query, config_versions jsonb, status, result jsonb, created_by, created_at. Never creates quotes/policies. Runs `RatingEngine` with `purpose=SIMULATION`.

`M17_life` (E11): `life_product_rules`, `premium_schedules`, `beneficiary_designations`, `beneficiary_designation_history`, `surrender_value_tables`, `summary_box_versions`. See §7.

`M18_special_schedules` (E11): `group_members`, `fleet_schedule_items`, `cargo_declarations`, `construction_projects`, `agri_schedules`, all FK `policies` + `risk_assets`.

All M-tables get `created_by`, `approved_by`, `published_at` where they are versioned config (§89), `timestampsTz`, uuid PK, and audit via `AuditWriter`.

### 2.3 Effective dating rules
- Resolution date = quote `rated_at` for new business, the endorsement effective date for MTAs, and the renewal effective date for renewals.
- Each versioned artifact is resolved **independently by effective date, then pinned** in `rating_runs` and `quote_offers.pricing_snapshot`. Once a quote is offered, re-resolution happens only on explicit rerate.
- Overlap guard (as in `TariffGovernanceService::approve`): for any (owner key), there can be no two APPROVED/PUBLISHED versions whose effective ranges overlap.

### 2.4 Deductible JSON
```json
{"type":"PERCENTAGE","percent_bp":500,"min_minor":5000000,"max_minor":null,"days":null,"applies_to":"LOSS"}
```
Types FIXED | PERCENTAGE | DAYS | COMBINED (`components:[...]`, `combine:"MAX"|"SUM"`) | MINIMUM | MAXIMUM. `DeductibleCalculator::indemnifiable(loss, deductible) → {deductible_minor, indemnifiable_minor, trace}`. Example from §13: 5% min 50,000 XAF on a loss of 600,000 gives max(30,000, 50,000) = 50,000, so the indemnifiable amount is 550,000.

---

## 3. Expression language (shared by all rule domains)

### 3.1 Principles
Only JSON data: no code, no `eval`, no regex from admins, and no network or DB access during evaluation. The engine is pure and has no clock reads: `today` is injected as a context fact. Unknown operators or facts fail validation at save time, not at runtime. Evaluation is bounded: max depth 12, max 200 nodes per condition, max 500 rules per set.

### 3.2 JSON Schema (draft 2020-12, abridged)
```json
{
  "$id": "https://opesinsure/schemas/expression-v1.json",
  "$defs": {
    "ref":   {"type":"object","required":["fact"],"properties":{"fact":{"type":"string","pattern":"^[a-z][a-z0-9_]*(\\.[a-z0-9_]+)*$"}},"additionalProperties":false},
    "lit":   {"type":"object","required":["value"],"properties":{"value":{}, "type":{"enum":["STRING","INTEGER","DECIMAL","MONEY_MINOR","BOOLEAN","DATE","DURATION","CODE","LIST"]}},"additionalProperties":false},
    "fn":    {"type":"object","required":["fn","args"],"properties":{"fn":{"enum":["AGE_AT","YEARS_BETWEEN","DAYS_BETWEEN","ADD","SUB","MUL","DIV_ROUND","MIN","MAX","COUNT","SUM","LOWER"]},"args":{"type":"array","items":{"$ref":"#/$defs/operand"}}}},
    "operand": {"oneOf":[{"$ref":"#/$defs/ref"},{"$ref":"#/$defs/lit"},{"$ref":"#/$defs/fn"}]},
    "cmp": {"type":"object","required":["op","left"],
      "properties":{"op":{"enum":["EQUAL","NOT_EQUAL","GT","GTE","LT","LTE","IN","NOT_IN","BETWEEN","CONTAINS","EXISTS","NOT_EXISTS"]},
                    "left":{"$ref":"#/$defs/operand"},"right":{"$ref":"#/$defs/operand"},
                    "inclusive":{"type":"boolean","default":true}}},
    "logic": {"type":"object","required":["op","args"],"properties":{"op":{"enum":["AND","OR"]},"args":{"type":"array","minItems":1,"items":{"$ref":"#/$defs/expr"}}}},
    "not":   {"type":"object","required":["op","arg"],"properties":{"op":{"const":"NOT"},"arg":{"$ref":"#/$defs/expr"}}},
    "expr":  {"oneOf":[{"$ref":"#/$defs/cmp"},{"$ref":"#/$defs/logic"},{"$ref":"#/$defs/not"},{"const":true}]}
  },
  "$ref": "#/$defs/expr"
}
```
Operators are exactly the owner's §20 list. Legacy `EQUALS` maps to `EQUAL` in the v1→v2 adapter.

Typing: DECIMAL values are strings evaluated with bcmath at scale 6. MONEY_MINOR is an integer. DATE is ISO `YYYY-MM-DD`. Cross-type comparison is a validation error. `BETWEEN` right = `{"value":[lo,hi]}`. `CONTAINS`: list contains scalar, or string contains substring (case-sensitive). `EXISTS` means present and non-null. A missing fact in any other comparison evaluates to **UNKNOWN**, which results in `MORE_INFORMATION_REQUIRED` for eligibility, and fails validation of a required rating fact.

### 3.3 Rule record
```json
{
  "code": "MOTOR_ELIG_VEHICLE_AGE_MAX",
  "priority": 100,
  "stop_processing": true,
  "condition": {"op":"GT","left":{"fn":"YEARS_BETWEEN","args":[{"fact":"vehicle.first_registration_date"},{"fact":"context.today"}]},"right":{"value":25,"type":"INTEGER"}},
  "outcome": {"type":"ELIGIBILITY","result":"INELIGIBLE","reason_code":"VEHICLE_TOO_OLD","message_key":"elig.vehicle_too_old"},
  "explanation_en": "Vehicles older than 25 years are not accepted.",
  "explanation_fr": "Les véhicules de plus de 25 ans ne sont pas acceptés."
}
```
Outcome types by domain:
- ELIGIBILITY `{result}`
- UNDERWRITING `{decision, authority_domain?}`
- REFERRAL `{reason_code, severity}`
- DOCUMENT `{requirement_code, stage, mandatory}`
- DECLINE `{reason_code}`
- RATING_FACTOR `{component, adjustment:{kind:MULTIPLY_BP|ADD_MINOR|SET_BAND, value}, cap_group?}`
- QUESTION_EFFECT `{effect: SHOW|HIDE|REQUIRE}`
- CLAIM_EVIDENCE `{document_code, required|conditional}`
- DISTRIBUTION `{allow:false, reason_code}`

### 3.4 Evaluation order and combination
1. Sort by `priority` ascending, then `code` ascending (deterministic tie-break).
2. Evaluate each enabled rule. Record a trace row for every evaluated rule, including the ones that did not fire.
3. If the rule fired and `stop_processing` is true, stop that rule set.
4. Combine the results. Eligibility severity order is INELIGIBLE > REFER_TO_UNDERWRITING > MORE_INFORMATION_REQUIRED > CONDITIONAL > ELIGIBLE. The worst result wins, and all fired reasons are kept. Underwriting order is DECLINE > REFER > REQUEST_MEDICAL = REQUEST_INSPECTION = REQUEST_INFORMATION > CONDITIONAL_ACCEPT > AUTO_ACCEPT. Documents are the union of all fired requirements. Rating factors are applied in rule order (see §5).
5. Domain order in the runtime chain: DISTRIBUTION → ELIGIBILITY (product, then plan) → QUESTION visibility → RATING → UNDERWRITING/REFERRAL/DECLINE → DOCUMENTS.

### 3.5 Explainability trace (per evaluation)
```json
{"trace_id":"…","domain":"ELIGIBILITY","rule_set":{"id":"…","version":3,"hash":"…"},
 "evaluated":[{"rule_code":"MOTOR_ELIG_VEHICLE_AGE_MAX","priority":100,"inputs":{"vehicle.first_registration_date":"1998-03-01","context.today":"2026-09-24"},
               "condition_hash":"…","result":true,"outcome":{"result":"INELIGIBLE","reason_code":"VEHICLE_TOO_OLD"},"stopped":true,"at":"2026-09-24T10:00:00Z"}],
 "combined":{"result":"INELIGIBLE","reasons":["VEHICLE_TOO_OLD"]},"engine_version":"2.0.0"}
```
Customer-facing responses show only `message_key` translations. Internal traces are shown only to users with `pricing.trace.view` (§92).

### 3.6 Fact dictionary (seed, per class). Keys are namespaced
Common context: `context.today`, `context.channel`, `context.region_code`, `context.branch_id`, `context.transaction_type`, `policy.duration_days`, `policy.instalment_frequency`, `party.type`, `party.age`, `party.kyc_level`, `party.claims_count_36m`.

| Class | Facts (type) |
|---|---|
| MOTOR | vehicle.fiscal_power (INTEGER CV), vehicle.usage (CODE: PRIVATE, COMMERCIAL, TAXI, TRANSPORT_PUBLIC, DRIVING_SCHOOL, RENTAL), vehicle.category (CODE: CAR, MOTORCYCLE, TRUCK, BUS), vehicle.value_minor (MONEY), vehicle.first_registration_date (DATE), vehicle.age_years (DERIVED), vehicle.seats (INTEGER), vehicle.energy (CODE), risk.zone (CODE: DOUALA, YAOUNDE, OTHER_URBAN, RURAL), driver.age (INTEGER), driver.licence_years (INTEGER), history.claims_36m (INTEGER), history.bonus_class (INTEGER), fleet.vehicle_count (INTEGER), cover.package (CODE) |
| HEALTH | members.count, members.oldest_age, members.youngest_age, members.ages (LIST), plan.zone (CODE CAMEROON/CEMAC/AFRICA/WORLDWIDE), history.pre_existing (BOOLEAN), group.size, group.average_age |
| TRAVEL | trip.destination_zone (CODE SCHENGEN/AFRICA/WORLD_EXCL_US/WORLD), trip.days (DERIVED), trip.purpose, travellers.count, travellers.max_age |
| PROPERTY / HOME | property.type, property.construction (CODE), property.occupancy, property.building_value_minor, property.contents_value_minor, property.city, property.security_features (LIST), property.flood_zone (BOOLEAN) |
| BUSINESS | business.activity_code (CODE, platform activity list OQ-5), business.turnover_minor, business.employees, business.premises_value_minor, business.stock_value_minor, business.hazard_class |
| PERSONAL_ACCIDENT | insured.age, insured.occupation_class (1–4), benefit.death_minor, benefit.disability_minor, benefit.medical_minor |
| LIABILITY | liability.limit_minor, liability.activity_code, liability.turnover_minor, liability.claims_36m |
| CARGO | shipment.mode (SEA/AIR/ROAD/RAIL), shipment.goods_category, shipment.value_minor, shipment.origin_country, shipment.destination_country, shipment.incoterm, shipment.packaging, cover.clause (ICC_A/B/C) |
| LIFE | insured.age, insured.sex (only where the carrier's actuarial basis requires it and the law allows, OQ-6), insured.smoker, policy.term_years, benefit.sum_assured_minor, premium.frequency, education.child_age, education.target_year |

`RiskSchemaCatalogue` keys map one-to-one: `fiscal_power`→`vehicle.fiscal_power`, `usage_type`→`vehicle.usage`, `zone`→`risk.zone`, `vehicle_value`→`vehicle.value_minor`, `claims_last_3_years`→`history.claims_36m`, and so on. The map lives in `fact_dictionary.derivation` with `source=QUESTION`. Legacy quotes keep their flat `risk_facts`, and the adapter builds the namespaced fact bag.

### 3.7 Determinism
`FactBag` is canonicalised with the existing `App\Application\Shared\CanonicalJson`. `input_hash = hash(fact_bag + all pinned version hashes + engine_version)`. Re-running a `rating_run` with the same pinned versions must produce a byte-identical `output_snapshot`, and the CI tests assert this (E3-AT4).

---

## 4. Governance workflows

### 4.1 Product version (§74)
DRAFT → CONFIGURATION → TECHNICAL_REVIEW → COMPLIANCE_REVIEW → BUSINESS_APPROVAL → READY → PUBLISHED, and later SUSPENDED ↔ PUBLISHED and → RETIRED.
- The maker cannot perform any review step. COMPLIANCE_REVIEW requires `RegulatoryValidationService` to report no blocking errors. READY → PUBLISHED happens immediately or at `scheduled_publish_at` (a scheduler job revalidates the gate at fire time).
- A PUBLISHED version is immutable. Any edit means clone to a new DRAFT. Rollback (§81) means suspending the new version and republishing the previous one, if it is still in its effective window. Nothing is deleted.
- Map legacy statuses: IN_REVIEW → TECHNICAL_REVIEW, ACTIVE → PUBLISHED.

### 4.2 Tariff (§75)
DRAFT → ACTUARIAL_REVIEW → APPROVAL → SCHEDULED → ACTIVE → EXPIRED. `TariffGovernanceService` keeps its checks (hash, maker-checker, overlap). IN_REVIEW maps to ACTUARIAL_REVIEW and APPROVED maps to ACTIVE/SCHEDULED by date, through a `workflow_status` column. The legacy `status` column is kept in sync for compatibility until E13.

### 4.3 Regulatory publication gate (§4–5)
`RegulatoryValidationService::gate(ProductVersion $v, Date $on): GateResult`. For every `product_version_coverages.cima_branch` (and each `product_regulatory_mappings` row), it resolves carrier → jurisdiction (CM/CIMA) → `insurer_authorizations` at branch level (the CIMA workstream adds `cima_branch`) → effective on `$on`. Possible results: AUTHORIZED | NOT_AUTHORIZED | AUTHORIZATION_EXPIRED | AUTHORIZATION_SUSPENDED | MAPPING_REQUIRED. Additional checks:
- Branches 14 and 15 are never ACCESSORY (Art. 328-1).
- Branch 10 appears on every motor version that is sold as compulsory motor insurance.
- Life (20–23) and IARD branches are never mixed in one version unless the carrier is authorized for both, which should not happen because Life and IARD are separate carriers (seed spec rule 4).
- Complementary covers on 20/21 are allowed only as COMPLEMENTARY.

Any failure produces **PRODUCT PUBLICATION BLOCKED** with a reason per branch. The gate is also re-run at quote time as a guard: an authorization that later expires stops sales (DISTRIBUTION outcome) without mutating the version.

### 4.4 Completeness checklist (§9, §97–98)
This applies only to `config_level = CONFIGURED` versions, and only to the capabilities whose resolved mode is a configured mode. BASIC versions (A6) use the minimal checklist. For example, a product with MANUAL_PREMIUM pricing never requires a tariff, and one with CARRIER_ORIGINAL documents never requires Opes templates. The regulatory gate is required at every level.
`ProductCompletenessService::check(v)` returns `{blocking:[…], warnings:[…]}`.
- Blocking items: no CIMA mapping, gate not AUTHORIZED, no mandatory coverage, no eligibility rule set, no ACTIVE/SCHEDULED tariff covering effective_from, no underwriting rule set, no policy_rule_version, no claims rules (claim types), missing an EN or FR label on a customer-facing item, missing templates POLICY_SCHEDULE/ATTESTATION (motor)/GENERAL_CONDITIONS in both locales, no accounting event mapping for premium/charges, no distribution channel, and approvals incomplete.
- Warnings: no commission rule, no renewal rules, no demo simulation run in the last 7 days, and question facts not used by any rule.

---

## 5. Rating engine v2

### 5.1 Interface
`App\Domain\Rating\RatingEngine::rate(RatingRequest): RatingResultV2`. This is a pure domain service. `DeterministicRatingEngine` remains the implementation for `rules_schema_version = 1`, and `RatingEngineV2` handles version 2. `RatingEngineRouter` selects between them.

### 5.2 Tariff rules v2 (stored in `tariff_versions.rules`)
```json
{
  "schema_version": 2, "currency": "XAF",
  "rounding": {"premium": {"mode":"HALF_UP","unit_minor":1}, "charges": {"mode":"HALF_UP","unit_minor":1}, "total": {"mode":"UP","unit_minor":5}},
  "components": [
    {"code":"RC", "coverage_code":"MOTOR_TPL", "cima_branch":10, "method":"TABLE", "table":"RC_BY_POWER_USAGE", "keys":["vehicle.fiscal_power","vehicle.usage"]},
    {"code":"DOM", "coverage_code":"MOTOR_OWN_DAMAGE", "cima_branch":3, "method":"RATE_X_SUM_INSURED", "rate_bp_table":"OD_RATE_BY_AGE", "sum_insured_fact":"vehicle.value_minor", "when":{"op":"IN","left":{"fact":"cover.package"},"right":{"value":["COMPREHENSIVE","FLEET"]}}},
    {"code":"IA", "coverage_code":"MOTOR_DRIVER_PA", "cima_branch":1, "method":"PER_PERSON", "amount_minor":250000, "persons_fact":"vehicle.seats"},
    {"code":"ASS", "coverage_code":"MOTOR_ASSISTANCE", "cima_branch":18, "method":"FIXED", "amount_minor":1500000}
  ],
  "factor_rule_set_id": "…",
  "discounts": {"max_total_bp": 3500, "stacking": "MULTIPLICATIVE"},
  "loadings":  {"max_total_bp": 15000},
  "deductible_options": [{"code":"STD","factor_bp":0},{"code":"HIGH","factor_bp":-1000}],
  "minimum_premium": {"per_policy_minor": 2500000, "per_component": {"RC": 1500000}},
  "short_period": {"method":"TABLE","table":"SHORT_PERIOD"},
  "instalment_loading_bp": {"MONTHLY": 800, "QUARTERLY": 500, "SEMI_ANNUAL": 300, "ANNUAL": 0},
  "commission_mode": "INCLUSIVE"
}
```
Methods (§27): FIXED, RATE_X_SUM_INSURED, RATE_X_LIMIT, TABLE, BAND, TIER (progressive slices), FORMULA (a restricted `fn` expression only), PER_PERSON, PER_VEHICLE, PER_EMPLOYEE, PER_DAY, PER_TRIP, PER_SHIPMENT.

### 5.3 Premium build-up (fixed order, each step traced)
1. Resolve components whose `when` is true and whose coverage is selected (mandatory, defaulted optional, or chosen optional/extension). Compute the **component base** for each.
2. Apply RATING_FACTOR rules (loadings/discounts), per component or `*`, in rule order. Each adjustment is recorded as `{rule_code, component, kind, value, delta_minor}`.
3. Cap: the cumulative discount across a component is at most `discounts.max_total_bp`, and the cumulative loading is at most `loadings.max_total_bp`. Excess is clipped and traced as `CAP_APPLIED`. MANUAL_AUTHORIZED discounts are applied last and require an authority row (§5.6).
4. Apply the deductible option factor.
5. Short period: if `policy.duration_days < 365`, apply the short-period table (e.g. ≤ 30d = 20%, ≤ 90d = 40%, ≤ 180d = 70%, else pro rata, all DEMO). Otherwise pro rata for durations over 12 months if allowed.
6. Minimum premium per component, then per policy. The uplift goes to the largest component, and ties go to the lowest `cima_branch` number.
7. Round each component (premium rounding) to get the **net premium** (prime nette).
8. Instalment loading on the net premium (it is part of the premium, not a fee; OQ-7 confirm tax treatment).
9. Charges (`ChargeEngine`): for every applicable `charge_version` sorted by `order`, compute on its basis (NET_PREMIUM, NET_PREMIUM_PLUS_ACCESSORIES, FIXED, PER_…) and round per charge. Kinds are output separately: `accessories` (policy cost / accessoires), `statutory_levies`, `taxes`, `fees`.
10. Total = net premium + accessories + levies + taxes + fees, with total rounding (the XAF has no subunit in practice, so minor unit = 1 XAF. OQ-8: the minimum rounding unit per insurer, 1 or 5 XAF).
11. Allocation (§5.5), then commission (§5.7).

Guards: every intermediate value is an integer minor unit. Rates are basis points, or bcmath at scale 6 for FORMULA. The total premium must be positive. Negative components are allowed only for discounts. Any missing required fact is an error, never a default.

### 5.4 Charges: Cameroon (all values are DEMO until confirmed; OQ-9)
| charge code | kind | basis | DEMO value |
|---|---|---|---|
| `CM_ACCESSORIES_POLICY_COST` | ACCESSORY_FEE | FIXED per policy, by class | 5,000 XAF |
| `CM_TAX_INSURANCE` (TCA / TVA regime to confirm) | TAX | NET_PREMIUM_PLUS_ACCESSORIES | 19.25% (DEMO) |
| `CM_FUND_MOTOR_GUARANTEE` (FGA-type levy) | STATUTORY_LEVY | NET_PREMIUM of branch 10 component only | 2% (DEMO) |
| `CM_STAMP_ATTESTATION` | STAMP | PER_VEHICLE | 1,000 XAF (DEMO) |
| `CM_FEE_CARTE_ROSE` / CEMAC card | STATUTORY_LEVY | PER_VEHICLE when `territory` includes CEMAC | DEMO |
| `OPES_PLATFORM_FEE` | FEE (collected_by PLATFORM) | FIXED | 0 (OQ-10: platform fee policy) |
No rate is hard-coded in PHP. `charge_versions.applies_to.cima_branches` allows branch-scoped levies.

### 5.5 Per-CIMA-branch allocation (Art. 411 reporting)
Output `branch_allocation[]` = `{cima_branch, mapping_type, net_premium_minor, accessories_minor, taxes_minor, levies_minor, commission_minor}`.
- Net premium is the sum of the components tagged with that branch, so the allocation is exact by construction.
- Policy-level charges and minimum-premium uplift are allocated pro rata to net premium, using largest-remainder rounding so the sum is exact. Branch-scoped charges go to their branch only.
- Reporting maps `cima_branch` to `CIMA_REPORT_*` using the CIMA dictionary's reporting mapping (INS-SET-CIMA-004). Stored on `quote_offers.branch_allocation` and in the policy snapshot, it becomes the source for `regulatory_report_runs` (Art. 411) and Art. 557 intermediary measures (written premium per branch, commission per branch).

### 5.6 Manual override
`POST /insurance/rate/{run}/override` accepts a `{override_total_minor | discount_bp, reason_code, notes}` body. `authority_matrices(domain=OVERRIDE or DISCOUNT)` must allow the actor's role for |delta|, or a second approver is required. The override creates a new `rating_run(purpose=OVERRIDE)` linked to the original plus a `premium_overrides` row, and it can never reduce statutory charges. The trace carries `MANUAL_OVERRIDE` with the actor and approver.

### 5.7 Commission handling
`commission_mode` INCLUSIVE means commission is paid out of the net premium, and the customer total is unchanged. EXCLUSIVE means commission is shown as a separate loading. It is rare and requires carrier config (OQ-11). `CommissionEngine` computes the expected commission per branch from `commission_rule_versions` (carrier + agreement + version + transaction type), on the base named by the method (PCT_NET means net premium; PCT_GROSS means net premium plus accessories, OQ-12). It is stored in the pricing snapshot as expected. The accrual happens at issuance, as today.

### 5.8 Pricing snapshot (quote_offers.pricing_snapshot)
```json
{"schema":2,"currency":"XAF","product_version":{"id":"…","version":2,"hash":"…"},"plan":"COMPREHENSIVE",
 "tariff_version":{"id":"…","version":4,"hash":"…"},"charge_versions":[{"code":"CM_TAX_INSURANCE","version":1}],"rule_sets":{"ELIGIBILITY":3,"RATING_FACTORS":4,"UNDERWRITING":2},
 "components":[{"code":"RC","cima_branch":10,"base_minor":0,"adjustments":[],"net_minor":0}],
 "net_premium_minor":0,"accessories_minor":0,"levies":[],"taxes":[],"fees":[],"total_minor":0,
 "branch_allocation":[],"commission_expected":[],"rating_run_id":"…","engine_version":"2.0.0"}
```

---

## 6. Policy snapshot (§83–84)
`policy_snapshots.snapshot` (schema 2) contains:
- the version ids and hashes for product, plan, tariff, charges, rule sets, policy rules, templates, question set, and exclusion texts
- coverages: code, limit, deductible, waiting period, territory, and `cima_branch`
- exclusions (text version id)
- the pricing snapshot, the instalment schedule, the risk objects (facts plus asset ids), parties by role (policyholder, insured, beneficiaries), documents, underwriting decision ids, and authority
- effective dates, `previous_policy_id`, and `terms_hash`

The existing `policies.terms_snapshot`/`terms_hash` continue to be written, and v2 is a superset. Renewals write kind RENEWAL for the successor policy. Endorsements write ENDORSEMENT with a sequence number. Claims evaluate against the snapshot that was effective on the loss date, **never** the current product version.

---

## 7. Life engine (§66–68) and special products (§69–73)
Scope now: the data model and API shells, plus term life and education plan rating from **carrier-supplied premium tables only** (no internal actuarial model; §68).
- `life_product_rules` (product_version_id): entry_age_min/max, max_age_at_maturity, terms allowed, premium frequencies, benefit types (DEATH, DISABILITY, MATURITY, ANNUITY), beneficiary rules, and complementary covers (Art. 328 complement to 20/21).
- `premium_schedules`: a table keyed by age × term (× smoker), giving the rate per 1,000,000 XAF of sum assured, versioned under a tariff.
- `beneficiary_designations` + history: role PRIMARY/CONTINGENT, party_id or free designation (e.g. "children born and unborn"), allocation_bp. The sum per role must be exactly 10000. Every change is a new row, and the policyholder signs through an endorsement.
- `surrender_value_tables` (Art. 65): surrender value by policy year given by the carrier. Surrender becomes available after the minimum years and premium threshold defined by the carrier and the Code (OQ-13: confirm the thresholds and the reduction formula currently in force after the 2024/2026 amendments).
- `summary_box_versions` (Art. 65-1, "encadré"): a template per product version with required fields: nature of contract, guarantees, participation in profits, surrender values for the first 8 years, fees, rights of withdrawal, and beneficiary designation. The template must be rendered and acknowledged before a life proposal can be submitted (becomes a DOCUMENT rule).
- Later (not in v1): policy loans, annuities in payment, maturity processing, profit participation, mathematical reserves (workstream 3), unit-linked (branch 21), tontine (22), and capitalization (23).
- Special schedules (group, fleet, cargo declarations, construction, agriculture) use a master policy with child `*_schedule_items`. Each item is rated by the same engine with `purpose=ENDORSEMENT` when added or removed.

---

## 8. Worked examples (all figures DEMO: "DEMO — NOT AN INSURER QUOTE")

### 8.1 Motor: branches 10 (RC) + 3 (own damage) + 1 (driver/passenger accident) + 18 (assistance)
Facts: fiscal_power 9 CV, usage PRIVATE, zone DOUALA, value 8,000,000 XAF, first registration 2019 (age 7), claims_36m 0, seats 5, package COMPREHENSIVE, duration 12 months, ANNUAL.

Packages:
- MOTOR_TP = RC (branch 10) only.
- TP_PLUS = RC + fire/theft (branch 3 partial, 8 for fire, OQ-14 mapping) + driver PA (branch 1).
- COMPREHENSIVE = RC + own damage (branch 3) + PA (branch 1) + assistance (branch 18, ACCESSORY).
- FLEET = COMPREHENSIVE with fleet discount and a vehicle schedule.

| Step | Component | Calculation | Amount (XAF) |
|---|---|---|---|
| base | RC (10) | table power 7–10 CV × PRIVATE | 85,000 |
| base | DOM (3) | 8,000,000 × 2.50% (age 6–10 band) | 200,000 |
| base | IA (1) | 5 seats × 2,500 | 12,500 |
| base | ASS (18) | fixed | 15,000 |
| factor | RC | zone DOUALA +10% | +8,500 |
| factor | DOM | zone DOUALA +10% | +20,000 |
| factor | RC, DOM | no-claims 36m −15% | −13,995 / −33,000 |
| min | – | policy minimum 25,000 not triggered | 0 |
| net | 10: 79,505 · 3: 187,000 · 1: 12,500 · 18: 15,000 | | **294,005** |
| accessories | CM_ACCESSORIES_POLICY_COST | fixed | 5,000 |
| levy | CM_FUND_MOTOR_GUARANTEE | 2% × 79,505 (branch 10 only) | 1,590 |
| tax | CM_TAX_INSURANCE | 19.25% × (294,005 + 5,000) | 57,558 |
| stamp | CM_STAMP_ATTESTATION | 1 vehicle | 1,000 |
| total | | | **359,153** |

The no-claims discount is multiplicative after the zone loading: RC (85,000 + 8,500) × 15% = 14,025 → rule rounding HALF_UP per step gives −14,025. The engine traces the exact per-step values. E3-AT7 asserts the computed table, so treat the numbers above as illustrative, not as fixtures.

Branch allocation: accessories of 5,000 are allocated pro rata across 10/3/1/18 by net premium (1,352 / 3,180 / 213 / 255, largest remainder). The levy goes to 10 only. Tax follows its base.

Fleet (FLEET): `fleet.vehicle_count ≥ 5` gives −10% on the DOM and RC components (cap group DISCOUNT, max total 35%). Each vehicle is a `fleet_schedule_items` row, and each is rated individually.

Referral: `vehicle.value_minor > 50,000,000` → REFER (authority UNDERWRITING ≥ SENIOR_UW). Decline: `vehicle.usage = RACING`. Documents: carte grise always; a pre-inspection report when package COMPREHENSIVE and age > 10.

### 8.2 Health (branch 2): family plan CEMAC, 4 members, oldest 42, no pre-existing
Method PER_PERSON with an age-band table: 0–17 = 60,000; 18–45 = 120,000; 46–60 = 210,000 (DEMO). Base = 2 × 60,000 + 2 × 120,000 = 360,000. Zone factor CEMAC +15% gives 414,000. Family discount for 4 or more members −5% gives 393,300. Accessories 5,000. Tax at the DEMO rate (health tax treatment is OQ-15). Waiting periods: maternity 300 days, dental/optical 90 days. Rule: pre_existing = true → REQUEST_MEDICAL.

### 8.3 Travel (branch 18 assistance + branch 2 medical, OQ-16 split)
Schengen, 21 days, 1 traveller aged 34. Method PER_DAY table: Schengen band 16–30 days = 32,000 flat (DEMO). Age ≥ 70 → +100% loading. Age ≥ 80 → INELIGIBLE. Medical limit 30,000 EUR equivalent: the Schengen minimum requires a `limit_amount_minor` in XAF with an EUR display, and a currency policy is needed (OQ-17). Allocation 70% branch 18 / 30% branch 2 by component definition. Documents: passport copy. Output: the Schengen certificate template.

### 8.4 Life (branch 20)
- **Term life**: age 35, term 10 years, sum assured 10,000,000 XAF, non-smoker. The `premium_schedules` rate at 35/10 is 3.10 per 1,000 (DEMO), giving an annual premium of 31,000. Policy fee 2,500. There is no tax on life premiums (OQ-18). Beneficiaries: spouse 60% / children 40% PRIMARY, estate CONTINGENT 100%. The summary box is required before proposal submission.
- **Education plan**: child aged 5, target year 2039 (term 13), target capital 5,000,000. Carrier table rate 7,020 per 100,000 per year (DEMO), giving a monthly premium of 29,250. Complementary cover: premium waiver on the parent's death (branch 20 COMPLEMENTARY). The surrender value table comes from the carrier. The example surrender is not shown because it requires carrier data.

---

## 9. Services and module layout (§86–88)

```
app/Domain/Rules/        ExpressionValidator, ExpressionEvaluator, FactBag, RuleSetEvaluator, EvaluationTrace (pure)
app/Domain/Rating/       RatingEngine (interface), DeterministicRatingEngine (v1, kept), RatingEngineV2, ComponentCalculators/*, ChargeEngine, Allocation\BranchAllocator, Rounding
app/Domain/Coverage/     DeductibleCalculator, LimitResolver
app/Application/ProductCatalogue/  ProductVersionService (clone/submit/review/publish/suspend/retire/schedule), PlanService, CoverageConfigurationService, ProductCompletenessService, ConfigurationDiffService, DependencyGraphService
app/Application/Regulatory/        RegulatoryValidationService (consumes CIMA module)
app/Application/Eligibility/       ProductEligibilityService
app/Application/Rating/            RatingService (orchestrates engine + persistence), TariffGovernanceService (extend), ChargeGovernanceService, PremiumOverrideService
app/Application/Underwriting/      UnderwritingDecisionService, AuthorityResolver, RiskScoreService; ProposalService (extend: documents via rules)
app/Application/Policies/          PolicyIssuabilityService, PolicySnapshotService, EndorsementRatingService, RenewalRatingService; PolicyIssuanceService/RenewalService (extend)
app/Application/Claims/            ClaimCoverageService, ClaimSettlementCalculator, ClaimEvidenceRequirementService
app/Application/Commissions/       CommissionEngine
app/Application/Distribution/      ProductDistributionService
app/Application/Simulation/        SimulationService, PortfolioSimulationJob
```
- `QuoteService::rate` becomes: `ProductDistributionService::sellable()` → `ProductEligibilityService` → `RatingService` → `UnderwritingDecisionService::preview()`. The private methods `eligible()` and `activeRules()` are removed after E4.
- Events (§88, emitted through the existing `OutboxWriter`): ProductCreated, ProductVersionSubmitted, ProductVersionApproved, ProductVersionPublished, ProductVersionSuspended, ProductVersionRetired, TariffApproved, TariffActivated, EligibilityEvaluated, QuoteRated, UnderwritingDecided, PolicySnapshotCreated, ClaimCoverageEvaluated, ClaimSettlementCalculated, CommissionCalculated. OQ-19: map these to the owner's exact 15 names if they differ.

---

## 10. Runtime chain (§100) and sandbox (§76–78)
- Every step below that the adaptive model lists as a capability runs through its A3 adapter. The steps shown here are the CONFIGURED implementations. In MANUAL mode the same states are reached through A4.
- Chain: `need(class, channel, party)` → `ProductDistributionService` (product ACTIVE, version PUBLISHED and in its sales window, agreement ACTIVE, broker authorization ACTIVE, channel/territory/branch/agent allowed, regulatory gate still AUTHORIZED) → eligibility → coverage resolution → rating → UW preview → quote → proposal (documents by rules, disclosures) → UW decision → `PolicyIssuabilityService` (approved + payment condition + all mandatory documents ACCEPTED + no open referral + required approvals) → issuance + snapshot → servicing → claims coverage → settlement → commission/accounting/Art. 411/557 reporting.
- **Sandbox**: `POST /carriers/{carrier}/product-versions/{version}/simulate` accepts `{facts, plan?, options?, as_of?}` and runs the whole chain in DRAFT/any status with `purpose=SIMULATION`. Nothing is persisted except `simulation_runs` and traces. The response includes eligibility, pricing snapshot, UW decision, documents, and `config_versions` (every version id and hash used). Output documents are watermarked per the demo spec.
- **Portfolio simulation**: this re-rates a sample (the last N published-version quotes/policies for the product, anonymised fact bags) against the candidate version. It reports the distribution of premium delta, eligibility changes, and referral-rate changes. It is required (warning, OQ-20: blocking?) before BUSINESS_APPROVAL.

---

## 11. APIs

### 11.1 Admin (owner routes, §91). All under `auth:sanctum`, tenant/carrier scope, and permission middleware
```
GET/POST   /api/v1/carriers/{carrier}/products
GET/PATCH  /api/v1/carriers/{carrier}/products/{product}
POST       /api/v1/carriers/{carrier}/products/{product}/versions            (new DRAFT or clone {from_version_id})
GET        /api/v1/product-versions/{version}                                (resolved config)
PUT        /api/v1/product-versions/{version}/plans | /coverages | /exclusions | /questions | /eligibility-rules | /underwriting-rules | /document-rules | /policy-rules | /endorsement-rules | /renewal-rules | /cancellation-rules | /claims-rules | /templates | /channels | /regulatory-mappings
POST       /api/v1/product-versions/{version}/tariffs                        (→ TariffGovernanceService::create, v2)
POST       /api/v1/product-versions/{version}/transition   {to, notes}       (workflow §4.1)
POST       /api/v1/product-versions/{version}/schedule     {publish_at}
GET        /api/v1/product-versions/{version}/completeness
GET        /api/v1/product-versions/{version}/regulatory-gate?on=YYYY-MM-DD
GET        /api/v1/product-versions/{version}/diff?against={version}
GET        /api/v1/product-versions/{version}/dependencies
POST       /api/v1/product-versions/{version}/simulate
POST       /api/v1/product-versions/{version}/portfolio-simulations
POST       /api/v1/rules/validate   {domain, class_code, expression}         (save-time validator used by the UI)
GET        /api/v1/fact-dictionary/{class}
GET/POST   /api/v1/charges, /api/v1/charges/{code}/versions, …/{v}/approve
GET/POST   /api/v1/carriers/{carrier}/authority-matrices
```
Legacy routes (`catalogue/*`, `tariffs/*`, `underwriting/disclosure-schemas`, `underwriting/document-requirements`, `configuration/cancellation-rules`) stay as they are and delegate to the new services. They are deprecated in the OpenAPI spec (workstream 7).

### 11.1a Adaptive layer (Part A)
```
GET/POST   /api/v1/carriers/{carrier}/capability-profiles            (versions; POST creates DRAFT)
POST       /api/v1/capability-profiles/{profile}/approve              (maker-checker)
GET        /api/v1/carriers/{carrier}/capability-profile/resolve?capability=&product=
POST       /api/v1/carriers/{carrier}/products/simple                 (5-question onboarding → BASIC version; response includes gate result)
POST       /api/v1/quotes/{quote}/carrier-requests                    {carrier_id, product_id, channel}   → carrier_quote_requests
GET        /api/v1/carrier/quote-requests?status=                     (carrier portal queue)
POST       /api/v1/carrier-quote-requests/{req}/responses             {premium components, valid_until, conditions, carrier_quote_reference, evidence_document_id?}
POST       /api/v1/carrier-quote-requests/{req}/decline               {reason}
POST       /api/v1/carrier-quote-responses/{resp}/record-offer        (broker converts to quote_offer; diffs → value_overrides)
POST       /api/v1/policy-issuance-requests/{req}/carrier-documents   (multipart upload; document_type, carrier_document_number, issued_on)
POST       /api/v1/documents/{doc}/carrier-confirm
POST       /api/v1/overrides  ·  POST /api/v1/overrides/{o}/approve
POST       /api/v1/imports {entity, file} → GET /imports/{job} → PUT /imports/{job}/mapping → POST /imports/{job}/validate → GET /imports/{job}/preview|errors(.csv) → PATCH /imports/{job}/rows/{row} → POST /imports/{job}/approve → POST /imports/{job}/execute
GET/POST   /api/v1/import-mapping-templates
```
Mobile (broker/agent app): `quote → send to insurer → record response → upload policy` screens call the same endpoints. Mobile idempotency keys are required on every POST, as for payments today.

### 11.2 Runtime
`POST /api/v1/insurance/eligibility/check`
```json
// request
{"class_code":"MOTOR","channel":"BROKER","party_id":"…","facts":{"vehicle":{"fiscal_power":9,"usage":"PRIVATE"},"risk":{"zone":"DOUALA"}},"as_of":"2026-09-24"}
// response
{"results":[{"product_id":"…","product_version_id":"…","plan":"COMPREHENSIVE","result":"ELIGIBLE","reasons":[],"missing_facts":[],"trace_id":"…"}]}
```
`POST /api/v1/insurance/rate` takes `{product_version_id, plan, options:{coverages:[…], deductible:"STD", instalments:"ANNUAL", duration:"P12M"}, facts, as_of}` and returns `{rating_run_id, pricing:{…§5.8 customer view: net, accessories, taxes[], levies[], fees[], total, instalments[]}, trace_id}`. The internal `calculation_trace` is included only with `pricing.trace.view`.
`POST /api/v1/insurance/rate/{run}/explain` returns the full trace (rules fired, versions, inputs).
`POST /api/v1/insurance/rate/{run}/override` is described in §5.6.
`POST /api/v1/quotes` (extend the existing controller) returns offers with `pricing_snapshot`.
`POST /api/v1/underwriting/evaluate` takes `{proposal_id}` and returns `{decision, reasons[], required_documents[], required_authority, risk_score?, trace_id}`.
`POST /api/v1/claims/coverage/evaluate` takes `{policy_id, claim_type, loss_date, reported_at, loss_amount_minor?, facts}` and returns `{outcome: COVERAGE_CONFIRMED|REVIEW_REQUIRED|POTENTIAL_EXCLUSION|OUTSIDE_COVERAGE, coverages[], potential_exclusions[], deadline:{met, days_late, approval_required}, evidence_required[], max_eligible_minor, trace_id}`.
`POST /api/v1/claims/{claim}/settlement/calculate` returns `{covered_minor, excluded_minor, deductible_minor, prior_settlements_minor, adjustments_minor, remaining_limit_minor, payable_minor, trace}`. The result is advisory, and a human decides (§49).

Mobile adapters: `/mobile/catalogue/lines/{code}/risk-schema` and the mobile quote flow call the same services. The response shapes stay backward compatible with the shipped APK.

---

## 12. Screens: Product Builder (§93–99), mapped to the register

Placement: **carrier users get the web partner workspace** (INS-SET/CAR screens). This needs a carrier-scoped Filament panel `app/Filament/Carrier` or the partner web app (OQ-21: which web surface is canonical for carriers). **Platform staff get `app/Filament/Admin`** (PLT-SET/PLT-CIMA). The mobile `carrier/products.tsx` remains read/activate only.

| Screen | Must do | Surface | Built on |
|---|---|---|---|
| INS-SET-017 Products list / CAR-013 Product Catalogue | List carrier products with class, family, published version, next scheduled version, status, and completeness badge. Actions: new product, new version, suspend | Carrier web | `insurance_products` + `product_versions` |
| INS-SET-018 / CAR-014 Product details | EN/FR identity, customer types, currency, dashboard KPIs (§96): policies, quotes, conversion, GWP, claims, loss ratio, brokers, warnings | Carrier web | reporting read models |
| INS-SET-019 / CAR-015 Version management | Timeline of versions, workflow transitions with approver identity, clone, diff, schedule, rollback (suspend/republish) | Carrier web | §4.1, diff service |
| INS-SET-020 Plans & packages | Plan CRUD and the plan×coverage matrix | Carrier web | `product_plans` |
| INS-SET-021 / CAR-016 Coverage configuration | Per coverage: role, limit type/scope/amount, sum-insured source, deductible builder with a live example, waiting period, territory, CIMA branch + mapping type | Carrier web | `product_version_coverages` |
| INS-SET-022 / CAR-017 Exclusions & conditions | Exclusion library, level/target, EN/FR legal text versions, general/special/particular conditions | Carrier web | M05 |
| INS-SET-023 Underwriting questions | Question-set builder: types, validation, conditional visibility (expression builder), fact mapping, EN/FR preview in the mobile quote layout | Carrier web | M07 |
| INS-SET-024 / CAR-018 Eligibility rules | Rule list with priority/stop; visual expression builder bound to the fact dictionary; save-time validation; test pane | Carrier web | M06 |
| INS-SET-025 / CAR-019/020/021 Tariffs | Tariff versions per workflow §4.2; component editor; table/band grid editor with CSV import; min premium, caps, rounding; approval history | Carrier web | M08 |
| INS-SET-026 / CAR-022 Rating rules | Loading/discount factor rules (RATING_FACTOR domain) with caps and authority for MANUAL_AUTHORIZED | Carrier web | M06 |
| INS-SET-027 Underwriting rules & authority | Referral/decline/request rules; authority matrix grid (role × metric × limit × branch/product) | Carrier web | M11 |
| INS-SET-028 Policy rules | Effective-date rule, durations, instalment plans, grace and non-payment action, numbering scheme picker (preview e.g. `AXA-MOT-2026-000001`) | Carrier web | M12 |
| INS-SET-029 Endorsement/renewal/cancellation rules | Per-type endorsement config; renewal window/rerate; cancellation reasons, notice, refund method, approval | Carrier web | M13 |
| INS-SET-030 Claims rules | Claim types, deadlines, evidence rules, initial reserve method, claims authority link | Carrier web | M14 |
| INS-SET-CIMA-003 Product-to-CIMA mapping | Branch mapping per coverage/version (PRIMARY/ACCESSORY/COMPLEMENTARY, legal ref, dates); live gate result per branch; Art. 328-1 validation | Carrier web | CIMA module + §4.3 |
| INS-SET-CIMA-005 Accessory risk mapping | Accessory covers and their principal branch; blocks 14/15 as accessory | Carrier web | CIMA module |
| PLT-SET-007 Global taxonomy | Classes, families, normalized products, EN/FR; links to `insurance_classes` / register taxonomy | Admin Filament | M01 |
| PLT-SET-008 Taxes & statutory charges | Charge definitions/versions, maker-checker, effective dating, applies-to (class/branch/channel), preview calculation | Admin Filament | M09 |
| PLT-SET-009 Fact dictionary / rule infrastructure | Fact dictionary per class, operators catalogue, engine version, trace retention | Admin Filament | M06 |
| Capability profile (Part A; INS-SET setup, near INS-SET-017) | Mode per capability with product overrides, maturity level badge, incoherence validation, approval, version history | Carrier web + Admin | A2 |
| Simple product onboarding (Part A, default "New product" entry) | The 5 questions; background CIMA mapping with a plain-language gate result; upload conditions PDF; submit for approval. The advanced 19-step wizard is offered only when a configured mode is chosen | Carrier web + Admin (on behalf) | A6 |
| Carrier quote-request queue (carrier portal) | Incoming requests with SLA, risk summary, respond/decline form, attach carrier quote | Carrier web | A4 |
| Broker quote → insurer response → policy upload | Send request, record response with evidence, record offer, upload carrier documents, deliver to customer | Broker web + mobile | A4/A5 |
| Import centre | Upload → map → validate → preview → errors → approve → import, with reusable mapping templates and a job history | Carrier/Broker web + Admin | A8 |
| Override log & approvals | Pending overrides inbox and history per record | All back-office | A7 |
| Wizard (§94, 19 steps) | Identity → class/family → CIMA mapping → plans → coverages → exclusions → questions → eligibility → tariff → charges preview → UW rules → authority → policy rules → servicing → claims → templates → distribution/commission → sandbox → submit | Carrier web | Wraps the screens above; step state = completeness service |
| Sandbox (§76) | Fact form generated from the question set, run, and show the trace tree with versions | Carrier web + Admin | §10 |
| Marketplace comparison (§99) | Normalized dimensions (limits per coverage code, deductible, waiting period, total premium) | Customer web/mobile | `quote_offers.pricing_snapshot` |

OQ-22: the INS-SET-017…030 labels above are inferred from the framework's section order (8–21). Confirm them against the owner's exact screen names in the 552-screen register before building the UI.

---

## 13. Epics (dependency-ordered, adaptive model first) with acceptance tests

Order: strict core, capability profile, adapters, manual mode, carrier documents and import come first (Wave 1). The configured rule engine (Part B) follows as opt-in epics (Wave 2). Carrier API mode comes last (Wave 3). Each configured epic is switched on per carrier/product through the capability profile, never globally.

**Wave 1: adaptive foundation and Mode 1 (maturity levels 1–2)**

| Epic | Scope | Depends on | Acceptance tests |
|---|---|---|---|
| A0 Foundations | Execution contracts + registries (A3); wrap today's quote/UW/issuance/claims/payment code as the OPES/CONFIGURED adapters without behaviour change; fix rating_runs to store tax/fee version ids | – | AT1 all existing feature tests are green unchanged; AT2 a registry resolves an adapter per mode; AT3 rating_runs carry all version ids |
| A1 Capability profile | A-M01/M02, `CapabilityResolver`, maturity level, incoherence validation, pinning on quote/proposal/issuance/claim | A0 | AT1 a product override beats the carrier default; AT2 a pinned mode survives a profile change; AT3 the maker cannot approve the profile; AT4 CONFIGURED_RULES without a tariff is rejected |
| A2 Product/version split + BASIC onboarding + gate | M01–M03 (minimal), `config_level`, 5-question onboarding, background CIMA mapping, regulatory gate (§4.3) | A1, CIMA module | AT1 the MOTOR_COMPREHENSIVE answer creates mappings 10/3/18; AT2 an unauthorized branch blocks it with a plain-language reason; AT3 legacy products backfill to version 1; AT4 sales screens never show CIMA codes |
| A3 Manual quotation (Mode 1) | carrier_quote_requests/responses, broker-on-behalf with evidence, record-offer, carrier portal queue, SLA/expiry | A2 | AT1 end-to-end: broker request → carrier portal response → offer; AT2 broker-on-behalf without an evidence document is rejected; AT3 a changed premium creates an override row; AT4 an expired request cannot be responded to; AT5 idempotent double submit |
| A4 Carrier original documents + manual issuance | A-M03 provenance, ManualUploadIssuer, carrier confirmation, customer delivery, public verification by provenance | A3 | AT1 no policy without a carrier document and confirmed payment; AT2 the sha256 is stored and the object is immutable; AT3 the customer sees the documents EN/FR in the app; AT4 verification shows UPLOADED_BY_BROKER vs CARRIER_CONFIRMED |
| A5 Manual servicing & claims modes | Manual renewal (renewal = new carrier request), manual endorsement with carrier document, ManualCarrier/BrokerAssisted claims (decision + settlement recorded with carrier evidence), BrokerCollection/CarrierCollection payments | A4 | AT1 a renewal links previous_policy_id; AT2 a carrier-recorded claim decision needs an evidence document; AT3 an off-platform collection requires reconciliation before commission accrues |
| A6 Controlled override | `value_overrides`, `OverrideService`, authority matrix (M11 subset, domain OVERRIDE) | A1 | AT1 below the threshold it applies with a row; AT2 above the threshold it is PENDING until a different user approves; AT3 no code path updates the overridable fields without a row (static test) |
| A7 Import framework | A8 tables and pipeline; entities in order: CUSTOMERS, VEHICLES_FLEET, POLICY_PORTFOLIO, RENEWALS, CLAIMS, COMMISSIONS, BROKER_AGREEMENTS, AGENTS, PRODUCTS, TARIFFS | A2, A4 | AT1 the full flow with an error file; AT2 re-running the same file is idempotent; AT3 approver ≠ uploader; AT4 a portfolio import sends no payments or notifications; AT5 a product import runs the regulatory gate |
| A8 Mode 1 UI | Profile, simple onboarding, quote-request queue, broker send/record/upload screens (web + mobile APK), import centre, override inbox | A1–A7 incrementally | AT1 the owner's Mode 1 flow on a real device and APK: company → branches → product → coverage → premium info → documents → agreement → broker quote → insurer response → premium → policy upload → customer receives |

**Wave 2: configured mode (maturity level 3), each epic opt-in**

| Epic | Scope | Depends on | Acceptance tests |
|---|---|---|---|
| B1 Full product model | M04–M05, M07, plans, coverages v2, exclusions v2, questions | A2 | AT1 a PUBLISHED version rejects mutation; AT2 5% min 50,000 on a loss of 600,000 gives 550,000 |
| B2 Governance | §4.1/4.2 workflows, config_approvals, CONFIGURED completeness, diff, scheduled publish, rollback | B1 | AT1 maker-checker at every step; AT2 scheduled publish revalidates the gate; AT3 rollback keeps the history |
| B3 Expression engine | M06, validator, evaluator, traces, fact dictionary for 9 classes | A0 | AT1 operator truth tables including UNKNOWN; AT2 priority/stop order; AT3 save-time rejection; AT4 byte-identical replay |
| B4 Rating v2 + charges (CONFIGURED_RULES / UPLOADED_TARIFF / EXCEL_IMPORT pricing) | M08–M10, ChargeEngine, branch allocation, rounding, rating overrides via A6 | B1, B3, A7 (tariff import) | AT1 the DEMO motor example reproduced; AT2 allocation sums are exact; AT3 caps and min premium; AT4 v1 tariffs rate identically; AT5 no hard-coded rate |
| B5 RULE_ENGINE / HYBRID underwriting + rule-driven documents | M11, UnderwritingDecisionService, HybridUnderwriting | B3, B4 | AT1 REFER is routed by authority; AT2 in hybrid, a REFER falls to manual/remote |
| B6 OPES_GENERATED issuance + snapshot v2 | M12, PolicyIssuabilityService, templates binding, numbering | B4, B5 | AT1 the snapshot is immutable; AT2 claims use the snapshot, not the current version |
| B7 Configured servicing | M13 | B6 | AT1 pro-rata/short-rate refunds; AT2 renewal on the current version |
| B8 OPES_WORKFLOW claims rules | M14, ClaimCoverageService, SettlementCalculator | B6 | AT1 late-claim approval; AT2 settlement formula and remaining limit; AT3 advisory only |
| B9 Distribution & CONFIGURED_RULES commission | M15, sellability, CommissionEngine, internal split | A2, B4, seed workstream | AT1 an expired agreement makes the product unsellable; AT2 the split must equal 100%; AT3 clawback |
| B10 Sandbox & portfolio simulation | M16 | B4–B8 | AT1 no side effects; AT2 the full version trace |
| B11 Life & special schedules | M17–M18 | B6 | AT1 beneficiaries sum to 100%; AT2 no life proposal without an acknowledged summary box; AT3 each fleet vehicle is rated |
| B12 Advanced Product Builder UI | §12 configured screens + 19-step wizard | B1–B9 | AT1 publish the DEMO motor product through the UI with two users |
| B13 DEMO configured products | `opesinsure:seed-demo` only | B4, B11 | AT1 refused in production; AT2 watermarked |

Note: sellability (B9's `ProductDistributionService`) is needed in minimal form in A3. That minimal version checks agreement ACTIVE + authorization ACTIVE + version PUBLISHED, and B9 extends it with channels and territory.

**Wave 3: remote API mode (maturity levels 4–5)**

| Epic | Scope | Depends on | Acceptance tests |
|---|---|---|---|
| C1 Carrier integration framework | Per-carrier connector config on `integration_clients`, request signing, retries, error queue, manual fallback (profile `fallback_mode`), `external_record_mappings` source-of-truth | A0, workstream 7 | AT1 an outage falls back to MANUAL with an audit trail; AT2 idempotent replay |
| C2 CarrierApi providers (quote / UW / issuance / claims) | Adapters with a canonical carrier API contract (OpenAPI) + a per-carrier mapping layer | C1 | AT1 contract tests against a mock carrier; AT2 carrier documents arrive with provenance CARRIER_API |
| C3 Sync & reconciliation | Policy/claim status sync, conflict handling | C2 | AT1 conflicts surface to a human, never overwrite silently |
| C4 Legacy retirement | Remove `QuoteService::eligible/activeRules`, migrate v1 tariffs | B4 | AT1 no reads of `insurance_products.eligibility_rules` |

### 13.1 Definition of Done mapping (owner §101, 38 items)
The epic IDs in the table below use the old numbering. Map them as follows: E1→B1/A2, E2→B2/A2, E3→B3, E4→B4, E5→B5, E6→B6/A4, E7→B7/A5, E8→B8/A5, E9→B9, E10→B10, E11→B11, E12→B12/A8. Some DoD items apply in every mode: the gate, maker-checker, snapshot, provenance, and commission traceability. Those must also pass in MANUAL mode through the A-epics.

The condensed spec does not list the owner's 38 items. The mapping below lists the capabilities those items must cover, numbered for tracking. OQ-23: replace this list with the owner's exact 38 items and renumber.

| # | DoD capability | Epic.AT |
|---|---|---|
| 1 | Carrier product separate from versions | E1.AT1 |
| 2 | Versions immutable once published | E1.AT3 |
| 3 | EN/FR everywhere customer-facing | E2 completeness, E12.AT2 |
| 4 | CIMA mapping on every version | E2.AT2 |
| 5 | Regulatory gate blocks publication | E2.AT2/AT4 |
| 6 | Art. 328-1 accessory rules | E2.AT3 |
| 7 | Plans/packages | E1 |
| 8 | Coverage limit types | E1 |
| 9 | Deductible types with computed indemnity | E1.AT4 |
| 10 | Exclusions at 6 levels with legal text | E1 |
| 11 | Extensions with effects | E1/E4 |
| 12 | Dynamic questions with conditions | E1 (M07)/E12 |
| 13 | Eligibility outcomes (5) | E3 |
| 14 | Structured expressions, owner operators | E3.AT1 |
| 15 | Priority and stop_processing | E3.AT2 |
| 16 | Explainability trace | E3/E4 |
| 17 | UW decisions (7) | E5 |
| 18 | Authority routing | E5.AT1 |
| 19 | Rating methods (13) | E4 |
| 20 | Tables/bands | E4 |
| 21 | Min premium and discount caps | E4.AT3/AT4 |
| 22 | Discounts and loadings with authority | E4.AT5 |
| 23 | Tax/statutory engine, nothing hard-coded | E4.AT7 |
| 24 | Pricing snapshot on quote | E4 |
| 25 | Quote validity | E4 |
| 26 | Risk-based documents | E5.AT2 |
| 27 | POLICY_ISSUABLE | E6.AT1 |
| 28 | Effective date/duration/instalments | E6 |
| 29 | Immutable policy snapshot | E6.AT2/AT3 |
| 30 | Endorsement rules | E7 |
| 31 | Renewal rules | E7.AT2 |
| 32 | Cancellation/suspension | E7 |
| 33 | Claims coverage and settlement | E8 |
| 34 | Commission per agreement/version/transaction | E9 |
| 35 | Distribution sellability | E9.AT1 |
| 36 | Sandbox with version trace | E10 |
| 37 | Maker-checker workflows | E2.AT1 |
| 38 | Per-branch allocation for Art. 411/557 | E4.AT2 |

---

## 14. Risks
1. **Product/version split backfill** touches live FKs. Mitigation: additive columns, a dual-write period, and a feature flag `rules.engine_v2` per carrier.
2. **Two rating dialects coexist** until E13. Mitigation: the router is keyed on `rules_schema_version`, plus a golden-file regression suite of legacy quotes.
3. **Tax and levy correctness**: rates and bases in Cameroon (TCA/TVA, FGA-type fund, stamp, carte rose) are unconfirmed. Everything stays DEMO until an insurer or tax adviser signs it off (OQ-9). This is a legal exposure if it is wrong.
4. **CIMA amendments (2024/2026)**: the gate and life rules depend on the dictionary being current. Mitigation: effective-dated regulatory data and re-gating on dictionary publication.
5. **Performance**: tracing every rule adds write volume. Mitigation: batch insert, monthly partitions, and sampling for SIMULATION only (never for QUOTE).
6. **Shipped APK compatibility**: mobile risk-schema and quote shapes must not change. Mitigation: adapter plus contract tests.
7. **Concurrent workstreams**: the CIMA dictionary and register seeding define `product_regulatory_mappings`, branch-level `insurer_authorizations`, `carrier_broker_agreements`, and product permissions. This plan consumes those and must be rebased once they land.
8. **Actuarial liability for life**: use only carrier-supplied tables. The UI must never suggest rates.
9. **Configuration complexity for carriers**: mitigated by the wizard, templates cloned from DEMO products, and the completeness checklist. The adaptive model means no carrier has to configure anything to start.
10. **Manual-mode data quality**: broker-entered premiums and uploaded documents can be wrong or forged. Mitigation: mandatory evidence, sha256, carrier confirmation, thresholds needing a second approver, and a reconciliation of carrier statements against recorded premiums (workstream 3).
11. **Branch allocation in manual mode** falls back to the default mapping, which can misstate Art. 411 figures. Mitigation: a flag on each record, a true-up via import of the carrier split, and a report warning (OQ-24).
12. **Mode drift**: a carrier switches modes mid-policy. Mitigation: the mode is pinned on each transaction, and servicing uses the pinned mode unless it is explicitly migrated.

---

## 15. Open questions
- OQ-1 Merge rule for duplicate (carrier, code) product rows with live quotes.
- OQ-2 Retention period for rule traces (regulatory minimum?).
- OQ-3 Cooling-off / withdrawal period by class (life: Art. rights of withdrawal).
- OQ-4 Minimum number of distinct approvers per carrier and per step.
- OQ-5 Business activity code list (NACE/ISIC or local).
- OQ-6 Use of sex/age in life and health pricing: legal constraints.
- OQ-7 Tax treatment of instalment loading.
- OQ-8 XAF rounding unit per insurer (1 or 5 XAF) and rounding mode.
- OQ-9 Authoritative list, bases and rates of Cameroon insurance taxes, levies, stamp duties and fund contributions per class.
- OQ-10 Platform fee policy (customer-visible or not).
- OQ-11 Whether any carrier uses commission-exclusive pricing.
- OQ-12 Commission base (net premium vs net premium + accessories) per carrier agreement.
- OQ-13 Surrender value thresholds and formula under the current Art. 65.
- OQ-14 CIMA branch mapping for motor fire/theft (3 vs 8/9).
- OQ-15 Health premium taxation.
- OQ-16 Travel branch split (2 vs 18) per carrier.
- OQ-17 Foreign-currency limits (EUR for Schengen) and conversion policy.
- OQ-18 Life premium taxation.
- OQ-19 Exact names of the owner's 15 events.
- OQ-20 Whether portfolio simulation is blocking before approval.
- OQ-21 Canonical carrier web surface (carrier Filament panel vs partner web app).
- OQ-22 Exact screen names for INS-SET-017…030.
- OQ-23 The owner's verbatim 38-item Definition of Done.
- OQ-24 In manual mode, can carriers be required to supply the premium split by CIMA branch (Art. 411), or is default-mapping allocation acceptable with a later true-up?
- OQ-25 Motor attestation/sticker chain when the carrier issues the attestation itself: does Opes still track the sticker serial?
- OQ-26 Are carrier-attested credit terms (issue before payment) allowed for broker/corporate business, and under what authority?
- OQ-27 Import limits (file size, rows) and whether portfolio imports may include historical cancelled/expired policies.
- OQ-28 May a broker record an insurer's response on its behalf without later carrier confirmation, and for which premium threshold?
- OQ-29 Default SLA for carrier responses to manual quote requests, and whether expiry notifies the broker only or also the carrier.
