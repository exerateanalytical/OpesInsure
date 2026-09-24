# OpesInsure CIMA Regulatory Dictionary module — owner specification v1.0 (2026-09-24)

A formal regulatory master-data module, placed **above** the insurer/product configuration — not a country setting and not a word list. Canonical data: `database/data/cima_regulatory_master_2026.json`.

## Layers (never collapsed into one field)
CIMA Code (e.g. branch 10) → Insurance class → Normalized product (e.g. MOTOR_LIABILITY) → Carrier product (commercial name) → Product version → Coverage/tariff/rules → Broker authorization → Policy → CIMA reporting.

## Content
- Namespaces: CIMA, CIMA_BRANCH, CIMA_MICRO_BRANCH, CIMA_REPORTING_CATEGORY, CIMA_TERM, CIMA_DOCUMENT_TYPE, CIMA_PARTY_ROLE, CIMA_POLICY_STATUS, CIMA_CLAIM_STATUS.
- Article 328 branches 1–18 (IARD), 19 reserved, 20–23 (life/capitalization); subdivisions; accessory-risk rules (Art. 328-1: branches 14 and 15 never accessory); complementary covers for 20/21.
- Motor spans several branches (3 own damage, 10 RC compulsory, 1 accident, 18 assistance); compulsory insurance attributes (is_compulsory, basis, legal reference, jurisdiction, effective dates) incl. imported goods.
- Article 717 microinsurance branches (1–7 non-life, 11–14 life), separate from conventional classes.
- Article 411 reporting categories; Article 557 intermediary measures (written/collected premium, recorded/collected commission, rate, current/prior period).
- Controlled bilingual terminology by semantic code (contract, parties, intermediaries, premium lifecycle, claims, FNOL, underwriting, documents, coverage, risk, cancellation, life incl. Art. 65/65-1 surrender and summary box, reinsurance, co-insurance, commissions, regulatory authorization "agrément", digital insurance). Context rule: translate by semantic code, never word-for-word; customers never see raw codes.
- Canonical policy document codes and person roles (16) — distinct roles, never one generic customer role.
- Proposal ≠ policy (Art. 6); avenant, never "edit policy"; policyholder ≠ insured ≠ beneficiary.
- Regulatory authorities: CIMA, CMA, CRCA, SG_CIMA, national authority.
- Insurer → regulatory authorization → authorized CIMA branches; product_regulatory_mappings (PRIMARY / ACCESSORY / COMPLEMENTARY, effective-dated, legal reference); **publication blocked** with explicit reason when the insurer isn't authorized for a required branch or mapping is missing.
- Effective-dated, versioned regulatory data and a legal reference library (Articles 6, 7, 8, 12, 13, 65, 65-1, 200, 328, 328-1, 328-2, 411, 557, 717).
- RegulatoryTerminologyService: (regime, locale, term) → label.

## New screens (22) — register now 552
PLT-CIMA-001…012 (dashboard, branch register, branch details, microinsurance register, reporting categories, terminology dictionary, EN/FR mapping, product mapping, insurer branch authorization, compulsory insurance, reference library, reporting mapping) · INS-SET-CIMA-001…006 (CIMA authorization, authorized branches, product-to-CIMA mapping, reporting mapping, accessory risk mapping, life complementary covers) · BRK-SET-CIMA-001…004 (reporting configuration, regulatory product classification, premium/collection classification, commission reporting mapping).

## CIMA-ready checklist
The owner's 28-item checklist (section 60 of the specification) is the acceptance gate.
