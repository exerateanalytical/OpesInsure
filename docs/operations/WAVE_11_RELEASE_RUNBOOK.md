# Wave 11 release certification runbook

No release is production-ready until every gate has immutable evidence and a second operator certifies it.

| Gate | Minimum evidence | Blocking condition |
|---|---|---|
| Security | SAST, dependency, secret and authorization scans; penetration-test summary | Open critical/high finding |
| Performance | Representative load profile, p50/p95/p99, saturation and error rate | p95 or error budget exceeded |
| Accessibility | Automated scan plus keyboard and screen-reader review | Unresolved WCAG 2.2 AA blocker |
| Disaster recovery | Encrypted backup restore and measured RTO/RPO | Restore unverified or objectives missed |
| UAT | Signed role-based scenarios for Admin, Broker, Carrier, Agent and Customer | Rejected critical scenario |
| OpenAPI | Lint, compatibility and contract-test output | Breaking or undocumented endpoint |
| Data migration | Forward/rollback rehearsal and row-count/hash reconciliation | Integrity mismatch |

## Production sequence

1. Freeze the commit and create the release candidate.
2. Execute all checks in staging using synthetic, non-production personal data.
3. Attach hashes, reports and timestamps to each gate.
4. Resolve blocking findings and repeat affected gates.
5. Obtain maker-checker certification from an operator other than the creator.
6. Back up, deploy canary, run smoke checks, then progressively increase traffic.
7. Abort and roll back if health, payment correctness, tenant isolation or ledger integrity degrades.
8. Record deployment evidence and conduct the post-release review.

Secrets must be KMS-managed; backups must be encrypted and restoration-tested. Logs must redact tokens, PINs, identity documents and payment credentials.
