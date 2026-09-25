# Session handoff log (local ⇄ cloud)

Two backend sessions work on this repo: the **local** session (owner's Windows PC, Laragon) and the **cloud** session (claude.ai/code container).
The mobile app session is separate and owns `mobile app/` (see BUILD_PROGRESS.md).

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
| Batch 7A authority, 7B underwriting, 7C policy chronology, 7D issuance ops | cloud agents | IN PROGRESS |
| Batch 5/6 rules-engine follow-ups (DocumentRequirementService, QuestionSetCatalogue, saveComparison alias) | cloud agent | IN PROGRESS |
| Duplicate master-data lists + bordereau type set + cloud test baseline triage | cloud agent | IN PROGRESS |
| Batch 13A providers, 13C reinsurance, 13D co-insurance, 12C complaints/correspondence | cloud agents | IN PROGRESS |
| Mobile app | app session (local) | Not on GitHub, so cloud can't reach it |

## Log (newest first)

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
