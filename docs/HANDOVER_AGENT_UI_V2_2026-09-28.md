# Commercial Agent mobile UI v2.0 — handover (28 September 2026)

| Item | Value |
|---|---|
| Spec | `docs/AGENT_UI_SPEC_V2.md` (owner-locked; visual master = Agent Profile) |
| Tokens | `src/theme/agent.ts` (colours, type, layout, icon sizes, locked status vocabulary) |
| Commit | `ff2979c` on master |
| Over-the-air update | **`1e528a72`** "agent_ui_v2", runtime **1.5.1**, signed |
| Checks | `npm run verify`: typecheck, lint 0 errors, **280/280 tests** |
| Who gets it | Phones on APK **1.5.1** (restart the app twice). Phones on 1.5.0 get it only after installing 1.5.1: https://expo.dev/artifacts/eas/WZVe63pN2B98Po3sEHi4GaoT0RHItgslkW-6DMDi83M.apk |

## 1. What was built

### Shared agent kit (`src/components/agent/`, import from `@/components/agent`)
AgentShell (operational header: OPESINSURE wordmark + "Commercial Agent Portal", bell with live unread count, avatar → profile; drill-down header: back arrow + centred title; sticky footer; pull-to-refresh), AgentSection, AgentCard (white, 1px #E9EAEB, radius 18, no shadow), AgentNavRow (outline icon 22 navy, title, subtitle, status, chevron, 62 min height, whole row tappable), AgentStatusChip (height 26, locked vocabulary, EN/FR), AgentButton (primary blue 52h radius 14; secondary and danger outline), AgentEmptyState, AgentSkeleton, HeritageAccent (one subtle brand-art moment, opacity clamped 3–8%), AgentAvatar.

### Locked bottom navigation (agent portal only)
Home · Leads · Customers · Policies · Earnings; active gold #D89209, inactive navy #073656, white, top border #E9EAEB, 72 high, labels visible. Commission and withdrawal pages highlight Earnings. Broker and insurer bars unchanged.

### Screens
| # | Screen | Route | Notes |
|---|---|---|---|
| 01 | Agent Profile / Account Home | `/agent/account` | Hero with avatar, name, "Commercial Agent", verification + account status, agent ID, phone, email, broker/entity, region; Account, Preferences, Security, Support & Legal rows; Sign out securely. Missing fields show "Not provided". |
| 02 | Notification Settings | `/account/notifications` | Channels, Sales & policies, Claims, Earnings, Security (lock + REQUIRED). Save Preferences pinned. Unsupported toggles show "Not available yet". |
| 03 | Security & Sessions | `/account/security` | Account security rows, biometric switch, active sessions (current device marked), session detail, sign out everywhere (with step-up), recent security activity with Recognized / New Device / Suspicious chips. |
| 04 | Login Activity | `/account/login-activity` | One filter icon, grouped Today / Yesterday / date (Africa/Douala), repeated sign-ins collapsed, event detail at `/account/login-activity/[id]`. |
| 05 | Earnings Overview | `/agent/wallet` (Earnings tab) | Period selector, navy TOTAL EARNED hero, Available / Pending / Paid this month / Withdrawn tiles (tap to filter), search + filter icon, commission rows (amount first, status, customer, product · insurer, policy · date), withdrawal history, Request withdrawal. |
| 06 | Earnings Filters | sheet on Earnings | Date range, insurer, product, policy type, customer, (staff and branch only when data exists), commission status checkboxes, payment and policy status, Reset, "Show N commissions". |
| 07 | Commission Detail | `/agent/commissions/[id]` | Amount + status, policy information, commission calculation (server values only), timeline, View Policy, Request Withdrawal when available. |
| 08 | Withdrawal Detail | `/agent/withdrawals/[id]` | Amount + status, ID, method, masked account, requested time, status timeline, Contact Support (prefilled). |
| — | Request Withdrawal + confirmation | `/agent/withdrawal` | Restyled; MTN / Orange choice; step-up kept; errors shown on screen. |
| 09 | Language & Region | `/account/language` | English/Français segmented control, Save; country, currency XAF, date and time format examples, time zone. |
| 10 | Privacy & Legal | `/account/privacy` | Legal documents, Download my data, Request correction, Consent history, Delete my account via confirmation sheet with retention note. |

Screens 02, 03, 04, 09 and 10 are shared with customers: one implementation, agent layout when the active portal is agent, customer look otherwise.

### Fixes along the way
- Agents were silently redirected away from profile editing, language, notification settings, privacy, KYC and support because those routes were customer-only; they are now open to every signed-in user.
- Earnings tab highlight on detail pages; hard-edged pattern on the earnings hero.

## 2. Checked
Agent (+237600000101) at 360/390/430: profile, language, privacy, notification settings, security, login activity, earnings, filters, commission detail, withdrawal detail, request withdrawal — no overflow, no clipped text. Broker earnings still render. Customer versions of the shared screens pass the layout checks.
Not captured: session detail and login event detail screens. Nothing run on a real phone.

## 3. Decisions for you
- **"Sign out all other sessions":** the backend can only sign out everywhere, including this phone, so the button says "Sign out everywhere". An "others only" endpoint is listed below.
- **Earnings default period:** per the spec it opens on "This month". The demo agent has no commissions this month, so it first shows 0 FCFA and "No matching commissions" with "Clear filters". Say if it should open on "All time".
- **Agent Agreement, Commission Terms, Claims & Complaints Policy:** show "Available soon" until you provide their documents or URLs.

## 4. Backend needs (send to the backend session)
1. Notification preference keys per event: whatsapp, new_lead, quote_updates, policy_issued, policy_cancellation, and separate keys for each claim and earnings event.
2. Sign out all other sessions (excluding the current one) with step-up.
3. Password last-changed date; transaction PIN and two-factor status.
4. `city` on login-activity rows; recognized / new-device flag per session; pagination on login activity (last 50 today).
5. `GET /mobile/agent/commissions`: premium_minor, rate_bps, accrued_at, paid_at, paid_minor, clawed_back_minor, product_name, statement_number, rule_version, branch_id/name.
6. `GET /mobile/agent/withdrawals`: payout_number, failure_reason, reviewed_at / processing_at / paid_at / failed_at, estimated_settlement_at, masked destination with the last 3 digits.
7. An earnings summary endpoint (total earned, available, pending, paid this month, withdrawn) and pagination beyond the 100 commission / 50 withdrawal caps.
8. Agent region on the profile (shows "Not provided" today).
