# Session handoff log (local ⇄ cloud)

Two backend sessions work on this repo: the **local** session (owner's Windows PC, Laragon) and the **cloud** session (claude.ai/code container).
The mobile app session is separate and owns `mobile app/` (see BUILD_PROGRESS.md).

> **Local session: start with docs/LOCAL_SESSION_RESUME.md.**
>
> **DEPLOY REQUEST (owner, 2026-09-25):** deploy branch `claude/charming-bohr-2fd2hk` at `7596e34` or later (full suite 1636 passed). Follow LOCAL_SESSION_RESUME.md sections 2–3, guides Batch 7 → 8 → 9 → 10 → 11_12 → 13_17. Record the release id here when done.

## Protocol (both sessions follow it)
1. **Before starting work:** `git fetch origin`, read this file top to bottom and the latest `docs/BUILD_PROGRESS.md`, and check
   `git log origin/master..origin/claude/charming-bohr-2fd2hk` and the reverse direction. Do not start a work item another session has
   marked IN PROGRESS.
2. **Never duplicate or override:** search for existing services/tables/routes first and extend them. If code already exists
   for a requirement, wire into it instead of adding a parallel path.
3. **After each work item:** add an entry at the top of the Log below (what, files, tests, deploy notes), commit it, and push.
4. **Split of duties (owner decision, option A, 2026-09-25):**
   - Cloud: builds and tests features, pushes to `claude/charming-bohr-2fd2hk`. Cloud cannot reach the server.
   - Local: pulls/merges that branch into `master`, runs the full pest suite, then does the deploy chain from BUILD_PROGRESS.md
     (backup → rehearse migrations → deploy.sh → verify-live). Afterwards, local records the release id here.
5. Each session updates BUILD_PROGRESS.md "Status" only for what it verified itself.

## Claims (who is working on what)
| Item | Owner | State |
|---|---|---|
| Deploy decisions batch `c0a8d01` | local | TODO: not confirmed live. Deploy before Batch 7 is merged. |
| Batch 7A authority, 7B underwriting, 7C policy chronology | cloud | DONE (on branch, needs deploy) |
| Batch 7D issuance ops (+ issuability gate on the payment→issuance path) | cloud | DONE (on branch) |
| Batch 5/6 rules-engine follow-ups | cloud | DONE (on branch) |
| Duplicate master-data lists + bordereau type set + test baseline triage | cloud | DONE (on branch) |
| Batch 13A providers, 13C reinsurance, 13D co-insurance, 12C complaints/correspondence | cloud | DONE (on branch) |
| **Deploy release acedff7 (Batch 7 + cloud work)**: see docs/RELEASE_HANDOVER_BATCH7.md | local | TODO |
| Batch 8 (10 agents: endorsements, cancellation, suspension, renewals, premium-to-cover/lapse, special products, portability, certificates/verification, document governance, roles+wiring) | cloud | DONE, on branch. Deploy per docs/RELEASE_HANDOVER_BATCH8.md |
| Batch 8 wiring | cloud | DONE (on branch) |
| Batch 9 money chain (obligations+instalments, allocations+premium status, payment machine+bank/card, retries+execution modes, reconciliation, refunds+clearing, cashier+FX, statements) | cloud | DONE, on branch. Deploy per docs/RELEASE_HANDOVER_BATCH9.md |
| Batch 10 (commission machine/rules/statements, settlement + bordereaux consolidation, GL mapping, journal lifecycle, period close, technical accounting, finance centre + Batch 9 roles) | cloud | DONE, on branch. Deploy per docs/RELEASE_HANDOVER_BATCH10.md |
| Owner decisions D10 | cloud | MERGED (ships with Batch 11/12) |
| Batches 11+12 claims (16 agents: machine, FNOL, coverage, limits, reserves, evidence, parties, types, adjusters, assessment, execution, decision, settlement, closure, recovery/litigation/collections, fraud) | cloud | DONE, on branch. Deploy per docs/RELEASE_HANDOVER_BATCH11_12.md |
| Batches 13B–17 (provider portal, cashless health, facultative/recoveries, compliance/AML, accumulation, regulatory reporting, KPIs, developer platform, API families, legacy migration, hardening) | cloud | DONE, on branch. Deploy per docs/RELEASE_HANDOVER_BATCH13_17.md |
| Owner specs V1 (vehicle/fiscal power) + F1 (finance sub-ledger) | cloud | DONE, on branch (same handover) |
| Mobile app | app session (local) | Not on GitHub, so cloud can't reach it |

## Log (newest first)

### 2026-09-26 local: DEPLOYED cloud Batches 10-17 + local canonical documents/web UI/letterheads (master c0d005a) as release r20260926-042840
- Merged origin/claude/charming-bohr-2fd2hk (0de6f6a) into master on top of local work. Post-merge fixes: legacy ClaimMachineAdapter moved to tests/Support (Batch 11 retired the legacy machines), policy reference and successor number masked in the single public verification service, OpenAPI regenerated.
- Full pest suite: 1661 passed + the 2 affected areas re-run green (108/108). The session_replication_role / scratch-DB tests need a Postgres superuser locally (they pass as postgres).
- Backup opesinsure-20260926-0412.sql.gz. 45 migrations rehearsed on a prod copy, then applied.
- Post-deploy: backfill-chronology 0 pending, backfill-obligations 0 pending, rbac:sync-role-permissions 8 roles +53. Scheduler cron present; schedule:list shows the new jobs. Stamp-duty schedules: 2 DRAFT (left as DRAFT).
- Live: verify-live 75/76 (legacy /claims). Purchase OK: POL-2026-000005 issued. FNOL on a demo policy: 201.
- Not done (manual, owner): remove cashier.sessions.operate from BRANCH_MANAGER; configure CLAIM_SETTLE/RESERVE_APPROVE authority_limits (until then claim decisions are referred).
- Next: the local Gap Closure Pack (8 agents, uncommitted in the local tree) goes into the next release after a combined test run.

### 2026-09-25 cloud: Batches 13B–17 + V1 + F1 merged. ALL BATCHES BUILT. Full suite 1636 passed / 0 failed. Deploy guide: docs/RELEASE_HANDOVER_BATCH13_17.md
- Route permissions all catalogued (config/permissions.php); OpenAPI regenerated. Cloud build is complete; the rest is local deploy.

### 2026-09-25 cloud: Batches 11+12 (15 claims agents) + D10 decisions + integration fix merged. Full suite 1440 passed / 0 failed. Deploy guide: docs/RELEASE_HANDOVER_BATCH11_12.md
- Follow-up merged: C8 claim types/deadlines + claims role pass (see the handover's follow-up section). Full suite 1457 passed / 0 failed.

### 2026-09-25 cloud: Batch 10 merged (10 agents + 1 fix agent). Full suite 1348 passed / 0 failed. Deploy guide: docs/RELEASE_HANDOVER_BATCH10.md
- Merge fixes: commission accrual combines the 10-2 rule resolver with the 10-1 state machine; JournalLine split into its own file (it broke autoload); a single commission payable per partner statement (the per-accrual payable is removed); one rule selector.
### 2026-09-25 local: DEPLOYED Batches 7, 8 and 9 (master c790693) as release r20260925-163340
- Before it: the decisions batch c0a8d01 (+4919d6c) went live as r20260925-144020.
- Local full pest on the merged tree: 1288 passed / 0 failed. Backup opesinsure-20260925-1613.sql.gz. All 22 migrations rehearsed on a prod copy, then applied.
- Post-deploy: policies:backfill-chronology 15 backfilled / 0 failed; rbac:sync-role-permissions 8 roles, +138 permissions; finance:backfill-obligations 15 backfilled / 0 failed.
- Live: verify-live 75/76 (the legacy /claims check). Purchase journey OK: 6 offers, POL-2026-000004 issued with 1 policy_versions row and 1 financial obligation.
- Local, uncommitted in the working tree and not yet deployed: canonical document security (cs1), canonical web UI + D2-D4 (cs2, adds the composer package mallardduck/blade-lucide-icons), issuer letterheads/logos. Local will merge master, test and deploy them next.

### 2026-09-25 cloud: Batch 8 wiring + Batch 9 merged (10 agents). Full suite 1288 passed / 0 failed. Deploy guide: docs/RELEASE_HANDOVER_BATCH9.md
- Merge fixes: duplicate payment_intents.financial_obligation_id column (9-4 vs 9-1), webhook/reconciliation obligation hooks re-applied on the new payment machine, refunds bound to obligations, reinstatement re-request bug fixed.

### 2026-09-25 cloud: Batch 8 merged (10 agents). Full suite 1228 passed / 0 failed. Deploy guide: docs/RELEASE_HANDOVER_BATCH8.md

### 2026-09-25 cloud: Batch 7D merged, so Batch 7 is complete. Full suite 1163 passed / 0 failed.
- Extra migration `2026_10_12_740001` (issuance_exceptions + sticker custody chain; backfills sticker_stock.custody_level).
- PaymentIssuanceTrigger no longer swallows failures: they go to `issuance_exceptions` (API issuance-exceptions/*: scan, retry, escalate, resolve), and the
  customer gets a one-time "issuance delayed" message. Territory now comes from data (risk facts → carrier → tenant), not a hard-coded CM.
- Issuability gate (documents per decision 31, premium-to-cover per decision 17, KYC, underwriting) now runs on the payment→issuance path.
  PAYMENT_PENDING with no underwriting case counts as straight-through approved.
- Certificates with a sticker now require a motor policy (single assignToPolicy path). Sticker handovers carrier→broker→branch→agent need the receiver to accept.
- New permissions to grant: policies.issuance_queue.{view,manage,resolve}, stickers.* (SYSTEM_ADMIN does not bypass them).
- Nothing schedules the issuance-exception scan yet (Batch 8/9 item).

### 2026-09-25 cloud: merged 9 work items. Full pest suite 1151 passed / 0 failed.
**Local, to deploy:** merge `origin/claude/charming-bohr-2fd2hk` into master, run the full suite, then the normal chain (backup → rehearse → deploy → verify-live).
New migrations (additive; Postgres triggers/checks), in order:
`2026_10_12_710001` authority_checks · `720001` underwriting case workflow/outcome · `730001` policy chronology tables · `750001` provider master/network ·
`760001` reinsurance treaties/cessions · `770001` coinsurance · `780001` complaints + correspondence register + COMPLAINT case type v2.
After migrate: `php artisan policies:backfill-chronology --dry-run`, then run it for real. Master-data seed on deploy marks aviation.manufacturer and
life_insurance.relationship INACTIVE (aliases keep old codes working).

Behaviour changes to watch in production:
- Authority (7A): premium over delegated limit → issuance request goes to CARRIER_REVIEW + AUTHORITY_REFERRAL case (no longer a 422). Partner with no
  intermediary authorization → referred (owner decision). Lapsed/suspended intermediary → denied. Every decision, including denials, is logged in `authority_checks`.
- Underwriting (7B): decide() goes through the proposal state machine; new CONDITIONAL outcome; `assign` no longer auto-moves to IN_REVIEW (use start-review);
  new workspace routes under underwriting/cases/* (evaluate, start-review, ready-for-decision, information-requests).
- Policies (7C): each issuance writes an immutable policy_versions row + structured parties/risks/coverages/limits.
- Legacy POST /web-experiences/marketplace/comparisons is now an alias of /quote-comparisons (stricter validation, tenant-checked, new response shape).
- Bordereau endpoints share one type set (PREMIUM, CLAIM, ENDORSEMENT, CANCELLATION, COMMISSION).
- phpunit.xml now carries the test placeholders that only lived in local .env.testing (see docs/audit/CLOUD_BASELINE_TRIAGE.md).

New permissions not yet granted to any role (wildcard admins only): providers.*, provider_networks.*, provider_tariffs.approve,
reinsurance.*, coinsurance.*. Owner to decide which roles get them.
Reverted: my issuability gate in PolicyIssuanceService::request (it blocked straight-through approvals); 7D re-does it on the payment path.
Open owner questions collected in the cloud session's chat; the ones still open go into OWNER_OPEN_QUESTIONS.md next.

### 2026-09-25 cloud: session start
- Reviewed BUILD_PROGRESS.md; origin/master == c0a8d01 (decisions batch). No local work newer than that is visible on GitHub.
  **Local: if you have uncommitted work, commit + push it and note it here, so cloud can review it before continuing.**
- Cloud environment: PHP 8.4 + PostgreSQL 16 (local to container), array cache/sync queue for tests.
- Inventory for Batch 7 (existing code, to extend rather than duplicate):
  - Authority: `Domain/CarrierOperations/AuthorityChecker` (legacy `delegated_authority_agreements`), `Application/Authority/AuthorityTypeCatalogue`,
    `authority_limits` (written by agreements backfill, never read). Only `PolicyIssuanceService::request` checks authority, and a denial is a bare 422.
  - Underwriting: `UnderwritingService::decide` bypasses the proposal state machine; `ProposalService::applyUnderwritingDecision` exists
    and becomes the single path.
  - Issuance: `PolicyIssuanceService` doesn't call `PolicyIssuabilityService`, `CoverTermsService::resolveStart` or `PremiumCoverEvaluator`;
    `PaymentIssuanceTrigger` swallows failures (no failed-issuance queue) and hard-codes territory CM.

### 2026-09-26 local: public desktop pages deployed (r20260926-072400)
- Deployed = bfba9df + ONLY the public-site files (marketplace /insurance, 7 directories /insurance/{line}, /compare, /about, /contact, /login, /signup).
  The Gap Closure Pack (a202714) is on master but NOT deployed; it still goes out in its own release (backup → rehearse → deploy).
- Live-verified: all pages 200, 23 published products listed, website OTP sign-in as Demo Customer works, sign-out works.
- Not built yet: compare/buy flow stages 03-24 (signed-in customer/agent/claims-officer web screens).
