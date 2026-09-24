# ADR-003: Insurance classification taxonomy

- Status: Accepted (2026-09-24)
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
