# OpesInsure Product & Insurance Rule Engine — Master Specification v1.0 (owner, locked 2026-09-24)

Authoritative owner specification (103 sections, supplied in session 2026-09-24). This file is the condensed canonical record; the implementation plan against today's code is in PRODUCT_RULE_ENGINE_IMPLEMENTATION_PLAN.md.

## Principles
- **Central insurance operating kernel**: configuration, never insurer logic in controllers (no `if product == "Motor Gold"`; no `if claim_type == collision require police report`).
- Built on **CIMA's branch-based authorization** (Art. 328) and Art. 8 contract content; all regulatory rules **effective-dated** (CIMA amended the Code in 2024 and 2026).
- **Policy reproducibility**: an issued policy stays tied to the exact product, tariff, wording, coverage and rule versions; no retroactive change.

## Layers (kept separate)
CIMA regulatory rule → insurer authorization → normalized class → carrier product → product version → coverages → eligibility → underwriting → tariff → policy rules → claims rules → broker distribution authority → customer transaction.
Hierarchy: Branch → Class → Product family → Carrier product → Version → Plan/Package → Coverage.

## Regulatory layer & gate (4–5)
Each version maps to ≥1 CIMA classification (regime, branch code, PRIMARY/ACCESSORY/COMPLEMENTARY, legal reference, effective dates). Gate: insurer → jurisdiction → authorization → branch → effective period → AUTHORIZED | NOT_AUTHORIZED | AUTHORIZATION_EXPIRED | AUTHORIZATION_SUSPENDED | MAPPING_REQUIRED; failure = PRODUCT PUBLICATION BLOCKED.

## Classes (6)
Non-life: MOTOR, HEALTH, PERSONAL_ACCIDENT, PROPERTY, HOME, FIRE, TRAVEL, LIABILITY, PROFESSIONAL_LIABILITY, BUSINESS_MULTIRISK, CONSTRUCTION, ENGINEERING, MARINE, TRANSPORT, CARGO, AVIATION, CREDIT, SURETY, AGRICULTURE, LIVESTOCK, ASSISTANCE, LEGAL_PROTECTION, FINANCIAL_LOSS. Life: LIFE, DEATH, SAVINGS, CAPITALIZATION, RETIREMENT, EDUCATION, CREDIT_LIFE, GROUP_LIFE, PROVIDENT, FUNERAL, ANNUITY.

## Product, versions, plans (7–10)
Carrier product (id, carrier, code, EN/FR names and descriptions, class, family, customer type INDIVIDUAL/FAMILY/SME/CORPORATE/GROUP/GOVERNMENT/ASSOCIATION, currency, status). Versions never edited live (version, effective, sales start/end, renewal/new-business allowed; DRAFT→REVIEW→APPROVED→PUBLISHED→SUSPENDED→RETIRED). Activation requires mapping, authorization, coverage, eligibility, rating, underwriting, policy rules, claims rules, templates, accounting, distribution, EN/FR wording, approval. Plans (TP/TP+/Intermediate/Comprehensive; Bronze…Platinum) with coverage set, limits, deductibles, eligibility, pricing reference.

## Coverage (11–16)
Coverage fields (code, EN/FR, mandatory/optional/default, limit type/amount, deductible, waiting period, territory, period). Limit types UNLIMITED, FIXED_AMOUNT, PERCENT_OF_SUM_INSURED, PERCENT_OF_LOSS, PER_EVENT, PER_PERSON, PER_YEAR, PER_POLICY_PERIOD, AGGREGATE, SUB_LIMIT. Deductibles FIXED, PERCENTAGE, DAYS, COMBINED, MINIMUM, MAXIMUM (e.g. 5% min 50,000 XAF) with computed indemnifiable amount. Exclusions at PRODUCT/PLAN/COVERAGE/CUSTOMER/RISK/CLAIM with legal text. Extensions affecting premium, limits, eligibility, underwriting. Risk objects PERSON, VEHICLE, PROPERTY, BUILDING, BUSINESS, CARGO, SHIPMENT, AIRCRAFT, VESSEL, MACHINERY, PROJECT, LOAN, CROP, LIVESTOCK, GROUP, EMPLOYEE.

## Questions, eligibility, rules (17–22)
Configurable questions (code, type TEXT/NUMBER/DATE/SELECT/MULTI_SELECT/BOOLEAN/CURRENCY/PERCENTAGE/ADDRESS/DOCUMENT/PHOTO/ENTITY_REFERENCE, EN/FR, required, validation, visibility condition, risk factor); conditional questions. Eligibility outputs ELIGIBLE, INELIGIBLE, CONDITIONAL, REFER_TO_UNDERWRITING, MORE_INFORMATION_REQUIRED. Structured expressions only (no admin-uploaded code); operators EQUAL, NOT_EQUAL, GT, GTE, LT, LTE, IN, NOT_IN, BETWEEN, CONTAINS, EXISTS, NOT_EXISTS, AND, OR, NOT; priority + stop_processing.

## Underwriting (23–25)
Decisions AUTO_ACCEPT, REFER, CONDITIONAL_ACCEPT, DECLINE, REQUEST_INFORMATION, REQUEST_INSPECTION, REQUEST_MEDICAL; authority routing by sum insured/premium/score/product/branch (insurer-configurable); optional explainable risk score (factor, weight, input, contribution) assisting, never replacing, rules.

## Rating (26–33)
Base + loadings + optional coverages + extensions − discounts + statutory charges + taxes + fees = total. Methods FIXED, RATE_X_SUM_INSURED, RATE_X_LIMIT, TABLE, BAND, TIER, FORMULA, PER_PERSON, PER_VEHICLE, PER_EMPLOYEE, PER_DAY, PER_TRIP, PER_SHIPMENT. Tariff tables/bands; effective-dated versions (quote stores tariff_version_id); minimum premium; discounts NO_CLAIMS, FLEET, GROUP, LOYALTY, CAMPAIGN, CORPORATE, SECURITY, MULTI_POLICY, MANUAL_AUTHORIZED (max, eligibility, approver, audit); loadings HIGH_RISK_USE, HIGH_CLAIMS, OLDER_ASSET, HAZARDOUS_ACTIVITY, GEOGRAPHIC_RISK; tax & statutory charge engine (charge code, jurisdiction, basis, rate/fixed, effective dates, classes) — no hard-coded rates.

## Quote → proposal → issuance (34–42)
Quote sequence and full immutable pricing snapshot (base, coverages, loadings, discounts, taxes, fees, total, currency, tariff and rule versions); validity days → QUOTE_EXPIRED; proposal declarations/documents/consent/beneficiaries; risk-dependent document requirements (MISSING, UPLOADED, REVIEWING, ACCEPTED, REJECTED, EXPIRED); POLICY_ISSUABLE only when approved + payment condition + documents + underwriting + approvals; effective-date rules IMMEDIATE/SPECIFIED_DATE/PAYMENT_DATE/APPROVAL_DATE/MIDNIGHT_RULE/CUSTOM; durations 1 day … 12 months/custom; instalments SINGLE/MONTHLY/QUARTERLY/SEMI_ANNUAL/ANNUAL/CUSTOM with first payment, fees and non-payment consequences.

## Servicing (43–48)
Endorsement rules per type (allowed, effective date, underwriting, documents, rerate, additional premium, refund, approval); mid-term adjustment PRO_RATA/SHORT_RATE/NO_ADJUSTMENT/CUSTOM; renewal rules (window, automatic quote, rerate, risk review, document/KYC refresh, inspection, automatic renewal) using the current version with previous_policy_id; cancellation (customer/carrier cancellable, reasons, notice, refund method, approval, documents, audit); suspension (reason, effective, coverage effect, remediation, reinstatement).

## Claims (49–56)
Claim eligibility chain → COVERAGE_CONFIRMED / REVIEW_REQUIRED / POTENTIAL_EXCLUSION / OUTSIDE_COVERAGE (humans decide); claim types per product; evidence rules (required/conditional); configurable reporting deadlines and late-claim approval; reserves INITIAL/CURRENT/FINAL; limits (policy, coverage, sub-limit, aggregate, deductible, prior payments → maximum eligible); settlement = covered loss − excluded − deductible − prior settlements ± adjustments ≤ remaining limit; recovery/subrogation/salvage as separate entities.

## Commission & distribution (57–62)
Commission on carrier + agreement + version + transaction type (NEW_BUSINESS/RENEWAL/ENDORSEMENT): % gross/net, fixed, tier, volume, hybrid; reversal, clawback, refund; internal broker allocation (broker/agent/supervisor/branch) without altering the carrier agreement. Sellable only if product ACTIVE, version PUBLISHED, agreement ACTIVE, authorization ACTIVE, branch and agent allowed. Channels CUSTOMER_WEB, CUSTOMER_MOBILE, BROKER, AGENT, BRANCH, API, BANCASSURANCE, PARTNER. Territorial scope COUNTRY/REGION/CITY/BRANCH.

## Documents (63–65)
Templates per version (quote, proposal, policy, schedule, general/special conditions, attestation, cover note, endorsement, renewal notice), effective-dated; issuance stores template_version_id; general/special/particular conditions separated.

## Life & special products (66–73)
Life rules (entry/max age, term, frequency, benefit, beneficiaries, maturity, surrender and surrender value, annuity, death, disability); beneficiary engine (primary/contingent, allocation = 100%, history); savings/capitalization from carrier-approved actuarial models only; group (master policy, members, certificates, eligibility, rate, join/leave); fleet (vehicle schedule, add/remove); marine cargo declarations (voyage, goods, value, mode, certificate); construction (project, contract value, period, site, contractors, work type); agriculture (crop, farm, area, season, livestock, cycle, location).

## Governance (74–84)
Product workflow DRAFT → CONFIGURATION → TECHNICAL_REVIEW → COMPLIANCE_REVIEW → BUSINESS_APPROVAL → READY → PUBLISHED; tariff workflow DRAFT → ACTUARIAL/TECHNICAL REVIEW → APPROVAL → SCHEDULED → ACTIVE → EXPIRED (immutable history); test sandbox with full trace; explainability (rule ID, version, condition, input, result, time); portfolio simulation before publishing; configuration diff; future-dated publication; non-destructive rollback (suspend/republish); dependency graph; immutable policy snapshot at issuance (version, coverages, limits, deductibles, premium, taxes, fees, risk, rules, documents, objects, beneficiaries); renewal snapshot.

## Architecture (85–92)
Bounded rule domains (Eligibility, Rating, Underwriting, Policy, Endorsement, Renewal, Cancellation, Claims, Commission, Distribution, Documents, Accounting) sharing one expression infrastructure. Modules ProductCatalogue, Regulatory, Coverage, Eligibility, Rating, Underwriting, Policy, Claims, Commission, Distribution, Documents. Services ProductEligibilityService, RatingEngine, UnderwritingDecisionService, CoverageResolver, PolicyIssuabilityService, EndorsementRatingService, RenewalRatingService, ClaimCoverageService, ClaimSettlementCalculator, CommissionEngine, ProductDistributionService, RegulatoryValidationService. Events ProductCreated … CommissionCalculated (15). Tables as listed in section 89 with version/effective/status/created_by/approved_by/published_at. Admin API under /api/v1/carriers/{carrier}/products and /product-versions/{version}/…; runtime POST /insurance/eligibility/check, /insurance/rate, /quotes, /underwriting/evaluate, /claims/coverage/evaluate; pricing response with internal calculation trace.

## Product Builder UI (93–99)
Insurer workspace sections Overview → Audit (20); 19-step New Product Wizard; product dashboard (status, version, branches, policies, quotes, conversion, premium, claims, loss ratio, brokers, upcoming version, warnings); configuration completeness checklist; blocking errors (no CIMA mapping, tariff, template, claims rules, authorization, accounting) vs warnings; marketplace comparison on normalized dimensions.

## Runtime chain (100) and Definition of Done (101)
Need → authorized products → distribution rights → risk → eligibility → coverage → rating → underwriting → quote → proposal → approval → payment → issuance → snapshot → servicing → renewal → claims coverage → decision → settlement → commission/accounting/CIMA reporting. 38-item Definition of Done as listed by the owner.
