# Wave 8 security matrix

| Control | Enforcement |
|---|---|
| Tenant isolation | Every operational read and mutation resolves `TenantContext`; cross-tenant party, policy, courier, ticket and delivery identifiers are rejected. |
| Idempotency | Tenant-scoped unique keys protect fulfilment, notification and ticket creation against replay. |
| Delivery proof | Six-digit OTP is stored only as an adaptive hash; proof metadata is retained with an immutable event. |
| State integrity | Explicit fulfilment and support transition maps plus database status constraints reject skipped states. |
| Communication privacy | Destinations/counterparties are SHA-256 fingerprints; templates require declared variables and respect opt-outs. |
| Mandatory notices | Security and transactional communication cannot be disabled. |
| Failure controls | Notification retries are bounded, exponentially delayed and dead-lettered after exhaustion; cancellation is state restricted. |
| Accountability | Material operations write audit records; retry dispatch uses the transactional outbox. |
| Least privilege | OAuth scopes/permissions separate fulfilment, communications and support operations. |

Secrets, message bodies and OTP values must not be written to logs. Provider callbacks require the same signed inbox pattern as payments before production enablement.
