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

### 3.3 Flows that had no web UI (fixed in the follow-up pass)
- **G6: Agent-assisted claims (FNOL for a client). Fixed.** New page `/account/customers/{id}/claim`, reached from a "File a claim" button on the client page. The button is shown to agents with `agent.clients.manage`. The page offers the client's active policies and posts to the existing `POST /mobile/partner/agent/claims`. That endpoint calls `FnolService::submitForCustomer`, which refuses a claimant outside the agent's book (tested). Brokers now file through `POST /mobile/partner/broker/claims` (`broker.claims.file`, granted to BROKER_ADMIN/SUPERVISOR/STAFF; `FnolService::submitForBrokerClient`, book = `BookScope::bookOf`), so staff file only for their own clients, supervisors for their team's.
- **G7 and G8: Book lists. Fixed.** New page `/account/book` (side nav "My Book"), with tabs for Quotes, Proposals, Policies and Claims, plus search.
  - New read endpoints: `GET /mobile/partner/agent/proposals`, `GET /mobile/partner/agent/claims` and `GET /mobile/partner/broker/proposals`.
  - They use the partner permissions the existing lists already use (`agent.clients.read` / `broker.portal.read`).
  - The existing broker claims list now uses the same shared query (`App\Application\PartnerWorkspace\PartnerBookQuery`), so agents and brokers are scoped identically. Rows are limited to `PartnerWorkspaceScope::bookPartyIds`.
- **G9: Documents for the book. Fixed.** There is a Documents section on the client page, backed by `GET /mobile/partner/{agent,broker}/clients/{id}/documents`.
  - A client outside the caller's book returns 404.
  - Only issued documents on the client's policies are listed, filtered by the new `DocumentAccessPolicy::intermediaryMay`: the customer-visible levels and A1/A2 profiles, **minus medical**. Insurer-confidential, internal and regulatory documents never appear.
  - Downloads use the existing short-lived signed route `mobile.policy-documents.download`.
- **G10: Staff invitation form. Fixed.** New page `/account/staff` (side nav, shown only with `broker.portal.read`).
  - It lists members and pending invitations from `GET /mobile/partner/broker/staff`.
  - The invite form posts to the existing `POST /mobile/partner/broker/staff/invitations` (`InvitationService::issue`). The form appears only when the API reports `can_invite`, and the API still enforces BROKER_ADMIN.
  - To hide the item from agents, the side nav gained an optional `data-perm` attribute (`portal.js`).

## 4. Design inconsistencies

| # | Finding | Status |
|---|---|---|
| D1 | FR: the Organisation settings page (nav label, title, every section, label, hint and the save button) was hard-coded English in all panels | **Fixed:** new `organisation_settings` lang file (EN/FR). The EN text is unchanged |
| D2 | FR: every Filament list page showed the raw key `filament-tables::table.result_count` (the FR package has no such key) | **Fixed:** `resources/lang/vendor/filament-tables/fr/table.php` |
| D3 | FR: the skip link on every panel page showed the raw key `filament-panels::layout.skip_to_content.label` | **Fixed:** `resources/lang/vendor/filament-panels/fr/layout.php` |
| D4 | FR: the sidebar group "Financial operations" was a literal English group key | **Fixed for `/broker`:** a panel group label (`partner_portal.financial_operations`). The admin panel still uses the literal (admin audit) |
| D5 | Commission receivables, Settlements and Bordereaux columns showed raw attribute names ("Amount minor", "Policy id", "Net amount minor", "Gross premium minor") in both languages | **Fixed:** translated column labels (`partner_portal.columns.*`) |
| D6 | The same columns used Filament `->money(divideBy: 100)` ("XAF 500.00"). The rest of the UI uses `Money::display` ("500 FCFA", locale grouping) | **Fixed** on those three resources, then on the remaining 12 admin resources (14 columns). No `divideBy` money column is left in `app/Filament` |
| D7 | Status badges on those three lists had no colour; the rest of the UI uses `RecordInfolist::color` | **Fixed** |
| D8 | Settlement period dates were raw ISO strings | **Fixed** (`->date()`) |
| D9 | The Staff list (`/broker/memberships`) title "Tenant Memberships" and its columns (User, Organization, Branch, Primary role) were English in FR, and role codes were shown raw (`BROKER_STAFF`) | **Fixed:** translated titles, columns, status values, filter, revoke label and empty state (`partner_portal.staff/statuses/roles`). Broker role codes show as labels; other roles fall back to `RoleCatalogue::LABELS` |
| D10 | Bordereaux URL is `/broker/bordereaux/bordereaus` (auto-pluralised slug) | Fixed. Slug is now `bordereaux` (`/admin|broker|insurer/bordereaux`); old URLs 301 via `RedirectLegacyBordereauxUrls` |
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

### Follow-up pass (same day)
- `app/Application/PartnerWorkspace/PartnerBookQuery.php` (new, shared by the agent and broker controllers), `app/Application/Documents/Engine/DocumentAccessPolicy.php` (`intermediaryMay`, additive).
- `app/Interfaces/Http/Controllers/Api/V1/PartnerWorkspace/{PartnerAgentWorkspaceController,PartnerBrokerWorkspaceController,PartnerWorkspaceShapes}.php`, `routes/wave16_partner.php`: five GET endpoints.
- `resources/views/public/account/pages/{book,staff}.blade.php`, `pages/customers/show/claim.blade.php`, `pages/customers/show.blade.php`, `layout.blade.php`, `public/landing/portal/{agent,portal}.js`, `resources/lang/{en,fr}/{account,account_agent,partner_portal}.php`.
- `app/Filament/Admin/Resources/Memberships/MembershipResource.php` (FR labels), and 12 admin resources (money display).
- `tests/Feature/Partners/PartnerWorkspaceBookTest.php` (book scoping, client documents and levels, assisted FNOL book check, invitation gate, pages in EN/FR). The crawl test also covers `/account/book`, `/account/staff` and the French Staff page.

## 6. Owner actions
1. Decide G1 (a, b or c) and G2 (whether broker roles get `distribution.agreements.view`).
2. Decide which claim-read string is canonical (G3).
3. Prioritise G6–G10 for the next build batch.
