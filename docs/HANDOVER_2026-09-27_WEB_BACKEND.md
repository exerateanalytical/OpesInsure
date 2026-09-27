# Handover: web app and backend (2026-09-27, evening)

Live: https://insurance.opesdatacenter.tech, release **r20260927-174612** (commit bcde791, deployed 17:46; full suite 2,070 passed; role grants synced in production). Section 3 below is now LIVE. Demo mode is **ON**, as the owner instructed; only the owner can turn it off.
Earlier handover: `docs/WEB_APP_HANDOVER_2026-09-26.md`.

## 0. LATEST STATUS (read first), 2026-09-27 late evening
**Live:** r20260927-174612 (bcde791).

**Committed on master, NOT yet deployed** (bcde791..7663a6a). The next step is the full suite on the latest commit in `opesinsure-test` on a fresh database, then backup, a migration rehearsal on a production copy, and deploy.

**Coverage wave 1:** desktop coverage rose from 27.5% to 39.3% (350 of 890 write actions). Measured by `php artisan ui:coverage`; `tests/Architecture/UiCoverageRatchetTest.php` fails if it drops. Batches and commits:
- 1–2 claims: 1effecc
- 3 policies: cd58f26
- 4 payments + quotes: 138c720
- 5 proposals + issuance maker-checker: d090b8e

The full plan (batches 6–33) is in `docs/UI_COVERAGE_2026-09-27.md`.

**Mobile backend needs** (`mobile app/docs/BACKEND_NEEDS_MOBILE_AUDIT_2026-09-27.md`), done in 735bab2 and 2e7ce3c:
- E9: carrier finance and claims officer grants, scoped to their carrier.
- A1: `GET /mobile/capabilities`, and `allowed_actions` on detail resources.
- B1–B6:
  - device and login-activity fields
  - security alerts
  - staff security suspend and force re-authentication
  - step-up purposes
  - Play Integrity: real verification once configured, `CONFIG_REQUIRED` until then
- Top-level `latitude`/`longitude` on the profile and on the quote risk.

**Security review** (a4454a6), written up in `docs/SECURITY_REVIEW_MOBILE_2026-09-27.md`:
- Withdrawals are locked to the registered MoMo number, with a 24h cooling-off after a change.
- Uploads are checked against their real bytes.
- Per-IP limits on OTP and password attempts.
- MoMo and Orange credit only if the amount, currency and order match.
- An IDOR test sweep and a route-authorization architecture test.

**Step-up rollout hold** (7663a6a): `config/mobile_runtime.php` `step_up.not_enforced_yet` holds SIGN_OUT_EVERYWHERE, PAYOUT_DESTINATION_CHANGE and PROFILE_SECURITY_CHANGE. Requests without a grant pass for these three; a grant that is presented is still checked. **Empty that list and deploy only after the mobile session confirms its step-up-aware app update is published.**

**Migrations pending deploy:**
- `2026_10_31_100003`: E9 grant sync
- `2026_11_01_100001`: issuance maker-checker stages
- `2026_11_02_100001`: login-activity columns
- `2026_11_02_100002`: staff-security grant sync

**Next waves** (after the deploy):
- Coverage batches 6+: KYC/compliance; commissions, which include mobile D8 (commission fields, 100-row cap, per_page, outstanding definition, statements.read for brokers); settlements and bordereaux; support; finance; provider portal; and so on.
- Mobile A7: one shared list filter (status, q, period_from/to in Africa/Douala, sort, carrier_id, line_code) across all the listed endpoints, plus the missing row fields.
- Mobile C, D and E items not yet done.

**Owner blockers:**
- **No OTP provider configured:** every Etech and Twilio driver reports not configured. Demo mode cannot be turned off until one is configured and tested with the owner's phone. The owner has said to turn demo off, so do it as soon as OTP works.
- **MoMo authentication fails** every 5 minutes.
- **No ClamAV scanner** (`CLAMAV_HOST` is unset), so mobile uploads fail closed as FAILED. Installing it on the server needs owner approval.
- **Play Integrity credentials:** `PLAY_INTEGRITY_ENABLED`, `PLAY_INTEGRITY_ACCESS_TOKEN` and `PLAY_INTEGRITY_CERT_SHA256`.
- **`SECURITY_GEO_CITY_HEADER`** is not set.
- **This session cannot read or write the production `.env` or secrets** (the permission classifier blocks it). The owner, or the mobile session at the owner's request, makes those edits.

**Lessons:**
- Agents sharing one working tree repeatedly swept each other's staged files into commits, and partial-hunk staging once reordered code (fixed in 7663a6a). Always check `git status` and `git diff --cached` before committing.
- Run the release suite only when no agents are running; it takes about 80 minutes when the machine is quiet.

## 1. Session ownership (owner decision, 2026-09-27)
- **Mobile app session:** owns everything in `mobile app/`, including code, EAS builds and APK publishing.
- **This session (web/backend):** owns Laravel, the Filament panels, `/account`, the public site and server deploys.
- The two sessions coordinate by cross-session messages. This is recorded in both sessions' memory (`project_session_split`).

## 2. What's live
| Release | Content |
|---|---|
| r20260926-191117 | **Document security D1–D4:** security matrix, issuance gate, secure shell for all PDFs, 220/220 canonical specs linked, provider documents. Ed25519 signing key `opesinsure-doc-2026-09` is live. |
| r20260927-023336 | **UI build-out:** core detail pages with tabs, shared workflow actions, view pages for all 109 resources, configuration screens (letterhead, templates, seal, organisation settings), provider portal forms, dashboards and reports, `carrier_logo_url` on mobile payloads. |
| r20260927-091032 | **Hotfix:** claim and policy header actions were failing on Livewire round-trips (tenant lost), now fixed. Also adds claim payments, disputes and recoveries, the claim evidence download and dashboard auto-refresh. |

## 3. Deployed in r20260927-174612 (previously pending) (master `ed3476e` and earlier; 21 commits after bbd29f7)
**Status:** web, RBAC, partner and underwriting suites are green (374 tests). The full suite is running on `ed3476e` in `C:\laragon\www\opesinsure-test`, database `opesinsure_test_rel4`.

**Next steps:** backup, then rehearse the migrations on a copy of production, then deploy.

**New migrations:**
- `2026_10_30_100001`: letterhead contacts and physical-asset features.
- `2026_10_31_100001`, `100002` and `200001`: RBAC grant syncs via `rbac:sync-role-permissions`. These only add permissions, never remove custom grants, and skip `*` roles.

**Access rules (owner decision):**
- A broker admin sees the whole company book. A supervisor sees their team. Staff see the clients they recorded; this definition is **pending owner confirmation**.
- Insurer admins see everything for their own insurer, including health data.
- The full role matrix is `docs/spec/RBAC_MATRIX_BROKER_CARRIER.md`.
- Isolation tests cover a broker never seeing another broker's data, and a carrier never seeing another carrier's data.
- Acting follows visibility: staff cannot quote for a colleague's client.

**Insurer portal:**
- Approval inbox.
- Read-only screens: issuance, underwriting, quotes, quote requests, referrals, stickers, co-insurance and reinsurance, KYC, cashier, FX and journals.
- FINANCE_OFFICER can now sign in.
- Health section scoped to the insurer's own carrier, in the web pages and via `health.carrier_scope` on the API.

**Provider portal:**
- **Fixed:** every provider role got a 403 before. Each job now has its own permission set.
- Clinical data minimisation.
- About 150 French labels.
- User management, and forms for treatment episodes, reconciliation and disputes.
- Double submits are prevented.

**Partner workspace (`/account`):**
- My Book.
- Agent and broker assisted claim filing (`POST /mobile/partner/broker/claims`).
- Client documents and staff invitations.

**Customer account:**
- KYC, privacy and requests pages.
- Counter-offer accept.
- Settlement accept/reject and refund, confirmed with a one-time code.
- Inspection reschedule, incident edit and adding people to a claim.
- Certificate delivery and support attachments.
- The attachment 415 bug is fixed; it affected mobile too.

**Header actions:**
- Quote: re-rate, send, carrier quote, premium override, convert.
- Proposal: submit, request information, underwriting decision, counter-offer.
- Party: KYC, merge, consent, roles, relationships.
- Partner: onboarding, status.
- Policy servicing: reinstatement, portfolio transfer, portability export, recovery.

**Documents:**
- Register filters, revoke/replace history, verification lookup log and tamper check (hash plus signature).
- `/verify` shows the status states in FR/EN.
- Signing-key screen.
- Insurer letterhead and logo in `/insurer`, with maker-checker. This is how insurers supply their own logos.
- "Pending approval" tab for templates.

**Consistency:**
- Lucide icons only, enforced by `DesignConsistencyTest`.
- `Columns::money/date/status` everywhere, dates in the viewer's timezone, newest-first sort, EN/FR empty states, search on key fields.
- Six visual fixes: provider grid, 24 blank detail pages, row links, scroll cue, sidebar wrapping, FR yes/no.

**Mobile API:**
- Contract audit and `MobileApiContractTest`: document `signed_url` (fixes "Open" in the app), quote history fields, proposal numbers, `risk_asset`, and partner logo and policy fields.
- `POST /mobile/claims` accepts latitude and longitude.

**After the deploy:**
- Tell the mobile session which fields are live.
- Check each role on the live site.

## 4. Next phase (owner order: deploy first, then this)
1. **Coverage list.** Every backend write action (about 1,470 API operations) is checked for a desktop screen and button. Build from the list until nothing is missing, deploying in batches.
2. **Mobile backend needs.** The list is `mobile app/docs/BACKEND_NEEDS_MOBILE_AUDIT_2026-09-27.md`. Priority order:
   - E9: carrier role permissions.
   - A1: capabilities and `allowed_actions`.
   - B: security.
   - Then E, D, C, and A2–A6.

   Most items overlap with item 1.
3. **Profile and quote-risk coordinates.** Top-level `latitude` and `longitude`, as agreed with mobile.
4. **Known UI gaps** from the audits (`docs/UI_AUDIT_*`, `docs/UI_VISUAL_QA_2026-09-27.md`):
   - French: column and page-title translation in the admin, insurer and broker panels.
   - Provider KPI labels.
   - Health money columns.
   - Filter badge shows "0" when no filter is active.
   - CLAIMS_MANAGER gets a 403 opening a policy.
   - Provider departments and service units have no UI.
   - Payment-rail status screens.

## 5. Waiting on the owner
1. **APK 1.5.0 download page:** DONE by the mobile session at the owner's request. The file is uploaded and verified (md5 `a50736ae…`). In `/srv/opesinsure/shared/.env` set:
   - `MOBILE_APP_VERSION=1.5.0`
   - `MOBILE_APP_ANDROID_SIZE="65 MB"`
   - `MOBILE_LATEST_VERSION=1.5.0`

   Then run `php artisan config:cache`. My permission system blocks production `.env` edits.
2. **MTN MoMo.** Authentication fails every 5 minutes in production. Check the credentials.
3. **Seal artwork (D6).** Upload the seal and have a second admin verify it. Only then enable `DOCUMENT_ENFORCE_CONTROLS`.
4. **Provider templates.** Approve and publish the 12 templates, using the "Pending approval" tab.
5. **Insurer logos.** Each insurer admin uploads theirs in `/insurer` → Letterhead & logo.
6. **Decisions:**
   - Does "staff own clients" mean clients the staff member recorded?
   - Should only clinical staff raise pre-authorizations?
   - Which role may request endorsements (the route is currently ungated)?
   - Approval inbox now requires `approvals.decide`.
   - Demo mode: the owner has said to turn it off. That is BLOCKED until an OTP provider is configured (all Etech/Twilio drivers report not configured) and MoMo is fixed. Otherwise nobody can sign in.
7. **Open questions.** See `docs/spec/OWNER_OPEN_QUESTIONS.md`.

## 6. Operations
- **Deploy:**
  1. Full pest suite in `opesinsure-test` on a fresh database, run as postgres.
  2. `backup.sh`.
  3. Rehearse the migrations on a restored production copy.
  4. Build the tarball from `opesinsure-deploy-snap`.
  5. Run `/srv/opesinsure/deploy.sh`.
- **Git:** every commit on master is auto-pushed to `exerateanalytical/OpesInsure`.
- **Parallel agents:** commit only your own paths. Staged files from other agents have been swept into commits several times, so check `git status` before committing.
- **Test runs:** the full suite takes about 80 minutes when the machine is quiet, and many hours while agents are running tests. Run the release suite only when no agents are active.
