# Batch 03 Financial Security Matrix

| Control | Enforced now | Remaining production gate |
|---|---|---|
| Webhook authenticity | HMAC over timestamp + raw body; constant-time comparison; five-minute replay window | Confirm each provider's actual signature format and mTLS capability |
| Webhook idempotency | Unique provider event ID and durable inbox | Dead-letter alerting and provider replay tooling |
| Amount integrity | Webhook amount/currency must match payment intent | Provider settlement-file reconciliation |
| Refund safety | Cannot exceed unrefunded amount; requester cannot approve | Provider adapter and configurable approval thresholds |
| Policy issuance | Matching successful payment and approved proposal required | Carrier-issued signature/certificate validation |
| Policy servicing | Explicit state machine, before/after snapshot and maker-checker | Carrier servicing adapters and delegated-authority ceilings |
| Ledger integrity | Integer units, balanced journals, immutable reversal journals | PostgreSQL deferred balance trigger and period locking |
| Commission integrity | Versioned rule hash, bounded rates, accrual and movement history | Automated vesting/clawback jobs and payout adapter |
| Sensitive destinations | Payout destination column designated encrypted | KMS-backed cast and key rotation |

