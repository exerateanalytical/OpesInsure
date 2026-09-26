# Design build brief (read fully before editing)

App: Expo SDK 54 / React Native 0.81 / expo-router, TypeScript, in `C:\laragon\www\opesinsure\mobile app`.
Design references: `app screens/` (see `app screens/manifest.csv`). Only files listed there under
`01_Mobile_Screens_Current` and the numbered `NN-*.png` files are targets. `02_...Alternates_Rejected` files
must NOT be implemented. `03_Mobile_Artefacts_And_Assets` are art you may use.

## Non-negotiables
1. Nothing is removed. Every existing feature, API call, route, i18n key usage, accessibility prop,
   validation, offline behaviour and state (loading / empty / error / retry / locked / expired) stays.
   If the design has no slot for something, keep it lower on the page.
2. Every button, chip, tile, link, search field and form on your screens must be live: wired to a
   real route or a real API call of the Laravel backend (`src/api/*`). No `onPress={() => {}}`, no
   placeholder text, no fake numbers. If the backend has no data for something the design shows
   (ratings, photos, reviews), omit that element; never invent values.
3. Data must be the same data the web app shows (same backend, same endpoints). Never hard-code
   policies, prices, names or counts.
4. Insurer logos: always render insurers through `src/components/InstitutionMark.tsx`
   (`logoUrl` + `initials`). Pass `logo_url` / `carrier_logo_url` / `logo` when the payload has it;
   otherwise look it up from `CustomerApi.institutions()` / `InstitutionsApi` by carrier id. Logos are
   being uploaded on the backend now; your code must show them the moment `logo_url` is non-null.
5. Clean, not cramped: 20dp page gutters, 16dp card padding, 12–16dp gaps, 48dp minimum touch
   targets, one primary action per screen (pinned in `CtaBar` when the design pins it), no text
   truncation at 360dp width for titles/labels that matter (wrap instead), no overlapping art.
6. Artefacts: decorative art must be `pointerEvents="none"`, hidden from screen readers, never cover
   text or controls, and use `resizeMode="contain"` with explicit width AND height (RN-web sizes
   images from the bitmap otherwise). Trim transparent margins with Pillow before adding to
   `assets/brand/` and keep each file under ~400 KB. Reuse an existing asset before adding a new one.
7. i18n: every new string goes into BOTH `src/i18n/en.ts` and `src/i18n/fr.ts` (fr.ts is typed
   against en.ts). Other agents append concurrently: insert your keys as one block just before the
   final `};` and re-read the file right before writing.
8. Preserve each file's line endings (some are CRLF). Use the Edit tool.
9. Do NOT commit, run git write commands, deploy, or publish updates. Do not touch files owned by
   another agent (your prompt lists yours). Shared files you may edit additively: `src/i18n/*.ts`,
   `app/_layout.tsx` (register routes only), `src/components/design/index.tsx` (add, don't change
   existing props).

## Foundation (use, don't duplicate)
`src/components/design/index.tsx`: BrandHeader, BrandLockup, StepIndicator, HeroCard, MetaGrid,
IconTile, ActionTile, TintedIcon, DetailRow, CheckList, Banner, SectionHeading, CtaBar, RadioCard.
`src/components/ui.tsx`: Screen (`footer` prop), Card (`onPress`), Button, Chip, ChipRow, StatusChip,
TextField, ripple, CONTENT_MAX_WIDTH. `src/components/customer/CategoryTiles.tsx` (CATEGORY_TINT).
`src/components/policies/RenewalUi.tsx` (PriceRow, TotalBand, NetworkTiles, InfoBox).
`src/components/documents/openDocument.ts` (in-app PDF viewer). Tokens: `src/theme/tokens.ts`.

## Side-by-side comparison (required, per screen)
1. Open the design PNG with the Read tool.
2. Run the app: a web preview normally runs at http://localhost:8089 (if not, start it with
   `cmd /c "C:\laragon\www\opesinsure\mobile app\scripts\web-preview.cmd"` in the background and wait
   until `http://localhost:8089/node_modules/expo-router/entry.bundle?platform=web&dev=true&hot=false&lazy=true`
   returns 200). Use the built-in browser tools in YOUR OWN TAB (`tabs_create`), mobile viewport
   (`resize_window` preset mobile). Sign in as the demo customer:
   `document.querySelector('[role=combobox]').click(); await new Promise(r=>setTimeout(r,500));
   document.querySelectorAll('[role=menuitem]')[0].click(); await new Promise(r=>setTimeout(r,9000))`.
   Deep links reset to "/", so navigate by clicking elements found by aria-label/text via
   `javascript_tool`. Screenshots often time out: retry at scale 0.6, and use `get_page_text` /
   `read_console_messages(onlyErrors)`.
3. Screenshot your screen, compare against the PNG (layout order, hierarchy, spacing, colours,
   chips, icons, art placement), fix differences, repeat until it matches as closely as the real
   data allows. Fix any console errors your screens produce.

## Finish
Run `npx tsc --noEmit`, `npx eslint app src`, `npm test` from the app folder; fix what you broke
(tests grep source strings; keep them true, update a test only when it asserts the old look, and say
why). Report: screens done (design file → route/file), live-wiring notes, data the design shows that
the backend lacks (omitted), files changed, new i18n keys, verbatim tails of the three commands.
