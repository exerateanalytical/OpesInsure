# Wave 4 Security Matrix

| Control | Enforcement |
|---|---|
| Secret isolation | Database stores credential references only; tokens come from managed runtime configuration. |
| Provider activation | Real requests require approved active connection records. |
| Request integrity | Provider idempotency and request IDs; payer number is hashed in attempt snapshots. |
| Webhook authenticity | HMAC, constant-time comparison, timestamp tolerance and provider allowlist. |
| Replay defense | Unique provider/event inbox key and insert-once processing. |
| Financial integrity | Amount/currency equality and explicit status transition allowlist. |
| Reconciliation | File hash deduplication, deterministic matching, exception workflow and independent approval. |
| Accounting | Balanced journal domain invariant, approved posting profiles, unique posted reference and reversal journals. |
| Refunds | Successful-payment gate, remaining-balance check, idempotency and maker–checker approval. |
| Chargebacks | Unique provider case, evidence, deadlines, assigned review and immutable events. |
| Tenant isolation | Payments, attempts, reconciliation and cases are scoped through their owning tenant. |

Production gates include provider certification, KMS-backed secrets, webhook penetration testing, TLS pinning where supported, WAF rules, dependency scanning and finance UAT.
