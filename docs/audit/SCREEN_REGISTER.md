# OpesInsure — Master Screen Register (built from code, 2026-09-24)

Source of truth: what actually exists in the code, not plans.
- Web: `routes/web.php`, `AppServiceProvider::registerPortalShellRoute()` (`/portal/{portal}`), `app/Providers/Filament/AdminPanelProvider.php` (single panel `admin`, path `/admin`, `login()->passwordReset()->profile()`), `app/Filament/Admin/**` (59 Resources, 136 resource pages, 2 custom Pages, **no Widgets directory contents, no RelationManagers**), `resources/views/**`.
- Mobile: every expo-router file under `mobile app/app` except `_layout.tsx` (x3) and `+native-intent.tsx` = **139 route files**. Files untracked in git at read time (other agents editing) are marked **(new, in progress)**.
- Not screens (excluded): `resources/views/pdf/*` (receipt, certificate, schedule PDFs), `mail/notification.blade.php`, `filament/auth/demo-accounts.blade.php` (render hook inside the login page), `/admin/dev-login/{email}` (redirect only), `/download/android` (file stream), `routes/wave10.php` (JSON API only).

Status legend: **REAL** = wired to real API/DB data and actions · **PARTIAL** = real shell but local/static data or incomplete · **PLACEHOLDER** = generic shell / static / "pending" · **DUPLICATE** = same screen reachable by another route (removed from canonical count).

---

## Layer 1 — Customer Web Marketplace

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| W1.1 | Customer portal (wave10 shell) | `GET /portal/customer` (auth) | `app/Providers/AppServiceProvider.php` + `resources/views/wave10/portal.blade.php` | dashboard | `PortalDashboardQuery` — raw `COUNT(*)` of 8 tables, tenant-wide | PLACEHOLDER |

The wave10 portal is **one 8-line template** rendered for 5 portals (ADMIN, BROKER, CARRIER, AGENT, CUSTOMER from `PortalWorkspaceService::PORTALS`). Navigation is a single `#` link, `actions` is `[]`, and the body slot is literally the string "Portal content design pending — see OPESINSURE_CLAUDE_UI_IMPLEMENTATION_HANDOFF.md." KPIs are unscoped table counts (tenant_customers, quotes, proposals, policies, payment_intents, claims, fulfilment_orders, support_tickets) — identical for every portal and not role-filtered. The code comment itself calls it "a placeholder". No customer web marketplace exists.

## Layer 2 — Agent Web Workspace

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| W2.1 | Agent portal (wave10 shell) | `GET /portal/agent` | same shell | dashboard | same generic counts | PLACEHOLDER |

## Layer 3 — Broker ERP (web)

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| W3.1 | Broker portal (wave10 shell) | `GET /portal/broker` | same shell | dashboard | same generic counts | PLACEHOLDER |

## Layer 4 — Carrier Portal (web)

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| W4.1 | Carrier portal (wave10 shell) | `GET /portal/carrier` | same shell | dashboard | same generic counts | PLACEHOLDER |

Broker and carrier staff can only reach operational web screens through the Filament admin panel (Layer 5), which is a platform back-office, not a role-scoped broker/carrier portal.

## Layer 5 — Platform Administration (Filament, `/admin`)

All resource pages are Eloquent-backed Filament v4 pages (REAL). Path = `/admin/<resource-slug>[/create|/{record}|/{record}/edit]`. Files under `app/Filament/Admin/Resources/<Dir>/Pages/`.

### 5a. Panel & custom pages

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| A1 | Login (+ demo accounts hook) | `/admin/login` | Filament built-in + `resources/views/filament/auth/demo-accounts.blade.php` | form | users table | REAL |
| A2 | Password reset request | `/admin/password-reset/request` | Filament built-in | form | mail | REAL |
| A3 | Password reset | `/admin/password-reset/reset` | Filament built-in | form | users | REAL |
| A4 | Edit profile | `/admin/profile` | Filament built-in | settings | users | REAL |
| A5 | Dashboard | `/admin` | `Filament\Pages\Dashboard` (AccountWidget + FilamentInfoWidget only) | dashboard | none (default widgets) | PLACEHOLDER |
| A6 | Integration health | `/admin/integration-health` | `Pages/IntegrationHealth.php` + `views/filament/admin/pages/integration-health.blade.php` | report | `IntegrationHealthService::summary()` | REAL |
| A7 | Platform settings | `/admin/platform-settings-page` | `Pages/PlatformSettingsPage.php` + `views/.../platform-settings.blade.php` | settings | platform settings store | REAL |
| A8 | Admin portal (wave10 shell) | `/portal/admin` | wave10 shell | dashboard | generic counts | PLACEHOLDER |

### 5b. Resource pages (136, all REAL)

L = List, C = Create, E = Edit, V = View. Number = A9 onward, one per page.

| # range | Resource (dir) | Pages | Types |
|---|---|---|---|
| A9–A11 | Attributions | L C V | list/form/detail |
| A12–A13 | Bordereaux | L V | list/detail |
| A14–A17 | Branches | L C E V | list/form/form/detail |
| A18–A20 | CancellationRules | L C E | list/form/form |
| A21–A22 | CarrierSettlements | L V | list/detail |
| A23–A24 | CertificateTemplates | L C | list/form |
| A25–A26 | Certificates | L V | list/detail |
| A27 | ClaimDecisions | L | list |
| A28–A29 | ClaimPayments | L V | list/detail |
| A30 | ClaimReserves | L | list |
| A31–A32 | Claims | L V | list/detail |
| A33–A34 | CommissionAccruals | L V | list/detail |
| A35–A36 | CommissionRules | L V | list/detail |
| A37–A38 | ComplianceCases | L V | list/detail |
| A39–A41 | Consents | L C V | list/form/detail |
| A42–A44 | CoverageDefinitions | L C E | list/form/form |
| A45–A47 | Customers | L C V | list/form/detail |
| A48–A49 | DataSubjectRequests | L V | list/detail |
| A50 | Devices | L | list |
| A51–A53 | DisclosureSchemas | L C E | list/form/form |
| A54–A56 | DocumentRequirements | L C E | list/form/form |
| A57–A59 | ExclusionDefinitions | L C E | list/form/form |
| A60–A61 | FinancialCases | L V | list/detail |
| A62–A63 | Fulfilments | L V | list/detail |
| A64–A66 | InsuranceLines | L C E | list/form/form |
| A67–A69 | InsuranceProducts | L C E | list/form/form |
| A70–A71 | IntegrationClients | L V | list/detail |
| A72 | IntegrationDeliveryAttempts | L | list |
| A73–A74 | Invitations | L C | list/form |
| A75–A76 | Journals | L V | list/detail (report) |
| A77–A79 | Memberships | L C E | list/form/form |
| A80–A81 | MobileIssueReports | L V | list/detail |
| A82–A83 | NotificationDeliveries | L V | list/detail |
| A84–A86 | Parties | L C V | list/form/detail |
| A87–A89 | PartnerLicences | L C V | list/form/detail |
| A90–A91 | PartnerPayouts | L V | list/detail |
| A92–A93 | PartnerStatements | L V | list/detail |
| A94–A96 | Partners | L C V | list/form/detail |
| A97–A98 | PaymentAttempts | L V | list/detail |
| A99–A101 | PaymentConnections | L C E | list/form/form (settings) |
| A102–A103 | PaymentRequests | L V | list/detail |
| A104–A105 | Policies | L V | list/detail |
| A106–A107 | PolicyIssuances | L V | list/detail |
| A108–A109 | PolicyTransactions | L V | list/detail |
| A110–A111 | PrivilegedAccess (grants) | L V | list/detail |
| A112–A113 | Proposals | L V | list/detail |
| A114–A115 | Quotes | L V | list/detail |
| A116–A117 | Reconciliations | L V | list/detail |
| A118–A119 | RegulatoryReports (RegulatoryReportRunResource) | L V | list/report |
| A120–A121 | Renewals | L V | list/detail |
| A122–A123 | RiskAlerts | L V | list/detail |
| A124–A126 | RiskAssets | L C E | list/form/form |
| A127–A128 | StickerBatches | L C | list/form |
| A129 | StickerInventory | L | list |
| A130–A131 | SupportTickets | L V | list/detail |
| A132–A134 | TariffVersions | L C E | list/form/form |
| A135–A138 | Tenants | L C E V | list/form/form/detail |
| A139–A140 | UnderwritingCases | L V | list/detail |
| A141–A144 | Users | L C E V | list/form/form/detail |

Layer 5 total: 8 + 136 = **144 screens**.

## Layer 6 — Public site / public verification (web)

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| P1 | Home | `/` | `resources/views/public/home.blade.php` | landing | static (lang strings), links to /download and /admin | REAL (static marketing) |
| P2 | Download app | `/download` | `resources/views/public/download.blade.php` | landing | `config/mobile_app.php` | REAL |
| P3 | Demo credentials directory | `/demo` (only if `demo.enabled`) | `resources/views/public/demo.blade.php` | list | seeder constants | REAL (demo-only) |
| — | Landing (248 lines) | **not routed** | `resources/views/public/landing.blade.php` | landing | static | DUPLICATE / orphan (superseded by P1; removed) |

No public **web** certificate/sticker verification page exists; verification is API-only (`POST /api/v1/public/certificates/verify`) and surfaced only in the mobile app (M12.31).

---

## Layer 7 — Mobile — Customer (57 files)

Paths relative to `mobile app/app/`. "Store" = `@/store/insurance` zustand store which itself calls `InsuranceApi`.

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| M7.1 | Home | `/(customer)/(tabs)` | `(customer)/(tabs)/index.tsx` | dashboard | CustomerApi.claims/notifications/quotes, SupportContactsApi, usePolicies | REAL |
| M7.2 | Explore insurers/brokers **(new, in progress)** | `/(tabs)/explore` | `(customer)/(tabs)/explore.tsx` | list | CustomerApi.institutions | REAL |
| M7.3 | Compare tab | `/(tabs)/compare` | `(customer)/(tabs)/compare.tsx` | state screen | static card → `/quote/product` | PARTIAL (static launcher) |
| M7.4 | My policies tab | `/(tabs)/policies` | `(customer)/(tabs)/policies.tsx` | list | usePolicies hook | REAL |
| M7.5 | My claims tab | `/(tabs)/claims` | `(customer)/(tabs)/claims.tsx` | list | CustomerApi.claims | REAL |
| M7.6 | Profile tab (renamed from account) | `/(tabs)/profile` | `(customer)/(tabs)/profile.tsx` | settings | AuthApi.requestEmailVerification, session | REAL |
| M7.7 | Quote: choose product | `/quote/product` | `quote/product.tsx` | wizard step | store (catalogue) | REAL |
| M7.8 | Quote: risk details | `/quote/risk` | `quote/risk.tsx` | wizard step | CatalogueApi.riskSchema, AssetsApi.list | REAL |
| M7.9 | Quote: questions/disclosure | `/quote/questions` | `quote/questions.tsx` | wizard step | DisclosureApi.session/saveAnswers/submit | REAL |
| M7.10 | Quote: offers | `/quote/offers` | `quote/offers.tsx` | list | store (rated offers) | REAL |
| M7.11 | Quote: compare offers **(new, in progress)** | `/quote/compare` | `quote/compare.tsx` | report | store | REAL |
| M7.12 | Quote: referral to underwriter | `/quote/referral` | `quote/referral.tsx` | state screen | store | REAL |
| M7.13 | Quote: proposal disclosure | `/quote/disclosure` | `quote/disclosure.tsx` | wizard step | store only (no API call) | PARTIAL (overlaps questions/terms) |
| M7.14 | Quote: terms acceptance | `/quote/terms` | `quote/terms.tsx` | wizard step | DisclosureApi.acceptTerms | REAL |
| M7.15 | Checkout | `/checkout` | `checkout.tsx` | wizard step | store, ProviderNotConfigured state | REAL |
| M7.16 | Payment | `/payment` | `payment.tsx` | wizard step | store (payment initiation/polling) | REAL |
| M7.17 | Purchase confirmation | `/confirmation` | `confirmation.tsx` | state screen | InsuranceApi.purchaseStatus | REAL |
| M7.18 | Quote history | `/quotes` | `quotes/index.tsx` | list | QuotesApi.history | REAL |
| M7.19 | Quote detail | `/quotes/[id]` | `quotes/[id].tsx` | detail | QuotesApi.show/resume/discard | REAL |
| M7.20 | Proposals list **(new, in progress)** | `/proposals` | `proposals/index.tsx` | list | ProposalsApi.list | REAL |
| M7.21 | Proposal detail **(new, in progress)** | `/proposals/[id]` | `proposals/[id].tsx` | detail | ProposalsApi.uploadDocument, fetch | REAL |
| M7.22 | Policy detail | `/policy/[id]` | `policy/[id].tsx` → `PolicyDetailView` | detail | wallet API | REAL |
| M7.23 | Renew policy | `/policy/[id]/renew` | `policy/[id]/renew.tsx` | form | PolicyApi.renewalQuote | REAL |
| M7.24 | Policy service request | `/policy/[id]/service` | `policy/[id]/service.tsx` | form | PolicyApi.service | REAL |
| M7.25 | Insurance wallet | `/wallet` | `wallet/index.tsx` | list | WalletApi.list | REAL |
| — | Wallet policy detail | `/wallet/policy/[id]` | `wallet/policy/[id].tsx` | detail | same `PolicyDetailView` as M7.22 | DUPLICATE |
| M7.26 | Delivery tracking | `/delivery/[id]` | `delivery/[id].tsx` | detail | WalletApi.delivery | REAL |
| M7.27 | Delivery address | `/delivery/[id]/address` | `delivery/[id]/address.tsx` | form | WalletApi.updateAddress | REAL |
| M7.28 | Confirm delivery | `/delivery/[id]/confirm` | `delivery/[id]/confirm.tsx` | form | WalletApi.confirmDelivery | REAL |
| M7.29 | Payments list | `/payments` | `payments/index.tsx` | list | PaymentsApi.list | REAL |
| M7.30 | Payment detail | `/payments/[id]` | `payments/[id].tsx` | detail | PaymentsApi.show/retry | REAL |
| M7.31 | Receipt | `/payments/[id]/receipt` | `payments/[id]/receipt.tsx` | detail | PaymentsApi.receipt | REAL |
| M7.32 | Refund request | `/payments/[id]/refund` | `payments/[id]/refund.tsx` | form | PaymentsApi.refund | REAL |
| M7.33 | New claim | `/claim/new` | `claim/new.tsx` | form | ClaimsApi.create | REAL |
| M7.34 | Emergency assistance | `/claim/emergency` | `claim/emergency.tsx` | form | ClaimsCompletionApi.requestEmergencyAssistance | REAL |
| M7.35 | Claim detail | `/claim/[id]` | `claim/[id].tsx` | detail | ClaimsApi.show, ClaimRecordsApi.timeline/evidence | REAL |
| M7.36 | Claim appeal | `/claim/[id]/appeal` | `claim/[id]/appeal.tsx` | form | ClaimsApi.appeal | REAL |
| M7.37 | Claim evidence checklist | `/claim/[id]/checklist` | `claim/[id]/checklist.tsx` | list | ClaimsCompletionApi.evidenceRequirements | REAL |
| M7.38 | Claim evidence upload | `/claim/[id]/evidence` | `claim/[id]/evidence.tsx` | form | ClaimRecordsApi.evidence, ClaimsApi.submitDeclaration | REAL |
| M7.39 | Incident details | `/claim/[id]/incident` | `claim/[id]/incident.tsx` | form | ClaimsCompletionApi.incident/saveIncident | REAL |
| M7.40 | Inspection | `/claim/[id]/inspection` | `claim/[id]/inspection.tsx` | detail | ClaimsCompletionApi.inspection/reschedule | REAL |
| M7.41 | Third parties | `/claim/[id]/parties` | `claim/[id]/parties.tsx` | form | ClaimsCompletionApi.parties/addParty | REAL |
| M7.42 | Repair status | `/claim/[id]/repair` | `claim/[id]/repair.tsx` | detail | ClaimsCompletionApi.repair | REAL |
| M7.43 | Settlement offer | `/claim/[id]/settlement` | `claim/[id]/settlement.tsx` | form | ClaimsCompletionApi.settlement/decideSettlement | REAL |
| M7.44 | Settlement payment | `/claim/[id]/settlement-payment` | `claim/[id]/settlement-payment.tsx` | detail | ClaimsCompletionApi.settlement | REAL |
| M7.45 | My assets | `/assets` | `assets/index.tsx` | list | AssetsApi.list | REAL |
| M7.46 | New asset | `/assets/new` | `assets/new.tsx` | form | AssetsApi.create | REAL |
| M7.47 | Asset detail | `/assets/[id]` | `assets/[id].tsx` | detail | AssetsApi.show | REAL |
| M7.48 | Asset document scan | `/assets/[id]/scan` | `assets/[id]/scan.tsx` | form | AssetsApi.uploadDocument/confirmScan | REAL |
| M7.49 | Service requests | `/services` | `services/index.tsx` | list | PolicyServicesApi.list | REAL |
| M7.50 | New service request | `/services/new` | `services/new.tsx` | form | PolicyServicesApi.create | REAL |
| M7.51 | Service request detail | `/services/[id]` | `services/[id].tsx` | detail | PolicyServicesApi.show/addMessage | REAL |
| M7.52 | Insurers directory | `/institutions/insurers` | `institutions/insurers.tsx` | list | InstitutionsApi.list | REAL |
| M7.53 | Insurer profile | `/institutions/insurer/[id]` | `institutions/insurer/[id].tsx` | detail | InstitutionsApi.show | REAL |
| M7.54 | Brokers directory | `/institutions/brokers` | `institutions/brokers.tsx` | list | InstitutionsApi.list | REAL |
| M7.55 | Broker profile | `/institutions/broker/[id]` | `institutions/broker/[id].tsx` | detail | InstitutionsApi.show | REAL |
| M7.56 | KYC onboarding | `/onboarding/kyc` | `onboarding/kyc.tsx` | wizard step | CustomerApi.kyc/addIdentifier/attachKycDocument/submitKyc | REAL |

Layer 7 canonical: 56 (57 files − 1 duplicate).

## Layer 8 — Mobile — Agent (18)

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| M8.1 | Agent field desk (dashboard) | `/agent` | `agent/index.tsx` | dashboard | AgentApi.dashboard (`/mobile/agent/dashboard`) | REAL |
| M8.2 | Leads **(new, in progress)** | `/agent/leads` | `agent/leads/index.tsx` | list | AgentWorkspaceApi.leads | REAL |
| M8.3 | New lead **(new, in progress)** | `/agent/leads/new` | `agent/leads/new.tsx` | form | AgentWorkspaceApi.createLead | REAL |
| M8.4 | Lead detail **(new, in progress)** | `/agent/leads/[id]` | `agent/leads/[id].tsx` | detail | lead/updateLead/convertLead | REAL |
| M8.5 | Clients | `/agent/clients` | `agent/clients/index.tsx` | list | AgentApi.clients | REAL |
| M8.6 | New client | `/agent/clients/new` | `agent/clients/new.tsx` | form | AgentWorkspaceApi.createClient | REAL |
| M8.7 | Client detail | `/agent/clients/[id]` | `agent/clients/[id].tsx` | detail | AgentApi.client | REAL |
| M8.8 | Quotes **(new, in progress)** | `/agent/quotes` | `agent/quotes.tsx` | list | AgentWorkspaceApi.quotes | REAL |
| M8.9 | Policies **(new, in progress)** | `/agent/policies` | `agent/policies.tsx` | list | AgentWorkspaceApi.policies | REAL |
| M8.10 | Renewals | `/agent/renewals` | `agent/renewals.tsx` | list | AgentApi.renewals | REAL |
| M8.11 | New assisted sale | `/agent/sales/new` | `agent/sales/new.tsx` | form | AgentApi.clients/createSale | REAL |
| M8.12 | Sale detail | `/agent/sales/[id]` | `agent/sales/[id].tsx` | detail | AgentApi.sale/requestPayment | REAL |
| M8.13 | Commission wallet | `/agent/wallet` | `agent/wallet.tsx` | report | AgentApi.commissions/withdrawals | REAL |
| M8.14 | Withdrawal | `/agent/withdrawal` | `agent/withdrawal.tsx` | form | AgentApi.requestWithdrawal | REAL |
| M8.15 | Agent onboarding | `/agent/onboarding` | `agent/onboarding.tsx` | form | AgentApi.profile/submitProfile | REAL |
| M8.16 | Offline queue | `/agent/offline` | `agent/offline.tsx` | list | AgentApi.offlineQueue/retryOffline | REAL |
| M8.17 | Notifications | `/agent/notifications` | `agent/notifications.tsx` → `PortalNotifications` | list | NotificationsApi.list | REAL (shared template) |
| M8.18 | Account | `/agent/account` | `agent/account.tsx` → `PortalAccount` | settings | AccountApi prefs/locale | REAL (shared template) |

## Layer 9 — Mobile — Broker (15)

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| M9.1 | Broker mobile office (dashboard) | `/broker` | `broker/index.tsx` | dashboard | BrokerApi.dashboard | REAL |
| M9.2 | Clients | `/broker/clients` | `broker/clients.tsx` | list | BrokerApi.clients | REAL |
| M9.3 | Client detail | `/broker/clients/[id]` | `broker/clients/[id].tsx` | detail | BrokerApi.client | REAL |
| M9.4 | Production (sales register) | `/broker/production` | `broker/production.tsx` | report | BrokerApi.production | REAL |
| M9.5 | Quotes **(new, in progress)** | `/broker/quotes` | `broker/quotes.tsx` | list | BrokerWorkspaceApi.quotes | REAL |
| M9.6 | Policies **(new, in progress)** | `/broker/policies` | `broker/policies.tsx` | list | BrokerWorkspaceApi.policies | REAL |
| M9.7 | Claims **(new, in progress)** | `/broker/claims` | `broker/claims.tsx` | list | BrokerWorkspaceApi.claims | REAL |
| M9.8 | Renewals | `/broker/renewals` | `broker/renewals.tsx` | list | BrokerApi.renewals | REAL |
| M9.9 | Receivables & statements | `/broker/receivables` | `broker/receivables.tsx` | report | BrokerApi.receivables, BrokerFinanceApi.accruals/statements | REAL |
| M9.10 | Commissions **(new, in progress)** | `/broker/commissions` | `broker/commissions.tsx` | report | BrokerWorkspaceApi.commissions | REAL |
| M9.11 | Staff & invitations **(new, in progress)** | `/broker/staff` | `broker/staff.tsx` | list+form | BrokerWorkspaceApi.staff/inviteStaff | REAL |
| M9.12 | Compliance | `/broker/compliance` | `broker/compliance.tsx` | list | BrokerApi.compliance | REAL |
| M9.13 | Marketplace publications | `/broker/publications` | `broker/publications.tsx` | list | BrokerApi.publications/togglePublication | REAL |
| M9.14 | Notifications | `/broker/notifications` | `PortalNotifications` | list | NotificationsApi.list | REAL (shared template) |
| M9.15 | Account | `/broker/account` | `PortalAccount` | settings | AccountApi | REAL (shared template) |

BROKER_ADMIN and BROKER_STAFF share the same `/broker` stack (`roleToPortal` → broker_admin/broker_staff → `/broker`).

## Layer 10 — Mobile — Carrier/Insurer (16)

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| M10.1 | Carrier operations (dashboard) | `/carrier` | `carrier/index.tsx` | dashboard | CarrierApi.dashboard | REAL |
| M10.2 | Products **(new, in progress)** | `/carrier/products` | `carrier/products.tsx` | list | CarrierWorkspaceApi.products/setProductActive | REAL (activate/deactivate only; no tariff editing) |
| M10.3 | Quotes & proposals **(new, in progress)** | `/carrier/proposals` | `carrier/proposals.tsx` | list | CarrierWorkspaceApi.proposals | REAL |
| M10.4 | Underwriting referrals | `/carrier/referrals` | `carrier/referrals/index.tsx` | list | CarrierApi.referrals | REAL |
| M10.5 | Referral decision | `/carrier/referrals/[id]` | `carrier/referrals/[id].tsx` | detail+form | CarrierApi.referral/decideReferral | REAL |
| M10.6 | Issuance queue | `/carrier/issuance` | `carrier/issuance.tsx` | list+action | CarrierApi.issuance, approve/rejectIssuance | REAL |
| M10.7 | Policies **(new, in progress)** | `/carrier/policies` | `carrier/policies.tsx` | list | CarrierWorkspaceApi.policies | REAL |
| M10.8 | Claims | `/carrier/claims` | `carrier/claims.tsx` | list | CarrierApi.claims | REAL |
| M10.9 | Claim detail/decision **(new, in progress)** | `/carrier/claims/[id]` | `carrier/claims/[id].tsx` | detail+form | acknowledge/propose/approveClaimDecision | REAL |
| M10.10 | Payments **(new, in progress)** | `/carrier/payments` | `carrier/payments.tsx` | list | CarrierWorkspaceApi.payments | REAL |
| M10.11 | Settlements | `/carrier/settlements` | `carrier/settlements.tsx` | list | CarrierApi.settlements | REAL |
| M10.12 | Settlement detail | `/carrier/settlement/[id]` | `carrier/settlement/[id].tsx` | detail | CarrierFinanceApi.settlement | REAL |
| M10.13 | Bordereaux | `/carrier/bordereaux` | `carrier/bordereaux.tsx` | report | CarrierFinanceApi.bordereaux | REAL |
| M10.14 | Distribution partners **(new, in progress)** | `/carrier/partners` | `carrier/partners.tsx` | list | CarrierWorkspaceApi.partners | REAL |
| M10.15 | Notifications | `/carrier/notifications` | `PortalNotifications` | list | NotificationsApi.list | REAL (shared template) |
| M10.16 | Account | `/carrier/account` | `PortalAccount` | settings | AccountApi | REAL (shared template) |

## Layer 11 — Mobile — Workspace / staff roles (2)

Covers SYSTEM_ADMIN, PLATFORM_ADMIN, COMPLIANCE_ADMIN, FINANCE_ADMIN, FINANCE_MANAGER, CLAIMS_MANAGER, CLAIMS_OFFICER (one template, role in URL).

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| M11.1 | Staff workspace | `/workspace/[role]` | `workspace/[role].tsx` | dashboard | WorkspaceApi.dashboard (6 live KPIs, permission-filtered modules) | REAL (generic for all staff roles) |
| M11.2 | Workspace module table | `/workspace/[role]/module/[module]` | `workspace/[role]/module/[module].tsx` | list | WorkspaceApi.module — 6 modules (policies, claims, payments, support, compliance, issue-reports), 50 rows, read-only, no record drill | REAL (read-only) |

## Layer 12 — Mobile — Auth / shared (31)

| # | Name | Route | File | Type | Data source | Status |
|---|---|---|---|---|---|---|
| M12.1 | Entry / router | `/` | `index.tsx` | state screen | session store redirect | REAL |
| M12.2 | Welcome | `/welcome` | `welcome.tsx` | landing | static + navigation | REAL |
| M12.3 | Sign in | `/(auth)/sign-in` | `(auth)/sign-in.tsx` | form | AuthApi.passwordLogin/requestOtp/verifyOtp/demoAccounts | REAL |
| M12.4 | Sign up | `/(auth)/sign-up` | `(auth)/sign-up.tsx` | form | AuthApi.register/requestOtp | REAL |
| M12.5 | OTP verify | `/(auth)/verify` | `(auth)/verify.tsx` | form | AuthApi.verifyOtp | REAL |
| M12.6 | Forgot password | `/(auth)/forgot-password` | `(auth)/forgot-password.tsx` | form | AuthApi.forgotPassword/resetPassword | REAL |
| M12.7 | Accept invitation | `/(auth)/invitation` | `(auth)/invitation.tsx` | form | InvitationApi.accept | REAL |
| M12.8 | Workspace/role picker | `/(auth)/role` | `(auth)/role.tsx` | list | session workspaces (from login) | REAL |
| M12.9 | Access denied | `/access-denied` | `access-denied.tsx` | state screen | static | REAL |
| M12.10 | Session expired | `/session-expired` | `session-expired.tsx` | state screen | static | REAL |
| M12.11 | Not found | `+not-found` | `+not-found.tsx` | state screen | static | REAL |
| M12.12 | Terms | `/terms` | `terms.tsx` | static page | static | REAL (static) |
| M12.13 | Step-up auth | `/security/step-up` | `security/step-up.tsx` | form | StepUpApi.request/verify | REAL |
| M12.14 | Device status | `/security/device-status` | `security/device-status.tsx` | state screen | DeviceSecurityApi.nonce/assess | REAL |
| M12.15 | System status | `/system/status` | `system/status.tsx` | state screen | local runtime/env store | REAL (client-side only) |
| M12.16 | Sync queue | `/sync` | `sync/index.tsx` | list | local resilience store | REAL (local) |
| M12.17 | Account: profile | `/account/profile` | `account/profile.tsx` | form | AccountApi.updateProfile | REAL |
| M12.18 | Account: security | `/account/security` | `account/security.tsx` | settings | session store only | PARTIAL |
| M12.19 | Account: devices | `/account/devices` | `account/devices.tsx` | list | AccountApi.devices/revokeDevice | REAL |
| M12.20 | Account: language | `/account/language` | `account/language.tsx` | settings | AccountApi.setLocale | REAL |
| M12.21 | Account: notification prefs | `/account/notifications` | `account/notifications.tsx` | settings | AccountApi prefs/registerPush | REAL |
| M12.22 | Account: data usage | `/account/data-usage` | `account/data-usage.tsx` | settings | local resilience store | REAL (local) |
| M12.23 | Account: privacy **(new, in progress)** | `/account/privacy` | `account/privacy.tsx` | settings | local preferences store, no API | PARTIAL |
| M12.24 | Notifications inbox | `/notifications` | `notifications/index.tsx` | list | CustomerApi.notifications, markRead/markAllRead | REAL |
| M12.25 | Notification detail | `/notifications/[id]` | `notifications/[id].tsx` | detail | NotificationsApi.markRead | REAL |
| M12.26 | Support cases | `/support` | `support/index.tsx` | list | SupportApi.list | REAL |
| M12.27 | New support case | `/support/new` | `support/new.tsx` | form | CustomerApi.createSupportCase | REAL |
| M12.28 | Support case detail | `/support/[id]` | `support/[id].tsx` | detail | SupportApi.show/reply/upload | REAL |
| M12.29 | FAQ **(new, in progress)** | `/support/faq` | `support/faq.tsx` | static page | static strings + support contacts | PARTIAL (static content) |
| M12.30 | Document viewer | `/documents/[id]` | `documents/[id].tsx` | detail | DocumentsApi.show/access | REAL |
| M12.31 | Public certificate verification | `/verify` | `verify.tsx` | form | PublicApi.verify | REAL |

---

## 26 dashboards (owner spec `docs/audit/DASHBOARDS_SPEC.md`)

Scoring = number of the 20 framework components present (see spec). "Drill-down" = does a KPI card open the exact filtered records it counts.

**Common finding: no dashboard anywhere has KPI drill-down.** Every mobile KPI card is a non-pressable `<Card>` rendering `{label, value}`; the backend metric contract (`{label, value, tone}`) carries no filter/href, and the `tone` field is not rendered. The wave10 web shell and Filament dashboard have no drill-down either.

| # | Dashboard | Exists? Where (file) | Expected metrics present | KPI drill-down | Data | Framework score | Verdict |
|---|---|---|---|---|---|---|---|
| 1 | Customer | Mobile `(customer)/(tabs)/index.tsx` (M7.1); web `/portal/customer` shell | Active policies, active claims, quotes in progress, renewals due, unread notifications, help/support. Missing: amount due/next instalment, documents, KYC status, deliveries | Sections "See all" to unfiltered lists (policies/claims tabs, /quotes, /notifications) — not exact filters | Real (mobile); web placeholder | 6/20: identity header (greeting only), KPI strip (no comparison), deadlines (renewals), quick actions (quote, renew, support), notifications, states (loading/error/offline) | PARTIAL |
| 2 | Customer Insurance Portfolio | Only list `(tabs)/policies.tsx` with status-bucket filters, `/wallet` | Policy list by status; no cover totals, sum-insured, insurer/product breakdown | n/a | Real | 3/20: work-queue-like list, status semantics (pills), states | PARTIAL (list, not dashboard) |
| 3 | Customer Claims | Only list `(tabs)/claims.tsx` | Claim list; no open/settled/paid KPIs, SLA, pending evidence count | n/a | Real | 2/20 | MISSING (list only) |
| 4 | Customer Payments & Billing | Only list `payments/index.tsx` | No outstanding/overdue/next-due, receipts summary | n/a | Real | 1/20 | MISSING |
| 5 | Agent | Mobile `agent/index.tsx` (M8.1) → `MobileAgentPortalController::dashboard`; web `/portal/agent` shell | Clients, active policies, renewals due, commission available, commission pending. Missing: leads/pipeline, quotes open, conversion, sales this period, targets | No | Real | 6/20: identity header (PortalHeader), KPI strip, quick actions (WorkspaceMenu), role scoping (server permission + origin-protected clients), states (StatePanel), responsive (useColumns) | PARTIAL |
| 6 | Agent Sales | None (lists `agent/quotes`, `agent/sales/*`) | — | — | — | 0 | MISSING |
| 7 | Agent Commission | `agent/wallet.tsx` (M8.13) | Commission balances, withdrawals history. Missing: earned by period/product, pending vs paid trend, statements | No | Real | 3/20: KPI (balances), financial position (partial, accrual-backed), recent activity (withdrawals) | PARTIAL |
| 8 | Agent Customer Portfolio | Only list `agent/clients` | — | — | Real list | 1/20 | MISSING |
| 9 | Broker Executive | Mobile `broker/index.tsx` (M9.1) → `MobileBrokerOpsController::dashboard`; web `/portal/broker` shell | Clients, policies in force, premium written 12m, commission outstanding, renewals due, open compliance items. Missing: GWP vs target, claims ratio, collections, insurer mix, branch breakdown | No | Real | 6/20 (same as #5) | PARTIAL |
| 10 | Broker Sales & Production | `broker/production.tsx` (M9.4) | Production register (policy rows). Missing: KPIs, period comparison, by insurer/product/producer | No | Real | 2/20 | PARTIAL |
| 11 | Broker Customer | Only list `broker/clients` | — | — | Real list | 1/20 | MISSING |
| 12 | Broker Renewal | `broker/renewals.tsx` (M9.8) | Renewal list. Missing: renewal rate, due-30/60/90 buckets, lost/retained | No | Real | 2/20 (queue, deadlines) | PARTIAL |
| 13 | Broker Claims | Only list `broker/claims.tsx` (new) | — | — | Real list | 1/20 | MISSING |
| 14 | Broker Finance | `broker/receivables.tsx` (M9.9), `broker/commissions.tsx` (M9.10); backend `MobileBrokerFinanceController::dashboard` exists but is not called by the app | Receivables, accruals, statements. Missing: collected vs billed, aged receivables, insurer payable, reconciliation diffs | No | Real (ledger/accrual) | 3/20 | PARTIAL |
| 15 | Branch Manager | None (Branches only as Filament CRUD A14–A17) | — | — | — | 0 | MISSING |
| 16 | Insurer/Carrier Executive | Mobile `carrier/index.tsx` (M10.1) → `MobileCarrierOpsController::dashboard`; web `/portal/carrier` shell | Underwriting referrals, issuance queue, open claims, policies in force, settlements net. Missing: GWP, loss ratio, partner production, premium collected | No | Real | 6/20 (same as #5) | PARTIAL |
| 17 | Carrier Underwriting | Queues only: `carrier/referrals` (M10.4), Filament UnderwritingCases (A139) | Referral queue. Missing: KPIs, SLA/aging, decision rates | No | Real | 2/20 (work queue, action) | PARTIAL |
| 18 | Carrier Policy Administration | Lists only (`carrier/policies`, Filament Policies/PolicyTransactions) | — | — | — | 1/20 | MISSING |
| 19 | Claims Operations | Lists only (`carrier/claims`, Filament Claims/Reserves/Decisions/Payments, workspace claims module) | Open-claims count only on #16/#24 | No | — | 1/20 | MISSING |
| 20 | Claims Adjuster/Expert | None (no adjuster role/assignment screen) | — | — | — | 0 | MISSING |
| 21 | Finance & Accounting | Lists only (Filament Journals, PaymentAttempts, PaymentRequests; workspace payments module) | Premium this month / payments pending on #24 only | No | — | 1/20 | MISSING |
| 22 | Settlement & Reconciliation | Lists only (`carrier/settlements`, Filament CarrierSettlements/Reconciliations/PartnerPayouts/Statements) | — | No | — | 1/20 | MISSING |
| 23 | Compliance & Risk | Lists only (`broker/compliance`, Filament ComplianceCases/RiskAlerts/DataSubjectRequests/PrivilegedAccess) | Open compliance items on #9 only | No | — | 1/20 | MISSING |
| 24 | Platform Administrator | Filament `/admin` Dashboard (A5, default Account+FilamentInfo widgets only); mobile `workspace/[role]` (M11.1) with 6 live KPIs (policies in force, open claims, premium this month, payments pending, open support, app issues) + permission-filtered modules; web `/portal/admin` shell | Mobile has 6 KPIs; Filament has none | No (module tables are unfiltered 50-row lists, no record drill) | Mobile real; Filament/web placeholder | Mobile 6/20 (header, KPI strip, quick actions/modules, role/permission awareness incl. "Restricted", states loading+error, responsive); Filament 1/20; web shell 2/20 | PARTIAL |
| 25 | Operations/System Health | Filament IntegrationHealth (A6); mobile `system/status` (client-side only), Filament MobileIssueReports/IntegrationDeliveryAttempts lists | Integration summary only; no queue depth, job failures, OTP/SMS/payment provider uptime trends | No | Real (integration summary) | 2/20 (alerts-ish status, freshness partial) | PARTIAL |
| 26 | Regulatory/Audit | Lists only (Filament RegulatoryReports A118–A119); no audit-log viewer | — | — | — | 1/20 | MISSING |

Totals: **BUILT 0 / PARTIAL 12** (#1, 2, 5, 7, 9, 10, 12, 14, 16, 17, 24, 25) **/ MISSING 14** (#3, 4, 6, 8, 11, 13, 15, 18, 19, 20, 21, 22, 23, 26).

Components never implemented by any dashboard: 5 Trends with period filters, 6 Portfolio breakdown charts, 8 Deadlines calendar (beyond a renewals count), 9 Recent activity with actor, 12 Communications, 13 Risk panel with evidence, 14 Drill-down standard, 15 Status semantics with icon+label on KPIs (tone is sent but ignored), 17 Data freshness/last refresh, 20 Auditability of dashboard actions (Note: `MobileBrokerFinanceController`/`MobileCarrierFinanceController::dashboard` write audit on read via `AuditWriter`, but those endpoints are not used by the app).

### Framework readiness

- **Mobile (best seed):** shared `PortalScreen`, `PortalHeader`, `PortalTabBar`, `PortalNotifications`, `PortalAccount` (`src/components/portal/PortalShell.tsx`), `WorkspaceMenu` (`src/components/portal/Workspace.tsx`), `StatePanel` (loading/error/empty/retry), `useColumns` (responsive grid), and a common metric shape `{label, value, tone}` returned by four endpoints (`/mobile/agent|broker|carrier|workspace/dashboard`). The three portal home screens (agent/broker/carrier `index.tsx`) are near copies of each other — a single `<DashboardScreen metrics queues actions/>` component would absorb them. Missing to reach the framework: metric `href`/filter for drill-down, comparison period/change, rendering `tone` as status semantics, `generated_at` freshness, attention/queue and activity sections.
- **Web:** `resources/views/wave10/portal.blade.php` already has the slot layout (sidebar nav, KPI strip, "priority work" panel, "next actions" panel) and `PortalDashboardQuery` + `PortalWorkspaceService` (workspace preferences) exist, but data is raw tenant-wide table counts with no role scoping and no content. It could host the framework but needs a per-role metric provider.
- **Filament:** panel calls `discoverWidgets(app/Filament/Admin/Widgets)` but the directory holds no widgets; Filament `StatsOverviewWidget` (stat → URL) and `TableWidget` would give KPI drill-down to existing resource list pages with filters, cheaply, for dashboards 17–26.
- **Backend:** no shared dashboard/metric contract; each controller hand-builds its array. A `DashboardMetric` DTO (key, label, value, change, tone, filter/route, freshness) is the missing piece common to all three surfaces.

---

## Totals

| Layer | Canonical screens | REAL | PARTIAL | PLACEHOLDER | Duplicates removed |
|---|---|---|---|---|---|
| 1 Customer Web Marketplace | 1 | 0 | 0 | 1 | 0 |
| 2 Agent Web Workspace | 1 | 0 | 0 | 1 | 0 |
| 3 Broker ERP (web) | 1 | 0 | 0 | 1 | 0 |
| 4 Carrier Portal (web) | 1 | 0 | 0 | 1 | 0 |
| 5 Platform Administration (Filament) | 144 | 142 | 0 | 2 (A5 dashboard, A8 admin shell) | 0 |
| 6 Public site / verification | 3 | 3 | 0 | 0 | 1 (unrouted landing.blade) |
| **Web subtotal** | **151** | **145** | **0** | **6** | **1** |
| 7 Mobile — Customer | 56 | 54 | 2 (compare tab, quote/disclosure) | 0 | 1 (wallet/policy/[id]) |
| 8 Mobile — Agent | 18 | 18 | 0 | 0 | 0 |
| 9 Mobile — Broker | 15 | 15 | 0 | 0 | 0 |
| 10 Mobile — Carrier | 16 | 16 | 0 | 0 | 0 |
| 11 Mobile — Workspace/staff | 2 | 2 | 0 | 0 | 0 |
| 12 Mobile — Auth/shared | 31 | 28 | 3 (account/security, account/privacy, support/faq) | 0 | 0 |
| **Mobile subtotal** | **138** | **133** | **5** | **0** | **1** |
| **Platform total** | **289** | **278** | **5** | **6** | **2** |

Of the mobile screens, 22 are untracked files "(new, in progress)" at read time (explore, privacy, faq, compare, proposals x2, agent leads x3/quotes/policies, broker quotes/policies/claims/commissions/staff, carrier claims/[id]/partners/payments/policies/products/proposals) — their REAL status reflects wiring present in the file, not verified runtime behaviour.

Caveat on "REAL": the web REAL count is dominated by 136 Filament CRUD pages for platform staff. The four role-facing web experiences (layers 1–4) are **one placeholder template**; every role-facing working screen lives in the mobile app.

---

## Missing screens (implied by the owner's platform checklist, not present in code)

### 1 Customer Web Marketplace
Entire customer web journey: product catalogue/browse, quote wizard, offer comparison, proposal/disclosure, checkout & payment, policy wallet, policy detail/documents, claims (new/track/evidence), payments & receipts, renewals, support, profile/KYC, customer dashboards #1–#4.

### 2 Agent Web Workspace
Agent dashboard, leads/pipeline, clients (list/detail/create), assisted sale/quote, policies, renewals, commission statements & withdrawals, onboarding/licence.

### 3 Broker ERP (web)
Executive dashboard; CRM (contacts, pipeline, activities/tasks, follow-ups); multi-insurer placement/quote slip & comparison; policy register & endorsements; claims handling; premium collection & aged receivables; commission statements & insurer payables; branches & branch-manager dashboard; staff/roles; document management; production/renewal/claims reports; marketplace publication management (web).

### 4 Carrier Portal (web)
Carrier executive dashboard; product & **tariff configuration** (role-scoped, not Filament admin); underwriting workbench & rules; issuance approval; policy administration; claims adjudication/adjuster assignment; reserves; settlements & reconciliation; bordereaux; **sticker/certificate inventory allocation** to partners; distribution partner management; reports.

### 5 Platform Administration (Filament)
KPI dashboard/widgets (A5 is empty); audit-log viewer; role & permission editor (roles are code constants in `RoleCatalogue`); notification template management; sticker allocation/transfer/void workflow (only batch create + inventory list exist); report builder/exports; dashboards #15, #18–#23, #26; job/queue monitor (beyond IntegrationHealth).

### 6 Public site / verification
Web certificate/sticker verification page (API exists, no page); public product catalogue & comparison; insurer/broker public directory; legal pages (privacy, terms) on web; contact/claims hotline page.

### 7 Mobile — Customer
Payments & billing dashboard (amount due, instalments, payment methods); claims dashboard; document library (only single-document viewer exists); cancellation/endorsement request flows distinct from generic service request.

### 8 Mobile — Agent
Sales dashboard (#6) and customer portfolio dashboard (#8); quote/policy detail screens (lists have no agent-scoped detail); performance/targets; commission statements by period.

### 9 Mobile — Broker
Branch manager view; CRM pipeline/activities; quote, policy and claim detail screens (lists only); broker finance dashboard (backend endpoint exists, unused); reports.

### 10 Mobile — Carrier/Insurer
Tariff configuration; underwriting rules; sticker inventory; proposal and policy detail; payment reconciliation detail; reports; claims-operations and adjuster dashboards.

### 11 Mobile — Workspace/staff
Dedicated finance, claims-manager/officer, compliance dashboards (#19–#23); record drill-down from module tables (rows are not tappable); actions from workspace (read-only today).

### 12 Mobile — Auth/shared
In-app messaging/communications centre; server-backed privacy/consent management (privacy screen is local-only); account security server features (password change/2FA management) — `account/security` uses session store only.
