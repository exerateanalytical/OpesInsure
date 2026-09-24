# ADR-005: One owner migration per table

- Status: Accepted (2026-09-24)
- REQ: REQ-DUP-019, REQ-ARC-008

## Context
Eight tables are `Schema::create`d in two migrations, the later one guarded by `Schema::hasTable`:
`support_tickets`, `support_ticket_events`, `notification_templates`, `notification_deliveries`, `couriers`, `communication_preferences`, `fulfilment_events` (batch five `2026_09_21_000006` and wave eight `2026_09_21_000017`), and `fulfilment_orders` (core `2026_09_20_000001` and wave eight).

## Decision
- Applied migrations are not edited (history stays). The **owner** of each table is the migration that creates it on a fresh database — recorded in `tests/Architecture/table_owners.json`. The guarded duplicate is a documented no-op; its column list is NOT authoritative.
- Schema changes to these tables go in new additive `alter` migrations, never by editing either declaration.
- `tests/Architecture/ArchitectureGovernanceTest.php` fails if any other table becomes declared by more than one migration, or if an owner no longer declares its table.

## Consequences
- A new duplicate `Schema::create` must either be removed or justified by adding it here and to `table_owners.json` (expected: never).
