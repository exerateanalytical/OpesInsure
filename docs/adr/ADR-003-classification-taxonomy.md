# ADR-003: Insurance classification taxonomy

- Status: Accepted (2026-09-24); amended 2026-09-25 (owner decision item 16, see Amendment 1)
- REQ: REQ-DUP-018 (ADR only), REQ-PRD-002, REQ-CIMA-*

## Context
Three overlapping taxonomies exist:
- `insurance_lines` (batch two, flat; products reference `line_code`),
- `insurance_classes` (official register migration `2026_09_26_090001`),
- `regulatory_branches` / `regulatory_class_defaults` (CIMA dictionary, `2026_09_27_100001`).
The blueprint calls the regulatory level `insurance_branches`.

## Decision
1. **`regulatory_branches` IS the blueprint `insurance_branches`.** It is the CIMA regulatory branch dictionary (versioned, effective-dated). No `insurance_branches` table will be created; docs may alias the name.
2. **`insurance_classes` is the platform class** (the level products, tariffs and carrier authorisations attach to). Each class maps to exactly one regulatory branch; the mapping values are official data and stay NULL/UNVERIFIED until confirmed (open question Q1 / REQ-CIMA-003) — never invented.
3. **`insurance_lines` is legacy.** Plan: (a) add a nullable mapping from each line to an `insurance_classes` row (additive migration); (b) move readers (`CatalogueService`, products' `line_code`) to the class; (c) freeze writes; (d) keep the table read-only for history. It is never dropped while referenced and master data is never deleted.

## Consequences
- New features must reference `insurance_classes` (product-facing) or `regulatory_branches` (reporting/CIMA), never add a fourth taxonomy.

## Amendment 1 (2026-09-25) — owner decision item 16
Supersedes Decision 1. Source: docs/spec/OWNER_DECISIONS_2026-09-25.md items 1-9 and 16.

1. **`insurance_branches` is the canonical CIMA branch table** (regulatory_regime_id, code, name, effective_from / effective_until, status, plus the existing number, labels, family and Article 328 flags). Migration `2026_10_08_710001` **renames** `regulatory_branches` to `insurance_branches`. No data is copied and no second table exists.
   - `regulatory_branches` stays as a **compatibility view** (`SELECT * FROM insurance_branches`). It is auto-updatable, so raw SQL readers keep working. New code must use `insurance_branches` (model `App\Models\Regulatory\RegulatoryBranch`, whose `$table` is now `insurance_branches`). Do not create a foreign key to the view.
   - `regulatory_regime_id` is backfilled and kept in sync by a trigger from `regime`. `name` is a generated column (`label_fr`), so it cannot drift.
   - The seeded-row delete protection trigger moved with the table.
2. **CIMA mapping is coverage-level**: PRODUCT → COVERAGE → CIMA BRANCH, many-to-many. `product_regulatory_mappings.coverage_code` (null = the whole product). Rules live in `regulatory_class_defaults` with `mapping_level = COVERAGE` (`App\Application\Regulatory\CimaCoverageRules`). The owner's Q1 answers are seeded: travel medical → 2, personal accident → 1, assistance/repatriation → 18, cancellation → 16, home fire → 8, home water damage/theft → 9, household liability → 13, business interruption → 16, motor theft/fire → 3, life disability → COMPLEMENTARY on 20 (separate premium, dates, conditions). The wholesale travel → 18 rule is superseded, not deleted. Coverages the owner did not list (for example travel baggage) stay unmapped.
3. **Authorization**: unknown authorization is `BLOCK_NEW_PRODUCT_PUBLICATION`, never a warning. Products already live are recorded in `legacy_product_authorizations` as `LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION`, with the branches they were selling. That status never permits a new branch, an expansion into another branch, a new product family, or a regulatory claim. The record resolves to VERIFIED when a real authorization is approved. New authorization fields: `source_authority`, `revocation_date`, `evidence`, `verification_status`. `authorization_reference` is the decision reference and `effective_until` is the expiry date; they are not duplicated.
4. **Branch premium allocation** is never invented. `product_regulatory_mappings.branch_allocation_status` defaults to `PENDING_CARRIER_ALLOCATION`. When a rating run has no carrier split, it records `PENDING_CARRIER_ALLOCATION`. An internal estimate (`branch_allocation_basis = ESTIMATED_NON_REGULATORY`) is recorded as such. `App\Application\Regulatory\Reporting\BranchPremiumAllocation` counts only `ALLOCATED` splits in regulatory totals.
5. **Reporting codes** (ISSUED, COLLECTED, BROKER_COMMISSION, AGENT_COMMISSION) stay internal. `App\Application\Regulatory\Reporting\RegulatoryExportCodeMap` is the only translation to regulator measures.
6. **Reconstructed checklists** carry truthful codes: `OPESINSURE_CIMA_READINESS_V1_DRAFT` and `OPESINSURE_DOD_V1_RECONSTRUCTED` (`App\Application\Regulatory\ReconstructedChecklists`).

Consequence (updated): new features reference `insurance_classes` (product-facing) or `insurance_branches` (CIMA/reporting). They never reference the `regulatory_branches` view in new foreign keys, and they never add a fourth taxonomy.
