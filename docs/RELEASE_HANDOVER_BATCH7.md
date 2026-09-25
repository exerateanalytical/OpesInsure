# Release handover: Batch 7 and cloud work (for the local deploy session)

**Branch:** `origin/claude/charming-bohr-2fd2hk` (release head `a4fe367` plus this handover commit). It is based on master `c0a8d01`: 28 commits, no conflicts expected.
**Tests (cloud, PostgreSQL 16, PHP 8.4):** the full pest suite passes, 1163 passed / 0 failed.
**Built by:** cloud session (see docs/SESSION_HANDOFF.md for the log).

## 0. Pre-condition
The decisions batch `c0a8d01` must be deployed first. If it is not live yet, deploy master as it is now, then this release.

## 1. Merge
```
git fetch origin
git checkout master && git pull
git merge --no-ff origin/claude/charming-bohr-2fd2hk
composer install            # no new packages; refreshes autoload
composer dump-autoload
vendor/bin/pest --parallel  # must be green locally before deploying
git push origin master
```
Note: `phpunit.xml` now carries the test placeholders (Twilio/MoMo/Orange, FILESYSTEM_DISK=local) that used to exist only in your `.env.testing`. Local runs are unaffected.

## 2. Deploy chain (standing order)
1. DB backup: `/srv/opesinsure/backup.sh`
2. Rehearse migrations on a copy of prod (8 new migrations, all additive, PostgreSQL triggers/check constraints):
   | Migration | Creates / changes |
   |---|---|
   | 2026_10_12_710001 | `authority_checks` (append-only authority decision log) |
   | 2026_10_12_720001 | underwriting_cases: DECISION_PENDING status, `outcome` column (+ backfill), evaluation summary |
   | 2026_10_12_730001 | `policy_versions` (bitemporal), `policy_parties`, `policy_risks`, `policy_coverages`, `policy_limits` |
   | 2026_10_12_740001 | `issuance_exceptions` (+events); sticker custody chain; backfills `sticker_stock.custody_level` |
   | 2026_10_12_750001 | provider master + network (12 tables) |
   | 2026_10_12_760001 | reinsurance treaties, versions, participants, cessions (6 tables) |
   | 2026_10_12_770001 | co-insurance arrangements, participants, apportionments |
   | 2026_10_12_780001 | `complaints`, `correspondence_register`, COMPLAINT case type v2 (v1 marked SUPERSEDED) |
3. Snapshot worktree: run `composer dump-autoload` there (classes were added), build the tarball, run `deploy.sh`.
4. After migrate on prod:
   - `php artisan policies:backfill-chronology --dry-run`, check the count, then run it without `--dry-run`.
   - The master-data seed (runs on deploy) marks `aviation.manufacturer` and `life_insurance.relationship` INACTIVE; aliases keep old codes working.
5. Live checks: `node docs/audit/verify-live.mjs` (75/76 expected as before). Also try one live purchase journey and confirm a policy issues
   and a `policy_versions` row exists for it.
6. Record the release id in docs/SESSION_HANDOFF.md (Log) and BUILD_PROGRESS.md (Status), commit and push.

## 3. Behaviour changes users will notice
| Area | Before | After |
|---|---|---|
| Delegated authority over the premium limit | 422 error | Issuance request goes to CARRIER_REVIEW and an AUTHORITY_REFERRAL case opens |
| Partner with no intermediary authorization | allowed | referred for review (owner decision) |
| Lapsed/suspended intermediary | allowed | denied (logged in authority_checks) |
| Paid but issuance failed | silently lost | `issuance_exceptions` queue plus a one-time customer message "issuance delayed" |
| Issuance territory | hard-coded CM | from risk facts → carrier → tenant; if none, the payment is queued |
| Issuance gate | payment only | also accepted ISSUANCE_REQUIRED documents, premium-to-cover, KYC, underwriting |
| Underwriting `assign` | moved the case to IN_REVIEW | stays queued until `start-review`; new CONDITIONAL outcome |
| Certificate with sticker | any policy | motor policy only; single assign path |
| POST /web-experiences/marketplace/comparisons | loose, no tenant check | alias of /quote-comparisons (2–5 live offers, tenant-checked, `{data: …}` response) |
| Bordereau endpoints | different type sets | both accept PREMIUM, CLAIM, ENDORSEMENT, CANCELLATION, COMMISSION |
| Motor usage PRIVATE (from the decisions batch) | rated COMMERCIAL | rated PRIVATE: price change |

## 4. New permissions (not in any role yet; grant them after deploy, or let Batch 8's agent add them)
`providers.view|manage|credential`, `provider_networks.view|manage`, `provider_tariffs.approve`,
`reinsurance.treaties.view|manage|approve`, `reinsurance.reinsurers.manage`, `reinsurance.cessions.view|calculate`,
`coinsurance.view|manage|approve|apportion`, `policies.issuance_queue.view|manage|resolve`,
`stickers.view|handover|allocate|allocate.carrier|reconcile|assign|assign.any`.
Note: SYSTEM_ADMIN does NOT bypass the issuance-queue and sticker permissions.

## 5. New APIs (all under /api/v1, tenant + permission scoped)
authority (internal), `underwriting/cases/*` workspace, `issuance-exceptions/*`, `stickers/*`, `sticker-handovers/*`, `sticker-reconciliations`,
`policies/{id}/sticker`, `providers/*`, `provider-networks/*`, `provider-contracts/*`, `medical-services`, `reinsurance/*`,
`coinsurance/arrangements/*`, complaints/correspondence and `queues/board` (routes/cases.php).

## 6. Mobile impact
None required for this release. Backlog items for the app session were added to BUILD_PROGRESS.md earlier (manual-quote waiting states etc.).

## 7. Rollback
Restore the DB backup from step 2.1 and redeploy the previous tarball. All migrations are additive, so the previous code runs on the new schema too.
