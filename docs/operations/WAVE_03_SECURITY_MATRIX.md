# Wave 3 Security Matrix

| Control | Enforcement |
|---|---|
| Tenant isolation | Proposal, case, referral, document and payment lookups are tenant scoped. |
| Price integrity | Payment requests use the accepted offer terms snapshot, never browser-supplied amounts. |
| Disclosure integrity | Canonical hashes are checked before attestation and submission. |
| Evidence security | Ownership checks, SHA-256 document identity, malware-clean gate and human verification. |
| Maker–checker | Disclosure schemas and document requirements require a different approver. |
| Underwriting authority | Assignment, referral resolution and decision routes require explicit permissions. |
| Decision safety | Open referrals block final decisions; conditions and reasons are retained. |
| Idempotency | One active proposal per offer and tenant-scoped payment idempotency keys. |
| Auditability | Material actions write chained audit records, status history and transactional outbox events. |
| Data minimization | Payment events expose status and amount while provider secrets remain adapter-owned. |

Pending release gates: runtime authorization tests, malware adapter sandbox, secrets scan, dependency scan, penetration test, CSP/browser review and carrier UAT.
