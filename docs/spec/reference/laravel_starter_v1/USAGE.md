# Laravel Starter v1.0 (owner, 2026-09-24) — REFERENCE ONLY, NOT INSTALLED

Designed as an overlay for a *fresh* Laravel app. OpesInsure already runs in production (175 tables, 416 routes), so installing it would create parallel tables (claims, policies, documents, tenants, users...) — a violation of the no-duplication rule.

Use:
- openapi.yaml (82 paths) = target API contract; map each path to an existing route or build it (see TRACEABILITY_MATRIX_V1.md, SCREEN_TO_API_MATRIX_V1.md).
- database/migrations = column reference for MISSING tables only (policy_versions, financial_obligations, payment_allocations, claim_coverage_assessments, authority_profiles/limits, product_versions, provider_*, reinsurance_*, cases/tasks...). New tables are written as additive migrations in our conventions (bigint id + uuid, money in *_minor per ADR, not numeric).
- Skeleton classes (CoverageAtLossEngine, RatingEngine, controllers) are stubs; do not copy.
