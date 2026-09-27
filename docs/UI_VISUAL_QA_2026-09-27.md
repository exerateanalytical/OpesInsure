# UI visual QA — 2026-09-27

A crawl of every web panel on a **local** copy of the app, run per demo role at 1366 px (EN and FR) and at 375 px mobile width.
Screenshots are in `docs/qa-screenshots/`.

## Setup (local only, nothing deployed)

- **Database.** The shared local DB `opesinsure` is behind the code (the `2026_10_19_*`–`2026_10_31_*` migrations are pending, and every dashboard fails with `relation "kpi_definitions" does not exist`). It was **not touched**. A separate DB, `opesinsure_qa`, was created and brought up with `migrate --force`, `db:seed` and `demo:seed` (demo mode on).
- **Server.** A HEAD snapshot was served with `artisan serve` on 127.0.0.1:8097. Its `vendor` folder is a junction to the repo's, so PHP classes load from the live working tree, while Blade views and lang files come from the HEAD snapshot. Two provider findings below are artefacts of that mix and are marked as such.
- **QA-only accounts.** Some roles have no seeded account. For these, users were created **only in `opesinsure_qa`** by a scratch script, with generated test passwords: `qa-insurer-admin@opesinsure.test` (CARRIER_ADMIN), `qa-broker-admin@opesinsure.test` (BROKER_ADMIN) and `qa-provider@opesinsure.test` (PROVIDER_ADMIN, employed by a QA test clinic). No repo seeder was changed.
- **Dark mode.** Skipped: the panels set `darkMode(false)` by owner design.
- **Crawler.** A headless Chrome script signs in each role and opens the dashboard, every sidebar item (EN and FR) and the first record's detail page. It then opens the dashboard and the first 4 nav items at 375 px. On each page it checks for 5xx errors, page-level horizontal overflow, raw translation keys in the text, broken images and empty icons.

| Role | Account | Surface | Page views |
|---|---|---|---|
| System admin | admin@opesinsure.local | /admin | 250 |
| Claims manager (insurer staff in /admin) | demo-claims-manager | /admin | 90 |
| Insurer staff | demo-mobile-insurer (CARRIER_STAFF) | /insurer | 42 |
| Insurer admin | qa-insurer-admin (QA) | /insurer | 56 |
| Broker staff | demo-broker-staff | /broker | 26 |
| Broker admin | qa-broker-admin (QA) | /broker | 27 |
| Agent | demo-agent | /account (the /broker panel returns 403 for AGENT, as intended) | 45 |
| Customer | demo-customer | /account | 45 |
| Provider | qa-provider (QA) | /provider | 45 |

No page had page-level horizontal overflow at 375 px, and no broken images or empty icons were found.

## Fixed

All fixes are in shared places.

1. **The provider panel lost its Tailwind layout** (`resources/css/filament/admin/theme.css`).
   - The theme's `@source` only scanned `app/Filament` and `resources/views/filament`.
   - The provider workspace renders `resources/views/provider-workspace/*` from `app/Application/Providers/Workspace/Filament`, so classes such as `md:grid-cols-4` were never generated. The provider dashboard stacked 26 KPI cards in one column.
   - Added `@source` entries for both paths.
   - Screenshots: `01-provider-dashboard-fr-before.png` → `01-provider-dashboard-fr-after.png`.
2. **24 admin detail pages were blank.**
   - Their `View*` pages extended `ViewRecord`, and their resources had no infolist and an empty form. Examples: compliance case, data subject request, journal, policy issuance, reconciliation, support ticket, underwriting case, claim payment.
   - They now extend the shared `App\Filament\Shared\Pages\RecordDetailPage`, which shows the Details, Status & provenance and Audit & history sections through `RecordInfolist`. The pages' own header actions (Transition, etc.) are kept.
   - Screenshots: `03-…-before.png` → `03-…-after.png`.
3. **Row action "View" / "Voir" was misaligned.**
   - Because of the theme's 44 px `.fi-link` min-height, Filament's baseline-aligned `.fi-link-label` sat at the top of the box while its icon was centred. This affected every table.
   - The label is now centred, and row actions are kept on one line.
   - Screenshots: `02-admin-quotes-fr-before.png` → `02-admin-quotes-fr-after.png`.
4. **The table scroll-edge cue never rendered.** The theme targeted `.fi-ta-content` (Filament v3), but v4 names the scroller `.fi-ta-content-ctn`. Both are now targeted, so wide tables (quotes, provider claims) show the scroll shadow.
5. **Sidebar labels were cut off.** Long FR labels were truncated with "…" (for example "Signalements de l'appl…", "Questionnaires de déc…", "Lots de règlement pre…"). They now wrap onto two lines in the 264 px sidebar (visible in the 01/02 after shots).
6. **FR boolean columns showed a raw key.** Every `IconColumn::boolean()` rendered the raw `table.columns.icon.boolean.true` as its accessible label, because the FR Filament tables pack lacks the key. Oui/Non were added in `resources/lang/vendor/filament-tables/fr/table.php`. Pages affected: approval matrix, trusted devices, coverages, vehicle makes, models and reference values.
7. **Long IDs and dates broke mid-token.** `.oi-value` used `overflow-wrap:anywhere`, which let IDs and dates break inside the token. It now uses `break-word`, which only breaks when the value would overflow.

`public/build` was rebuilt with `vite build`.

## Open findings (not fixed here)

- **Untranslated FR page titles and column headers in /admin, /insurer and /broker.**
  - The nav is translated through `LocalizedNavigationManager` and `lang/*/navigation.php`, but resource titles, breadcrumbs and column labels are not. Examples: "Quotes", "Approval Matrix Rules", and the columns "Line code", "Channel", "Expires at", "Claim number", "Billed minor".
  - Seen in `02-*`, `04-*` and `06-*`.
  - Status badges ("Accepted", "Offered") and "not configured" / "Platform" also stay in English.
  - Needs a column-label translation pass: for example `Column::configureUsing(->translateLabel())` plus a FR JSON dictionary, or `__()` per resource.
- **Provider dashboard KPI labels are English and upper-case in FR** ("SUBMITTED CLAIMS"…), see `01-…-after.png`. The provider workspace pages were being edited in the working tree by another session, so they were left alone.
- **"minor" money columns are shown raw** in the insurer health queues ("Billed minor", "Allowed minor"), see `06-insurer-provider-claims-fr.png`. They should use `Columns::money`. `HealthQueuePage` was being edited by another session.
- **The insurer policy detail ends in an empty divider** after the header card, see `05-insurer-policy-detail.png`. The cover period also wraps at the date hyphen ("2026-10-\n22").
- **The table filter button always shows a "0" badge**, even with no filters active, on the broker and insurer lists. See `07-broker-staff-m375.png` and `06-*`.
- **The claims manager sees Policies in /admin but gets 403 on a policy detail.** This is an RBAC inconsistency between the list and view policies.
- **The Data readiness page prints raw catalogue codes** (`kyc.risk.factors.customer`, `v1.0.0`) as body text. This looks intentional, since they are codes, but they read like untranslated keys.
- **Customer and agent /account.** No overflow at 375 px (`09-customer-policies-m375.png`). FR is fully translated (`08-customer-dashboard-fr.png`). The dashboard widgets load slowly ("Chargement…" after 4 s on the local `artisan serve`).
- **Snapshot artefacts, not product bugs.** The provider "User management" page returned 500 (`View [provider-workspace.users-form] not found`), and raw `provider_workspace.ui.*` keys appeared on the provider Integration page. Both came from HEAD views and lang files being combined with uncommitted PHP from the working tree. Re-check once that work is committed.
- **Crawler artefact.** Sidebar labels are sometimes hidden in full-page screenshots because Filament's `x-transition` hadn't finished. Viewport screenshots are fine.
