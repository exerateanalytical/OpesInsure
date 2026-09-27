# UI audit — Provider portal (/provider), 2026-09-27

Scope: the hospital / clinic workspace at `/provider` (`app/Application/Providers/Workspace/Filament/Pages`, one
shared base `ProviderWorkspacePage`), for every provider role in `RoleCatalogue::PROVIDER_ROLES`, checked against
`OpesInsure_Provider_Portal_Hospital_Clinic_Gap_Free_Implementation_Spec_v1.json` (rbac_permissions, dashboards,
ui_screen_register, principles.medical_data_minimization, acceptance test 1). Insurer-side Health pages
(`app/Filament/Admin/Pages/Health*`) were out of scope and not touched.

Regression guard: `tests/Feature/Web/ProviderRoleNavCrawlTest.php` (per-role crawl in FR then EN, hidden screens,
French labels and formats, diagnosis minimisation, per-desk dashboard widgets).

## Findings and fixes

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 1 | Blocker | No governed provider role could use the panel. Pages are gated on the spec permissions (`provider.dashboard.view`, `provider.claim.view`, …), but `RoleCatalogue::PROVIDER_PERMISSIONS` only carried the older FRP V / portal codes (`provider.portal.read`, `provider_portal.*`). Every role — `PROVIDER_ADMIN` included — got **403 on the home page** (`/provider` → dashboard) and an almost empty sidebar. | `RoleCatalogue::PROVIDER_WORKSPACE_PERMISSIONS`: spec permissions per desk (reception, clinical, dispensing, billing, finance, administrator), merged into the defaults of the 7 FRP V roles and the 6 legacy SPEC roles (`FRONT_DESK`, `DOCTOR`, `BILLING_OFFICER`, `PHARMACY_USER`, `LAB_USER`, `FINANCE_USER`). Existing tenants: run `php artisan rbac:sync-role-permissions` (additive) after deploy. |
| 2 | High | Dashboard showed every widget to everyone: reception saw receivables (outstanding / payable / disputed amounts) and the per-insurer finance table. | Dashboard is per desk (spec `dashboards`): executive + finance table only with `provider.finance.view`; insurance desk with `provider.eligibility.check` / `provider.preauth.view`; claims & billing with `provider.claim.view`. Dashboard data computed once per render. |
| 3 | High | Clinical minimisation relied on value redaction only: tables built from "all keys of the first row" still rendered a *Diagnosis summary* / *Clinical notes* column (empty) to non-clinical roles, and the episode form offered a diagnosis field to non-clinical users. | The base page never renders clinical keys (`diagnosis_summary`, `clinical_notes`, `diagnosis_code`, `admission_reason`, `type_details`, `decision_notes`, `discharge_summary`) — columns, cards or detail rows — unless `ProviderAccess::mayReadClinical`; treatment episodes have an explicit column list; the diagnosis input is shown to clinical roles only. Front desk has no treatment / documents screens at all. |
| 4 | Medium | Column headers and card labels were raw keys with underscores removed (`requested amount minor`, `submitted claims`), English in the French UI. | `provider_workspace.columns.*` (EN/FR, ~150 keys) via `ProviderWorkspacePage::label()`; unknown keys are humanised, never raw. |
| 5 | Medium | Money shown as raw minor units (`1500000`), dates as ISO strings, booleans as 1/0, ids (`tenant_id`, `*_id`) as columns. | `ProviderWorkspacePage::cell()`: `Money::display` with the row currency (FCFA), `d/m/Y` dates and `d/m/Y H:i` timestamps in the viewer's timezone (`TimezoneResolver`), Oui/Non, em dash for empty; identifier and redaction-marker columns hidden. Aging buckets formatted as money and labelled. |
| 6 | Medium | Permitted feature with no UI: `provider.users.manage` only listed users; assigning a role / facility scope or revoking needed the API. | User management form (staff of the provider, canonical portal role with clinical flag, ALL / ASSIGNED facilities) and a Revoke row action, both through the same controller actions as `POST /users` and `/users/{id}/revoke` (validation, facility scope, audit). |
| 7 | Low | Integration settings page had English-only literals. | Translated (`ui.idempotency_rule`, `ui.source_system_rule`). |

Icons: the panel icons are being moved to the lucide set by a separate change in progress; this audit did not
touch them. Every provider nav entry has an icon.

## Role → navigation (after the fix)

| Role (FRP V / legacy) | Screens |
|---|---|
| PROVIDER_ADMIN | all 19 screens (no `provider.reconciliation.match`: payment matching stays with finance) |
| PROVIDER_FRONT_DESK / FRONT_DESK | Dashboard (insurance desk), Eligibility, Preauthorizations, Admissions, Notifications, Profile, Facilities (+ Contracts for legacy FRONT_DESK via `provider_portal.network.view`) |
| PROVIDER_DOCTOR / DOCTOR | desk screens + Treatment episodes, Documents (medical documents for clinical provider roles) |
| PROVIDER_BILLING / BILLING_OFFICER | Dashboard (claims & billing + insurance desk), Preauthorizations, Admissions, Treatment episodes (no diagnosis), Claims, Disputes, Contracts & tariffs |
| PROVIDER_PHARMACY / PHARMACY_USER, PROVIDER_LAB / LAB_USER | Dashboard, Eligibility, Preauthorizations, Admissions, Claims (they bill their own dispensing / tests) |
| PROVIDER_FINANCE / FINANCE_USER | Dashboard (executive + finance), Claims, Accounts, Settlements, Reconciliation, Disputes, Contracts, Reports |

Every screen outside a role's navigation answers 403 (never 500) — asserted by the crawl.

## Clinical minimisation (spec acceptance test 1)

Clinical content is decided by the provider-user role (`provider_users.provider_role`, `ProviderAccess::ROLES`):
PRACTITIONER, NURSE, PREAUTHORIZATION_OFFICER, MEDICAL_RECORDS_OFFICER, PHARMACIST, LAB_TECHNICIAN. A user with
no provider-user row (legacy organisation employee) never sees clinical content. Verified by the crawl for
front desk (both codes), billing, finance and administrator (no diagnosis value, column or label) and for a
practitioner (diagnosis visible).

## Open items (not fixed here)

- Facility management: `provider.settings.manage` departments / service units are API-only
  (`POST /facilities/{id}/departments`); the Facilities screen lists facilities only.
- The preauthorization request form asks reception for a diagnosis code (required by `PreauthLifecycle::TYPE_FIELDS`
  for OUTPATIENT / ADMISSION). Input only — reception never reads it back — but the owner may prefer that only
  clinical roles raise clinical preauthorizations (would remove `provider.preauth.create` from the reception desk).
- Status codes (`SUBMITTED`, `APPROVED`, …) are shown as codes, not translated labels.
- Notifications list shows outbox event codes.
