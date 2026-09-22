# OpesInsure Route-to-Screen Implementation Register

**Purpose:** the mandatory first action required by `OPESINSURE_CLAUDE_UI_IMPLEMENTATION_HANDOFF.md` before any styling work begins, built against `OpesInsure_Premium_Visual_Identity_and_Dashboard_Specification_v3.docx` ("the v3 spec"). Preserve and update this register throughout implementation; do not treat a page as complete until its row says so with evidence.

Audited: 2026-09-21, against the live application at `opesinsure.test`.

---

## A. Style conflict register

The application currently runs **three separate, incompatible visual systems** at once. None of them matches the v3 spec. This is the most urgent structural finding — implementing new screens without resolving this first will add a fourth system.

| # | System | Where | Canvas | Navy | Primary action color | Radius | Shadow | Font | Icons |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Filament admin theme | `resources/css/filament/admin/theme.css`, applied to all 55 Filament resources + auth pages | Ivory `#fffcf7` (whole app body) | `#071a2b` | Blue `#1769e0` | 8px controls / 12px sections | none | Inter only (Filament default) | Heroicons (Filament default) |
| 2 | Wave10 portal shell | `public/css/opesinsure-portals.css`, used only by the placeholder `/portal/{portal}` route | `#f4f7fb` | `#082f49` | Teal `#0f766e` — **legacy brand color, explicitly prohibited by v3 §4** | 18px everywhere | Shadow on every KPI/panel card — **v3 §9.3 requires no default operational shadow** | Inter only | none (text-only sidebar) |
| 3 | v3 spec (new, authoritative) | Not yet implemented anywhere | Mineral 50 `#F5F7F8` (operational); Warm Ivory 25 `#FCFBF7` reserved for signature/welcome surfaces only | Three-tier: Obsidian 1000 `#04131F` / Assurance 950 `#071A2B` / Atlantic 850 `#0E3048` | Insurance Blue 650 `#155FCC` | 10px controls / 12px operational cards / 16–20px signature only | none by default; shadow reserved for lifted/hero elements only | Manrope (headings) + Inter (body), not yet loaded | Lucide + custom insurance icon set, none built yet |

**Specific defects found:**

1. **Ivory-as-operational-canvas conflict.** Current theme.css uses Ivory for the *entire* app body (`.fi-body{background:var(--oi-ivory)}`). v3 §9.1 reserves Ivory for signature/welcome surfaces only and mandates Mineral 50 as the operational canvas. Every existing Filament page needs this corrected.
2. **Legacy teal brand color** (`--brand:#0f766e`) is live in the Wave10 portal shell. v3 §2 and the handoff doc explicitly call out "legacy teal brand use" as something to find and remove.
3. **Auth/login pages are unthemed.** `theme.css`'s `@source` scan covers `app/Filament/Admin/**` and the two Blade view paths, but the actual Filament login page still renders Filament's stock dark theme (confirmed live) — none of the three systems above apply to it.
4. **Generic Heroicons throughout**, no Lucide, no custom OpesInsure insurance icon set (motor cover, sticker, bordereau, etc. — v3 §6.2 lists 13 required custom symbols, none exist).
5. **No bottom tab bar / off-canvas drawer mobile navigation anywhere.** Filament's default responsive sidebar (collapsible drawer) is the only mobile pattern in the app; it does not match v3 §14's per-portal mobile navigation requirements.
6. **`docs/design/DESIGN_SYSTEM.md`** (this project's older, v1 design doc) is now superseded by the v3 spec but has not been marked as such — risk of a future implementer following the wrong document.
7. **No French UI strings in the Filament admin panel** (labels, navigation, validation messages are English-only; `wave8`–`wave11` lang files exist for their own API-facing strings, but no Filament resource has translated labels). v3 §20 requires full EN/FR parity.
8. **No infolist implemented on any of the 55 Filament resources** (see register below) — this is a content/functionality gap, not styling, but it means most "View" screens are currently blank regardless of visual treatment, which affects how batches should be sequenced (styling a page that renders nothing has no visible effect).

---

## B. Route-to-screen register

### B.1 The five primary portal dashboards (v3 §11)

| Portal | Route | Current state | Notes |
|---|---|---|---|
| Platform Administration | `/admin/*` (Filament panel, 55 resources) | **Partial** | Functional CRUD/list screens exist; zero alignment with v3 visual system (see conflict register). This *is* "Platform Administration" per the handoff doc's 6 experience layers. |
| Customer Web Marketplace | none | **Missing** | No route, no controller, no view beyond the generic placeholder below. |
| Agent Web Workspace | none | **Missing** | Same. |
| Broker ERP | none | **Missing** | Same. |
| Carrier Portal | none | **Missing** | Same. |
| Public Verification Portal | none | **Missing** | Certificate/policy public verification mentioned in v3 §19.6; no public route exists. |
| *(generic placeholder)* | `/portal/{admin\|broker\|carrier\|agent\|customer}` | **Placeholder** | Added this session to close the "unwired Blade shell" gap from the Wave 8-11 merge. Renders real backend metrics (via `PortalDashboardQuery`) but uses the Wave10 portal CSS (system #2 above — wrong tokens, includes the prohibited teal). **This route should be treated as scaffolding to replace, not a fourth dashboard to preserve as-is.** |

**First-viewport priority (v3 §11.1) for all five:** not yet implemented for any — no signature assurance card, no priority queue, no proof marks exist anywhere in the app.

### B.2 Platform Administration — Filament resources by navigation group

State legend: **Complete** (form + table + view, styled) · **Partial** (functional, unstyled) · **Placeholder** (renders but no real content) · **Missing** (no infolist, so View page is blank).

| Group | Resource | Pages | Form | View page state |
|---|---|---|---|---|
| Customers & partners | Attributions, Parties, Partners, Customers, Consents, PartnerLicences | index, create, view | ✅ | Missing (no infolist) |
| Products & pricing | InsuranceLines, InsuranceProducts, TariffVersions, CoverageDefinitions, ExclusionDefinitions | index, create, edit | ✅ | n/a (no view page) |
| Sales workspace | Quotes, Proposals, RiskAssets, PaymentRequests | index, (+create/edit for RiskAssets) | RiskAssets only | Missing |
| Underwriting | UnderwritingCases, DisclosureSchemas, DocumentRequirements | index, (+create/edit for the latter two) | Partial | Missing |
| Policy operations | Policies, PolicyIssuances, PolicyTransactions, Renewals, CancellationRules, Certificates, CertificateTemplates, StickerBatches, StickerInventory | index, view/create mixed | Partial | Missing |
| Claims operations | Claims, ClaimDecisions, ClaimReserves, ClaimPayments | index, view | ❌ (all empty — audit/lifecycle records, not manually created) | Missing |
| Financial operations | Journals, CommissionAccruals, CommissionRules, CarrierSettlements, PartnerPayouts, PartnerStatements, Reconciliations, PaymentAttempts, PaymentConnections, FinancialCases, Bordereaux | index, view | Mostly empty | Missing |
| Trust & compliance | RiskAlerts, ComplianceCases, DataSubjectRequests, PrivilegedAccess, RegulatoryReports | index, view | Empty (new in Wave 9) | Missing |
| Operations | Fulfilments, NotificationDeliveries, SupportTickets | index, view | Empty (new in Wave 8) | Missing |
| *(top-level, no group)* | Organizations (Tenants), Branches, Access & roles (Memberships), Invitations, Trusted devices (Devices), Platform users (Users) | index, create, view/edit | ✅ mostly | Tenants/Users only |

**Every single "View" page across all 55 resources is currently blank** except where a disabled form happens to render (the 12 resources with `form=yes` and a `view` page). This is the single highest-leverage functional gap in the whole admin experience, and it's orthogonal to styling — no amount of visual polish fixes a blank page.

### B.3 Authentication

| Screen | Route | State | Notes |
|---|---|---|---|
| Sign in | `/admin/login` | Partial | Functional, includes local-only one-click demo login panel. Unthemed (Filament stock dark, see conflict #3). |
| Password reset | `/admin/password-reset/*` | Not yet verified | Registered by Filament's `->passwordReset()` call; not manually tested this session. |

---

## D. Batch progress log

### Batch 1 — Foundation + shell: **Done** (2026-09-21)

- `resources/css/filament/admin/theme.css` rewritten to the full v3 token set (colors, radii, fonts); Mineral 50 is now the operational canvas (defect #1 fixed), sidebar is Assurance Navy 950 at 264px/80px-collapsed with 44px item targets.
- Manrope Variable self-hosted (`public/fonts/manrope/*.woff2`) and applied to all heading selectors; confirmed via computed `font-family` on rendered headings.
- `public/css/opesinsure-portals.css` tokens de-tealed — legacy `#0f766e` removed (defect #2 fixed); file marked as placeholder scaffolding pending the real portal redesign.
- `docs/design/DESIGN_SYSTEM.md` marked superseded (defect #6 fixed).
- **Login page** (defect #3): fixed two bugs found during verification, not just a class-name restyle:
  1. `AdminPanelProvider` now calls `->darkMode(false)`. The panel was rendering `html.dark` (Filament's default), and the new `.fi-section{background:var(--oi-white)}` rule combined with Filament's `dark:text-gray-200` classes produced literal white-on-white text on the demo-accounts panel — a real accessibility break, not cosmetic. The v3 spec defines only one light institutional palette, so dark mode is now disabled panel-wide (theme switcher no longer appears in the profile menu — confirmed).
  2. `Color::hex()` was found to only preserve a color's hue; it discards the exact input lightness/chroma and applies a generic ramp, so none of the v3 spec's exact hex values (`#155FCC` etc.) were actually reaching any shade. Replaced with an explicit palette (`AdminPanelProvider::v3Colors()`) that splices the spec's soft/border/base/text stops into shades 50-100/200-300/400-700/800. Verified `--primary-600` resolves to exactly `rgb(21, 95, 204)` (`#155FCC`).
  - Screenshot evidence: login canvas is Mineral 50, card is white with Manrope "OpesInsure"/"Sign in" heading, submit button is exact Insurance Blue, demo-accounts panel text is legible dark-on-white.
- **Mobile nav** (defect #5, admin panel only): verified at 375px width — off-canvas drawer opens correctly themed (Navy 950 background, white active-item pill, dimmed backdrop). This satisfies the admin/Platform-Administration portal; the bespoke per-portal mobile patterns v3 §14 describes for the four customer-facing portals remain blocked on those portals being built (still **Missing**, see B.1).
- Not touched in this batch (intentionally out of scope): Heroicons→Lucide swap, custom insurance icon set, French UI strings, infolists — all tracked as separate defects/decisions above (#4, #7, #8).

### Batch 2 — Customers & partners: **Done** (2026-09-22)

Verified all 6 resources (Party, Customer, Consent, Partner, PartnerLicence, Attribution) end-to-end in the browser: create form → saved record → View page. Building the infolists (the batch's main deliverable) surfaced four real, pre-existing bugs, all now fixed:

1. **View pages weren't "blank" as first assumed — worse, they silently showed wrong data.** With no `infolist()` defined, Filament v4's `ViewRecord` falls back to rendering the create form in a disabled state. But several fields on these resources (`phone_e164`/`email`/`identifier_value` on Party, `segment`/`external_reference` on Customer, `evidence_reference`/`affirmed` on Consent) are form-only inputs that services redistribute into related tables or JSON columns — they aren't real model attributes, so the fallback form showed them **blank even when the data existed**. Real `infolist()` methods added to all 6 `*Resource.php` files fix this; verified live with real records (e.g. Customer's "Segment: Retail" / "External reference: CRM-88213" now render correctly).
2. **Reactive form fields were broken.** `PartyResource`'s `type` select and `AttributionResource`'s `origin_type` select controlled sibling fields' `visible()` conditions (e.g. show "Registration number" only for organizations) without `->live()` — so switching the option never revealed the dependent field. Fixed by adding `->live()` to both.
3. **Partner search was completely broken** on `PartnerLicenceResource` and `AttributionResource`: `Select::make('partner_id')->relationship('partner','id')->searchable()` searches the literal `id` UUID column, so typing a partner's name always returned "No options match your search" (only `->preload()`'s unfiltered initial list worked). Fixed with an explicit `getSearchResultsUsing()` that searches the linked party's display name.
4. **Silent failure on business-rule rejection, app-wide.** Application services throw `ValidationException::withMessages(['bare_key' => ...])`. Filament's `CreateRecord::create()` and `InteractsWithActions` both catch `ValidationException` generically and just re-throw it; Livewire returns it to the browser, but since no form input has a `wire:model` matching the bare key (vs. the real `data.bare_key` path), nothing ever renders — the user sees the page just sit there with zero feedback. Reproduced live (Attribution create silently did nothing when the selected partner's licence wasn't yet verified). Fixed for this batch's 6 Create pages + 4 row actions (Customer status, Consent withdraw, PartnerLicence decide, Attribution dispute/resolve) via a new `App\Filament\Admin\Concerns\{NotifiesServiceValidationErrors,ServiceValidation}` — verified via network trace that a proper "Action failed" danger notification now fires. **This almost certainly affects the other ~49 resources too** (same service layer, same pattern) — flagged as a separate follow-up, not fixed site-wide here. Fixed below in Batch 2b.

### Batch 2b — Silent service-validation failures, site-wide sweep: **Done** (2026-09-22)

Follow-up to defect #4 above. Swept every remaining Filament resource (all of B.2 except the 6 already covered in Batch 2) for the same bug: an Application Service call — from a `CreateRecord`/`EditRecord` page's `handleRecordCreation()`/`handleRecordUpdate()`, or from a table/header `Action::make(...)->action(...)` closure — left unguarded against `ValidationException`, so a business-rule rejection produced zero visible feedback.

- **Shared trait extended first:** `App\Filament\Admin\Concerns\NotifiesServiceValidationErrors` only overrode `create()`; added a matching `save()` override (`public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void`, mirroring `EditRecord`'s real signature) so Edit pages get the same protection.
- **52 resources reviewed** (every resource under `app/Filament/Admin/Resources` not already fixed in Batch 2). **29 resources actually needed a fix** (33 files touched, 2 of them getting two separate fixes): Devices, Tenants, Invitations, Memberships, IntegrationClients, IntegrationDeliveryAttempts, TariffVersions, RiskAssets, DisclosureSchemas, DocumentRequirements, CancellationRules, Proposals, PolicyIssuances, Policies, PolicyTransactions, Renewals, StickerBatches, CertificateTemplates, Certificates, PaymentRequests, Claims, ClaimPayments, ClaimDecisions, ClaimReserves, ComplianceCases, DataSubjectRequests, PrivilegedAccess, RegulatoryReports, RiskAlerts. The other 23 (CommissionRules, Branches, Users, CoverageDefinitions, ExclusionDefinitions, InsuranceLines, InsuranceProducts, Quotes, UnderwritingCases, StickerInventory, PaymentConnections, PaymentAttempts, Journals, Reconciliations, FinancialCases, Bordereaux, CarrierSettlements, CommissionAccruals, PartnerPayouts, PartnerStatements, NotificationDeliveries, SupportTickets, Fulfilments) needed no change — either plain Eloquent create/edit with no Application Service involved, or table/view actions with no mutating service call (pure `ViewAction` reporting screens).
- **Key finding: the bug's center of mass moved.** In Batch 2, every instance was a Create page or a table row action. Across the other 49 resources, the large majority of real instances are **`ViewRecord` page header actions** — the approve/reject/assign/reserve/advance/void/transition buttons that drive a record's lifecycle (e.g. `ViewClaim`'s assign/reserve/advance, `ViewPolicyIssuance`'s approve/reject, `ViewPolicyTransaction`'s four payment/approval actions, `ViewPrivilegedAccessGrant`'s approve/revoke). These are exactly where maker-checker and state-machine rejections happen, so they were the highest-impact fraction of the sweep.
- **New defect surfaced, left out of scope:** several of the newly-wrapped actions (e.g. `ViewClaim::assign/reserve/advance`, `ViewClaimPayment::approve`, most of the Compliance/Risk/Support header actions) had **no success notification to begin with** — success was already silent before this fix, independent of the `ValidationException` bug. This fix only guarantees *failure* is now visible; it does not add a "Saved"/success toast where one never existed. Worth a dedicated follow-up pass.
- **Verified mechanism directly** (not just `php -l`): via `php artisan tinker` against the `opesinsure_testing` database, called `CancellationRuleService::approve()` with the same user as both maker and checker — confirmed it throws `ValidationException::withMessages(['actor' => ...])` (the exact bare-key bug shape), then confirmed `ServiceValidation::run()` catches it, returns `null`, and queues a Filament `danger` notification (`title: "Action failed"`, `body: "The requester or creator cannot approve their own record."`) via `session()->push('filament.notifications', ...)` — the same mechanism the browser's Livewire notification component reads from, so this is the real success/failure path, not a simulation.
- **Full test suite unaffected:** `APP_ENV=testing php artisan test --env=testing` → 157 passed, 4 failed. The 4 failures (`MembershipRevocationTest`, `PartyIdentityTest`, `CertificateSecurityTest`, `RenewalAttributionTest`) are pre-existing and unrelated (an `oauth_access_tokens.user_id` bigint/UUID mismatch, an encryption-format assertion, and two tests referencing undefined variables in their own test code) — same 4 as before this batch, no new failures introduced.

## C. Recommended batch order

Per the handoff doc's "batches of five page families" rule, and prioritizing where styling will actually be visible (skip pages that render blank until their content gap is separately closed):

1. **Foundation** (not a page batch): resolve the token conflict register above — one canonical `theme.css` implementing the v3 tokens, remove/replace `opesinsure-portals.css`, load Manrope, mark `DESIGN_SYSTEM.md` as superseded.
2. **Auth + shell**: login page, sidebar/topbar navigation chrome, mobile nav pattern for the admin portal — highest visibility, touched on every session.
3. **Customers & partners** family (6 resources, all have real create/view forms — content exists to style).
4. **Products & pricing** + **Underwriting** families (form-heavy, no blank-view problem).
5. **Policy operations** family.
6. Everything else, sequenced *after* a decision on the infolist gap (§A.8) — styling 30+ resources whose view pages are blank should wait until there's content to show, or the batch should explicitly scope "list + form only, view page tracked separately."

This register should be updated as each batch completes, per the handoff doc's acceptance rule: responsive + bilingual + accessibility + state + performance + real-device evidence, not just "renders."
