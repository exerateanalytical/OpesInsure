# UI audit: broker, agent and customer web experience (2026-09-27)

Scope: the broker portal `/broker` (BROKER_ADMIN, BROKER_SUPERVISOR, BROKER_STAFF, BRANCH_MANAGER), agents (role `AGENT`), and the customer web account area `/account` ("My Account"). The admin and insurer panels, the provider portal, and claim payments and disputes belong to other audits.

Method: I rendered every page as each role, using the role's **governed default permissions** (`RoleCatalogue::defaultPermissions`). Earlier tests used `'*'`, which hid the real behaviour. I read the sidebar from the rendered HTML and requested every link in EN and FR. The crawl is kept as a regression test in `tests/Feature/Web/BrokerAgentNavCrawlTest.php`.

## 1. Where each account type works

| Account | Web area | Entry |
|---|---|---|
| BROKER_ADMIN / BROKER_SUPERVISOR / BROKER_STAFF / BRANCH_MANAGER | Filament `/broker` (`PortalAccess::PORTAL_ROLES['broker']`). Read-only by owner decision D4. | `PortalLogin` |
| Broker staff (write flows) | Partner workspace in `/account` (`/account/customers`, `/account/buy`, `/account/leads`, `/account/reports`, `/account/commissions`). Uses the `/mobile/broker/*` and `/mobile/partner/broker/*` APIs. | Web sign-in (API token) |
| AGENT | **No Filament panel.** Agents work only in the `/account` partner workspace (`agent.js` switches to agent mode when the session has `agent.clients.read`). Uses the `/mobile/agent/*` and `/mobile/partner/agent/*` APIs. | Web sign-in |
| CUSTOMER | `/account` (dashboard, policies, quotes, claims, payments, documents, vehicles, profile, notifications, support) and the buy page `/account/buy`. | Web sign-in |

## 2. What each broker role sees in `/broker` (before the fixes)

| Role | Sidebar | Dashboard widgets |
|---|---|---|
| BROKER_ADMIN | Dashboard, Organisation settings, Staff, Financial operations (Commission receivables, Settlements, Bordereaux) | Portal metrics tiles and My work queue only |
| BROKER_SUPERVISOR | Same as BROKER_ADMIN | Same |
| BROKER_STAFF | Same as BROKER_ADMIN | Same |
| BRANCH_MANAGER | Dashboard, Organisation settings, Policies, Claims | Metrics, My work, Expiring policies |

No role saw **Quotes**. BROKER_* roles did not see **Policies**, **Claims**, **Reports**, **Carrier–broker agreements**, **Expiring policies (renewals)** or **Open claims**. Every link returned 200: there were no 500s and no dead links.

## 3. Gaps found

### 3.1 Needs an owner decision (RBAC, not changed)
- **G1: `/broker` hides the book from broker roles.** The portal read gate (`PortalAuthorization::READ_PERMISSIONS`) needs `quotes.read` / `policies.read` / `claims.view`. The widgets need `policies.read` / `claims.read`, and Reports needs `reporting.kpis.view` / `finance.reports.view` / `reports.insurance.read`. The `BROKER_*` roles carry `broker.portal.read`, `quotes.manage` and so on, but none of those strings. So the Quotes, Policies and Claims resources, the renewals and open-claims widgets, and Reports exist in the broker panel, but no broker role can reach them. `/broker/policies` and `/broker/claims` return 403 for BROKER_ADMIN.
  I drafted a broker-panel read alias (BROKER_ADMIN only, tenant-scoped, read-only) and then **reverted it**, because it widens permissions. The owner must choose one of these:
  - (a) add `policies.read`, `claims.view` and `quotes.read` to `BROKER_ADMIN` (and possibly `reporting.kpis.view`) in `RoleCatalogue`;
  - (b) have the portal accept `broker.portal.read` for tenant-scoped reads, for ORGANIZATION-scope roles only;
  - (c) keep the book in the partner workspace only.

  Staff and supervisors have ASSIGNED/TEAM scope. The Filament resources scope only by tenant, so they should keep using the partner workspace APIs, which scope rows to the caller's own book.
- **G2: Carrier–broker agreements** need `distribution.agreements.view`, which no broker role has. The section is therefore invisible in `/broker`, although owner decision D4 exposes it read-only.
- **G3: `OpenClaimsWidget` needs `claims.read`, but the portal resources use `claims.view`.** BRANCH_MANAGER has `claims.view` only, so the widget never shows for them. Pick one string.

### 3.2 Flows with no entry button (fixed)
- **G4:** `/broker` had no way to start client onboarding, quoting for the book, leads or commission statements. The portal is read-only (D4), and these flows live in the partner workspace. **Fixed:** there is a new **Partner workspace** navigation group in `/broker` with My clients, New client, New quote, Leads, and Commissions & statements. Each link is shown only when the user holds the permission the target API already requires (`broker.portal.read`, `crm.leads.manage`, `quotes.manage`, `crm.leads.read`, `broker.finance.read`). These are links only; nothing is granted.
- **G5:** `/account/customers` had "New quote" but no **New client** button. Onboarding was hidden behind a "+ New client" toggle inside the buy page. **Fixed:** a New client button on the client list opens `/account/buy?new_client=1`, and the buy page opens the onboarding form straight away. The button is hidden unless the partner can onboard (agent: `agent.clients.manage`; broker: `crm.leads.manage`). The rule "partners quote only for their own attributed clients or new clients they onboard" is unchanged: the form still posts to `/mobile/agent/clients` or `/mobile/broker/clients`, which lock the client's origin to the partner (PartnerBook, 1827f54).

### 3.3 Flows with no web UI (open, not built in this pass)
- **G6: Agent-assisted claims (FNOL for a client).** `POST /mobile/partner/agent/claims` (AgentFnolController) exists, but `/account/claims/new` files only for the signed-in user. A client selector (from the book) and a policy picker on the client detail page are needed.
- **G7: Broker claims support.** `GET /mobile/partner/broker/claims` exists, but no web page lists the broker book's claims.
- **G8: Book lists.** Partner quotes and policies (`/mobile/partner/{agent,broker}/quotes|policies`) appear only as charts on `/account/reports` and per client on the client page. There is no list of the book's quotes, proposals or policies with status filters.
- **G9: Documents for the book.** `/account/documents` shows only the signed-in user's own documents. There is no way to see or download a client's certificate from the partner workspace.
- **G10: Broker staff management on the web.** `/broker/memberships` is read-only. The staff-invitation API (`POST /mobile/partner/broker/staff/invitations`) has no web form.

## 4. Design inconsistencies

| # | Finding | Status |
|---|---|---|
| D1 | FR: the Organisation settings page (nav label, title, every section, label, hint and the save button) was hard-coded English in all panels | **Fixed:** new `organisation_settings` lang file (EN/FR). The EN text is unchanged |
| D2 | FR: every Filament list page showed the raw key `filament-tables::table.result_count` (the FR package has no such key) | **Fixed:** `resources/lang/vendor/filament-tables/fr/table.php` |
| D3 | FR: the skip link on every panel page showed the raw key `filament-panels::layout.skip_to_content.label` | **Fixed:** `resources/lang/vendor/filament-panels/fr/layout.php` |
| D4 | FR: the sidebar group "Financial operations" was a literal English group key | **Fixed for `/broker`:** a panel group label (`partner_portal.financial_operations`). The admin panel still uses the literal (admin audit) |
| D5 | Commission receivables, Settlements and Bordereaux columns showed raw attribute names ("Amount minor", "Policy id", "Net amount minor", "Gross premium minor") in both languages | **Fixed:** translated column labels (`partner_portal.columns.*`) |
| D6 | The same columns used Filament `->money(divideBy: 100)` ("XAF 500.00"). The rest of the UI uses `Money::display` ("500 FCFA", locale grouping) | **Fixed** on those three resources. Twelve other admin resources still use `divideBy` (admin audit) |
| D7 | Status badges on those three lists had no colour; the rest of the UI uses `RecordInfolist::color` | **Fixed** |
| D8 | Settlement period dates were raw ISO strings | **Fixed** (`->date()`) |
| D9 | The Staff list (`/broker/memberships`) title "Tenant Memberships" and its columns (User, Organization, Branch, Primary role) are English in FR. Role codes are shown raw (`BROKER_STAFF`) | Open (shared MembershipResource) |
| D10 | Bordereaux URL is `/broker/bordereaux/bordereaus` (auto-pluralised slug) | Open. Changing the slug also changes admin URLs |
| D11 | Commission receivables show a copyable policy UUID instead of the policy number (the model has no `policy` relation) | Open |
| D12 | Icons: the resources declare Heroicons, and `LucideIcons` swaps them centrally when the panel is served. The new workspace links use `lucide-*` directly | OK |
| D13 | List pages open view pages (ViewAction plus row click) on all six broker resources | OK |
| D14 | `/account` EN/FR key sets for account, account_*, desk, site, dashboards and web_experience are in sync | OK |

Duplicates: none added. The broker portal reuses the admin resources through `PortalPanelFactory`, the workspace links go to the existing `/account` pages, and money and status formatting reuse `Money::display` and `RecordInfolist::color`.

## 5. Files changed
- `app/Providers/Filament/BrokerPanelProvider.php`: Partner workspace nav group and links; translated "Financial operations" label.
- `app/Filament/Shared/Pages/OrganizationSettings.php`, `resources/views/filament/shared/pages/organisation-settings.blade.php`: translated.
- `app/Filament/Admin/Resources/{CommissionAccruals,CarrierSettlements,Bordereaux}/*Resource.php`: column labels, money, status colour, dates (table only).
- `resources/views/public/account/pages/customers.blade.php`, `buy.blade.php`: New client entry point.
- `resources/lang/{en,fr}/partner_portal.php`, `resources/lang/{en,fr}/organisation_settings.php`, `resources/lang/vendor/filament-{tables,panels}/fr/*.php`.
- `tests/Feature/Web/BrokerAgentNavCrawlTest.php`: crawls every `/broker` nav URL per broker role in EN and FR (no 500, 403 or 404, no raw translation keys), crawls every `/account` side-nav link in EN and FR, and checks the workspace-link visibility and the New client entry point.

## 6. Owner actions
1. Decide G1 (a, b or c) and G2 (whether broker roles get `distribution.agreements.view`).
2. Decide which claim-read string is canonical (G3).
3. Prioritise G6–G10 for the next build batch.
