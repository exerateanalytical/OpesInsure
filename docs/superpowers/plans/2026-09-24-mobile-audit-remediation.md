# Mobile Audit Remediation — End-to-End Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every finding from the 2026-09-24 mobile audit (business login, security, API wiring, routing, flows, visuals, responsiveness, dead code), remove login rate limiting, verify on the live server with every persona, ship a fresh APK, and only then set up email for registration/confirmation.

**Architecture:** Laravel 11 backend (`C:\laragon\www\opesinsure`, deployed by tarball + `/srv/opesinsure/deploy.sh` to 187.77.110.114) and an Expo Router app (`C:\laragon\www\opesinsure\mobile app`, its own git repo, shipped as an EAS `production-apk` build uploaded to `/download`). Fixes are grouped into phases; each phase ends with a green test run and a commit. Nothing is deployed until Phase 6 passes.

**Tech Stack:** PHP 8.3 / Laravel / Passport / PostgreSQL 16 / Filament; Expo SDK + expo-router + TypeScript; EAS Build; nginx on Ubuntu 24.04.

**Status legend:** ✅ = code written in this session, still needs the verification step in its task. ⬜ = not started.

---

## Finding → task coverage matrix

Every audit finding, where it is fixed, and its state. Nothing is dropped.

| # | Finding (source audit) | Task | State |
|---|---|---|---|
| L1 | OTP 123456 only for 4 demo phones; web demo business phones get random codes | 1.1 | ✅ |
| L2 | No SMS provider on prod → real users never receive codes | 1.2 + 8 | ⬜ (needs Twilio creds) |
| L3 | Business self-signup blocked in app and backend | 3.4 | ✅ (by invitation) |
| L4 | Invitation accept attaches no role/permissions | 1.3 | ✅ |
| L5 | App never calls `invitations/accept` | 3.4 | ✅ |
| L6 | No insurer roles in admin forms | 1.4 | ✅ |
| L7 | Partner not linked to user's party; created PENDING with no activation path | 1.3, 1.5 | ✅ |
| L8 | `DemoScenarioSeeder` only runs by hand | 1.9 | ✅ |
| L9 | App role map misses backend role codes → dead tap / blank screen | 3.1 | ✅ |
| L10 | Demo build fake API crashes every login; OTP 246810 | 3.5 | ✅ (fake API removed) |
| L11 | Login rate limits lock testers out | **2.1** | ⬜ (remove, per user) |
| L12 | Stale stored tenant makes restart look like logout | 3.2 | ✅ |
| S1 | Carrier endpoints have no permission check, tenant-wide data readable by customers | 1.6 | ✅ |
| S2 | Broker and workspace endpoints lack permission checks; broker compliance tenant-wide | 1.6 | ✅ |
| S3 | Step-up code always random (even demo) → refunds/settlements/withdrawals blocked | 1.7 | ✅ |
| S4 | Withdrawal route has no server-side step-up | 1.7 | ✅ |
| A1 | `POST /public/insurance/verify` missing | 1.8 | ✅ |
| A2 | Workspace dashboard `title` vs app `label`; `*` permission shown Restricted | 1.8, 3.6 | ✅ |
| A3 | Registration requires a password the app no longer collects | 1.10 | ⬜ (in progress) |
| A4 | Backend endpoints nothing uses (broker statements/accruals, bordereaux, uploads, claim timeline/evidence, `me`, capabilities, tenants); `AgentApi.withdrawals` unused | 4.1 | ⬜ |
| A5 | Institution directory is bundled static data | 4.2 | ⬜ |
| A6 | Hard-coded server values (`origin_locked`, `city '—'`, `line1 'Not provided'`) | 4.3 | ⬜ |
| A7 | Dashboard type lists `recent_sales` that is never returned | 4.1 | ⬜ |
| R1 | Splash sends multi-workspace signed-in users to welcome | 3.2 | ✅ |
| R2 | Role picker always shown; back returns to verify | 3.2 | ✅ |
| R3 | Signed-in users can reach auth screens | 3.3 | ✅ |
| R4 | Universal links `/app` prefix has no route; no not-found screen | 3.3 | ✅ (+ 7.3 for assetlinks) |
| R5/R6 | `workspace/[role]` blank; `workspace/denied` blank | 3.1 | ✅ |
| R7 | Customer group double-guarded inconsistently | 3.3 | ⬜ verify |
| U1 | Quote questions/referral/terms unreachable | 5.6 | ✅ (linked from disclosure) |
| U2 | Brokers directory unreachable | 3.6 | ✅ |
| U3 | Public certificate verify unreachable | 3.6 | ✅ |
| B1 | No sign-out/account/notifications/switch in agent/broker/carrier | 5.1 | ✅ |
| B2 | 22 portal fetches have no catch/retry | 5.2 | ✅ |
| B3 | Terms link dead, no terms page | 3.4 | ✅ (draft text, needs legal review + real emails) |
| B4 | Unused password field, no forgot-password | 3.4, 1.10 | ✅ app / ⬜ backend |
| B5 | Partner sign-up options dead-end | 3.4 | ✅ |
| B6 | Policy detail lacks report-claim / documents / payments | 5.5 | ✅ |
| B7 | Home product tiles don't pass product | 5.4 | ✅ |
| B8 | Renew depends on global quote state | 5.5 | ✅ |
| B9 | `demo-accounts` duplicates sign-in demo block | 3.5 | ✅ |
| B10 | Customer home: no bell, key flows buried in 17 Account rows | 5.4 | ✅ |
| B11 | Decorative "Brokers & Agents"/"Insurance Companies" tiles | 3.4 | ✅ |
| N1 | Non-customer portals have no persistent navigation | 5.1 | ✅ |
| N2 | New portal account/notifications screens not registered in role guards | 5.1 | ✅ |
| N3 | Partner notifications may call a customer-only endpoint | 4.4 | ⬜ |
| V1 | Two design systems (authTokens/Manrope vs spec) | 5.3 | ✅ |
| V2 | Hard-coded hex colours | 5.3, 5.7 | ✅ except `app/index.tsx` → 5.7 ⬜ |
| V3 | AuthHero image/badge overlap; fixed onboarding decorations | 5.3 | ✅ |
| V4 | Bare `Loading…` text | 5.2 | ✅ |
| V5 | Manrope package still in `package.json` | 5.7 | ⬜ |
| V6 | Grey text contrast | 5.3 | ✅ |
| P1 | `Screen` lacks keyboard avoidance | 5.2 | ✅ |
| P2 | No bottom inset outside tab bar | 5.2 | ✅ |
| P3 | Fixed 48% grids, no tablet breakpoints | 5.3, 5.7 | ✅ portals/home, ⬜ workspace |
| P4 | Module table horizontal scroll without cue | 5.7 | ⬜ |
| P5 | Welcome pager doesn't re-sync on resize | 5.7 | ⬜ |
| P6 | Onboarding audience grid still 48% | 5.7 | ⬜ (decide) |
| D1 | Legacy backup, patch folders, duplicate JSONs, PDFs | 5.8 | ✅ (in Recycle Bin) |
| D2 | `tsconfig` includes everything | 5.8 | ✅ |
| D3 | Root `OPESINSURE_CAMEROON_DEMO_DATA_v1.json` unused | 5.8 | ⬜ |
| T1 | Stale mobile test expecting `/claims` | 3.7 | ✅ |
| T2 | Backend Wave12 withdrawal/dashboard tests failing | 1.11 | ⬜ (in progress) |
| T3 | No tests for the new security rules | 1.11 | ⬜ (in progress) |
| O1 | No queue worker, no scheduler on prod (emails/notifications will pile up) | 7.4 | ⬜ |
| O2 | No automated DB backups | 7.5 | ⬜ |
| O3 | MTN MoMo reconciler noise; `reconciliation:run` exits 1 | 7.6 | ⬜ |
| E1 | No mail (`MAIL_MAILER=log`) → registration/confirmation emails | Phase 8 | ⬜ (after all above) |

---

## Phase 1 — Backend: login, invitations, security (tests must go green)

Files already changed by the backend agent: `app/Application/Identity/MobileAuthService.php`, `InvitationService.php`, `RoleCatalogue.php` (new), `app/Application/Security/MobileStepUpService.php`, `app/Application/Carriers/CarrierScopeResolver.php` (new), partner controller + Filament forms, `routes/api.php`, `routes/wave12_*.php`, `routes/wave14_mobile.php`, `MobileWorkspaceController.php`, `PublicInsuranceVerifyController.php` (new), seeders, a migration adding `carrier_id` to memberships/invitations, `demo:seed` command, `DEPLOYMENT.md`.

- [ ] **1.1 Demo OTP for business demo phones.** Verify: `php artisan test --filter=AuditFixesTest::test_demo_business_phone_accepts_fixed_otp` passes.
- [ ] **1.2 SMS failure is loud.** Verify a `Log::critical` is written when Twilio is unconfigured: `php artisan test --filter=AuditFixesTest::test_sms_failure_logs_critical`.
- [ ] **1.3 Invitation accept attaches role, permissions, party and linked PENDING partner.** Verify test `test_invitation_accept_attaches_role_and_partner`.
- [ ] **1.4 CARRIER_ADMIN / CARRIER_STAFF roles with `carrier_id`.** Verify migration runs clean on a fresh DB: `php artisan migrate:fresh --seed` then `php artisan tinker --execute="dump(App\Models\Role::pluck('code'))"` lists both.
- [ ] **1.5 Partner reuse + activation.** Verify `test_partner_store_reuses_user_party` and that `POST partners/{id}/status` flips PENDING→ACTIVE.
- [ ] **1.6 Permission checks + carrier scoping.** Verify `test_customer_gets_403_on_carrier_endpoints`, `test_demo_carrier_gets_200_and_only_own_carrier_data`, `test_broker_compliance_scoped_to_partner`.
- [ ] **1.7 Step-up demo code + withdrawal step-up.** Verify `test_withdrawal_requires_step_up` and `test_demo_step_up_accepts_fixed_code`.
- [ ] **1.8 Public insurance verify + workspace `label`.** Verify `test_public_insurance_verify_returns_result_without_pii`, `test_workspace_dashboard_returns_label`.
- [ ] **1.9 `demo:seed` on deploy.** Verify `php artisan optimize` runs `demo:seed` and is idempotent (run twice, no duplicate rows).
- [ ] **1.10 Password optional on `POST /public/accounts`.**

  In the registration request validation change `'password' => ['required', ...]` to `['nullable', 'string', 'min:12']`, and in `AccountController::register`:

  ```php
  $password = $validated['password'] ?? Str::random(48);
  $user->password = Hash::make($password);
  ```

  Test: register without password → 201, and a user row exists. Then remove `throwawayPassword` from `mobile app/app/(auth)/sign-up.tsx` and send no password.
- [ ] **1.11 Test suite green.** Create `tests/Feature/Wave15/AuditFixesTest.php` with the tests named above; update outdated Wave12 expectations (obtain a step-up grant for withdrawals rather than removing the requirement). Run:

  ```bash
  php artisan test
  ```

  Expected: `Tests: N passed, 0 failed`. Record N in the commit message.
- [ ] **1.12 Commit backend** on `master`:

  ```bash
  git add -A app routes database config tests DEPLOYMENT.md
  git commit -m "Close audit findings: business login, invitations, carrier/broker authorization, step-up, public verify"
  ```

## Phase 2 — Remove login rate limiting (user request)

- [ ] **2.1 Remove login throttles.** In `routes/api.php` remove the `->middleware(...)` throttle on:
  - line 64 `public/accounts` (`throttle:5,1`)
  - line 107 `auth/mobile/otp/request`
  - line 108 `auth/mobile/otp/verify`
  - line 109 `auth/mobile/refresh`
  - line 295 `invitations/accept` (`throttle:5,10`)

  In `app/Application/Identity/MobileAuthService.php` (~233-240) delete the `RateLimiter::attempt('mobile-otp:phone:…')` and `('mobile-otp:ip:…')` checks and the exception they throw.

  **Kept deliberately:** the 5-wrong-guesses-per-code lock (`max_attempts` on the challenge, lines 68-105). It is not a rate limit on logging in; it stops someone guessing a 6-digit code. Say so in the report.
- [ ] **2.2 Test:** add `test_otp_request_not_rate_limited` — 30 OTP requests for one phone from one IP all return 2xx. Run `php artisan test --filter=AuditFixesTest`. Expected PASS.
- [ ] **2.3 Mobile:** remove any "too many attempts" countdown/lockout UI in `app/(auth)/sign-in.tsx` / `verify.tsx` that assumes the 429 (keep generic error handling).
- [ ] **2.4 Commit** "Remove login rate limiting for now (per product owner); keep per-code guess limit".

## Phase 3 — Mobile: auth, routing, API (done, verify)

- [ ] **3.1 Role map** (`src/store/session.ts`): all 13 codes from `RoleCatalogue::LABELS` + `FINANCE_STAFF` fallback; unknown → `/access-denied`. Verify: unit test in `tests/trust-boundary.test.mjs` asserting every code in RoleCatalogue appears in `roleToPortal`.
- [ ] **3.2 Splash / role picker / hydrate** — verify on device (Phase 6) for single, multiple, stale workspace.
- [ ] **3.3 Guards, deep links, not-found, R7** — confirm `(customer)/_layout.tsx` redirect and `Stack.Protected` agree; remove the redundant redirect if `Stack.Protected` covers every customer route.
- [ ] **3.4 Invitation flow, terms page, sign-up cleanup** — replace the made-up `support@opesinsure.cm` / `partners@opesinsure.cm` with the real addresses (ask owner); keep "DRAFT — PENDING LEGAL REVIEW".
- [ ] **3.5 Fake API removed; demo block reads `/public/demo-accounts`.**
- [ ] **3.6 Verify/brokers links; workspace `label ?? title`; `*` permission.**
- [ ] **3.7 Mobile tests:** `npm test` → 46/46 (done). `npx tsc --noEmit` → 0 errors (done).

## Phase 4 — API gaps not yet addressed

- [ ] **4.1 Wire or remove unused endpoints.** Decision per endpoint:
  - Broker statements + commission accruals → add a "Statements" row to broker Receivables screen (`app/broker/receivables.tsx`) using new `BrokerApi.statements()` / `accruals()`.
  - Carrier bordereaux + settlement detail → add `app/carrier/bordereaux.tsx` and settlement detail from `app/carrier/settlements.tsx`.
  - Claim timeline + evidence list → show on `app/claim/[id].tsx`.
  - `AgentApi.withdrawals` → history list on `app/agent/wallet.tsx`.
  - `mobile/uploads*` (chunked) and `POST mobile/documents` → keep backend, not needed by app now (documented).
  - `me`, `public/capabilities`, `tenants` → keep (used by web/admin), documented.
  - Drop `recent_sales` from the agent dashboard type.
  Each wired screen gets loading/error/empty via `useLoad` + `StatePanel`.
- [ ] **4.2 Institution directory from backend.** Add `GET /api/v1/public/institutions?type=insurer|broker` (reads carriers + active broker partners), switch `app/institutions/*` to it, delete `src/data/insurers.ts`, `src/data/brokers.ts`.
- [ ] **4.3 Remove placeholder server values:** `origin_locked` computed from data; `city` nullable (app shows nothing instead of "—"); agent client intake makes `line1` optional instead of writing `'Not provided'`.
- [ ] **4.4 Partner notifications.** Confirm `/mobile/notifications` works for AGENT/BROKER/CARRIER memberships; if it is customer-only, allow any authenticated membership scoped to the user.
- [ ] **4.5 Tests + commit** for 4.1–4.4 (backend feature tests per new/changed endpoint; `npm test`, `tsc`).

## Phase 5 — Mobile UI (done, verify) and leftovers

- [ ] **5.1 Portal shell + account/notifications + guards** — verify on device.
- [ ] **5.2 `Screen` keyboard/inset; `useLoad`/`StatePanel` everywhere** — verify keyboard on Android device.
- [ ] **5.3 Single theme, contrast, AuthHero** — verify on a 360px phone.
- [ ] **5.4 Home tiles pass product; bell + shortcuts.**
- [ ] **5.5 Policy detail actions; renew by id.**
- [ ] **5.6 Quote disclosure → questions → referral/terms → checkout linked.**
- [ ] **5.7 Leftovers:**
  - `app/index.tsx` hard-coded hex → tokens.
  - Remove `@expo-google-fonts/manrope` from `package.json` (`npm uninstall @expo-google-fonts/manrope`).
  - `app/workspace/[role].tsx` grid → `useColumns()`.
  - `workspace/[role]/module/[module].tsx` → card list under 600px, table with scroll hint above.
  - `app/welcome.tsx` pager: recompute offsets on `useWindowDimensions()` change.
  - Onboarding audience grid: keep 2×2 but switch to `useColumns()` min width 150px.
- [ ] **5.8 Cleanup:** delete root `mobile app/OPESINSURE_CAMEROON_DEMO_DATA_v1.json` if still unreferenced (`grep -r OPESINSURE_CAMEROON_DEMO_DATA` empty).
- [ ] **5.9 Commit mobile** (`npm test`, `npx tsc --noEmit`, `npx expo lint` all clean).

## Phase 6 — Deploy and verify live (requires deploy permission)

- [ ] **6.1 Build backend release locally:**

  ```bash
  composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
  npm ci && npm run build
  tar -czf "$TMP/opesinsure-release.tar.gz" --exclude='.git' --exclude='node_modules' --exclude='.env' --exclude='storage' --exclude='tests' --exclude='mobile app' --exclude='landing page' .
  ```
- [ ] **6.2 Deploy:** `scp` + `ssh … '/srv/opesinsure/deploy.sh /tmp/opesinsure-release.tar.gz'`. Expected: health check OK, `current` flipped. Then restore dev deps locally (`composer install`).
- [ ] **6.3 Live API checks** with `docs/audit/replay.mjs` for all personas (customer, agent, broker, insurer, and the web demo BROKER_STAFF +237600000007 / AGENT +237600000008) using OTP 123456. Expected: 200 everywhere they're allowed.
- [ ] **6.4 Live security checks:** as customer, `GET /mobile/carrier/referrals` → 403; as insurer → 200 with only its carrier's rows; step-up with 123456 on a demo refund → grant issued.
- [ ] **6.5 Live invitation journey:** admin issues AGENT invite → accept via API with a fresh phone → partner PENDING → admin activates → agent dashboard 200. Repeat for CARRIER_STAFF.
- [ ] **6.6 Rate limit gone:** 20 OTP requests from one IP → all 2xx.
- [ ] **6.7 Customer journey:** `docs/audit/journey.mjs` → JOURNEY COMPLETE.
- [ ] **6.8 APK:** `npx eas build --profile production-apk --platform android`, download, upload to `/srv/opesinsure/shared/storage/app/public/downloads/opesinsure-<version>.apk`, bump `MOBILE_APP_VERSION` if version changes. Verify `curl -I https://insurance.opesdatacenter.tech/download/android` → `application/vnd.android.package-archive`, MD5 matches local file.
- [ ] **6.9 On-device pass** (user's phone or emulator): splash → welcome → sign-in each persona → portal tabs → sign out; keyboard on forms; 360px layout; deep link `https://insurance.opesdatacenter.tech/app/sign-in`.
- [ ] **6.10 Update `docs/HANDOVER_MOBILE_PLATFORM_AUDIT.md`** with what was verified; commit.

## Phase 7 — Production hygiene found during audit

- [ ] **7.1 Twilio (L2):** set `TWILIO_*` in `/srv/opesinsure/shared/.env` once the owner provides credentials; verify a real phone gets a code.
- [ ] **7.2 Demo OTP scope:** confirm `DEMO_MODE_ENABLED=true` is intended on prod; note that turning it off disables 123456.
- [ ] **7.3 App Links:** serve `/.well-known/assetlinks.json` (package name + release SHA-256 from `eas credentials`) and `apple-app-site-association` from Laravel `public/.well-known/`; verify with `curl`.
- [ ] **7.4 Queue worker + scheduler (O1):** systemd user unit or supervisor for `php artisan queue:work redis --tries=3`, and a crontab entry `* * * * * php /srv/opesinsure/current/artisan schedule:run`. Needed before emails (queued mail).
- [ ] **7.5 Nightly `pg_dump` (O2)** into `/srv/opesinsure/backups`, keep 14 days.
- [ ] **7.6 Silence MTN MoMo reconciler when `PAYMENT_PROVIDER=fake`; fix `reconciliation:run` exit 1 (O3).**

## Phase 8 — Email for registration and confirmation (only after Phases 1–7 are verified)

- [ ] **8.1 Discover:** on the VPS check for an existing MTA or mail stack (`ss -ltnp | grep -E ':25|:587|:465'`, `ls /etc/postfix /etc/exim4 /opt/mailcow* 2>/dev/null`, the SNCA `.env` MAIL_* settings via the owner since we cannot read `/srv/snca`). Check DNS MX/SPF/DKIM for `opesdatacenter.tech`, and whether Hostinger blocks outbound port 25.
- [ ] **8.2 Choose:** reuse existing mail server if present; otherwise install an open-source server (Postfix + OpenDKIM for send-only, or docker-mailserver/Stalwart if mailboxes are needed). Requires root — the deploy user's sudo cannot do this; owner runs it or grants access.
- [ ] **8.3 DNS:** SPF, DKIM, DMARC, PTR/rDNS for the sending hostname.
- [ ] **8.4 Laravel:** `MAIL_MAILER=smtp`, `MAIL_FROM_ADDRESS=no-reply@insurance.opesdatacenter.tech`; add email verification to registration (`MustVerifyEmail` + signed verify link, or an email OTP for the mobile app), welcome/confirmation mails, invitation emails, password/account notices. Queue them (needs 7.4).
- [ ] **8.5 Mobile:** sign-up collects email (optional/required per owner), "Check your email" screen, resend link, deep link `…/app/verify-email?token=…`.
- [ ] **8.6 Verify:** register a real address → email arrives (not spam, check mail-tester score ≥ 8/10) → confirm link → account marked verified; invitation email arrives and opens the app.

---

## Self-review

- Coverage: every audit finding ID in the matrix maps to a task; no finding is marked "won't do".
- Open decisions for the owner: real support/partner email addresses (3.4), Twilio credentials (7.1), root access or owner action for the mail server (8.2), whether email is required at sign-up (8.5).

---

## Phase 9 — Owner requirements added 2026-09-24 (supersede conflicting items above)

These replace B4 ("drop the password") and parts of Phase 8.

- [ ] **9.1 Platform settings in the admin panel (Filament).** A `PlatformSettings` page (singleton settings table, encrypted secrets) holding:
  - Support contacts: support email, support phone, WhatsApp number, partner-onboarding email.
  - Twilio: account SID, auth token, from number, WhatsApp sender, enabled flag.
  - ETECH KEYS: SMS legacy `login`/`password`/`sender` (≤ 11 chars) for `https://sms.etech-keys.com/ss/envoyer.php`; REST v1 bearer token for `https://v1.api.etech-keys.com/api/v1/send-sms` and `/whatsapp/send` (template name + language); enabled flags.
  - OTP channel priority (e.g. WhatsApp → SMS), provider priority (ETECH KEYS → Twilio).
  - Mail (SMTP host/port/user/password/from) — used once Phase 8 has a server.
  Settings are read at runtime (not only `.env`), cached, and override `.env` when set.
- [ ] **9.2 Public contacts endpoint.** `GET /api/v1/public/support-contacts` → `{email, phone, whatsapp, partner_email}`. The app's Terms, Support, Invitation and Access-denied screens read it (replaces the made-up addresses). WhatsApp opens `https://wa.me/<number>`.
- [ ] **9.3 OTP delivery drivers.** `OtpChannel` interface with `EtechSmsDriver`, `EtechWhatsAppDriver`, `TwilioSmsDriver`, `TwilioWhatsAppDriver`; fallback through the configured priority; failures logged critical. DLR webhook `POST /api/v1/webhooks/etech/dlr` (`tel`, `etat`, `id`, `date`). Feature tests use `Http::fake()`.
- [ ] **9.4 Registration.** Phone (required) + password (required, min 8) + email (optional, UI marks "recommended"). User picks where to receive the verification code: WhatsApp or SMS for the phone, or email when given and mail works.
- [ ] **9.5 Login with phone + password.** `POST /api/v1/auth/mobile/password-login {phone, password, device}` returns the same token/bootstrap payload as OTP verify. Keep OTP login as an alternative ("Use a code instead"). Add forgot password: OTP to phone (WhatsApp/SMS) → set new password.
- [ ] **9.6 Verification temporarily lifted.** Setting `require_contact_verification` (default **off** until a delivery channel is proven working). When off, registration activates the account immediately and marks contacts unverified; the app shows a "verify later" banner. When on, the OTP/email verification is enforced.
- [ ] **9.7 Mobile.** Sign-up screen back to phone + password + optional email with "Recommended" hint and channel choice; sign-in screen phone + password, with "Use a code instead" and "Forgot password"; remove `throwawayPassword`.
- [ ] **9.8 Email.** Phase 8 runs only if the deploy account can install/use a mail server (it has limited sudo — see DEPLOYMENT.md §6). If not possible, email sending stays on the admin SMTP settings (9.1) and verification stays lifted (9.6).
- [ ] **9.9 Tests, deploy, live verification** for all of the above using Phase 6 steps, plus: set test ETECH/Twilio values in admin → OTP request logs an attempted provider call; register with phone+password → login with phone+password on live.
