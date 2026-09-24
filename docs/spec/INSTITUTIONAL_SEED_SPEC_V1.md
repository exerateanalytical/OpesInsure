# OpesInsure Cameroon Institutional Seed Data Specification v1.0 — owner specification (2026-09-24)

The full 75-section specification was supplied by the owner in the session of 2026-09-24. Key locked rules:

1. **Provenance on every seeded record:** `data_origin` = REGULATORY | CARRIER_PUBLISHED | PLATFORM_NORMALIZED | DEMO_SYNTHETIC; regulatory records carry `source_authority` (DGTCFM/MINFI) and `reference_year` (2026); synthetic records carry `is_demo=true`.
2. **Canonical IDs:** insurers `CM-INS-IARD-001..018`, `CM-INS-LIFE-001..011` (order and codes as listed by the owner, e.g. CM-INS-IARD-012 = NSIA ASSURANCES AU CAMEROUN, code NSIA_IARD); brokers `CM-BRK-2026-001..123` in regulator order. Never expose integer PKs as identity.
3. **No invented institutional facts:** no fake licence numbers, expiry dates, emails, addresses or capital; unknown fields stay null.
4. **Branches** IARD, LIFE, CAPITALIZATION; Life and IARD entities of the same group are separate carriers.
5. **Taxonomy codes** (IARD and LIFE classes, motor/health/travel/property/liability/construction/transport/life product codes, coverage modules) with EN/FR names as specified.
6. **Effective-dated authorizations:** `intermediary_authorizations` and insurer authorization/name/brand history; never overwrite a year's register.
7. **Distribution:** `carrier_broker_agreements` + product permissions (can_quote, can_bind, can_collect_premium, requires_carrier_approval) + commission rules per agreement/product/version — no global commission.
8. **Demo layer:** fictitious "OPESINSURE DEMO BROKERAGE" (DEMO_ONLY) with 5 demo branches, linked to real insurers only through demo agreements; demo tariffs marked "DEMO — NOT AN INSURER QUOTE"; demo identities use `.invalid` domains; demo documents watermarked "DEMONSTRATION — NOT VALID / DÉMONSTRATION — NON VALABLE"; demo QR verification returns DEMO_VALID; environment banner "DEMONSTRATION ENVIRONMENT — NO REAL INSURANCE COVER IS CREATED".
9. **Separation of commands:** `opesinsure:seed-regulatory --country=CM --year=2026` (allowed in production) vs `opesinsure:seed-demo` (refused in production); `seed_catalog_versions` dataset versioning; idempotent upserts on canonical IDs; no-duplicate matching by canonical ID, legal name, normalized name, aliases.
10. **Demo coverage:** at least one record for every workflow state (quotes, proposals, underwriting, payments, policies, endorsements, renewals incl. paid-but-not-issued, claims, commissions, settlements); balanced journals; dashboards computed from records; one end-to-end chain CUS-DEMO-0001 → VEH → QUOTE → PROP → UW → PMT → POL → DOC-ATT → CLM → ASSESS → DEC → SETTLE + COM visible in Customer 360; recommended scale 29 insurers, 123 brokers, 1 demo brokerage, 5 branches, 30 agents, 550 customers, 300 vehicles, 600 quotes, 250 active policies, 100 claims.
11. **Execution order** 1–33 and the OpesInsureMasterSeeder structure as specified; acceptance tests in section 74.
12. **Source hierarchy:** DGTCFM/MINFI → CIMA/CRCA → official insurer publications → official broker publications → verified onboarding documents.

Open decision: production currently runs with demo mode on for testing. Section 46 forbids demo transaction seeders in production; enforcing it requires either a separate demo/staging environment or switching production demo mode off.
