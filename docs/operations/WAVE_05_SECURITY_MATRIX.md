# Wave 5 Security Matrix

| Control | Enforcement |
|---|---|
| Coverage activation | Successful payment alone is insufficient; reconciliation and issuance authority are mandatory. |
| Carrier authority | Direct carrier review or an effective delegated-authority agreement scoped to the exact tenant, carrier, line, premium and territory. |
| Terms integrity | Canonical SHA-256 hashes protect proposal, policy, transaction and certificate-template snapshots. |
| Maker–checker | Issuance, servicing, template and cancellation-rule approval cannot be self-approved. |
| Certificate security | Random verification token, stored hash only, serial uniqueness, document hash and void lifecycle. |
| Public privacy | Verification omits customer identity and records only hashed request fingerprints. |
| Sticker custody | Carrier batch evidence, unique serial/security-code hash, row lock and immutable custody events. |
| Servicing payments | Positive adjustments create a transaction-bound intent; approval requires the exact assigned amount, currency, successful status and reconciliation. |
| Refund calculation | Approved non-overlapping effective-dated rule, bounded refund evidence and an independently approvable refund request. |
| Race resistance | Issuance and servicing approvals, service requests, sticker assignment and configuration versioning use transactional row locks. |
| Renewal attribution | New quote inherits the original immutable attribution identifier. |
| Tenant isolation | Policies, transactions, issuance requests, certificates and renewals resolve through tenant ownership. |

Release gates: certificate renderer signing, QR abuse/rate tests, sticker inventory reconciliation, carrier authority UAT, migration tests, accessibility and browser QA.
