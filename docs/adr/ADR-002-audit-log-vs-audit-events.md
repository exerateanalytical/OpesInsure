# ADR-002: `audit_log` is canonical; `audit_events` is a view

- Status: Accepted (2026-09-24)
- REQ: REQ-AUD-001, REQ-DUP-016, REQ-ARC-008

## Context
The blueprint names the append-only, hash-chained audit store `audit_events`. The platform shipped `audit_log` (core migration `2026_09_20_000001`) with `sequence`, `previous_hash`, `entry_hash`, `correlation_id`, written only by `App\Application\Audit\AuditWriter` and checked by `AuditChainVerifier`.

## Decision
- `audit_log` remains the **single physical table and single write path** (`AuditWriter`). No second audit table is created.
- `audit_events` exists only as a **read-only database view** over `audit_log` for blueprint/report compatibility. Nothing writes to it.
- Append-only is enforced at DB level (trigger rejecting UPDATE/DELETE on `audit_log`), owned by the REQ-AUD-001 work item.

## Consequences
- Specs and reports may say `audit_events`; code uses `audit_log` / `AuditWriter`.
- Any new "event log" table for audit purposes is a duplicate and must be rejected in review.
