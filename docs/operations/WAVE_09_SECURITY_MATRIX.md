# Wave 9 security matrix

| Risk | Enforcement |
|---|---|
| Cross-tenant access | TenantContext query filters and controller ownership checks |
| Replay mutation | Tenant/idempotency unique keys plus payload hashes |
| Self approval | Database constraints and service maker–checker guards |
| Silent privilege use | Expiring/revocable grants and immutable access events |
| Rule tampering | Canonical hashes, effective dates and overlap rejection |
| Evidence leakage | Only evidence hashes stored for identity verification |
| Failed filing loss | Retry state, attempts, next-attempt time and failure reason |
| Untraceable decisions | Audit log, workflow events and outbox messages |
