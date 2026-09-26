# Field-Rule Coverage — Canonical Document Spec (work item D2)

Scope: the detailed field specifications of the 36 critical documents in
`OpesInsure_Canonical_Implementation_Specification_v1.json` (684 bullets), and the catalogue linkage of all 220
canonical document records. Agent d2s, 2026-09-26. Companion to `DOCUMENT_SPEC_GAP_AUDIT.md`.

## 1. Catalogue linkage (before / after)

| Measure | Before | After |
|---|---|---|
| Canonical spec records linked to a catalogue type | 203 / 220 | **220 / 220** |
| Spec records `PENDING_VERIFICATION` (no type) | 17 | 0 |
| Catalogue types carrying a canonical security profile | 206 | 223 |

The 17 records (confirmed against the gap audit: DOC-014, 015, 138, 139, 140, 169, 179, 185, 196, 199, 200, 209,
213, 214, 218, 219, 220) now have catalogue types in the new namespace `CANONICAL_SPEC`
(`type_id` = `SPEC.<CANONICAL_CODE>`), defined in `database/data/document_catalogue_spec_additions_2026.json` and
merged by `DocumentCatalogueSeeder::withSpecAdditions()`. The owner register's `DOC-###` numbering is untouched
(the numbering conflict stays an owner decision). Names are the spec's EN/FR names, so the existing exact-name
mapping links them. The security profile (tier, controls, confidentiality, access) comes from the spec record.

| Spec | Type | Nearest existing type (`same_as_type_id`) | Packs (all CONDITIONAL) |
|---|---|---|---|
| DOC-014 | SPEC.OFFER_ACCEPTANCE_CONFIRMATION | DOC-015 Offer Acceptance | every `*_NEW_BUSINESS_PACK` |
| DOC-015 | SPEC.ELECTRONIC_CONSENT_RECORD | DOC-020 Electronic Contract Consent | every `*_NEW_BUSINESS_PACK` |
| DOC-138 | SPEC.TRAVEL_INSURANCE_CERTIFICATE | none | TRAVEL new business, renewal |
| DOC-139 | SPEC.VISA_TRAVEL_COVERAGE_CERTIFICATE | none | TRAVEL new business, renewal |
| DOC-140 | SPEC.TRAVEL_ASSISTANCE_INFORMATION | none | TRAVEL new business, renewal, claim |
| DOC-169 | SPEC.CLAIM_ASSESSMENT_REPORT | CLM-09 Assessment Report | same packs as CLM-09 |
| DOC-179 | SPEC.CLAIM_DISCHARGE | CLM-19 Discharge | same packs as CLM-19 |
| DOC-185 | SPEC.SUBROGATION_RECOVERY_NOTICE | CLM-26 Subrogation Notice | packs of CLM-26 and CLM-27 |
| DOC-196 | SPEC.BROKER_COMMISSION_STATEMENT | none | UNIVERSAL_FINANCIAL |
| DOC-199 | SPEC.TAX_LEVY_BREAKDOWN | FINANCE.TAX_FEE_STATEMENT | UNIVERSAL_FINANCIAL |
| DOC-200 | SPEC.RECONCILIATION_STATEMENT | none | UNIVERSAL_FINANCIAL |
| DOC-209 | SPEC.REINSURANCE_RECOVERY_REQUEST | REINSURANCE.RECOVERY_REQUEST | UNIVERSAL_CLAIM |
| DOC-213 | SPEC.COINSURANCE_PREMIUM_ALLOCATION | COINSURANCE.PREMIUM_ALLOCATION_STATEMENT | UNIVERSAL_FINANCIAL |
| DOC-214 | SPEC.COINSURANCE_CLAIM_ALLOCATION | COINSURANCE.CLAIM_ALLOCATION_STATEMENT | UNIVERSAL_CLAIM |
| DOC-218 | SPEC.KYC_APPROVAL_REMEDIATION_NOTICE | REGULATORY_COMPLIANCE.KYC_APPROVAL | UNIVERSAL_SERVICING |
| DOC-219 | SPEC.REGULATORY_DECLARATION_RETURN | REGULATORY_COMPLIANCE.REGULATORY_REPORT | none (entity-level return) |
| DOC-220 | SPEC.REGULATORY_AUDIT_VERIFICATION_REPORT | none | none (entity-level audit) |

The pack items are CONDITIONAL on purpose: the pack resolver never generates a CONDITIONAL item, so no live flow
starts issuing these documents before an engine trigger exists (travel certificates, discharge, recovery and
co-insurance issuance belong to D3/D4 and later work). **Owner decision (PENDING_VERIFICATION):** whether each
near-synonym pair should be merged into one type, or kept as two types.

## 2. Detailed field rules (before / after)

| Status | Before | After |
|---|---|---|
| ENFORCED (canonical key, blocks issuance when empty) | 180 | 180 (unchanged) |
| SECURITY_CONTROL | 28 | 28 |
| NO_CANONICAL_SOURCE (existing dictionary `@no_source`) | 31 | 31 |
| CONDITIONAL | 3 | 3 |
| **UNMAPPED_PENDING_VERIFICATION** | **442** | **41** (each names its missing source) |
| **MAPPED_PLATFORM_SOURCE** (new) | 0 | **401** |
| Total | 684 | 684 |

`app/Application/DocumentCatalogue/DetailedFieldSourceMap.php` maps each bullet to a canonical field key plus
the platform table and column that hold the value (policy, parties, policy_risks, risk_asset_vehicles, quote
offers, proposals and declarations, beneficiary designations, health members and preauthorizations, provider
profiles and contracts, claims, assessments, decisions, settlements, payments and recoveries, financial
obligations, instalments, premium components, partner statements, bordereaux, settlement batches,
reinsurance and co-insurance tables, cargo declarations, regulatory report runs, institution profiles, tenant
branches, letterheads and document templates). Every named table is checked against the migrated schema by a
test. `CanonicalDocumentSpec::detailedFieldMap()` records `status`, `target` and `source` per bullet in
`document_canonical_specs.detailed_field_map`. `DocumentFieldRequirements::requiredKeys()` now returns them under
`mapped`.

**Enforcement is unchanged.** Mapped rules render when present and never block issuance. Only the 180 rules
enforced before still block. Turning mapped rules into blocking ones is an owner decision per document, because
many sources are only filled at later workflow steps. Wiring each key's value into the renderer's view model is
work for D3/D4, which move the renderers onto the secure shell.

The 31 NO_CANONICAL_SOURCE rules from the earlier audit were left as they were (outside the 442). Several now
have platform data and can be re-pointed in a follow-up: broker and agent (`partners`), branch
(`tenant_branches`), payment schedule (`policy_premium_instalments`), balance and allocation
(`financial_obligations`, `payment_allocations`) and cashier or channel (`cashier_collections`).

## 3. Per-document coverage (36 critical documents)

| Spec | Document | Enforced | Mapped to platform | Pending (gap named) | No canonical source | Controls | Conditional |
|---|---|---|---|---|---|---|---|
| DOC-001 | Insurance Quote | 15 | 16 | 0 | 4 | 0 | 0 |
| DOC-003 | Insurance Proposal | 6 | 19 | 1 | 0 | 0 | 0 |
| DOC-016 | Insurance Policy | 13 | 8 | 1 | 8 | 2 | 0 |
| DOC-017 | Policy Schedule | 14 | 1 | 0 | 7 | 2 | 0 |
| DOC-021 | Cover Note | 4 | 11 | 0 | 0 | 1 | 1 |
| DOC-022 | Insurance Certificate | 10 | 0 | 0 | 2 | 3 | 0 |
| DOC-036 | Motor Insurance Attestation | 16 | 2 | 0 | 2 | 2 | 1 |
| DOC-057 | Medical Declaration | 0 | 14 | 0 | 0 | 1 | 0 |
| DOC-060 | Health Membership Card | 1 | 9 | 1 | 0 | 0 | 0 |
| DOC-066 | Preauthorization Approval | 2 | 17 | 0 | 0 | 1 | 0 |
| DOC-069 | Guarantee of Payment | 1 | 13 | 2 | 0 | 1 | 0 |
| DOC-076 | Life Insurance Illustration | 3 | 11 | 4 | 0 | 0 | 0 |
| DOC-082 | Beneficiary Nomination | 3 | 11 | 1 | 0 | 1 | 0 |
| DOC-096 | Master Group Policy | 3 | 11 | 3 | 0 | 2 | 0 |
| DOC-125 | Professional Liability Certificate | 7 | 6 | 1 | 0 | 1 | 0 |
| DOC-133 | Marine Cargo Certificate | 5 | 13 | 1 | 0 | 1 | 0 |
| DOC-138 | Travel Insurance Certificate | 4 | 9 | 2 | 0 | 1 | 0 |
| DOC-147 | Policy Endorsement | 8 | 8 | 1 | 0 | 2 | 0 |
| DOC-151 | Renewal Notice | 2 | 12 | 0 | 0 | 0 | 0 |
| DOC-158 | Cancellation / Termination Notice | 4 | 9 | 2 | 0 | 1 | 0 |
| DOC-161 | Claim Notification Form | 6 | 26 | 0 | 0 | 0 | 0 |
| DOC-162 | Claim Acknowledgement | 4 | 7 | 1 | 0 | 0 | 0 |
| DOC-169 | Claim Assessment Report | 3 | 17 | 3 | 0 | 0 | 0 |
| DOC-174 | Claim Decision | 4 | 17 | 0 | 0 | 1 | 0 |
| DOC-177 | Settlement Offer | 2 | 11 | 2 | 0 | 1 | 0 |
| DOC-179 | Claim Discharge | 3 | 8 | 1 | 0 | 1 | 0 |
| DOC-181 | Claim Settlement Statement | 4 | 12 | 0 | 0 | 0 | 1 |
| DOC-186 | Premium Invoice | 1 | 14 | 0 | 1 | 0 | 0 |
| DOC-190 | Premium Receipt | 11 | 2 | 0 | 3 | 2 | 0 |
| DOC-194 | Broker Statement | 5 | 13 | 0 | 1 | 0 | 0 |
| DOC-197 | Carrier Settlement Statement | 4 | 11 | 1 | 1 | 0 | 0 |
| DOC-201 | Reinsurance Slip | 2 | 17 | 1 | 1 | 0 | 0 |
| DOC-206 | Risk Bordereau | 6 | 11 | 0 | 0 | 0 | 0 |
| DOC-208 | Claims Bordereau | 2 | 13 | 0 | 0 | 0 | 0 |
| DOC-215 | Provider Contract | 1 | 10 | 9 | 1 | 1 | 0 |
| DOC-219 | Regulatory Declaration / Return | 1 | 12 | 3 | 0 | 0 | 0 |

## 4. Rules still PENDING_VERIFICATION, and the missing source for each (41)

| Spec | Field rule | Missing source |
|---|---|---|
| DOC-003 | payment preference | No stored payment preference on proposals (payment_intents only record the method used after checkout) |
| DOC-016 | clauses | No clause library with versioned clause texts (exclusion_legal_texts cover exclusions only) |
| DOC-060 | emergency/provider contact | No emergency/assistance contact configured per product or network (provider_networks has no contact) |
| DOC-069 | claim submission instructions | No provider claim-submission instructions stored on provider_contracts (document_reference only) |
| DOC-069 | contact/escalation point | No escalation contact configured per carrier/network |
| DOC-076 | projected values | No life projection engine (projected values are not computed) |
| DOC-076 | maturity values | No life projection engine (maturity/projected values are not computed) |
| DOC-076 | charges | No life charge schedule (policy fees/charges) in the life product model |
| DOC-076 | validity/assumption basis | No verified life illustration basis (interest/mortality assumptions) configured |
| DOC-082 | witness/notarization only if required/configured | No witness/notary capture on beneficiary designations |
| DOC-096 | contribution/premium basis | No group contribution basis (per member / payroll) stored on group schemes |
| DOC-096 | enrollment rules | No group enrolment rules (joining windows, evidence of insurability) on special_policy_profiles.terms |
| DOC-096 | member certificate rules | No member certificate issuance rule configured for group schemes |
| DOC-125 | territorial/jurisdictional scope | No territory/jurisdiction field on products or policies |
| DOC-133 | coverage terms/clauses | No Institute Cargo Clause library (A/B/C) with verified texts |
| DOC-138 | emergency assistance | No travel assistance provider/contract recorded |
| DOC-138 | emergency assistance number | No verified travel assistance phone number recorded |
| DOC-147 | tax/fee delta | policy_transactions carries premium_delta_minor only; no separate tax/fee delta |
| DOC-158 | claims implications where appropriate | No configured statement of claims implications per cancellation reason |
| DOC-158 | reinstatement possibility where applicable | No reinstatement rule in cancellation_rule_versions |
| DOC-162 | next steps | No configured next-steps text per claim status |
| DOC-169 | inspection date | No inspection date on claim_assessments |
| DOC-169 | conflicts/limitations | No conflict-of-interest / limitations capture on claim_assessments |
| DOC-169 | assessor declaration | No assessor declaration/independence statement captured on claim_assessments |
| DOC-177 | tax/withholding where applicable | No verified withholding-tax rule for claim settlements (tax_levy_versions covers premium only) |
| DOC-177 | validity period of offer | No offer validity/expiry on claim_settlements (offered_at only) |
| DOC-179 | scope of discharge/release | No release-scope wording configured per settlement nature (legal text PENDING_VERIFICATION) |
| DOC-197 | prior balance | No carried-forward balance on settlement_batches |
| DOC-201 | governing reference | No governing law/jurisdiction on facultative placements or treaties |
| DOC-215 | renewal/termination | No renewal/termination terms on provider_contracts |
| DOC-215 | billing rules | No provider billing rules on provider_contracts |
| DOC-215 | eligibility process | No eligibility-check procedure text on provider contracts |
| DOC-215 | preauthorization rules | No preauthorization rule set on provider_contracts |
| DOC-215 | claim submission rules | No provider claim-submission rules on provider_contracts |
| DOC-215 | dispute process | No provider dispute clause on provider_contracts (provider_disputes records disputes, not terms) |
| DOC-215 | audit rights | No provider contract clause store (audit rights wording) |
| DOC-215 | fraud/abuse obligations | No provider contract clause store (fraud/abuse obligations) |
| DOC-215 | confidentiality/data protection | No provider contract clause store (data-protection wording) |
| DOC-219 | reviewer | regulatory_report_runs has preparer and approver only; no separate reviewer |
| DOC-219 | required regulatory line items | CIMA return line items are PENDING_OFFICIAL_IMPORT (regulatory_report_dictionary_lines not verified) |
| DOC-219 | certification/declaration | No verified CIMA certification wording for returns |

### Missing sources, grouped

- **Clause and legal-text library:** policy clauses, Institute Cargo Clauses, discharge release scope, CIMA
  return certification wording and provider contract clauses (audit rights, data protection, fraud, disputes,
  renewal and termination, billing, claim submission, preauthorization and eligibility procedures).
  These need verified legal texts.
- **Life projection engine:** maturity values, projected values, illustration basis and life charges.
- **Assistance and contacts:** travel assistance provider and number, emergency and escalation contacts per
  product or network, and next-steps texts per claim status.
- **Claims:** assessor declaration and conflicts, inspection date, offer validity, and withholding tax on
  settlements.
- **Products and policies:** territory or jurisdiction, group contribution basis, enrolment and member
  certificate rules, reinstatement rule, claims implications of a cancellation, and the tax/fee delta on
  endorsements.
- **Finance and regulatory:** carried-forward settlement balance, a separate reviewer on regulatory runs,
  verified CIMA return line items (PENDING_OFFICIAL_IMPORT), governing law on reinsurance, witness and notary on
  beneficiary designations, and payment preference on proposals.
