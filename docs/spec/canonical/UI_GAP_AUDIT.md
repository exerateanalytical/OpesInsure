# UI Gap Audit — Canonical UI Implementation Handoff (web)

Source of requirements: `OpesInsure_Canonical_Implementation_Specification_v1.json` → `ui_implementation_handoff` (binding, together with the manifest `critical_invariants`).
Scope of this register: **web experiences only** — Filament admin (`/admin`), insurer portal (`/insurer`), broker portal (`/broker`), public website and public verification (`/`, `/verify`), plus global error/exceptional states. Native mobile is owned by the "Opesinsure Mobile app" session; its requirements are in `MOBILE_UI_HANDOFF_NOTES.md`.
Audited: 2026-09-25 against master `3176a7c` + working tree (other agents active). Route inventory from `php artisan route:list --json` (282 non-API web routes: 239 `/admin`, 12 `/broker`, 10 `/insurer`, 21 public).
The previous register (`docs/design/ROUTE_TO_SCREEN_REGISTER.md`, built against the superseded v3 visual spec) is preserved; this register supersedes its token decisions (see §A).

State legend: **complete** / **partial** / **placeholder** / **broken** / **missing**. "Before" = state at audit time. Final evidence per batch is in `UI_BATCH_REPORTS.md`.

---

## A. Token / colour / typography conflict register (before Batch 1)

| # | Conflict | Where | Canonical (handoff) | Correction |
|---|---|---|---|---|
| A1 | Primary blue `#155FCC` (v3 "Insurance Blue 650") on all panels | `AdminPanelProvider::v3Colors()`, `theme.css --oi-blue-650` | `#1769E0` is the only interactive colour | Batch 1: palette + tokens to `#1769E0` |
| A2 | Headings in **Manrope** (panels); whole public site in Manrope | `theme.css` @font-face + heading selectors; `public/landing/site.css` | **Inter Variable**, local WOFF2, `font-display:swap`, system fallback | Batch 1: panels on Inter Variable only (already shipped locally by Filament at `/fonts/filament/filament/inter`). Public site: **decision D1** (`site.css` is being edited by another agent; the owner-approved landing mockup uses Manrope) |
| A3 | Accent "brass" `#D5A13C` / `#A96F13` | `theme.css` | gold `#D99100`, restrained accent only | Batch 1: `--oi-gold-500:#D99100`; warning palette base `#D99100`, text stop `#764B00` |
| A4 | Focus ring = 30 % transparent blue outline (≈1.5:1, fails 3:1 non-text contrast) | `theme.css *:focus-visible` | visible **3 px blue** focus | Batch 1: solid 3 px `#1769E0`, 2 px offset |
| A5 | Public-site focus ring gold `#F1C06D` on white (≈1.6:1) | `public/landing/site.css` | 3 px blue | D1; error pages (Batch 9) use blue |
| A6 | Public CTAs are gold gradients (gold used as an interactive colour) | `site.css .btn-gold` | blue is the only interactive colour | **D1** |
| A7 | Legacy teal `#0f766e` for the public verification "valid" result; also in unused `components/public/layout.blade.php` | `public/verify.blade.php` | emerald `#07855B` for verified/valid | Batch 9: verify page recoloured to tokens. Unused component recorded, not deleted |
| A8 | Glyph entities used as icons (`&#10004; &#9888; &#10006; ?`) on verification result | `verify.blade.php` | SVG outline icons, 2 px stroke | Batch 9: SVG sprite icons (`public.partials.icons`) |
| A9 | Hard-coded hex colours inline in every shared record-shell view | `resources/views/filament/shared/*.blade.php` | tokens only | Batch 2: `oi-*` classes in `theme.css` |
| A10 | `/portal/{portal}` Wave10 shell (`public/css/opesinsure-portals.css`) = third visual system | `routes/wave10.php`, `wave10/portal.blade.php` | one shell | Recorded, unchanged (route contract). **D2** |
| A11 | Icon family: Filament Heroicons (outline) | all panels | Lucide outline, 2 px | **D3** (needs a new composer icon package). Heroicons outline kept meanwhile: one outline family, no mixing, no emoji |

## B. Cross-cutting defects (before)

| # | Defect | Evidence | Batch |
|---|---|---|---|
| B1 | **No locale handling in any Filament panel** — admin/insurer/broker always render `config('app.locale')`; `users.locale` ignored; no EN/FR switch | `grep setLocale app/` → only the public middleware and the mobile API | 1 |
| B2 | 0 of 107 admin resources translate their labels (`__()` unused); Filament chrome is translated once the locale is set | grep | 10 → remains a register item; shared components and portals are fully bilingual |
| B3 | Shared record-shell tables (documents, financial) squeeze 9–10 columns into phone width | `document-viewer`, `financial-panel` | 2 (labelled mobile cards) |
| B4 | Status badges colour-only (no icon) in shared views | `detail-header`, `timeline`, `document-viewer`, `financial-panel` | 2 (badge = icon + label + colour) |
| B5 | Failure vocabulary has 10 failures but not the required component states: downstream timeout, offline/queued, retrying, partial completion, expired, read-only/locked, cancelled, archived | `FailureState` | 2 |
| B6 | No distinct financial breakdown (gross premium / platform fee / processing fee / commission / carrier settlement) | — | 3 |
| B7 | No approval panel (maker, checker, requested change, evidence, before/after); self-approval only rejected server-side | — | 3 |
| B8 | No persistent payment state panel (timeout / retry / receipt recovery) on web | — | 3 |
| B9 | No global 403/404/409/419/422/429/500/503 pages — Laravel defaults, English-only, unbranded | `resources/views/errors` absent | 9 |
| B10 | Desktop sidebar not collapsible to 80 px | panel providers | 1 |
| B11 | Portal KPI grid not per handoff (4 desktop / 2 mobile) | `PortalMetricsWidget` | 6/7 |
| B12 | Money helper prints `XAF` with a breakable space between amount and currency | `Money::format` | 3 (`FCFA`, non-breaking) |
| B13 | No `prefers-contrast` support | `theme.css` | 1 |
| B14 | Inputs 14 px on phones (browser zoom) | Filament default | 1 |

## C. Route-to-screen register

### C.1 Insurer portal (`/insurer`; CARRIER_* / UNDERWRITER / claims roles via `PortalAccess`; tenant = entry membership)

| Route (name) | Screen | Class | APIs / perms | Before | Responsive | Token | Type | Spacing/card | Missing states | EN/FR | A11y | Tests present | Correction | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `GET insurer` (`filament.insurer.pages.portal-dashboard`) | Dashboard | `Shared\Pages\PortalDashboard` + `PortalMetricsWidget` | `PortalAccess`, `PortalDashboardMetrics` | partial | KPI columns default | A1 A3 | A2 | ok | no-tenant empty state | EN only (B1) | A4 | `WebExperienceShellTest` | B1 B10 B11 | `UiCanonicalWebTest` |
| `GET insurer/login` | Sign in | `PortalLogin` | — | partial | ok | A1 | A2 | ok | — | EN only | A4 | yes | B1 + language switch | `UiCanonicalWebTest` |
| `GET insurer/password-reset/*`, `insurer/profile` | Recovery / profile | Filament | — | partial | ok | A1 | A2 | ok | — | EN only | A4 | no | B1 | `UiCanonicalWebTest` |
| `GET insurer/policies` | Policy list | `ListPolicies` + `ListScreen` | policies.read | partial | table scroll | A1 | — | ok | empty/no-result ✓ | labels EN | ok | yes | B1 | existing |
| `GET insurer/policies/{record}` | Policy detail | `ViewPolicy` + `RecordShell` | policies.read + tenant | partial | B3 | A9 | — | inline styles | B5 | shell EN/FR | B4 | yes | B3 B4 B5 | `UiCanonicalWebTest` |
| `GET insurer/claims`, `insurer/claims/{record}` | Claim list/detail + authority | `ListClaims` / `ViewClaim` | claims.read | partial | B3 | A9 | — | inline | B5 | shell EN/FR | B4 | yes | B3 B4 B5 | `UiCanonicalWebTest` |
| — | Contracts & delegated authority; products/tariffs; UW referrals; endorsements/cancellations; bordereaux/settlement (handoff Batch 7) | not exposed in portal | — | **missing** (backend in admin/API) | — | — | — | — | — | — | — | — | **D4** | — |

### C.2 Broker portal (`/broker`; BROKER_* / branch roles)

| Route | Screen | Class | Before | Correction |
|---|---|---|---|---|
| `GET broker` | Dashboard | `PortalDashboard` | partial | as C.1 |
| `GET broker/login`, `password-reset/*`, `profile` | Auth | `PortalLogin`, Filament | partial | B1 |
| `GET broker/quotes`, `/{record}` | Quotes | `ListQuotes` / `ViewQuote` | partial (no infolist → disabled-form fallback) | B1; infolist D4 |
| `GET broker/policies`, `/{record}` | Policies | shared | partial | B3–B5 |
| `GET broker/claims`, `/{record}` | Claims | shared | partial | B3–B5 |
| — | Tenant/branches/staff, customer ledger, receivables, renewals, bordereaux, commissions, marketplace publishing, reports, compliance (handoff Batch 6) | — | **missing** in portal (admin-only) | D4 |

### C.3 Platform administration (`/admin`; platform roles; 107 resources + 10 custom pages)

Common to every row: before = **partial**; responsive = Filament default (tables scroll horizontally inside their container, drawer navigation under 1024 px); tokens A1/A3/A4; typography A2; labels English-only (B2); list states = Filament default unless `ListScreen`; correction = Batch 1 shell/tokens/locale (applies to every page), Batch 2/3 shared components where the resource adopts them.

| Navigation group | Resource | View page | ListScreen | RecordShell |
|---|---|---|---|---|
| Approvals | ApprovalMatrixRuleResource | no infolist (disabled-form fallback or list-only) | no | no |
| Approvals | ApprovalRequestResource | no infolist (disabled-form fallback or list-only) | no | no |
| Claims operations | ClaimDecisionResource | no infolist (disabled-form fallback or list-only) | no | no |
| Claims operations | ClaimPaymentResource | no infolist (disabled-form fallback or list-only) | no | no |
| Claims operations | ClaimReserveResource | no infolist (disabled-form fallback or list-only) | no | no |
| Claims operations | ClaimResource | infolist | yes | yes |
| Customers & partners | AttributionResource | infolist | no | no |
| Customers & partners | ConsentResource | infolist | no | no |
| Customers & partners | CustomerResource | infolist | no | no |
| Customers & partners | PartnerLicenceResource | infolist | no | no |
| Customers & partners | PartnerResource | infolist | no | no |
| Customers & partners | PartyResource | infolist | no | no |
| Financial operations | BordereauResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | CarrierSettlementResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | CommissionAccrualResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | CommissionRuleResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | FinancialCaseResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | JournalResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | PartnerPayoutResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | PartnerStatementResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | PaymentAttemptResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | PaymentConnectionResource | no infolist (disabled-form fallback or list-only) | no | no |
| Financial operations | ReconciliationResource | no infolist (disabled-form fallback or list-only) | no | no |
| Integrations | IntegrationClientResource | no infolist (disabled-form fallback or list-only) | no | no |
| Integrations | IntegrationDeliveryAttemptResource | no infolist (disabled-form fallback or list-only) | no | no |
| Operations | FulfilmentResource | no infolist (disabled-form fallback or list-only) | no | no |
| Operations | MobileIssueReportResource | infolist | no | no |
| Operations | NotificationDeliveryResource | no infolist (disabled-form fallback or list-only) | no | no |
| Operations | SupportTicketResource | no infolist (disabled-form fallback or list-only) | no | no |
| Policy operations | CancellationRuleResource | no infolist (disabled-form fallback or list-only) | no | no |
| Policy operations | CertificateResource | no infolist (disabled-form fallback or list-only) | no | no |
| Policy operations | CertificateTemplateResource | no infolist (disabled-form fallback or list-only) | no | no |
| Policy operations | PolicyIssuanceResource | no infolist (disabled-form fallback or list-only) | no | no |
| Policy operations | PolicyResource | infolist | yes | yes |
| Policy operations | PolicyTransactionResource | no infolist (disabled-form fallback or list-only) | no | no |
| Policy operations | RenewalResource | no infolist (disabled-form fallback or list-only) | no | no |
| Policy operations | StickerBatchResource | no infolist (disabled-form fallback or list-only) | no | no |
| Policy operations | StickerInventoryResource | no infolist (disabled-form fallback or list-only) | no | no |
| Products & pricing | CoverageDefinitionResource | no infolist (disabled-form fallback or list-only) | no | no |
| Products & pricing | ExclusionDefinitionResource | no infolist (disabled-form fallback or list-only) | no | no |
| Products & pricing | InsuranceLineResource | no infolist (disabled-form fallback or list-only) | no | no |
| Products & pricing | InsuranceProductResource | infolist | no | no |
| Products & pricing | TariffVersionResource | no infolist (disabled-form fallback or list-only) | no | no |
| Sales workspace | PaymentRequestResource | no infolist (disabled-form fallback or list-only) | no | no |
| Sales workspace | ProposalResource | no infolist (disabled-form fallback or list-only) | no | no |
| Sales workspace | QuoteResource | no infolist (disabled-form fallback or list-only) | yes | no |
| Sales workspace | RiskAssetResource | no infolist (disabled-form fallback or list-only) | no | no |
| Trust & compliance | ComplianceCaseResource | no infolist (disabled-form fallback or list-only) | no | no |
| Trust & compliance | DataSubjectRequestResource | no infolist (disabled-form fallback or list-only) | no | no |
| Trust & compliance | PrivilegedAccessGrantResource | no infolist (disabled-form fallback or list-only) | no | no |
| Trust & compliance | RegulatoryReportRunResource | no infolist (disabled-form fallback or list-only) | no | no |
| Trust & compliance | RiskAlertResource | no infolist (disabled-form fallback or list-only) | no | no |
| Underwriting | DisclosureSchemaResource | no infolist (disabled-form fallback or list-only) | no | no |
| Underwriting | DocumentRequirementResource | no infolist (disabled-form fallback or list-only) | no | no |
| Underwriting | UnderwritingCaseResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | BranchResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | BrokerMasterDataMappingResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | BusinessHoursResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CalendarExceptionResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CarrierMasterDataMappingResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CaseRecordResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CaseTaskResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CaseTypeResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaAuthorityResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaBranchResource | infolist | no | no |
| Platform configuration / reference data | CimaCompulsoryInsuranceResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaInsurerAuthorizationResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaLegalReferenceResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaMicroBranchResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaProductMappingResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaReportingCategoryResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaReportingMappingResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | CimaTermResource | infolist | no | no |
| Platform configuration / reference data | DeviceResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | DocumentClassApplicabilityResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | DocumentIssuanceProfileResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | DocumentNumberingFamilyResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | DocumentPackItemResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | DocumentPackResource | infolist | no | no |
| Platform configuration / reference data | DocumentStatusChangeResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | DocumentTemplateResource | infolist | no | no |
| Platform configuration / reference data | DocumentTypeResource | infolist | no | no |
| Platform configuration / reference data | GeneratedDocumentResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | InstitutionProfileResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | InstitutionVerificationLabelResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | InvitationResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataAliasResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataChangeResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataDomainResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataImportResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataListResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataMergeRequestResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataReviewResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataTenantOverrideResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MasterDataValueResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | MembershipResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | ProductDocumentRequirementResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | TenantResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | UserResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | VehicleGenerationResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | VehicleMakeResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | VehicleMasterChangeResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | VehicleMasterReviewResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | VehicleModelResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | VehicleReferenceValueResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | VehicleVariantResource | no infolist (disabled-form fallback or list-only) | no | no |
| Platform configuration / reference data | WorkQueueResource | no infolist (disabled-form fallback or list-only) | no | no |

Custom admin pages (`app/Filament/Admin/Pages`): CimaBrokerSetup, CimaInsurerSetup, CimaRegulatoryDashboard, DataReadinessDashboard, DocumentEngine (21 routes — **owned by cs1**, not restyled here), DocumentRequirementMatrix, IntegrationHealth, MasterDataDashboard, MasterDataQuality, PlatformSettingsPage — all **partial**; they inherit Batch 1 tokens, focus and locale.

### C.4 Public website and public verification

| Route | Screen | View | Before | Defects | Correction |
|---|---|---|---|---|---|
| `GET /` | Landing | `public/landing` | complete (owner-approved) | A2 A5 A6 | D1 |
| `GET about, how-it-works, claims, faq, privacy, terms, partners, providers, contact, account/delete, download, demo` | Content pages | `public/pages/*` | complete | A2 A5 A6; bilingual via `site.php` ✓ | D1 |
| `GET app/{path?}` | App-link fallback | `public/pages/open-app` | complete | — | — |
| `GET verify` | Public certificate / document verification | `public/verify` | partial | A7 A8, inline hex; EN+FR side by side (intentional for QR scans) | Batch 9 recolour + SVG icons + labelled status; **verification behaviour/security owned by cs1, unchanged** |
| `GET portal/{portal}` | Wave10 shell | `wave10/portal` | placeholder | A10 | D2 |
| (none) | 403 / 404 / 409 / 419 / 422 / 429 / 500 / 503 (maintenance) | — | **missing** (B9) | — | Batch 9 |

### C.5 Customer Web Marketplace / Agent Web Workspace

No web routes exist: customer and agent experiences are the Expo app (`mobile app/`), and the public site deep-links into it (`/app/*`). Their web part is the public site (C.4). Handoff Batches 4 and 5 are therefore **N/A on web** (recorded, not silently skipped); their requirements are transferred to `MOBILE_UI_HANDOFF_NOTES.md`.

## D. Decisions required (owner)

| # | Decision | Options |
|---|---|---|
| D1 | Public site (owner-approved mockup: Manrope, gold gradient CTAs, gold focus ring) vs handoff (Inter, blue-only interaction, blue focus) | (a) keep the marketing site as a documented exception; (b) migrate `site.css` to canonical tokens (coordinate with the agent currently editing it) | **Approved (migrate); waiting for the owner design folder** |
| D2 | `/portal/{portal}` Wave10 placeholder shell | **Approved 2026-09-25: retired (301 redirects)** |
| D3 | Lucide icons in Filament | **Approved: done via central mapping (see UI_BATCH_REPORTS)** |
| D4 | Broker/carrier sections in portals | **Approved: agreements, bordereaux, settlements, receivables, staff exposed read-only** |

## E. Register status after implementation

See `UI_BATCH_REPORTS.md` → "Remaining register items" for the live status of every row above.
