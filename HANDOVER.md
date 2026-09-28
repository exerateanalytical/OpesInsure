# OpesInsure — Web Platform & Backend Handover

**Prepared:** 2026-09-27 (evening, Africa/Douala)
**Scope:** Laravel backend, REST API, Filament staff panels, customer/partner web account, public site, production server.
**Out of scope:** the Android/iOS app in `mobile app/`. The *Opesinsure Mobile app* session owns it (see §12).

This document is the single entry point. Every statement below was checked against the repository or the live server on the date above. Where something is unverified, the text says so.

---

## 1. Current state in one page

| Item | State |
|---|---|
| Production URL | https://insurance.opesdatacenter.tech |
| Live release | `r20260927-174612`, built from commit **`bcde791`**, deployed 2026-09-27 17:46 |
| Test status of live release | Full suite **2,070 passed, 0 failed** before deploy |
| Code on `master` not yet deployed | `bcde791..5c2684d`: 11 commits (see §6). Full suite **not run**: stopped at handover. Unverified. |
| Demo mode | **ON.** The owner has ordered it OFF. That is blocked because no SMS/OTP provider is configured (§10). |
| Desktop UI coverage of backend write actions | **39.3%** on `master` (350 of 890), 27.5% on the live release. Measured by `php artisan ui:coverage` (§7). |
| Audit copy of deployed code | `OpesInsure_web_deployed_r20260927-174612_bcde791.zip` in the project root (§14) |

**Summary.** The backend (domain services, database, API, tests) is extensive and mature. The staff desktop UI is behind it: about 60% of state-changing backend actions still have no screen or button. §7 has the measured list and the plan to close it. Production cannot leave demo mode until an SMS provider, MTN MoMo credentials and a malware scanner are configured on the server. Only the owner can supply those.

---

## 2. System overview

### 2.1 Stack
- **PHP 8.3**, **Laravel 12**, **Filament 4** (staff panels), **Laravel Passport 13** (API/mobile tokens)
- **PostgreSQL 16**, Redis, nginx, PHP-FPM on Ubuntu 24.04 (Hostinger VPS)
- **Pest** test suite: 330 test files, about 2,100 tests
- Front-end assets are built with Vite; the compiled output (`public/build`) is committed

### 2.2 Applications served by the one Laravel app
| Surface | Path | Users |
|---|---|---|
| Admin panel (Filament) | `/admin` | Platform, system, compliance, finance and claims staff |
| Insurer portal (Filament) | `/insurer` | Insurance company admins and staff (underwriting, claims, finance, health, compliance) |
| Broker portal (Filament) | `/broker` | Broker company admin, supervisor, staff, branch manager |
| Provider portal (Filament pages) | `/provider` | Hospitals and clinics: front desk, clinical, pharmacy/lab, billing, finance, admin |
| Customer & partner web account | `/account` (Blade + JS) | Customers; agents and brokers (partner workspace) |
| Public site | `/`, `/insurance`, `/compare`, `/verify`, … | Anonymous visitors |
| REST API | `/api/v1/*` | Mobile app, web account JS, integrations. OpenAPI is in `docs/api/openapi.json`, 1,321 paths. |

### 2.3 Repository layout (deploy-relevant)
```
app/Application/     domain services (business logic lives here)
app/Domain/          value objects, state machines, shared domain types
app/Filament/        staff UI: Admin/, Shared/ (Actions, Components, Pages, Columns)
app/Interfaces/Http/ API + web controllers, middleware
app/Models/          Eloquent models
app/Policies/        authorization policies
routes/              api.php + ~55 feature route files (wave*.php, *.php)
database/            174 migrations, seeders, data/ (reference datasets)
resources/           Blade views, lang/{en,fr}, CSS
config/              includes permissions.php, mobile_runtime.php, demo.php
```

### 2.4 Architectural rules (enforced by tests)
- **Business logic lives in `app/Application/*` services.** Controllers and Filament actions call the same service method. `tests/Architecture/*` enforces this, e.g. status writes outside services are rejected.
- **Staff UI actions** extend `app/Filament/Shared/Actions/WorkflowAction`. Each action is hidden without the API route's permission, re-checks it when run, calls the same service and shows refusals as notifications.
- **Detail pages** use `RecordShell` / `CoreRecordOverview` (core records) or `RecordInfolist` (configuration/reference data).
- **Columns** go through `app/Filament/Shared/Columns` (`money`, `date` in the viewer's timezone, `status`).
- **Icons:** Lucide only (`DesignConsistencyTest`).
- **Money:** stored as integer minor units ×100; XAF is displayed without decimals ("FCFA").
- **RBAC:** role permissions live in `app/Application/Identity/RoleCatalogue.php` and are synced to the DB with `php artisan rbac:sync-role-permissions`. That command only adds grants, never removes custom ones, and skips `*` roles. The broker/insurer matrix is in `docs/spec/RBAC_MATRIX_BROKER_CARRIER.md`.
- **Data scoping:**
  - `BookScope`: a broker admin sees the whole company, a supervisor their team, staff the clients they recorded, an agent their own book.
  - `PortalScope` / `CarrierScopeResolver`: insurer users see only their own carrier.
  - Tests cover isolation.
- **Gated data is never invented.** Master data carries a status: VERIFIED, PLATFORM_NORMALIZED, UNVERIFIED, PENDING_SOURCE, CONFIG_REQUIRED, DEMO_ONLY or RETIRED.

---

## 3. Environments and access

| | |
|---|---|
| Production host | `187.77.110.114`, SSH user `opesinsure`, key `~/.ssh/opesinsure_deploy` |
| App root | `/srv/opesinsure` (`current` → `releases/<id>`, `shared/.env`, `shared/storage`, `shared/keys`, `backups/`) |
| Staging | **None.** Owner decision: production only. |
| Local dev | `C:\laragon\www\opesinsure`, PHP `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`, Composer `C:\laragon\bin\composer\composer.phar` |
| Isolated test worktree | `C:\laragon\www\opesinsure-test` (detached HEAD; release suites run here) |
| Deploy build worktree | `C:\laragon\www\opesinsure-deploy-snap` |
| Git remote | `github.com/exerateanalytical/OpesInsure`, `master`. A post-commit hook auto-pushes every commit. |
| Document signing key | Ed25519 `opesinsure-doc-2026-09`, private key at `/srv/opesinsure/shared/keys/` (0600). Public key served at `/api/v1/public/document-signing-keys`. |

**Access limitation of the AI session:** the permission system blocks reading or writing production secrets (`shared/.env`) and replacing files on the server outside `deploy.sh`. The owner makes those changes, or the mobile session at the owner's request.

---

## 4. Release procedure (follow exactly)

1. **Full test suite** on the exact commit, in the isolated worktree, on a fresh DB, run as the Postgres superuser. Some tests need `SET session_replication_role`.
   ```bash
   cd C:/laragon/www/opesinsure-test && git fetch ../opesinsure master && git checkout <commit>
   php composer.phar dump-autoload
   createdb -U postgres opesinsure_test_<name>
   DB_USERNAME=postgres DB_PASSWORD= DB_DATABASE=opesinsure_test_<name> php vendor/bin/pest
   ```
   The suite takes about 80 minutes on a quiet machine and many hours if other test runs compete. Release only on 0 failures.
2. **OpenAPI:** `php artisan api:openapi`. Commit if it changed; `OpenApiAndDiscoveryTest` enforces this.
3. **Back up production:** `ssh … /srv/opesinsure/backup.sh`, which writes `backups/opesinsure-<stamp>.sql.gz`.
4. **Rehearse the migrations:** copy that backup locally, restore it into a fresh DB, and run `php artisan migrate --force` from the test worktree against it. Confirm every pending migration reports DONE.
5. **Build:** in `opesinsure-deploy-snap`, run `git checkout <commit>`, then `composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix --no-scripts`, then delete `bootstrap/cache/{packages,services,config,routes-v7}.php`. Then:
   ```bash
   tar --force-local -czf rel.tar.gz --exclude=.git --exclude=node_modules --exclude=./storage --exclude=.env --exclude="mobile app" --exclude="landing page" .
   ```
6. **Deploy:** `scp` the tarball to `/tmp/rel.tar.gz`, then run `/srv/opesinsure/deploy.sh /tmp/rel.tar.gz`. The script runs the migrations and seeders and health-checks before the symlink switch, then keeps 5 releases.
7. **Verify live:**
   - HTTP 200 on `/`, `/verify`, `/admin/login`, `/insurer/login`, `/broker/login`, `/provider/login`, `/account` and `/api/v1/public/institutions`
   - `migrate:status` shows no Pending migrations
   - role grants synced (spot-check `roles.permissions`)
   - check `storage/logs/laravel.log` for new errors
8. **Record** the release id in this file (§5) and notify the mobile session of any API changes.

**Rollback:** repoint `/srv/opesinsure/current` to the previous release directory and reload PHP-FPM. Restore the pre-deploy `backups/*.sql.gz` only if a migration corrupted data; all migrations to date are additive.

---

## 5. Release history (recent)

| Release | Commit | Content |
|---|---|---|
| r20260926-191117 | 2ea5d75 | **Document security D1–D4:**<ul><li>security matrix and 15-step issuance gate</li><li>secure A4 shell for all PDFs</li><li>220/220 canonical document specs linked</li><li>provider documents</li><li>Ed25519 signing live</li></ul> |
| r20260927-023336 | a99d539 | **UI build-out 1:**<ul><li>detail pages for all resources</li><li>shared workflow actions</li><li>letterhead, template, seal and settings screens</li><li>provider portal forms, dashboards and reports</li></ul> |
| r20260927-091032 | bbd29f7 | **Hotfix:** claim/policy header actions lost the tenant on Livewire round-trips. Also adds claim payments, disputes and recoveries. |
| **r20260928-145520 (LIVE)** | **bcde791 + 7e161c9 controller only** | **Hotfix on top of r20260927-174612:** public broker profile returns `affiliated_insurers`, `products` (from ACTIVE non-demo carrier_broker_agreements) and `featured` (ASSUR EXPERT D&G SARL). Only `PublicInstitutionController.php` changed; master's later commits are still NOT deployed. |
| r20260927-174612 | bcde791 | **Access and portals:**<ul><li>broker/insurer RBAC and data scoping</li><li>insurer portal screens</li><li>provider portal access fix (every provider role had been refused)</li><li>partner workspace (My Book, assisted claims, documents, staff)</li><li>customer account (KYC, privacy, step-up settlement/refund)</li><li>header actions for quote, proposal, party, partner and policy</li><li>documents (tamper check, verification log, insurer logo upload)</li><li>design-consistency sweep</li><li>mobile API contract fixes</li><li>FNOL coordinates</li></ul> |

---

## 6. Committed on `master`, NOT yet deployed (`bcde791..5c2684d`)

| Commit | Content |
|---|---|
| 74dc31d | `ui:coverage` command, `docs/ui-coverage.json` and the coverage ratchet test |
| 1effecc | **Coverage batches 1–2 (claims, 43 actions):**<ul><li>coverage, investigation, expert, parties/evidence, carrier exchange and settlement actions</li><li>claim tabs</li><li>repair-network register</li></ul> |
| cd58f26 | **Coverage batch 3 (policies, 22 actions):**<ul><li>"Policy operations" group</li><li>servicing tab</li><li>sticker custody</li></ul> |
| 138c720 | **Coverage batch 4 (payments and quotes, 28 actions):**<ul><li>payment actions</li><li>Refund and Clearing screens</li><li>quote actions</li><li>/account quote/payment buttons</li></ul> |
| d090b8e | **Coverage batch 5 (proposals and issuance, 13 actions):**<ul><li>issuance maker-checker with verification, correction and second approval, enforced by DB constraints</li><li>issuance exceptions</li><li>new mobile issuance endpoints</li></ul> |
| 735bab2 | Carrier FINANCE_OFFICER / CLAIMS_OFFICER read grants, scoped to their carrier (mobile item E9) |
| 2e7ce3c | **Mobile items A1, B1–B6 and location:**<ul><li>`GET /mobile/capabilities`</li><li>`allowed_actions` on detail resources</li><li>device and login-activity fields</li><li>security alerts</li><li>staff security suspend/force-reauth</li><li>step-up purposes</li><li>Play Integrity verification (CONFIG_REQUIRED until configured)</li><li>latitude/longitude on profile and quote risk</li></ul> |
| a4454a6 | **Security review:** see §8 |
| 7663a6a | **Step-up rollout hold:** see §8. Also fixes a staging error that had put the payout-change alert after `return` and misordered login security events. |
| 5c2684d, 6b4ed59 | Handover docs |

**Pending migrations:**
- `2026_10_31_100003`: E9 grant sync
- `2026_11_01_100001`: issuance maker-checker stages
- `2026_11_02_100001`: login-activity columns
- `2026_11_02_100002`: staff-security grant sync

**Verification status:**
- Each batch passed its own targeted tests.
- The full suite on `5c2684d` was **started and stopped at handover. It has not been run.**
- An earlier partial run, on a worktree mixing old and new files, showed 4 failures in `MobileSecurityDetailTest`. These are expected to be fixed by `7663a6a`, but that is **not yet confirmed**.
- **Do not deploy until the full suite is green.**

---

## 7. Desktop UI coverage — the main remaining gap

- **Measurement:** `php artisan ui:coverage --md=docs/ui-coverage-summary.md` lists every non-GET `/api/v1` action and whether a Filament action or `/account` page calls it. Method and caveats are in `docs/UI_COVERAGE_2026-09-27.md`.
- **Today:** 885–890 write actions. **27.5% covered on the live release, 39.3% on `master`.**
- **Uncovered after wave 1:** mostly STAFF_DESKTOP_NEEDED, then CUSTOMER_WEB_NEEDED, plus a small number of MOBILE_ONLY_OK and SYSTEM_ONLY actions (webhooks, callbacks).
- **Ratchet:** `tests/Architecture/UiCoverageRatchetTest.php` fails if coverage falls below the recorded floor (39.3 / 40.1). Raise the floor after each batch.

**Build plan:** batches of about 20 actions, in `docs/UI_COVERAGE_2026-09-27.md` §batches.

| Status | Batches |
|---|---|
| Done (wave 1) | 1–5 |
| Next (wave 2) | 6–7 KYC/AML/compliance, 8 parties, 9–10 commissions/payouts/statements/settlements/bordereaux, 11 documents/underwriting, 12 support/complaints |
| Then | 13 account/security, 14–15 finance, 16–17 provider portal, 18 catalogue, 19 master data, 20 reinsurance, 21 document governance/legacy migrations, 22 regulatory/distribution, 23–33 long tail |

**Known UI defects not yet fixed:**
- **French:** many column headers, page titles and status badges in `/admin`, `/insurer` and `/broker` are still English. This is the largest visible gap.
- **Provider dashboard:** KPI labels are English and upper-case.
- **Insurer health lists:** show raw minor-unit columns.
- **Filter button:** always shows a "0" badge.
- **CLAIMS_MANAGER** sees the policy list but gets a 403 on policy detail.
- **Insurer policy detail:** layout nits (trailing divider, date wrap).
- **Payment screens are admin-only.** Payment records aren't carrier-scoped yet, so they can't be shown in `/insurer`.
- **Provider departments/service units** have no UI (API only).
- **Browser verification:** only by one automated crawl (about 680 page views, `docs/UI_VISUAL_QA_2026-09-27.md`), not a human walkthrough.

Reports: `docs/UI_AUDIT_*_2026-09-27.md` (admin/insurer, broker/agent, customer, provider) and `docs/UI_VISUAL_QA_2026-09-27.md`.

---

## 8. Security posture

**In place (live):**
- Passport auth, tenant middleware and permission middleware on every API route.
- Maker-checker on sensitive approvals.
- Audit log.
- Ed25519-signed documents with public QR verification. Personal data is masked on the public result.
- Tamper check (hash plus signature).
- `DOCUMENT_ENFORCE_CONTROLS` is **OFF** until corporate seal artwork is uploaded and verified by a second admin (§10).

**Security review on `master`** (a4454a6; `docs/SECURITY_REVIEW_MOBILE_2026-09-27.md`):
1. **Route authorization:** all 80 `/mobile/{partner,agent,broker,carrier}/*` routes have auth, tenant and permission middleware. An architecture test enforces this. The rate-limit gap on the agent profile PATCH is fixed.
2. **IDOR sweep:** 32 `{id}` routes across tenants, brokers and carriers. No leak found.
3. **MTN MoMo / Orange callbacks:**
   - The callback token is required, and status is re-queried with the operator.
   - Replays are idempotent.
   - **Added:** credit is refused on any order, amount or currency mismatch.
4. **Payout theft vector fixed:**
   - Withdrawals now go only to the registered MoMo number. Before this, they went to any number supplied in the request.
   - There is a 24h cooling-off after a payout-number change (`PAYOUT_DESTINATION_COOLING_OFF_HOURS`).
5. **Rate limits:** per-IP limits of 60 OTP sends per hour and 60 password attempts per 15 minutes, on top of the per-phone limits.
6. **Uploads:**
   - Real file-byte checks (`FileSignature`) at finalize and at evidence registration.
   - Type and size limits.
   - Fail-closed malware scan.

**Step-up rollout hold** (7663a6a):
- `config/mobile_runtime.php` → `step_up.not_enforced_yet` holds SIGN_OUT_EVERYWHERE, PAYOUT_DESTINATION_CHANGE and PROFILE_SECURITY_CHANGE.
- A request *without* a grant passes for these purposes. A grant that *is* presented is still verified.
- **Empty this list and deploy only after the mobile session confirms its step-up-aware app is published.**

**Known gaps and risks:**
- No malware scanner on production (§10), so mobile uploads fail closed.
- Play Integrity is not configured.
- `BRANCH_MANAGER` holds tenant-wide `policies.read` / `claims.view` on the core API. This was pre-existing and not narrowed.
- Settlement batches created before the health carrier scoping can mix carriers.

---

## 9. Data

**Seeded and live:**
- 29 insurers and 63 offices
- 153 vehicle makes and 512 models
- 364 document types and 220 canonical document specs
- 4,326 master-data values
- the CIMA dictionary
- the insurer directory

**Intentionally empty:** fields with the status PENDING_SOURCE, which have no verified source. These include insurer authorizations, the provider network, medical services, reinsurers, vehicle generations/variants and fiscal-power records. Do not invent them.

**Stamp duty schedules:** 2, both still DRAFT and awaiting owner confirmation.

**Insurer logos:** none uploaded, so `logo_url` is null and apps show initials. Insurer admins can now upload their own under `/insurer` → *Letterhead & logo* (maker-checker). The logos then flow to the mobile app automatically.

---

## 10. Blockers only the owner can clear

| # | Blocker | Effect | What is needed |
|---|---|---|---|
| 1 | **No OTP provider configured.** Etech SMS/WhatsApp and Twilio SMS/WhatsApp all report not configured; Twilio Verify errors. | Demo mode (fixed code 123456) can't be turned off: nobody could sign in. The owner has ordered demo OFF. | Add credentials for Etech or Twilio in `shared/.env`. Then test by sending a code to the owner's phone, set `DEMO_MODE_ENABLED=false`, run `config:cache`, and verify: no demo banner, no demo accounts list, a real login works. |
| 2 | **MTN MoMo auth fails** every 5 minutes in the production log | Payments fail | Correct the MoMo collection credentials |
| 3 | **No ClamAV** (`CLAMAV_HOST` unset) | Mobile claim-evidence and KYC uploads end as FAILED (fail-closed) | Install ClamAV on the server and set `CLAMAV_HOST`. Needs owner approval. |
| 4 | **Play Integrity** | Device attestation reports CONFIG_REQUIRED | `PLAY_INTEGRITY_ENABLED`, `PLAY_INTEGRITY_ACCESS_TOKEN`, `PLAY_INTEGRITY_CERT_SHA256` |
| 5 | **Seal artwork** | Document enforcement stays OFF | Upload the seal in Admin → Physical security assets and have a second admin verify it. Then set `DOCUMENT_ENFORCE_CONTROLS=true`. |
| 6 | **12 provider document templates** in REVIEW | Provider documents use unapproved templates | Admin → Document templates → "Pending approval" → Approve & publish |
| 7 | `SECURITY_GEO_CITY_HEADER` | Device list has no approximate city | Name of the edge geo-IP header |

---

## 11. Open owner decisions

1. Does "staff sees own clients" mean clients that staff member recorded? This is currently implemented as `customer_attributions.recorded_by`.
2. Should only clinical provider roles raise pre-authorisations? Reception currently types a diagnosis code.
3. Which role may request policy endorsements? The servicing route is currently ungated.
4. After an issuance correction request, should the requester get a "resubmit" step? Currently only a checker's verification clears it.
5. The approval inbox now requires `approvals.decide`. Is that intended for all admin roles?
6. The remaining items are in `docs/spec/OWNER_OPEN_QUESTIONS.md`:
   - Q8 numbering conflict
   - near-synonym document types
   - blocking field rules
   - KYC defaults
   - authority limits

---

## 12. Coordination with the mobile session

- **Ownership:** the *Opesinsure Mobile app* session owns `mobile app/` completely, including code, EAS builds and APK publishing. This session never edits it.
- **Messaging:** communicate by cross-session message; use `ListAgents` to find the session.
- **After every deploy:** tell the mobile session which endpoints and fields went live.
- **Current app:** APK 1.5.0 (versionCode 19) is published at `/download/android`.
- **Mobile backend requests:** `mobile app/docs/BACKEND_NEEDS_MOBILE_AUDIT_2026-09-27.md`.

| Status | Items |
|---|---|
| Done on `master` | A1, B1–B6, E2, E9, location |
| Queued: A7 | A shared list filter on all mobile lists: `status`, `q`, `period_from`/`period_to` (Africa/Douala), `sort`, `carrier_id`, `line_code`, plus missing row fields |
| Queued: D8 | Commission accrual fields; lift the 100-row cap; honour `per_page`; define "outstanding"; `statements.read` for brokers |
| Queued: other | The rest of C, D and E, and A2–A6 |

**Pending confirmation:** the mobile session will say when its step-up-aware app is live. After that, lift the §8 hold.

---

## 13. Recommended next steps (in order)

1. Run the full suite on `8fc41fe` (latest `master`). If it is green, back up, rehearse the migrations, deploy, verify, notify mobile, and update §5.
2. Coverage wave 2: batches 6–12. Include mobile D8 in batch 9–10 and mobile A7 as one shared list-filter helper.
3. A French translation pass on the panels (column labels, titles, badges).
4. Carrier-scope payment records, then expose payments in `/insurer`.
5. Continue the coverage waves to 100% of STAFF_DESKTOP_NEEDED and CUSTOMER_WEB_NEEDED.
6. When the owner clears §10 #1–#3, switch demo mode off with the verification steps above.

**Working rules learned the hard way:**
- **Commit only your own paths.** Parallel agents sharing one working tree repeatedly swept each other's staged files into their commits. Always check `git status` and `git diff --cached` first.
- **Never stage partial hunks of a file another agent is editing.** It reordered code once (fixed in 7663a6a).
- **Run release suites only when no other test runs are active.**
- **Base each release on a tested commit,** not on the moving `master`.

---

## 14. Audit copy of deployed code

`OpesInsure_web_deployed_r20260927-174612_bcde791.zip` (project root, about 11.6 MB, 3,405 files):
- **Contents:** an exact `git archive` of the **live commit `bcde791`**, limited to what deployment needs: `app/`, `bootstrap/`, `config/`, `database/` (migrations, seeders, reference data), `public/` (including the compiled `build/`), `resources/`, `routes/`, `artisan`, `composer.json`/`composer.lock`, `package.json`/`package-lock.json`, `vite.config.js` and `.env.example` (placeholder values only).
- **Excluded:** tests, docs, the mobile app, the landing-page sources, `vendor/`, `node_modules/`, `storage/` and every secret. Reproduce `vendor/` with `composer install --no-dev` from the included lock file.
- **Not in git:** the zip is untracked and should not be committed.

---

## 15. Document index

| Topic | Location |
|---|---|
| Server, deploy script details | `DEPLOYMENT.md` |
| Coverage method and batch plan | `docs/UI_COVERAGE_2026-09-27.md`, `docs/ui-coverage.json` |
| RBAC matrix | `docs/spec/RBAC_MATRIX_BROKER_CARRIER.md` |
| Owner decisions and questions | `docs/spec/OWNER_DECISIONS_2026-09-25.md`, `docs/spec/OWNER_OPEN_QUESTIONS.md` |
| Security review | `docs/SECURITY_REVIEW_MOBILE_2026-09-27.md` |
| Mobile API contract | `docs/MOBILE_API_CONTRACT_2026-09-27.md`, `tests/Feature/Mobile/MobileApiContractTest.php` |
| Document security plan | `docs/spec/canonical/DOCUMENT_SECURITY_COMPLETION_PLAN.md` |
| UI audits | `docs/UI_AUDIT_ADMIN_INSURER_2026-09-27.md`, `docs/UI_AUDIT_BROKER_AGENT_2026-09-27.md`, `docs/UI_AUDIT_CUSTOMER_2026-09-27.md`, `docs/UI_AUDIT_PROVIDER_2026-09-27.md`, `docs/UI_VISUAL_QA_2026-09-27.md` |
| Earlier working notes (superseded by this file) | `docs/HANDOVER_2026-09-27_WEB_BACKEND.md`, `docs/WEB_APP_HANDOVER_2026-09-26.md`, `docs/SESSION_HANDOFF.md` |
