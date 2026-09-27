# RBAC matrix: broker and carrier roles (owner decision 2026-09-27)

Source of truth for grants: `app/Application/Identity/RoleCatalogue.php`. Portal read gate: `PortalAuthorization` (READ_PERMISSIONS, PORTAL_SECTIONS, EQUIVALENT_READS). Row scoping: `PortalScope::narrowTable` / `narrowStaff` / `brokerPartnerId`, built on `Partners\BookScope` and `Identity\CarrierScopeResolver`. Enforced by `tests/Feature/Rbac/BrokerCarrierRbacMatrixTest.php`.

## 1. Data scope

| Role | DataScope | Rows seen in the portal |
|---|---|---|
| BROKER_ADMIN | ORGANIZATION | whole company book: parties with an ACTIVE customer_attribution to the user's broker Partner. No partner link: nothing in a shared tenant, the whole tenant in a BROKER tenant |
| BROKER_SUPERVISOR | TEAM | clients recorded (`customer_attributions.recorded_by`) by members of the user's `team_code`s (no team = self) |
| BROKER_STAFF | ASSIGNED | clients the user recorded |
| AGENT | ASSIGNED | no Filament panel; `/account` partner workspace, own agent book |
| BRANCH_MANAGER | BRANCH | clients recorded by users of the manager's branches |
| CARRIER_SUPER_ADMIN, CARRIER_ADMIN, CARRIER_STAFF | CARRIER_RELATIONSHIP | rows of `tenant_memberships.carrier_id` (policies.carrier_id, claims via policy, quotes via quote_offers, proposals via offer, underwriting_cases.carrier_id, KYC of the carrier's policyholders). Unlinked: tenant-wide only in an INSURER/CARRIER tenant, else refused |
| UNDERWRITER, SENIOR_UNDERWRITER, REINSURANCE_OFFICER, CUSTOMER_SERVICE | CARRIER_RELATIONSHIP | same as above (usually unlinked in the insurer's own tenant) |
| ADJUSTER | ASSIGNED | insurer panel claims (carrier narrowing) |

The tenant filter is always applied first. Narrowing covers the Quote, Policy and Claim resources, their detail pages (out-of-scope id = 404), every portal KPI record set (dashboard tiles, Expiring policies, Open claims), the staff list, and carrier agreements (portal and `GET /api/v1/carrier-broker-agreements`).

## 2. /broker panel

| Page / resource / widget | Permission checked | BROKER_ADMIN | BROKER_SUPERVISOR | BROKER_STAFF | BRANCH_MANAGER |
|---|---|---|---|---|---|
| Dashboard `/broker` | portal membership | yes | yes | yes | yes |
| Organisation settings | portal membership | yes | yes | yes | yes |
| Quotes `/broker/quotes` | quotes.read or broker.portal.read | company | team | own | no (403) |
| Policies `/broker/policies` (renewals) | policies.read or broker.portal.read | company | team | own | branch |
| Claims `/broker/claims` | claims.view or broker.portal.read | company | team | own | branch |
| Carrier agreements and commission terms | distribution.agreements.view | own company | no | no | no |
| Commission receivables | broker.finance.read | own partner | own partner | own partner | no |
| Settlements, Bordereaux | broker.finance.read | tenant (carrier-keyed) | same | same | no |
| Staff `/broker/memberships` | broker.portal.read | company colleagues | team | self | no |
| Reports `/broker/reports` | finance.reports.view / reporting.kpis.view / reports.insurance.read | no (403) | no | no | no |
| Workspace links (clients, new client, new quote, leads, commissions, reports) | broker.portal.read, crm.leads.manage, quotes.manage, crm.leads.read, broker.finance.read | links to `/account/*` | same | same | leads only |
| Widgets: metrics tiles, Expiring policies, Open claims | policies.read / claims.view (equivalents) | scoped | scoped | scoped | scoped |
| Widgets: Premium collected, Recent activity | finance.reports.view / audit.read | no | no | no | no |
| Actions | portal is read-only (D4); every non-view ability refused | none | none | none | none |

Why the generic strings are not granted to broker roles: `policies.read`, `claims.view`, `quotes.read` and the report permissions also open tenant-wide core APIs (`/api/v1/policies`, `/api/v1/claims`, finance and KPI reports). In a shared tenant that would expose other brokers' data. The portal therefore accepts `broker.portal.read` as the equivalent read (`EQUIVALENT_READS`) and narrows rows. Book reports are in `/account/reports`.

## 3. /insurer panel (roles' default permissions)

| Page | Permission (any) | CSA | CA | CS | UW | SUW | RO | CUS | ADJ |
|---|---|---|---|---|---|---|---|---|---|
| Dashboard | membership | y | y | y | y | y | y | y | y |
| Policies | policies.read, carrier.issuance.read | y | y | y | y | y | y | y | - |
| Claims | claims.view, carrier.claims.read | y | y | y | - | - | y | y | y |
| Quotes | quotes.read, carrier.quote_requests.view | y | y | y | y | y | - | - | - |
| Agreements | distribution.agreements.view | y | y | - | - | - | - | - | - |
| Bordereaux | carrier.finance.read, bordereaux.view | y | y | y | - | - | - | - | - |
| Settlements | carrier.finance.read, settlement.read | y | y | y | - | - | - | - | - |
| Commission accruals | finance.obligations.view, statements.read | y | y | - | - | - | - | - | - |
| Issuance queue | policies.issuance_queue.view, carrier.issuance.read | y | y | y | - | - | - | - | - |
| Underwriting cases, Referrals | underwriting.decide, carrier.referrals.read | y | y | y | y | y | - | - | - |
| Approvals inbox | approvals.inbox.view | y | y | - | - | - | - | - | - |
| Stickers | stickers.view | y | y | y | - | - | - | - | - |
| Journals | ledger.read/post/approve | y | - | - | - | - | - | - | - |
| KYC reviews | kyc.view | y | y | - | - | - | - | - | - |
| Coinsurance / Reinsurance treaties, cessions | coinsurance.view / reinsurance.*.view | y | - | - | y (coins.) | y (coins.) | y | - | - |
| Cashier sessions | cashier.sessions.view | y | - | - | - | - | - | - | - |
| FX rates | fx.rates.view | y | y | - | - | - | y | - | - |
| Reports | finance.reports.view, reporting.kpis.view, reports.insurance.read | y | y | - | - | - | y | - | - |
| Health: pre-authorizations (read, review, cancel, extension proposal incl. line-level PARTIAL) | health.preauth.view / .review | y | y | y | - | - | - | - | - |
| Health: pre-authorization decide / return, extension decide | health.preauth.approve (+ .supervise for REFERRED) | y | y | - | - | - | - | - | - |
| Health: provider claims (read, review, adjudicate, disputes) | health.provider_claims.view / .adjudicate | y | y | y | - | - | - | - | - |
| Health: provider claim payable | health.provider_claims.approve_payment | y | y | - | - | - | - | - | - |
| Health: provider settlements (create batch, pay) | health.provider_settlements.manage / .pay | y | y | - | - | - | - | - | - |
| Claim actions (ClaimActions) | claims.* workflow permission per action | per grant | | | | | | | |

CSA = CARRIER_SUPER_ADMIN, CA = CARRIER_ADMIN, CS = CARRIER_STAFF, UW/SUW = (senior) underwriter, RO = REINSURANCE_OFFICER, CUS = CUSTOMER_SERVICE, ADJ = ADJUSTER. Rows pinned by the test: dashboard, policies, claims, agreements, bordereaux, settlements, reports, quotes, KYC.

Health (owner decision: insurance company admins see everything about their own company). Rows are narrowed to the caller's carrier: a pre-authorization by `health_preauthorizations.carrier_id` (else its policy's carrier), a provider claim by its policy or verified pre-authorization, a settlement batch and a dispute by the provider claims they carry (`PortalScope::visibleOf`). The /insurer queues filter rows and 404 an out-of-scope detail; the health API (`EnsureHealthCarrierScope` middleware, alias `health.carrier_scope`, on `/api/v1/health/*` and `provider-disputes/{id}/resolve`) filters the preauthorization and provider-claim lists, 404s an out-of-scope `{preauth}` / `{claim}` / `{batch}` / dispute id, requires `claim_ids` of the own carrier to create a settlement batch (so a batch never mixes carriers; the panel action passes them), and refuses the cross-carrier provider statement. An unlinked carrier role in a shared tenant is refused (CarrierScopeResolver). CLAIMS_OFFICER (tenant staff, not in /insurer) gets the two health reads only (its explicit maker set carries no `.review`); CLAIMS_MANAGER keeps `*`. ADJUSTER, underwriting, reinsurance and customer-service roles get no health grant. Enforced by `tests/Feature/Rbac/CarrierHealthIsolationTest.php`.

Known limits: a settlement batch created before this change can mix carriers; a carrier then sees the batch but only its own claims in the detail, and paying it pays every claim of the batch. The provider statement API stays platform-only.

## 4. Permissions changed
- BROKER_ADMIN: + distribution.agreements.view.
- CARRIER_ADMIN (and CARRIER_SUPER_ADMIN): + distribution.agreements.view, + kyc.view.
- `claims.read` retired; `claims.view` is the only claim-read permission (widget, closure routes, mobile workspace card, CLAIMS_OFFICER).
- CARRIER_STAFF: + health.preauth.view, health.preauth.review, health.provider_claims.view, health.provider_claims.adjudicate (RoleCatalogue::CARRIER_HEALTH_MAKER). CARRIER_ADMIN / CARRIER_SUPER_ADMIN: + those and health.preauth.approve, health.preauth.supervise, health.provider_claims.approve_payment, health.provider_settlements.manage, health.provider_settlements.pay (CARRIER_HEALTH_CHECKER). CLAIMS_OFFICER: + health.preauth.view, health.provider_claims.view. Migration `2026_10_31_100002_rbac_carrier_health_grants` runs `rbac:sync-role-permissions`.
- Production roles: migration `2026_10_31_100001_rbac_broker_carrier_owner_decision_grants` runs `rbac:sync-role-permissions`. It is additive (never removes a custom grant, skips `*` roles) and renames retired codes.

## Acting follows visibility (2026-09-27)

`PartnerBook::contains` / `assertInBook` (quote → offer → proposal → payment) and broker-assisted FNOL (`POST /mobile/partner/broker/claims`, `broker.claims.file`) use `BookScope::bookOf`: BROKER_STAFF act only for clients they recorded, BROKER_SUPERVISOR for their team's, BROKER_ADMIN for the whole company book. The caller's own party stays actionable.
