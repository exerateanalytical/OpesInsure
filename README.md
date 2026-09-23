# OpesInsure

> **Deploying to live?** The production environment at
> <https://insurance.opesdatacenter.tech> is already provisioned and verified.
> Connection details, the deploy command, rollback steps and the isolation
> rules for the shared VPS are in **[DEPLOYMENT.md](DEPLOYMENT.md)**.

OpesInsure is a multi-tenant, API-first insurance distribution platform for Cameroon and the CEMAC market. The backend is a Laravel modular monolith organized with domain-driven design, CQRS, transactional outbox events, OAuth 2.0, OpenAPI contracts, PostgreSQL, Redis, and asynchronous workers.

Current delivery has been reset to an **end-to-end acceptance model**. Earlier Batches 1–6 are provisional architecture/backend foundations, not completed modules. Wave 0 now starts the actual Laravel/Filament web application with platform administration, organizations and users. See `docs/END_TO_END_MASTER_PLAN.md`, `docs/MODULE_ACCEPTANCE_GATE.md`, `docs/END_TO_END_STATUS.md` and `docs/WAVE_00_PROGRESS.md`.

## Product surfaces

- B2C insurance marketplace API for the future Expo mobile app
- Agent-mode API for assisted/offline customer sales
- Broker ERP API for corporate tenants and their staff
- Carrier and product administration
- Quote, proposal, underwriting, policy, renewal, endorsement, cancellation and refund lifecycles
- Payment orchestration, double-entry ledger, commissions, reconciliation and settlements
- Claims intake and tracking
- Document OCR, verification, policy certificate and sticker fulfilment
- Logistics dispatch and proof of delivery
- Notifications, consent, audit, complaints and regulatory reporting

## Local development

1. Copy `.env.example` to `.env`.
2. Run `docker compose up -d --build`.
3. Run `docker compose exec api composer install`.
4. Run `docker compose exec api php artisan key:generate`.
5. Run `docker compose exec api php artisan migrate --seed`.
6. Run `docker compose exec api php artisan passport:install`.
7. Run `docker compose exec api php artisan test`.

The current repository contains the executable platform foundation and canonical specifications. External carrier, payment, OCR, SMS, email and logistics providers are connected through ports/adapters and require credentials plus signed partner rules before production activation.

## Key documents

- `docs/product/MODULE_REGISTER.md`
- `docs/architecture/ARCHITECTURE.md`
- `docs/architecture/DOMAIN_MODEL.md`
- `docs/design/DESIGN_SYSTEM.md`
- `docs/api/openapi.yaml`
- `docs/DELIVERY_TRACKER.md`
Current implementation status is tracked in `docs/END_TO_END_STATUS.md`. Wave 0B adds provisional end-to-end web/API slices for branches, invitations, memberships/roles, organization lifecycle, and MFA/device foundations; see `docs/WAVE_00B_IMPLEMENTATION_REPORT.md`. “Provisional” means the code exists but the mandatory Laravel/PostgreSQL/browser acceptance suite has not yet run in this workspace.

Wave 1A adds provisional web/API slices for canonical parties, tenant customers, consent/privacy evidence, partner licensing and permanent customer attribution. See `docs/WAVE_01A_IMPLEMENTATION_REPORT.md` and `docs/operations/WAVE_01_SECURITY_MATRIX.md`.
