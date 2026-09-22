# Wave 7 — Claims security matrix

| Control | Enforcement | Evidence |
|---|---|---|
| Tenant isolation | Every API lookup and Filament query is constrained through `TenantContext`; policy and claimant must belong to the tenant | `ClaimLifecycleController`, `ClaimPolicy`, resource query scopes |
| FNOL replay protection | Tenant-scoped idempotency receipt stores request hash and response; conflicting reuse fails closed | `ClaimCommandGuard`, `claim_command_receipts` |
| Concurrent commands | Claim, reserve, decision and payment aggregates use database transactions and `FOR UPDATE` locks | Wave 7 services |
| Evidence integrity | Clean scan and SHA-256 required; attachment hash is immutable and rechecked before verification | `ClaimEvidenceService` |
| Chain of custody | Receipt, verification and rejection record actor transfer, purpose, hash and timestamp | `claim_evidence_custody_events` |
| Reserve governance | One pending reserve change, maker-checker approval and database separation constraint | `claim_reserve_changes` |
| Decision authority | Effective delegated claim authority or privileged claims role is required at proposal and approval | `ClaimLifecycleService::authority` |
| Payment control | Decision cap, positive amount, idempotency, maker-checker, retry, failure and reversal states | `ClaimPaymentService`, database constraints |
| Carrier exchange | Hashed payload, correlation id uniqueness, retries and acknowledgements | `ClaimCarrierExchangeService` |
| Auditability | Material commands create tamper-evident audit records and transactional outbox messages | `AuditWriter`, `OutboxWriter` |
| Privacy | API returns tenant-scoped claims; evidence contents are never embedded in claim responses | Controllers/services |

No CIMA threshold, statutory claim deadline or reserve factor is hard-coded. Such values require approved, effective-dated configuration.
