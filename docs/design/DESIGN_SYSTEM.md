# OpesInsure Design System v1

> **Superseded 2026-09-21.** `OpesInsure_Premium_Visual_Identity_and_Dashboard_Specification_v3.docx` is now the authoritative visual specification (see `docs/design/ROUTE_TO_SCREEN_REGISTER.md`). This v1 document is kept for historical reference only — do not implement against it.

The UI is calm, trustworthy, financial and distinctly African without ornamental overload. It must not imitate an insurer's brand or use carrier colors as platform navigation colors.

## Color tokens

| Token | Hex | Use |
|---|---|---|
| Navy 950 | `#071A2B` | Primary text, premium headers |
| Navy 800 | `#123652` | Navigation and dark surfaces |
| Blue 600 | `#1769E0` | Primary actions and selected controls |
| Blue 50 | `#EEF5FF` | Informational surfaces |
| Emerald 600 | `#07855B` | Verified, paid, active, success |
| Amber 500 | `#D99100` | Pending, warning, expiring |
| Red 600 | `#C9363E` | Destructive, failed, cancelled |
| Ivory 25 | `#FFFCF7` | Warm app background |
| Slate 700 | `#344454` | Secondary text |
| Slate 400 | `#8A98A6` | Muted text/icons |
| Slate 200 | `#DCE3E8` | Borders/dividers |
| White | `#FFFFFF` | Cards and inputs |

Text/background pairs must meet WCAG 2.2 AA. Status is always communicated by icon + label, never color alone. Carrier logos appear only inside neutral offer containers.

## Typography and layout

- Font: Inter for Latin UI; system fallback supported. Tabular numerals for amounts.
- Mobile grid: 4 columns, 16 px margins, 8 px base spacing, 44 px minimum hit target.
- Desktop grid: 12 columns, 24 px gutters, 1280 px content maximum; dense ERP tables support 1366 px screens.
- Radii: 8 px controls, 12 px cards, 16 px feature panels. Shadows are minimal; borders carry structure.

## Icon policy

Lucide icons use 1.75–2 px stroke and optical sizes 16/20/24. Never mix filled and outline icon families in one context.

| Action/domain | Lucide icon | Notes |
|---|---|---|
| Home | `House` | Primary mobile navigation |
| Compare/quotes | `ListFilter` | Avoid generic shopping cart metaphor |
| Policies | `ShieldCheck` | Active cover; `ShieldAlert` for exception |
| Claims | `ClipboardPlus` | FNOL and claim entry |
| Payments | `WalletCards` | Collection and wallet overview |
| Agents | `BadgePercent` | Agent sales/commission context |
| Brokers | `Building2` | Corporate partner context |
| Vehicles | `CarFront` | Automobile risk |
| Documents | `Files` | Repository; `ScanLine` for capture/OCR |
| Delivery | `Bike` | Motorcycle dispatch; `PackageCheck` delivered |
| Renewals | `RefreshCw` | Never use repeat icon for payment retry |
| Settlements | `Landmark` | Bank-bound settlement |
| Reconciliation | `Scale` | Financial matching/balance |
| Audit | `ScrollText` | Audit trail |
| Security | `KeyRound` | Credentials/access |
| Support | `LifeBuoy` | Help and support |

Custom icons are allowed only for insurance line symbols not adequately represented by Lucide. Each must use a 24×24 viewBox, round caps/joins, 2 px stroke, no embedded color, unique semantic name and SVG source. No generic AI icon, collage, emoji, duplicate metaphor or raster icon is accepted.

## Core components

App shell, tenant switcher, role switcher, command palette, status badge, money display, policy card, offer comparison card/table, stepper, timeline, document capture tile, verification badge, consent sheet, payment state panel, empty/error/offline state, data table, filter drawer, approval panel and immutable audit viewer.

