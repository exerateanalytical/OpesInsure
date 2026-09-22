# Batch 01 Security Enforcement Matrix

| Control | Enforcement | Remaining production gate |
|---|---|---|
| Authentication | Passport API guard on private routes | Install keys/clients; verify PKCE integration |
| Tenant isolation | Active membership check and tenant context | PostgreSQL RLS and isolation property tests |
| Authorization | Permission middleware backed by tenant roles | Seed canonical roles; policy coverage on every endpoint |
| Passwords | Laravel hashed cast; 12-character minimum; session revocation on change | Compromised-password screening and passwordless option |
| Enumeration/abuse | Registration and password rate limits | Distributed adaptive limiter and bot protection |
| Validation | Strict phone, enum, length, date and UUID validation | Central RFC 9457 error renderer |
| Audit | Hash-linked audit entries for privileged mutations | Serialize writes/anchor hashes to immutable storage |
| Attribution protection | No overlapping active attribution; disputes required | Database exclusion constraint and maker-checker reassignment |
| Licence decisions | Dedicated permission and immutable decision audit | Dual approval for high-risk activation |
| Response hardening | No-sniff, frame denial, referrer, permission and no-store headers | CSP/HSTS at web edge |
| Secrets/PII | Password hiding and private storage policy | Field encryption with KMS/HSM and key rotation |
| Tests | Domain invariant and architecture tests added | Execute in PHP/Docker CI; add feature/security suites |

