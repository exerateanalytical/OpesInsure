# Release handover: Batches 13B–17 + owner specs V1/F1 (for the local deploy session)

**Branch:** `origin/claude/charming-bohr-2fd2hk`. This is the final cloud build: every batch up to 17 is built.
**Deploy together with:** the earlier handovers (Batch 7 → 8 → 9 → 10 → 11_12), in that order, then this one. One deploy is fine.
**Tests (cloud, PostgreSQL 16, PHP 8.4):** full pest suite 1636 passed / 0 failed. It must be green locally too.

## 1. What is in it
| Area | Contents |
|---|---|
| 13B provider portal | provider users/roles (PROVIDER_ADMIN, FRONT_DESK, DOCTOR, BILLING_OFFICER, PHARMACY_USER, LAB_USER, FINANCE_USER), portal views |
| 14 cashless health | members, cards, eligibility, pre-authorisation / guarantee of payment, provider claims + adjudication, provider settlements, benefit accumulators |
| Reinsurance | facultative placements, recoveries (bill / settle) on top of Batch 7 treaties |
| Compliance / AML | screening lists + rescreen, risk scoring, EDD, STR reports, findings/actions, governance registers, DSR, privacy purposes. Per owner decision A: "screened against your approved lists; matches reviewed by your compliance team" |
| Accumulation / catastrophe | exposure accumulation, capacity check, cat events |
| 15 regulatory | returns, rules, inspections; KPI definitions |
| 16 developer platform | API keys/apps, OpenAPI (`docs/api/openapi.json`, `/api/v1/developer/openapi.json`), carrier connectors + outbound message dispatch, API family coverage (docs/audit/API_FAMILY_COVERAGE.md) |
| 17 hardening | legacy migration staging, operations console (heartbeat, DR restore checks), security centre (impossible travel, privileged access windows), production readiness (docs/audit/PRODUCTION_READINESS.md) |
| V1 owner spec | Cameroon vehicle power / fiscal power master (tables + lookup) |
| F1 owner spec | finance counterparty accounts, immutable sub-ledger, premium remittances, aging, 22 reports, broker/insurer dashboards (`/api/v1/finance/subledger/*`); gap analysis in docs/audit/FINANCE_SUBLEDGER_GAP_ANALYSIS.md |
| Permissions | every route permission is now catalogued in `config/permissions.php` (drift test enforces this), so they appear in role management |

## 2. Migrations (19, all additive)
`2026_10_17_130201` … `131101` (health, facultative, recoveries, AML, compliance, accumulation), `139901` WA-FIX health integration columns,
`140101` vehicle/fiscal power, `140201` finance sub-ledger (immutable-entry trigger; seeds 2 accounting-event mappings),
`2026_10_18_160101` … `160701` (regulatory, KPIs, developer platform + carrier connectors, legacy migration staging, operations console, security centre).
Rehearse on a copy of prod as usual.

## 3. Scheduler / commands
New scheduled jobs: `aml:rescreen` daily 02:45 · `security:sweep` every 15 min · `ops:heartbeat` every minute · `carriers:dispatch-messages` every minute.
Manual: `php artisan api:openapi` regenerates docs/api/openapi.json (run after any route change; a test enforces it).
The finance sub-ledger fills itself from posted journals on first read (no backfill command needed).

## 4. New .env settings (all optional, defaults are safe)
AML_SCREENING_GATE_MODE, AML_SCREENING_MATCH_THRESHOLD, AML_SCREENING_RESCREEN_DAYS · COMPLIANCE_DSR_DEFAULT_RESPONSE_DAYS ·
HEALTH_PREAUTH_AUTHORITY_TYPE, HEALTH_PREAUTH_GOP_VALIDITY_DAYS · OPS_DR_* (BACKUP_PATH, BACKUP_PATTERN, ENVIRONMENT, RESTORE_COMMAND, RESTORE_TIMEOUT, SCRATCH_CONNECTION),
OPS_HEARTBEAT_STALE_SECONDS, OPS_MONITORED_QUEUES, OPS_READINESS_RESTORE_MAX_AGE_DAYS · PRIVILEGED_ACCESS_LEGACY_SINGLE_STEP, PRIVILEGED_ACCESS_MAX_WINDOW_HOURS ·
SECURITY_GEO_COUNTRY_HEADER / LAT_HEADER / LNG_HEADER, SECURITY_IMPOSSIBLE_TRAVEL_KMH · APP_ATTEST_*, PLAY_INTEGRITY_*, APP_LINKS_* (mobile device integrity / deep links).
Set OPS_DR_BACKUP_PATH to `/srv/opesinsure` backups so the readiness page reports real restore tests.

## 5. Behaviour changes
- Privileged access grants are now two-step (request → approve); a one-step grant returns REQUESTED. `PRIVILEGED_ACCESS_LEGACY_SINGLE_STEP=true` restores the old behaviour.
- `AUTOMOBILE_STAMP_DUTY` charge is now UNVERIFIED and the fiscal-power stamp-duty schedules are **DRAFT**: an authorised user must approve them before they price.
- CLAIMS_OFFICER is maker-only (cannot approve a colleague's claim); CLAIMS_MANAGER keeps `*`.
- New routes are permission-gated; only wildcard roles hold the new permissions until the owner grants them in role management.

## 6. Deploy steps (in addition to the standing chain in LOCAL_SESSION_RESUME.md)
1. Migrate, then `php artisan rbac:sync-role-permissions --dry-run` and for real.
2. Approve the stamp-duty schedules (or leave draft until the rates are confirmed).
3. Check the scheduler picks up the 4 new jobs (`php artisan schedule:list`).
4. `node docs/audit/verify-live.mjs` + open `/api/v1/developer/openapi.json`.

## 7. Open owner questions
- Which roles get the new health / reinsurance / AML / compliance / accumulation / regulatory / security / finance sub-ledger permissions (catalogued, not granted).
- F1: no debit/credit note store (register returns NOT_AVAILABLE); no customer group/fleet store (group_id/fleet_id filters 422); no agent/provider advance workflow;
  default GL codes of the 10 new control accounts need confirmation; no netting configuration (net figures informational).
- Stamp-duty and fiscal-power rates to confirm before approving the DRAFT schedules.
- Mobile: backlog for the app session in docs/spec/MOBILE_BACKLOG_BATCH17.md.
