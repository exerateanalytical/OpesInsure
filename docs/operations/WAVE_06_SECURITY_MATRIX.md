# Wave 6 security matrix

| Threat/control | Enforcement |
|---|---|
| Cross-tenant access | TenantContext filtering in controllers, policies and all Filament queries; tenant IDs are server-derived. |
| Duplicate money movement | Tenant-scoped idempotency keys, unique constraints and transactional replay. |
| Concurrent batch preparation | PostgreSQL transaction advisory locks plus row locks on included records. |
| Self-approval | Database constraints and service-layer maker-checker checks. |
| Rate manipulation | Effective-dated approved rule versions; 0–10,000 basis-point database bounds; no embedded statutory rates. |
| Statement tampering | Canonical content hashes and immutable item snapshots. |
| Destination disclosure | Payout destinations encrypted at rest and never listed in Filament tables. |
| Overpayment | Published-statement balance validation, reserved payout aggregation and locked rows. |
| Provider ambiguity | Per-attempt request hashes, unique provider references and explicit FAILED state. |
| Silent changes | Hash-chained audit records, append-only distribution events and transactional outbox messages. |
| Settlement over-remittance | Premium less configured commission calculation; negative settlements fail closed. |
| Unauthorized operations | OAuth2, granular permission middleware and model policies. |

Runtime penetration, queue-retry, database constraint and OAuth-scope tests remain mandatory in the deployment environment.
