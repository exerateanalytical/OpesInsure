# OpesInsure mobile app — handover, 27 September 2026

## 1. Where things stand

| Item | Value |
|---|---|
| Latest APK | **1.5.0** (EAS build 9fd4eaca, channel `production-apk`) — see section 7 for download status |
| Latest over-the-air update on 1.4.0 | `d45c329c` (commit 4251533) |
| Repository | `C:\laragon\www\opesinsure\mobile app`, branch `master` |
| Checks | `npm run verify`: typecheck, lint 0 errors, 221 tests passing |
| Ownership | Mobile session owns the whole app, OTA and APK publishing. Backend session owns Laravel, web and the server `.env`. |

Phones on APK 1.4.0 keep receiving over-the-air updates on runtime 1.4.0. APK 1.5.0 starts runtime 1.5.0; future OTA updates must target 1.5.0 (`node scripts/publish-update.mjs production-apk ...` publishes for whatever version `app.json` holds).

## 2. What was delivered today

### Screen designs (`app screens/`)
- Every screen design in the folder is mapped to an app screen in `scripts/qa-screens.json` (112 comparisons) and compared side by side at 360, 390 and 430 dp with `node scripts/qa-shots.mjs` + `python scripts/qa-compose.py`.
- Brand artwork placed through `src/components/design/BrandArt.tsx`; icon-style images replaced by Lucide icons (owner rule: mobile uses Lucide only).
- Visual regression: `npm run qa:diff` compares captures against `docs/qa/baseline/` (311 screens).

### Features
- **Forms:** shared `SelectField` (searchable bottom sheet) and `OptionGroup`; 52 dp fields and buttons; car make → model → generation → year → engine as stacked dropdowns.
- **Language:** follows the phone/browser language (EN/FR); an explicit choice on the language screen overrides it.
- **Location autofill:** town, region and GPS on claims and other forms, only into empty fields, with per-form opt-out and a switch in privacy settings (needs APK 1.5.0).
- **Documents:** native PDF viewer (fit width, pinch zoom, page counter, offline copy) and zoomable images; old WebView kept only as a fallback for APK 1.4.0.
- **Verification in the app:** `/verify` (manual code + scan) and `/verify/[code]` result page using `POST /api/v1/public/verify`; "Verify QR" no longer opens the browser. QR camera links and the in-app scanner need APK 1.5.0.
- **Home:** "Needs your attention" feed, compact compare card, Featured providers as a 2-up auto-sliding carousel with logos.

### Full audit (`app screens/OpesInsure_Mobile_Full_Audit_2026-09-27.json`, 123 findings)
Six groups worked through every finding. Each was checked against the code first; fixed findings were not rebuilt.

| Group | Findings | Result |
|---|---|---|
| Look, usability, accessibility, forms, navigation | 35 | 27 fixed, 3 already fixed, 3 partial (backend), 2 blocked (device) |
| Agent portal and commissions | 15 | 5 fixed, 1 already fixed, 8 partial (backend), 1 blocked (backend roles) |
| Broker portal, filters, customer portfolio | 19 | 6 fixed, 1 already fixed, 12 partial (backend) |
| Insurer portal and issuance | 17 | 5 fixed, 2 already fixed, 7 partial (backend), 3 blocked (backend) |
| Security, fraud, offline, freshness | 23 | 11 fixed, 7 already fixed, 5 partial (backend / native / owner keys) |
| Performance, architecture, release | 14 | 3 fixed, 3 already fixed, 3 partial, 5 blocked (device / backend) |

Every "partial" item has the mobile side built and hides or labels the missing part "Not available on mobile yet" rather than inventing data. The server stays the authority for permissions, payments and issuance.

Main additions: agent/broker/insurer detail pages, commission ledger with filters and KPI drill-down, one shared filter framework with saved filters, shared detail layout (loading / 404 / 403 / offline / stale / retry), insurer access gates, login activity and device detail screens, sync-state and freshness labels, crash reporting that the server accepts, stricter release checks, deep-link and reload restore for portals, high-contrast mode, font-scale checks, a lint rule against raw buttons.

## 3. Waiting on the backend
Full list sent to the backend session: `docs/BACKEND_NEEDS_MOBILE_AUDIT_2026-09-27.md`. Their order: insurer role permissions, capabilities/allowed actions, security items, then insurer, broker and agent endpoints. Also pending from them:
- `POST /mobile/claims` with latitude/longitude (commit a4d3c59) — the app keeps a second call until confirmed live.
- Latitude/longitude on profile and quote forms — the app will send them only after confirmation.
- Server demo mode and the "Demo environment" banner — owner decision; MTN MoMo sandbox credentials were failing authentication.

## 4. Needs the owner
- **Screenshots setting:** removing the hard-coded screenshot block was refused twice by the permission system as a security weakening. The protection is unchanged. To proceed, add a permission rule in Claude Code settings allowing edits to `plugins/withOpesInsureSecurity.js` and `app.config.js`, then ask again.
- **OTA code signing:** needs keys that are not in the repository. Steps are in `docs/SECURITY_AND_RELEASE_NOTES.md`.
- **Device integrity:** Play Integrity needs a native module (`OpesIntegrity`) and backend token verification.
- **Colour tokens:** four small differences from the designs (page background, active tab gold, promo navy, link blue) were left for your decision.
- **Real-device tests:** no emulator here. Please test APK 1.5.0 on a phone: sign-in, PDF viewer, QR scanner, location prompt, quote vehicle step, and a TalkBack pass.

## 5. APK 1.5.0 notes
- New native parts: expo-camera, react-native-pdf, react-native-blob-util, expo-location, expo-build-properties.
- Build size: only arm64-v8a and armeabi-v7a (no x86 emulators or some Chromebooks); R8 minify and resource shrinking on, with keep rules for the PDF libraries.
- `/verify` Android link filter added (autoVerify false).
- Demo account picker is off in this build (`EXPO_PUBLIC_SHOW_DEMO_LOGIN=false`).

## 6. How to work on it
- Web preview: launch config `mobile-web` (port 8089).
- Checks: `npm run verify`; release gate `node scripts/release-doctor.mjs --production`.
- Publish OTA: `node scripts/publish-update.mjs production-apk --platform android --message <no_spaces>`.
- Build APK: `EAS_NO_VCS=1 npx eas-cli build --profile production-apk --platform android --non-interactive` (without `EAS_NO_VCS` the upload includes the git history and fails).
- Put an APK on the download page: back up `/srv/opesinsure/shared/storage/app/public/downloads/opesinsure-<version>.apk`, upload the new file there, then ask the backend session to set `MOBILE_APP_VERSION` and `MOBILE_APP_ANDROID_SIZE` in the server `.env`.
- GitHub: the mobile repo is not pushed yet; run `git remote add origin https://github.com/exerateanalytical/OpesInsure.git` and `git push -u origin master:mobile-app` from the mobile folder.

## 7. APK 1.5.0 download status
| Item | Value |
|---|---|
| EAS build | 9fd4eaca, versionCode 19, runtime 1.5.0 |
| Artifact | https://expo.dev/artifacts/eas/J061rlT0HmDhj_LLtkQROnfjFxb6USRQyBR8yOHfw8I.apk |
| Size | 68,045,218 bytes (down from about 96 MB for 1.4.0) |
| md5 | a50736aeb64d1299f4c22aa68b000b98 |
| On the server | `/srv/opesinsure/shared/storage/app/public/downloads/opesinsure-1.5.0.apk` (1.4.0 file kept) |
| Checked in the file | version 1.5.0, arm64-v8a + armeabi-v7a only, native PDF library, camera and location permissions, `/verify` link filter, backup disabled, production API host |
| Download page | switches to 1.5.0 when the backend session sets `MOBILE_APP_VERSION=1.5.0` and the size in the server `.env` (requested) |
| Not yet done | install and smoke test on a real phone (no emulator on this machine) |
