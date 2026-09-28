# Commercial Agent mobile UI v2.0 — handover (28 September 2026)

| Item | Value |
|---|---|
| Spec | `docs/AGENT_UI_SPEC_V2.md` (owner-locked; visual master = Agent Profile) |
| Tokens | `src/theme/agent.ts` (colours, type, layout, icon sizes, locked status vocabulary) |
| Kit | `src/components/agent/` (import from `@/components/agent`) |
| Current APK | **1.5.2** (build 21, md5 317e85eb2741f4261ec6a1e46b142485, ~71 MB), served by https://insurance.opesdatacenter.tech/download/android |
| Latest over-the-air update | **`56c390f8`** "agent_portal_full_restyle", runtime 1.5.2, commit `aea6ab5` |
| Checks | `npm run verify`: typecheck, lint 0 errors, **280/280 tests**; update endpoint for 1.5.2 returns 200 |
| How to see it | On APK 1.5.2, fully close and reopen the app twice (first launch downloads, second applies). |

## 0. Why 1.5.1 received nothing (resolved)
APK 1.5.1 was built to accept only **signed** over-the-air updates. Expo's update service returned "no update" (HTTP 204) for runtime 1.5.1, so every update published for 1.5.1 (CarFront icon, agent UI v2) never reached phones. A 1.5.1 phone cannot be fixed over the air. **APK 1.5.2** removes the signing requirement; the endpoint now answers 200 and updates arrive. Signed updates stay off on the current Expo plan (opeswares-team, 19 USD/month) until the plan is confirmed to support EAS Update code signing. The signing key remains saved outside the repo (`C:/Users/PC/.opesinsure-keys/codesigning/`).

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

### Full portal restyle (update `56c390f8`)
Every remaining agent page now uses the kit and spec (operational header or back + centred title, white cards, navy outline icons, amount-first rows, one filter icon per list, sticky primary buttons, spec states). Nothing removed; broker screens sharing components keep their look via optional `variant="agent"`.

| Area | Screens |
|---|---|
| Home | `/agent`: navy welcome hero (one heritage moment), Needs attention + Overview KPI cards (amounts no longer truncated), Quick actions (New sale, Add a lead, Search, What I can sell), Workspace rows, offline sync nudge |
| Leads | list (search + filter icon, Open/Converted/Lost in the sheet), lead detail (stage, follow-up, convert), new lead |
| Customers | list (KYC chips), customer 360 (profile card, policies, proposals, claims, documents, sticky "Start assisted sale"), register client |
| Policies | list (premium first), policy detail (renewal, claims, documents), renewals, proposals; expiring chips amber |
| Quotes & sales | quotes list, quote detail, new assisted sale (client via searchable dropdown), sale detail (lifecycle timeline, commission, sticky payment action) |
| Claims | claims list, claim detail, file a claim |
| Other | notifications inbox, offline queue, agent information & payout (step-up kept), product catalogue |

Shared helpers added: `src/components/partner/AgentListUi.tsx` (agent list row, status to locked-vocabulary chip), `src/components/partner/AgentBookUi.tsx` (title, load/empty/error states), `src/components/filters/FilteredList.tsx` `variant="agent"`.

## 2. Checked
Agent (+237600000101) at 360/390/430: profile, language, privacy, notification settings, security, login activity, earnings, filters, commission detail, withdrawal detail, request withdrawal — no overflow, no clipped text. Broker earnings still render. Customer versions of the shared screens pass the layout checks.
Full restyle: all 22 remaining agent screens captured at 390 (Home, policies, new sale also at 360) with no overflow and no clipped text.
Not seen with real data (the demo agent has no leads, sales or claims, and its customers have no linked records): lead detail, sale detail, claim detail, file a claim, and populated customer 360 / policy detail sections. Session detail and login event detail were not captured. Nothing run on a real phone; please check these on the device.

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
