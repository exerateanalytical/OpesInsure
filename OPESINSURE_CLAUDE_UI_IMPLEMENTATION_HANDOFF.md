# OpesInsure Claude UI Implementation Handoff

## Your role

You are implementing the approved OpesInsure web-platform design system inside an existing Laravel 12 and Filament 4 insurance platform. You are not designing a new product and you are not rebuilding completed backend modules.

Use `OpesInsure_Platform_Design_and_Responsive_UI_Specification_v2.docx` as the authoritative source for colors, typography, responsive layouts, cards, controls, tables, icons, states, accessibility, localization and page-quality requirements.

Use `OPESINSURE_WAVES_08_TO_11_MERGE_GUIDE.md` when integrating Waves 8–11.

## Non-negotiable restrictions

1. Do not replace the existing Laravel application.
2. Do not rewrite completed Waves 0–11.
3. Do not change insurance business rules, state machines, pricing calculations, commission rules, payment logic, ledger logic, attribution locks, tenant isolation, audit evidence or maker-checker controls merely to simplify the UI.
4. Do not change API URLs, OpenAPI contracts, database columns, migrations, events, queues, policies, RBAC permissions or external-provider contracts unless a verified defect requires it and the change is separately documented and approved.
5. Do not remove working functionality because it is difficult to style.
6. Do not install a generic dashboard template or introduce a second component library.
7. Do not introduce arbitrary colors, fonts, shadows, radii, breakpoints or icon families.
8. Do not use emoji as interface icons.
9. Do not hide actions on mobile without providing an accessible equivalent.
10. Do not mark a page complete without responsive, accessibility, bilingual and state verification.

## Mandatory first action

Before changing any file, audit the existing application and create a route-to-screen implementation register containing:

- route and route name;
- portal and role;
- page/screen name;
- current Blade, Livewire or Filament class;
- API endpoints and permissions used;
- current state: complete, partial, placeholder, broken or missing;
- responsive defects;
- color/token conflicts;
- typography defects;
- spacing/grid/card defects;
- missing loading, empty, error, offline, denied and success states;
- English/French localization status;
- accessibility gaps;
- automated/browser tests present;
- required correction;
- final acceptance evidence.

Do not begin broad styling until this register exists. Preserve it throughout implementation.

## Existing experience layers to cover

The audit and implementation must cover every page and state in:

1. Customer Web Marketplace.
2. Agent Web Workspace.
3. Broker ERP.
4. Carrier Portal.
5. Platform Administration.
6. Public Verification Portal.

Native mobile applications are outside the present scope. “Mobile-first” means responsive mobile web, not Flutter or Expo.

## Authoritative visual direction

The interface must feel calm, precise, modern and trustworthy. OpesInsure is an insurance and financial platform, not a generic SaaS dashboard or e-commerce storefront.

Use:

- deep navy `#071A2B` for primary text and institutional navigation;
- blue `#1769E0` for primary interactions, links, focus and selected controls;
- emerald `#07855B` for confirmed success, verified, paid and active states;
- gold `#D99100` only as a restrained premium or attention accent;
- red `#C9363E` only for danger, failure and destructive intent;
- cool canvas `#F6F8FA` and white cards;
- Inter Variable with local WOFF2 and system fallbacks;
- Lucide outline icons with a consistent 2 px stroke.

Blue is the only interactive brand color. Do not use teal as an alternative brand color. Carrier colors must remain inside neutral carrier/offer containers and must never recolor the platform shell.

## Implementation sequence

Work in controlled batches of five page families. Complete and validate each batch before starting the next.

### Batch 1 Foundation and shared shell

1. Consolidate design tokens and remove conflicting aliases.
2. Establish typography, spacing, radius, border, shadow and focus foundations.
3. Implement mobile and desktop application shells.
4. Implement responsive navigation for all role contexts.
5. Implement shared page container, heading, breadcrumb and action-bar patterns.

### Batch 2 Shared components

1. Buttons and icon buttons.
2. Inputs, selects, dates, money, telephone and validation.
3. Cards, KPI cards and status badges.
4. Tables, mobile record cards, filters and pagination.
5. Modals, bottom sheets, drawers, toasts and confirmation patterns.

### Batch 3 Insurance components

1. Quote and carrier offer cards.
2. Policy cards, lifecycle statuses and timelines.
3. Claim/FNOL progress and evidence components.
4. Payment, Mobile Money and financial breakdown components.
5. Certificate, sticker and verification components.

### Batch 4 Customer Marketplace

1. Home/discovery and product catalogue.
2. Quote inputs and comparison.
3. Proposal, document and disclosure flow.
4. Checkout, payment and confirmation.
5. Policies, renewals, claims, delivery, notifications and support.

### Batch 5 Agent Workspace

1. Dashboard and performance.
2. Client registration and permanent attribution visibility.
3. Assisted quote/proposal/document capture.
4. Client Mobile Money payment request and transaction status.
5. Portfolio, renewals, commissions, withdrawals, reversals and disputes.

### Batch 6 Broker ERP

1. Dashboard, tenant, branches, staff and permissions.
2. Customer ledger, fleets/groups and documents.
3. Quotes, proposals, policies and receivables.
4. Claims, renewals, bordereaux and commissions.
5. Marketplace publishing, reports, exports, compliance and audit.

### Batch 7 Carrier Portal

1. Dashboard, contracts and delegated authority.
2. Products, tariffs, eligibility and availability.
3. Underwriting referrals and issuance exchange.
4. Endorsements, cancellations and claims exchange.
5. Bordereaux, reconciliation, settlement and reporting.

### Batch 8 Platform Administration

1. Partner, licensing and attribution operations.
2. Catalogue, tariffs and regulatory configuration.
3. Payments, ledger, reconciliation and settlement operations.
4. Fraud, compliance, privileged access and regulatory reports.
5. Logistics, notifications, support, audit, integrations and release assurance.

### Batch 9 Public and exceptional experiences

1. Public certificate/sticker verification.
2. Broker/carrier verification.
3. Authentication, invitation, MFA and account recovery.
4. Global 403, 404, 409, 422, 429, 500 and maintenance states.
5. Offline, degraded provider, queued, stale-version and retry states.

### Batch 10 Final convergence

1. English/French content and expansion audit.
2. Accessibility and keyboard audit.
3. Cross-device and browser audit.
4. Performance and visual-regression audit.
5. UAT evidence and unresolved-gap closure.

## Mobile-first rules

The default design target is a 360–430 px phone. Also verify 320, 768, 1024, 1280 and 1440 px.

- One primary content column on mobile.
- Page padding: 16 px on narrow phones and 20 px on standard phones.
- Minimum touch target: 44 × 44 px; primary mobile controls should be 48–52 px high.
- Inputs must remain at least 16 px on mobile to avoid browser zoom.
- Customer bottom navigation: Home, Compare, Policies, Claims and Account.
- Agent bottom navigation: Dashboard, Clients, Sell, Portfolio and Wallet.
- Broker, carrier and admin mobile experiences use a 56 px top bar and accessible off-canvas drawer.
- Desktop uses a 264 px sidebar, collapsible to 80 px where necessary, and a 64–72 px top bar.
- Do not squeeze desktop tables into mobile width. Convert rows to labeled mobile cards or use contained scrolling with a visible cue.
- Sticky regions must account for safe-area insets and must not cover validation, consent, totals or actions.
- Do not use fixed card heights where translated text or status details can clip.

## Responsive grid

- 320–479 px: 4 columns, 16–20 px outer margin, 12 px gutter.
- 480–767 px: 4 columns, 24 px outer margin, 16 px gutter.
- 768–1023 px: 8 columns, 24 px outer margin, 20 px gutter.
- 1024 px and above: 12 columns, 32–40 px outer margin, 24 px gutter.
- Maximum application content width: 1440 px.
- Maximum reading/form width: 760 px.
- Dashboard mobile: one content column and one or two KPI columns.
- Dashboard desktop: four KPI columns and an 8/4 main/aside split.
- Policy/claim grids: one column mobile, two tablet, three desktop, never more than four.
- Quote comparison: horizontal snap cards mobile; aligned cards/table desktop.

## Required component states

Every reusable component and page must deliberately support:

- initial/loading;
- empty;
- populated;
- validation failure;
- permission denied;
- stale version or conflict;
- duplicate/idempotent request;
- downstream timeout;
- offline/queued;
- retrying;
- partial completion;
- success;
- cancelled;
- failed;
- expired;
- read-only/locked;
- archived where applicable.

Never replace specific insurance or transaction states with a generic “error” or “pending” badge.

## Insurance and financial UI rules

- Use tabular numerals for money, policy numbers, references, dates and OTPs.
- Keep amount and `FCFA` together.
- Display gross premium, platform fee, processing fee, commission and carrier settlement as distinct labeled values.
- Before Mobile Money confirmation, show network, customer phone, gross premium, each fee and total.
- Payment processing must use a persistent state panel with timeout, retry and receipt recovery—not an endless spinner.
- Show policy state using label, icon, effective dates and responsible next party.
- Show maker, checker, requested change, evidence and before/after values in approval interfaces.
- Disable self-approval with an explanation; do not merely hide the button.
- Present customer-origin attribution as protected, read-only ownership information with a dispute path.
- Public verification must expose only privacy-minimized information.

## Bilingual implementation

- No hard-coded English strings in components, templates or controllers.
- Maintain English and French keys together.
- Allow 30–40 percent text expansion for French.
- Do not truncate translated navigation or legal consent text.
- Localize dates, phone display and FCFA formatting while retaining stable API values.
- Error messages must explain the problem and recovery action in both languages.

## Accessibility implementation

- WCAG 2.2 AA minimum.
- Minimum contrast: 4.5:1 for normal text and 3:1 for large text and UI components.
- Visible 3 px blue focus indicator.
- Correct headings, landmarks, labels and descriptions.
- No placeholder-only fields.
- Icon-only controls require accessible names and desktop tooltips.
- No hover-only action.
- Full keyboard operation for desktop workflows.
- Support 200 percent zoom and 320 CSS px reflow.
- Respect reduced-motion and increased-contrast preferences.
- Provide alternatives to drag gestures.
- Status cannot rely on color alone.

## Performance implementation

- Load a local subset of Inter Variable with `font-display: swap`.
- Use SVG for interface icons.
- Do not ship desktop-only charts or large decorative assets to mobile clients.
- Use responsive images and lazy-load noncritical media.
- Prevent layout shifts by reserving media and skeleton dimensions.
- Preserve critical policy, payment, claim and support information when optional JavaScript fails.
- Test on a modest Android device and throttled network, not only a desktop emulator.

## Testing requirements

For every batch, add or update:

- component tests;
- authorization and tenant-isolation tests;
- responsive browser tests;
- English/French rendering tests;
- keyboard/focus tests;
- accessibility checks;
- loading/empty/error/offline/state tests;
- screenshot or visual-regression evidence at required widths.

Do not weaken existing tests to make a redesign pass. Correct the implementation.

## Batch completion report

At the end of each batch, provide:

1. Exact files changed.
2. Pages/components completed.
3. Before/after defects corrected.
4. Responsive widths tested.
5. English/French verification.
6. Accessibility checks and results.
7. Automated/browser tests run and results.
8. Screenshots or evidence locations.
9. Known blockers or decisions required.
10. Remaining register items.

Do not write “complete” if tests were not run. State “implemented but unverified” and explain the missing runtime or evidence.

## Definition of done

A page is complete only when it:

- matches the authoritative specification;
- works from 320 px through 1440 px without page overflow;
- uses approved colors, typography, spacing, radii, shadows and icons;
- has one clear primary action;
- includes all relevant states;
- handles long French text and large FCFA amounts;
- passes contrast, keyboard, focus, label and touch-target checks;
- preserves tenant and role boundaries;
- preserves form data after recoverable failure;
- exposes regulated actions with actor, time, reason and evidence;
- passes automated and browser tests;
- is reviewed on a real modest Android phone.

## Start command

Begin by auditing the repository and producing the route-to-screen implementation register. Then present the first five page families proposed for Batch 1, the exact files that will be changed, the risks, and the test plan. Do not modify application code until the audit and Batch 1 plan are presented and approved.
