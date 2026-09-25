# Local session: resume here

Written by the cloud session on 2026-09-25 for the local (Windows/Laragon) session, which is paused until its credit resets.
Read this file first, then `docs/SESSION_HANDOFF.md` (the live log), then the release handovers below.

## 1. Where things stand
- **Production:** Phase 6 is live (r20260925-093812). The decisions batch `c0a8d01` is **not confirmed live**.
- **Branch with all new work:** `origin/claude/charming-bohr-2fd2hk` (cloud pushes only here). `origin/master` is still `c0a8d01`.
- **Deployable, green commit:** the branch head (all batches through 17 are built; full pest suite 1636 passed / 0 failed in the cloud). The cloud build is finished.

## 2. What to deploy, in order (one deploy is fine)
| # | Guide | Contents |
|---|---|---|
| 0 | (none) | the decisions batch `c0a8d01`, if not live yet |
| 1 | docs/RELEASE_HANDOVER_BATCH7.md | authority, underwriting, policy chronology, issuance queue, stickers, providers, reinsurance, co-insurance, complaints |
| 2 | docs/RELEASE_HANDOVER_BATCH8.md | endorsements, cancellation, suspension, renewals, premium default, special products, portability, verification, document governance |
| 3 | docs/RELEASE_HANDOVER_BATCH9.md | money chain (obligations, allocations, payments, reconciliation, refunds, cashier, FX, statements) + Batch 8 APIs |
| 4 | docs/RELEASE_HANDOVER_BATCH10.md | commission, settlement, bordereaux, GL mapping, journals, periods, technical accounting, finance centre |
| 5 | docs/RELEASE_HANDOVER_BATCH11_12.md | claims end to end + owner decisions D10 + claim types + claims roles |
| 6 | docs/RELEASE_HANDOVER_BATCH13_17.md | provider portal, cashless health, facultative/recoveries, AML/compliance, accumulation, regulatory, developer platform, hardening, vehicle power, finance sub-ledger |

## 3. Deploy chain (standing order, unchanged)
1. `git fetch origin && git checkout master && git merge --no-ff origin/claude/charming-bohr-2fd2hk` (up to the deployable commit)
2. `composer install && composer dump-autoload && vendor/bin/pest --parallel`: must be green
3. `git push origin master`
4. `/srv/opesinsure/backup.sh`
5. Rehearse migrations on a copy of prod (about 74 new migrations, all additive, Postgres triggers/checks)
6. Build the tarball from the `C:\laragon\www\opesinsure-deploy-snap` worktree (**run `composer dump-autoload` there first**), then `deploy.sh`
7. After migrate on prod, run **in this order**, each with `--dry-run` first:
   - `php artisan policies:backfill-chronology`
   - `php artisan finance:backfill-obligations`
   - `php artisan rbac:sync-role-permissions`
8. Manual: remove `cashier.sessions.operate` from existing BRANCH_MANAGER roles (owner decision; the sync only adds).
9. Configure CLAIM_SETTLE and RESERVE_APPROVE `authority_limits` per role/carrier. Without them, every claim decision is referred to a supervisor.
10. Make sure the **scheduler** runs. New jobs: issuance-queue scan (hourly), premium-cover sweep 00:45, ledger periods 00:05, renewals sweep 01:15,
    commissions advance 02:10, claims auto-close 02:40, collections 03:20, aml:rescreen 02:45, security:sweep every 15 min, ops:heartbeat and carriers:dispatch-messages every minute. The weekly `settlements:prepare` is retired.
11. `node docs/audit/verify-live.mjs` (75/76 expected) + one live purchase + one FNOL on a demo policy.
12. Record the release id in SESSION_HANDOFF.md, update BUILD_PROGRESS.md "Status", commit and push.

## 4. Watch on day 1 in production
- Many actions now **post journals automatically**, and a DB trigger rejects unbalanced journals at commit (payments, refunds, cashier, clearing, commission, settlements, reserves, claim settlements).
- Issuing a policy now auto-accrues commission and creates amounts-owed (obligations/instalments).
- Claim decisions need an accepted assessment and complete mandatory evidence (guards). Late-reported claims need approval before assessment.
- Motor quotes with usage=PRIVATE price differently (decisions batch).

## 5. Rollback
Restore the DB backup from step 4 and redeploy the previous tarball. All migrations are additive.

## 6. Mobile app
The app lives only on the owner's PC (not on GitHub), so the cloud cannot touch it. The backlog for the app session is in BUILD_PROGRESS.md
"Mobile backlog". New backend endpoints for mobile since then: `mobile/statements`, `mobile/partner/agent/claims` (agent-assisted FNOL),
signature requests `signature-requests/{id}/sign|decline`, public `public/verify` (status field).

## 7. Open owner decisions (placeholders in use)
Claim decision/rejection reason codes · dunning calendar (1/15/30/60 days) · expert-assignment SLA targets · narrowing CLAIMS_OFFICER (currently `*`) ·
cataloguing ~128 uncatalogued legacy permission names · carrier net-payable definition · premium status precedence.
The full list is in docs/spec/OWNER_OPEN_QUESTIONS.md (Q8 onward).
