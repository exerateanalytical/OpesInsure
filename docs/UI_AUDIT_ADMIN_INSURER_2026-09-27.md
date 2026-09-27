# UI audit: platform admin, insurer admin and insurer staff (2026-09-27)

Scope: `/admin` (platform/system admins and the back-office roles that enter it) and `/insurer` (CARRIER_SUPER_ADMIN, CARRIER_ADMIN, CARRIER_STAFF, UNDERWRITER, SENIOR_UNDERWRITER, REINSURANCE_OFFICER, CUSTOMER_SERVICE, ADJUSTER). Provider portal/health screens, claim payments/disputes and the broker/agent panels were out of scope (other agents).

**Method.** `tests/Feature/Web/AdminInsurerNavCrawlTest.php` signs in as each role with the role's **catalogue default permissions** (`RoleCatalogue::defaultPermissions`, what production roles get; not `*`), renders the panel home, reads the sidebar that role actually gets (group + label + URL), requests every nav URL and the first row's view page of every list. Set `UI_AUDIT_DUMP=<dir>` to write the per-role navigation and results as JSON. `CRAWL_ONLY=ROLE1,ROLE2` restricts the crawl.

## 1. What each role sees (after the fixes)

| Role | Panel | Nav items | Groups / notable items | Dashboard widgets |
|---|---|---|---|---|
| SYSTEM_ADMIN | admin | 125 | Everything except business-data lists hidden by REQ-RBAC-004 | Account, My work, Recent activity (business widgets hidden by design) |
| PLATFORM_ADMIN | admin | 135 | Administration, Trust & compliance, Operations, Approvals, Customers & partners, Products & pricing, Sales workspace, Underwriting, Health, Distribution, Financial operations, Policy operations, Claims operations, Integrations, CIMA dictionary, Institutional directory, Document engine, Document catalogue, Vehicle master data, Master data, Cases & tasks | Premium chart, My work, Expiring policies, Open claims, Recent activity |
| CLAIMS_MANAGER (`*`) | admin | ~40 | Claims operations, Approvals, Health, Cases & tasks, Financial operations | as above |
| CLAIMS_OFFICER | admin | 19 (was 28) | Claims operations (claims, reserves, decisions), Sales workspace, Cases & tasks | My work, Open claims |
| FINANCE_MANAGER (`*`) | admin | 37 | Financial operations, Approvals, Claim payments, Cases & tasks | all |
| CARRIER_SUPER_ADMIN | insurer | 8 (was 5) | Dashboard, Organisation settings, Reports, Policies, Claims, Contracts & delegated authority, Settlements, Bordereaux | Metrics, Premium chart, My work, Expiring policies, Open claims |
| CARRIER_ADMIN | insurer | 8 (was 5) | same as super admin | same |
| CARRIER_STAFF | insurer | 6 (was 4) | Dashboard, Organisation settings, Policies, Claims, Settlements, Bordereaux | Metrics, My work, Expiring policies, Open claims |
| UNDERWRITER / SENIOR_UNDERWRITER | insurer | 3 | Dashboard, Organisation settings, Policies | Metrics, My work, Expiring policies |
| REINSURANCE_OFFICER | insurer | 5 | Dashboard, Organisation settings, Reports, Policies, Claims | all list widgets |
| CUSTOMER_SERVICE | insurer | 4 | Dashboard, Organisation settings, Policies, Claims | list widgets |
| ADJUSTER | insurer | 3 | Dashboard, Organisation settings, Claims | My work, Open claims |

Result: **no nav URL returns 500, 403 or 404 for any of the 13 roles**, and every list row that exists opens its view page (200).

## 2. Findings and fixes

| # | Finding | Severity | Fix |
|---|---|---|---|
| F1 | `resources/lang/{en,fr}/web_experience.php` declared the key `sections` **twice**; the second array silently replaced the first, so the sidebar showed raw keys `web_experience.sections.agreements / receivables / settlements / bordereaux` in **both** panels and both languages. | High | Merged the two arrays (commit 1c943cd). The crawl now fails on any `a.b.c`-style label. |
| F2 | CARRIER_SUPER_ADMIN, CARRIER_ADMIN and CARRIER_STAFF saw **no Policies and no Claims** in `/insurer`, and empty Expiring policies / Open claims widgets. Portal reads checked `policies.read` / `claims.view` / `claims.read`, but insurer roles hold the `carrier.issuance.read` / `carrier.claims.read` names the carrier APIs use. ADJUSTER (has `claims.view`) did not get the Open claims widget (`claims.read`). | High | `PortalAuthorization::EQUIVALENT_READS` + `allowsRead()`; used by the portal gate and by `RecordListWidget::canView()` (one map, no duplication). |
| F3 | CARRIER_ADMIN / SUPER_ADMIN could not reach *Contracts & delegated authority* although `config/permissions.php` suggests `distribution.agreements.view` for them; `RoleCatalogue` never granted it. | Medium | Added `distribution.agreements.view` to `CARRIER_ADMIN_PERMISSIONS` (inherited by super admin). Existing tenant roles pick it up with `php artisan rbac:sync-role-permissions` (SyncRolePermissions). |
| F4 | Nine admin resources used `viewAny = any active membership`: Compliance cases, Data subject requests, Risk alerts, Commission rules, Commission receivables, Partner statements, Payout requests, Bordereaux, Settlements. A CLAIMS_OFFICER saw (and for rules/statements/payouts/bordereaux/settlements could **create**) finance and compliance records. | High (RBAC) | `viewAny` now also needs the permission the API requires: `compliance.cases.read`, `compliance.dsr.receive`, `fraud.alert.create|decide`, `commission.manage`, `commission.read`, `statements.read`, `commission.read|statements.read`, `bordereaux.view`, `settlement.read`. Portal access is unchanged (decided first by `PortalAuthorization`). |
| F5 | Four sidebar labels were duplicated (`Dashboard` ×4, `Product mapping` ×2, `Document types` ×2) and `Health` existed both as a group and as an item under Integrations. | Medium | Renamed centrally: *CIMA overview*, *Document engine overview*, *Master data overview*, *Document register*, *Product documents*, *CIMA product mapping*, *Integration health*. The crawl asserts no duplicate URL and no duplicate label within a group. |
| F6 | The admin sidebar was **English only** for French users (≈130 hard-coded labels and 21 groups), mixed with some already-translated labels: mixed EN/FR in one sidebar. | High | `App\Filament\Shared\LocalizedNavigationManager` (bound in `WebExperienceServiceProvider`) relabels every panel's sidebar from `resources/lang/{en,fr}/navigation.php`. Labels already translated via `__()` are untouched. |
| F7 | Casing mixed Title Case (model-derived: "Compliance Cases", "Payment Intent Records") and sentence case ("Approval inbox"). | Low | The same manager sentence-cases labels and groups (acronyms kept: CIMA, QR, FR/EN). Table-named labels renamed: *Customers* (was Tenant Customers), *Payment requests* (Payment Intent Records), *Sticker stock*. |
| F8 | Eight admin items (Organizations, Branches, Access & roles, Invitations, Trusted devices, Platform users) sat ungrouped at the top next to Dashboard and Reports. | Low | New **Administration** group (Membership stays ungrouped as *Staff* in the portals). |
| F9 | Claim reserve changes and Claim decisions lists had no view page and rows were not clickable. | Medium | Rows open the parent claim's view page (Reserves / Decisions tabs live there). A test asserts every admin/insurer resource opens a record (view/edit page or row URL). Approval inbox keeps its row *Details* slide-over. |
| F10 | 12 Heroicons were not mapped to Lucide (Letterhead designer, Physical security assets, some actions). | Low | Added to `LucideIcons::MAP` (commit 1c943cd). Action buttons that pass a Heroicon directly (e.g. `PaperAirplane` on template submit) are still Heroicons: new code must use `lucide-*` names. |
| F11 | The admin dashboard showed Filament's own *FilamentInfoWidget* (framework version, GitHub/docs links) to every user. | Low | Removed from `AdminPanelProvider`. |

## 3. Gaps not fixed (need an owner decision or belong to another area)

1. **FINANCE_OFFICER, CASHIER, BRANCH_MANAGER-for-insurers, DEVELOPER, REGULATOR have no web panel.** FINANCE_OFFICER is in neither `User::canAccessPanel()` nor `PortalAccess::PORTAL_ROLES['insurer']`; the crawl confirms a clean 403 (never 500). The owner must say whether insurer finance officers use `/insurer` (then add the role and a Finance section) or `/admin`.
2. **Approval inbox not reachable from `/insurer`.** CARRIER_ADMIN/SUPER_ADMIN hold `approvals.inbox.view` and `approvals.decide`, but `ApprovalRequestResource` is only in `/admin`, which refuses CARRIER_* roles. Needs `InsurerPanelProvider` (currently being edited by the provider/health agent) to register it; the resource is already tenant-scoped.
3. **Insurer permissions with no UI:** `carrier.referrals.read/decide` (referral queue), `carrier.quote_requests.view/respond`, `policies.issuance_queue.view/manage`, `stickers.*`, `providers.manage`, `special_policies.*`, `coinsurance.*`, `reinsurance.treaties/cessions.view`, `ledger.*`, `fx.rates.*`, `cashier.sessions.*`, `documents.retention/legal_hold/destruction.*`, `parties.merge.*`, `kyc.*`. The admin resources for several of these exist (Policy issuance requests, Sticker batches, Parties) but are not registered in `/insurer`; the rest are API-only. Recommended next batch: register *Policy issuance requests*, *Underwriting cases* (referrals) and *Quotes* read-only in `/insurer` behind the carrier.* permissions (same pattern as D4 sections).
4. **Policies/claims in `/insurer` are narrowed by tenant, not by carrier.** Correct while one insurer = one tenant (current seed). If a tenant ever holds memberships for several carriers, apply `PortalScope::narrowToCarrier()` to `PolicyResource`/`ClaimResource` as Bordereaux already do.
5. **Underwriters have no dashboard work items for their queue** (referrals, underwriting cases); My work shows cases/tasks only.
6. **`TenantCustomerPolicy::viewAny` and several `create = viewAny` policies** (claim reserves/decisions/payments/disputes, invitations) still accept any member; the claim ones are behind the claim workflow actions' own permission checks and belong to the claims agent's area.
7. **Formats.** Money: most admin lists use Filament `->money($currency, divideBy: 100)` while portal sections moved to `Money::display()` (XAF has no minor unit in display). Dates: `->dateTime()` default format everywhere, not the tenant timezone/locale format. A shared column helper should replace both (ListScreen is the natural place).
8. **Badge tones.** Status badges on legacy resources use Filament's default grey; the core records use `StatusBadge` tones. Not changed here (100+ files).
9. **Organisation settings** sits in the *Integrations* group in `/admin`; the page (`Shared/Pages/OrganizationSettings.php`) was being edited by another agent, so it was not moved. It should go to *Administration*.

## 4. Tests
- `tests/Feature/Web/AdminInsurerNavCrawlTest.php`: 13-role crawl (no 500, no 403/404 behind a visible link, no raw translation keys, no duplicate URLs/labels per group); insurer admins/staff reach Policies, Claims, Agreements and the widgets show their book; sentence-case, unambiguous and French sidebar; every list opens a record; roles with no panel get a clean refusal.
