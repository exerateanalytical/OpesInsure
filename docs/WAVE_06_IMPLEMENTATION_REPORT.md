# Wave 6 — Financial distribution

Implemented commission governance, accrual and vesting; partner statements; payout requests and provider attempts; carrier settlements; and premium/commission bordereaux.

The implementation is tenant-scoped, configuration-driven and uses maker-checker approval, idempotency keys, PostgreSQL advisory/row locking, explicit failure states, audit records, append-only lifecycle events and outbox events. Commission rates are never inferred or embedded.

Web operations are exposed through four navy/ivory-compatible Filament resources using professional Heroicons. The API surface is isolated in `routes/wave6.php` for conflict-free integration and documented in `docs/api/openapi-wave6.yaml`. English and French validation messages have matching keys.

## Runtime acceptance still required

Run migrations and rollback, Pest, OpenAPI validation, PostgreSQL concurrency tests, queue delivery, OAuth permission tests, browser acceptance, payout-adapter contract tests and recovery tests where PHP, Composer, PostgreSQL and browser tooling are available.
