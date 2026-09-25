# Mobile UI handoff notes (for the "Opesinsure Mobile app" session)

From `OpesInsure_Canonical_Implementation_Specification_v1.json` → `ui_implementation_handoff`. The web agent (cs2) did not edit `mobile app/`. The handoff itself scopes native apps out ("mobile-first = responsive mobile web"). But the Customer Marketplace and Agent Workspace only exist in the Expo app, so these requirements apply to it by analogy. The owner decides how far to apply them.

## Visual direction (same tokens as web)
- Navy `#071A2B` text/navigation · blue `#1769E0` = the ONLY interactive colour (links, focus, selection, primary buttons) · emerald `#07855B` success/verified/paid/active · gold `#D99100` restrained accent only (never a button colour) · red `#C9363E` danger/failure only · canvas `#F6F8FA`, white cards.
- Inter Variable (local), Lucide outline icons 2 px stroke; no emoji as icons; no teal; carrier colours only inside neutral offer containers.
- Borders carry structure; shadows only on lifted surfaces (sheets, modals).

## Layout
- Target 360–430 px; verify 320 and tablet 768. Page padding 16 px (narrow) / 20 px (standard). One primary column.
- Touch targets ≥ 44×44; primary controls 48–52 px high; inputs ≥ 16 px text.
- Bottom navigation: **Customer** = Home, Compare, Policies, Claims, Account. **Agent** = Dashboard, Clients, Sell, Portfolio, Wallet.
- Sticky bars respect safe-area insets and never cover validation, consent, totals or actions.
- No fixed card heights (FR text is 30–40 % longer). Quote comparison = horizontal snap cards.
- Tables become labelled cards.

## States (every screen/component)
loading, empty, populated, validation failure, permission denied, stale/conflict, duplicate/idempotent, downstream timeout, offline/queued, retrying, partial completion, success, cancelled, failed, expired, read-only/locked, archived. Never show a generic "error"/"pending" in place of a specific insurance/transaction state. The web copy for these states (EN/FR) is in `resources/lang/{en,fr}/web_experience.php` → `states`, `failure`, `payment.states`, `errors`. Reuse the wording so both channels say the same thing.

## Insurance and financial rules
- Tabular numerals for money, policy numbers, references, dates, OTPs. Keep amount + `FCFA` together (no-break space). fr grouping = narrow no-break space. API values stay unchanged.
- Show gross premium, platform fee, processing fee, commission and carrier settlement as separate labelled lines.
- Before Mobile Money confirmation, show network, customer phone, gross premium, each fee and the total.
- Payment processing = a persistent state panel with timeout, retry and receipt recovery (no endless spinner). Say "do not pay twice" while the state is PENDING or UNKNOWN.
- Policy state = label + icon + effective dates + responsible next party.
- Agent: customer-origin attribution is shown as protected, read-only ownership with a dispute path.
- Self-approval is disabled with an explanation, never just hidden.

## Bilingual / accessibility / performance
- No hard-coded strings; EN and FR keys maintained together; do not truncate navigation or consent text. Error messages give the problem AND the recovery action.
- WCAG 2.2 AA: contrast 4.5:1 text, 3:1 UI; visible 3 px blue focus; labels (no placeholder-only fields); accessible names on icon buttons; no hover-only or drag-only actions; reduced-motion and increased-contrast honoured; status never conveyed by colour alone.
- Keep critical policy, payment, claim and support information available when optional features fail. Test on a modest Android device on a throttled network.
