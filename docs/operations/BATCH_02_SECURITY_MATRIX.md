# Batch 02 Security and Integrity Matrix

| Control | Enforced now | Remaining gate |
|---|---|---|
| Product immutability | Published versions cannot be edited through APIs; new version required | Add database trigger after governance sign-off |
| Tariff governance | Draft/approved states and privileged approval route | Maker-checker: creator cannot approve own tariff |
| Explainability | Every offer stores version references and calculation breakdown | Regulatory wording approval |
| Determinism | Integer minor units, bounded factors, input/rule hashes | Property tests across approved tariff library |
| Risk isolation | Tenant + party ownership checked on quote submission | PostgreSQL RLS |
| Optimistic locking | Risk asset version required on update | Apply to every aggregate |
| Quote integrity | Quote/outbox stored in one transaction; offer expiry checked | Idempotency middleware enforcement |
| Underwriting authority | Dedicated decision permission and permanent decision record | Carrier-specific authority ceilings and dual approval |
| Data minimization | Risk facts separated from customer identity and snapshotted | Field-level encryption/tokenization |

