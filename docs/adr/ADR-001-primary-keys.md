# ADR-001: Primary keys and public identifiers

- Status: Accepted (2026-09-24)
- REQ: REQ-ARC-005 (BP X §175, SEED rule 2)

## Context
The blueprint recommends bigint internal keys plus a public UUID/ULID, and forbids exposing integer PKs. The live schema already uses UUID primary keys on almost every table (38 of 51 migrations create `uuid('id')->primary()`); only `platform_settings` uses `$table->id()` (bigint). Official register entities also carry canonical codes (`CM-INS-*`, `CM-BRK-*`).

## Decision
1. **Existing UUID PKs are kept.** No PK rewrite migration: the cost (every FK, every API contract, mobile caches) outweighs the index-size benefit at our volume. A UUID PK *is* the public identifier for those tables.
2. **New tables:** default to UUID PK. A bigint PK is allowed only for internal, high-volume, append-only or config tables that are never addressed by a client (e.g. `platform_settings`, ledgers' internal sequences); if such a row ever needs to be referenced externally it gets an additional unique `public_id` UUID/ULID column and APIs use that.
3. **Never expose integer PKs** in API payloads, URLs, QR codes, documents or verification links. Monotonic business numbers (policy numbers, `audit_log.sequence`) are separate columns, not keys.
4. Register entities are referenced externally by their canonical code, not by PK.

## Consequences
- Route model binding stays on UUID.
- Reviewers reject new `->id()` tables exposed through an API resource without a `public_id`.
