# UI batch completion reports (web): canonical UI handoff

Agent: cs2 (web UI). Register: `UI_GAP_AUDIT.md`. Mobile requirements: `MOBILE_UI_HANDOFF_NOTES.md`.
Approval gate: the handoff says "present the audit + Batch 1 plan before modifying code". The owner's instruction to this session told it to implement batch by batch, and that instruction was treated as the approval. The audit was written before any code changed.
Invariants respected: no change to business rules, state machines, APIs/OpenAPI, migrations, permissions, tenant scoping, audit or maker-checker enforcement. The only behavioural UI changes are listed per batch.

Overall status: **implemented, automated-tested; browser/device evidence NOT captured.** No screenshots were taken at 320–1440 px, and there was no review on a real Android device (no emulator or device in this environment). Per the handoff, no page is marked "complete".

---

## Batch 1: Foundation and shared shell
1. **Files:** `resources/css/filament/admin/theme.css` (rewritten token set), `app/Providers/Filament/AdminPanelProvider.php` (palette values, uses shared chrome, locale middleware), `app/Providers/Filament/PortalPanelFactory.php` (new `chrome()` shared by all 3 panels), `app/Filament/Shared/Middleware/SetPanelLocale.php` (new), `resources/views/filament/shared/language-switch.blade.php` (new), `public/build/*` (rebuilt theme).
2. **Done:** one canonical token set (blue `#1769E0`, emerald, gold `#D99100`, red, canvas `#F6F8FA`); Inter Variable only (local Filament WOFF2, swap); spacing/radius/shadow/focus tokens; responsive margins/gutters per the handoff grid; 1440 max content, 760 reading width; 264/80 px collapsible sidebar; 56 px mobile / 64 px desktop top bar; header actions wrap; EN/FR switch in the top bar and on every auth page; panel locale = ?lang > session > user.locale > Accept-Language.
3. **Defects corrected:** A1, A2 (panels), A3, A4, B1, B10, B13, B14.
4. **Widths:** CSS rules written for 320/380/480/768/1024/1280; **not browser-verified**.
5. **EN/FR:** login pages of admin/insurer/broker render FR and EN, and the choice persists (test).
6. **A11y:** 3 px solid blue focus (was 30 % alpha); 44 px targets; 16 px inputs on phones; reduced-motion, prefers-contrast and forced-colors rules. No axe run.
7. **Tests:** `tests/Feature/Web/UiCanonicalWebTest.php` (tokens, bilingual panels, user locale + tenant boundary).
8. **Evidence:** test output only.
9. **Decisions:** D1 (public site Manrope/gold CTAs vs handoff), D3 (Lucide package).
10. **Remaining:** B2 (107 admin resources have English-only labels).

## Batch 2: Shared components (extended, no parallel components)
1. **Files:** `resources/views/filament/shared/{status-badge (new), failure-states, detail-header, timeline, document-viewer, financial-panel, authority-widget}.blade.php`, `app/Application/WebExperiences/{FailureState, RecordSummary, Money}.php`, `resources/lang/{en,fr}/web_experience.php`.
2. **Done:** the status badge is icon + label + colour and keeps the stable code in `data-status`. `FailureState` extended with the 9 missing handoff states (timeout, offline/queued, retrying, partial, expired, read-only, cancelled, archived, validation), each with an EN/FR problem and recovery text, a tone and an icon. Banners use role alert or status. The document and financial tables become labelled cards under 768 px. The timeline is an ordered list with `<time>`. All inline hex colours were replaced by tokens. `Money::display()` renders FCFA with a no-break space and locale grouping; `Money::format()` is unchanged (stable API value).
3. **Defects corrected:** A9, B3, B4, B5, B12 (UI display; record-summary metadata still uses the stable format).
4–6. Same caveats as Batch 1. The document viewer changes are **presentation only**; access filtering (DocumentAccessPolicy) and verification behaviour belong to cs1 and were not changed.
7. **Tests:** states/copy/icons, badge, labelled cards, FCFA. The existing `WebExperienceShellTest` still passes.
10. **Remaining:** modal/bottom-sheet/drawer/toast patterns are Filament defaults (restyled by tokens only).

## Batch 3: Insurance components
1. **Files:** new `financial-breakdown`, `financial-breakdown-slot`, `payment-state`, `payment-state-slot`, `approval-panel` views; new `app/Application/WebExperiences/{FinancialBreakdownQuery, ApprovalPanelData}.php` (read-only); `RecordShell::financialBreakdown()` / `paymentState()` (added to the existing shell, and the breakdown is added to the Financial tab of every `detailTabs()` screen); `PaymentRequestResource` gains an infolist (header + payment state + timeline); `ApprovalRequestResource` gains a details modal (approval panel). Approve and reject are now **shown but disabled with a tooltip** for the requester, instead of hidden.
2. **Done:** distinct gross premium / platform fee / processing fee / commission / carrier settlement lines. Only persisted figures are shown (policy premium, commission accruals, settlement items); a line with no source is omitted, never estimated. The payment panel shows the explicit state, network, phone, reference, timeout, retry and receipt-recovery links, and the "do not pay twice" copy. The approval panel shows maker, checker, change, reason, evidence and before/after, plus the self-approval explanation and the ApprovalService blockers. `ApprovalService` still enforces maker-checker and SoD server-side.
3. **Defects corrected:** B6, B7, B8.
10. **Remaining:** quote/offer comparison cards and certificate/sticker components have no web screen to host them (customer flow is mobile). Attribution dispute-path copy exists (`web_experience.attribution`) but is not wired to a web screen yet.

## Batches 4 and 5: Customer Marketplace / Agent Workspace
N/A on web: there are no web routes, and both experiences live in the Expo app. Their requirements were transferred to `MOBILE_UI_HANDOFF_NOTES.md`.

## Batches 6, 7 and 8: Broker ERP, Carrier Portal, Platform Administration
- The shell, tokens, locale and shared components apply to every existing portal and admin page. Portal KPI grid: 1 / 2 / 4 columns (`PortalMetricsWidget::getColumns`).
- **Not done (D4):** the missing broker and carrier families (contracts, delegated authority, bordereaux, settlements, receivables, staff) are not exposed in the portals. Each needs tenant scoping and an RBAC review, which is outside UI-only scope. The admin custom pages owned by cs1 (DocumentEngine) were not touched.

## Batch 9: Public and exceptional experiences
1. **Files:** `resources/views/errors/{layout,403,404,409,419,422,429,500,503}.blade.php` (new), `resources/views/public/verify.blade.php` (colours and icons only).
2. **Done:** standalone branded error pages (no DB, no JS), EN/FR with a switch, the stable HTTP code, a problem explanation plus a recovery action, and a timestamp for support. Verify page: legacy teal and glyph icons replaced by canonical colours and SVG outline icons, with a role=status result. The verification logic and privacy-minimised fields are unchanged (cs1).
3. **Defects corrected:** A7 (verify), A8, B9.
7. **Tests:** 404 in EN/FR, all codes render with a primary action, verify has no teal.
10. **Remaining:** auth MFA/invitation screens are Filament defaults. There is no offline or service-worker web state (the panels are online-only).

## Batch 10: Final convergence
- Automated: 10 new tests (`UiCanonicalWebTest`, 211 assertions) + the existing Web/Wave10 tests (48 passed). Full-suite result: see the final report.
- **Not done:** axe/keyboard audit in a browser, screenshots at the required widths, visual regression, a performance run on throttled Android, UAT. All pages remain **"implemented but unverified"** for those criteria.

---

## Owner decisions D2 / D3 / D4 (approved 2026-09-25). D1 is waiting for the owner's public-site design folder.

**D2: `/portal/{portal}` retired.** `AppServiceProvider::registerPortalShellRoute()` now returns a 301 redirect (ADMIN→`/admin`, BROKER→`/broker`, CARRIER→`/insurer`, AGENT/CUSTOMER→`/download`). Unknown portal codes still return 404, and the route name `portal.dashboard` is kept. The placeholder view `resources/views/wave10/portal.blade.php` was removed (the only thing that used it was this route). `public/css/opesinsure-portals.css` was left in place. The Wave10 API (`/api/v1/web-experiences/*`) is unchanged.

**D3: Lucide.** Installed `mallardduck/blade-lucide-icons` (^2.0, prefix `lucide-`). Composer needed `--ignore-platform-req=ext-pcntl/ext-posix` on this Windows dev box, which is a pre-existing Horizon requirement and not caused by this package. `app/Filament/Shared/LucideIcons.php` does two things when a panel is served (hook in `WebExperienceServiceProvider::boot`): it registers the panel chrome icon aliases, and it swaps every Heroicon navigation icon to its Lucide equivalent through one central map, so the 100+ resource files owned by other agents were not edited. The shared components, error pages and verify page now use Lucide directly. Still Heroicon: action/button icons set inline inside individual resources (`->icon(Heroicon::…)`) and Filament table/form internals. They are outline icons but not yet Lucide; that is the remaining register item.

**D4: broker ERP / carrier portal sections.** All of them are read-only in the portals. Writes stay in the existing APIs and the admin panel, and no business logic changed.
| Section | Portal | Reused resource | Permission (same as existing API) | Row scope |
|---|---|---|---|---|
| Contracts & delegated authority | insurer, broker (+ admin) | **new** `CarrierBrokerAgreementResource` over new read model `CarrierBrokerAgreementRecord` (no admin screen existed before) | `distribution.agreements.view` | partner in the portal tenant (= `CarrierBrokerAgreementController::index`); insurer also sees its own carrier's agreements; platform tenant in admin sees all |
| Bordereaux | insurer, broker | `BordereauResource` | insurer `carrier.finance.read`, broker `broker.finance.read` | tenant + caller carrier (`CarrierScopeResolver`, = `MobileCarrierFinanceService`) |
| Settlements | insurer, broker | `CarrierSettlementResource` | same | same |
| Receivables (commission) | broker | `CommissionAccrualResource` | `broker.finance.read` | tenant + caller partner (= `MobileBrokerOpsController::receivables`); no partner = empty |
| Staff | broker | `MembershipResource` | `broker.portal.read` | portal tenant only; create/edit/revoke refused or hidden in the portal |

How it is enforced: `PortalAuthorization::PORTAL_SECTIONS` refuses every non-view ability in the portals and checks view abilities per record (tenant, carrier, partner). `PortalScope` narrows list queries and does nothing outside the portals. The admin panel's behaviour is unchanged apart from translated navigation labels on these four resources.
Tests: `tests/Feature/Web/UiOwnerDecisionsTest.php` covers D2 redirects, D3 icon resolution and mapping, D4 carrier scoping (other carrier / other tenant hidden, 404, missing permission → 403, writes refused), broker staff tenant isolation and read-only behaviour, agreement tenant boundary, and FR navigation.
Not done: branches and tenant settings in the broker portal (their admin resources are platform-level with create/edit; this needs a separate review). Broker receivables of customer premiums (payment intents) are not exposed; "receivables" follows the existing mobile API definition (commission).
