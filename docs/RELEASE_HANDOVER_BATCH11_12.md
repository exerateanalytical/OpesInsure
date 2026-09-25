# Release handover: Batches 11 + 12 (claims) + owner decisions D10, for the local deploy session

**Branch:** `origin/claude/charming-bohr-2fd2hk`. Deploy after the Batch 7–10 handovers, or all together.
**Tests (cloud):** the full pest suite passes, 1440 passed / 0 failed.

## Steps
Standard chain (merge → local pest → backup → rehearse migrations → dump-autoload in the snapshot → deploy.sh → verify-live → record the release id), then:
1. `php artisan rbac:sync-role-permissions --dry-run`, then run it for real.
2. Manual cleanup (owner decision D10): remove `cashier.sessions.operate` from existing BRANCH_MANAGER roles (the sync only adds, never removes).
3. New scheduled jobs: `claims:auto-close` 02:40, `collections:run` 03:20 (Africa/Douala). The weekly `settlements:prepare` is **no longer scheduled**.
4. Load the document catalogue if not already done (`DocumentCatalogueSeeder`), otherwise claim evidence checklists are empty.
5. Set up CLAIM_SETTLE and RESERVE_APPROVE `authority_limits` per role/user and carrier. **Until they exist, every claim decision is REFERRED to a supervisor** and needs `claims.decision.supervise` plus a covering limit.

## Migrations (additive): 2026_10_16_1102..1116
FNOL snapshots · coverage checks · policy_limit_movements (+ aggregate over-commit check) · event-based reserve columns · evidence review reason ·
claim_involved_parties extended into claim parties · expert assignments (+ events, case type CLAIM_EXPERT_ASSIGNMENT without SLA targets) ·
assessments + investigations · claims execution (signing keys, manual entries) · decision reason codes + appeals + immutability trigger ·
claim settlements · claim closures + reopen requests · recoveries/litigation/collections · claim fraud flag + review link. All include immutability triggers where noted.

## Behaviour changes users will notice
| Area | Change |
|---|---|
| Claim lifecycle | One state machine; every transition runs approval guards and writes workflow history. Blueprint names accepted by `claims/{id}/transitions`. |
| Guards now active | No decision without an **accepted assessment** and complete mandatory evidence; no approval while a coverage check is unresolved or a fraud review is open; no close until the closure checklist passes. Carrier apps that propose decisions without an assessment now get 422 `CLAIM_ASSESSMENT_REQUIRED`. |
| FNOL | Every claim gets an immutable FNOL snapshot and a capability pin; agents can file for their own clients (`POST mobile/partner/agent/claims`). |
| Decisions | Approved/returned decisions can never be edited (DB trigger); appeals are new rows. Customers are notified of decisions with reasons. |
| Reserves | Per head/coverage, INITIAL/CURRENT/FINAL; approval posts a journal and may return **202 REFERRED** if over authority. |
| Settlement | Calculator capped by the remaining limit; discharge must be e-signed before payment; paying creates and settles a CLAIM payable and posts to the ledger. |
| Fraud / SoD | Indicators only flag REVIEW_REQUIRED; only a human confirms fraud (DB check). The same user can't collect + reconcile/refund the same payment. |
| Ledger | Old `POST ledger/journals` now creates a DRAFT (maker-checker). Payout reversal reopens commission; the requester can't reverse their own payout. |
| Roles | New CASHIER role; branch manager approves cashier sessions but no longer operates them. |

## Placeholders pending owner input
Claim decision/rejection reason codes (PLATFORM_PROVISIONAL list), dunning calendar (1/15/30/60 days), expert-assignment SLA targets (suggested, not seeded).

## Permissions
The claims permissions added by these batches are granted by the follow-up role pass (next release). Until then only wildcard roles can use the new claims endpoints.

## Rollback
DB backup + previous tarball. Migrations are additive; immutability triggers stay until the migrations are rolled back.

## Follow-up included on the branch (after 1b8821a)
- **Claims role pass:** 29 claims permissions catalogued + granted (checkers only CLAIMS_MANAGER / CARRIER_SUPER_ADMIN / FINANCE_MANAGER); `legal` and `collections` added to business-data modules.
- **C8 claim types + reporting deadlines:** migration `2026_10_16_110801` (claim_type_versions with 14 platform defaults, claim_reporting_checks).
  Every FNOL records a reporting check; a late claim is flagged (never refused), opens a CLAIM_COVERAGE_REVIEW / LATE_REPORTING case and cannot enter
  ASSESSMENT until a manager approves. An FNOL naming a claim type not configured for the policy's line gets 422. Deadlines are platform defaults
  (motor 5 days, theft 2, health/travel 30, life 90…), overridable per insurer/product with maker-checker; not legal deadlines.
- Permissions: claims.types.manage|approve, claims.late_report.recommend|approve (catalogued; managers/officers hold them via their wildcard).
- Run `rbac:sync-role-permissions` after deploy.
