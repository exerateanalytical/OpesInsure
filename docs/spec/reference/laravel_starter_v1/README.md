# OpesInsure Laravel Implementation Starter v1.0

This package is an **implementation overlay** for a fresh Laravel application. It converts the approved OpesInsure master architecture into buildable Laravel/PostgreSQL scaffolding.

## Authoritative files

1. `openapi.yaml` — public API contract baseline.
2. `database/migrations/` — canonical relational schema baseline.
3. `app/Domain/` — bounded-context model and service skeleton.
4. `routes/api.php` — route-to-controller baseline.
5. `docs/BUILD_ORDER.md` — implementation sequence and non-negotiable controls.
6. `docs/SCHEMA_MAP.md` — domain/table mapping.

## Important rules

- Do not replace business-action endpoints with generic `PATCH status` endpoints.
- Tenant context is resolved from authenticated context/middleware, never trusted from request body.
- RBAC and delegated authority are separate checks.
- Monetary values use PostgreSQL `numeric`, never floating point.
- Published policy/product/tariff/template history is effective-dated and non-destructive.
- Policy history is reproduced through `policy_versions` and effective-dated child rows.
- Payments allocate many-to-many to financial obligations.
- Claims payments use the shared finance subsystem.
- High-risk financial mutations require idempotency.
- Critical audit history is append-only at application level.
- Carrier integration uses adapters: manual, configured, hybrid, remote API.

## Installation approach

Create a normal Laravel application and copy this overlay into it. Then add the framework packages your deployment standard requires (OAuth/OIDC implementation, queues, object storage, observability, etc.). This package deliberately does **not** hard-code a specific identity provider or payment SDK.

Run migrations after reviewing production naming and PostgreSQL extensions:

```bash
php artisan migrate
```

Generate the real OpenAPI code/client bindings from `openapi.yaml` only after the contract has passed engineering review.

## Current scope

The schema covers the foundation needed for identity, tenancy, organization, party/customer, KYC/AML, MDM, vehicles/property, regulatory classification, products/rating/UW, distribution, quote/proposal, policies, finance/accounting, claims, providers, reinsurance/co-insurance, documents, case/SLA, correspondence, regulatory rules, catastrophe/exposure, audit/outbox/integrations.

The files are intentionally scaffold-level: insurer-specific rates, CIMA/legal thresholds, tax rates, provider tariffs, actuarial methods and carrier-specific wording remain configuration or verified institutional data.
