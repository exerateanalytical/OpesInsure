# OpesInsure Mobile: Industry-Standard Audit v1

- Date: 2026-09-24
- Scope: `mobile app/` (Expo SDK 54, RN 0.81.5, expo-router 6, zustand), app version 1.2.2
- Method: read-only static review plus `npm run verify`. No code was changed. Other agents were editing the tree during the audit, so line numbers are as of this snapshot.
- Paths are relative to `mobile app/`.
- Delivery column: **APK** means a new native build is needed (native config, permissions, plugins, native modules). **OTA** means a JS-only change that can ship through `eas update` on the same `runtimeVersion` (appVersion 1.2.2).

## 0. `npm run verify` results

| Step | Result |
|---|---|
| `tsc --noEmit` | **FAIL**: 5 errors, all in `src/lib/masterFields.ts` (lines 88, 102 x3, 261): `noUncheckedIndexedAccess` violations. The file is untracked (`??`), so another agent is still working on it. Because `verify` chains with `&&`, lint and tests never run through `verify` while this fails. |
| `expo lint` (run separately) | PASS (exit 0, no output) |
| `npm test` (run separately) | PASS: 111/111 across 11 files, about 1.5 s |

Note on the tests: most of them read source files and regex-match them (`readFileSync` plus `assert.match`; for example, trust-boundary has 116 such asserts). Almost none render a component or exercise behaviour. See T-1.

`docs/spec/SCREEN_TO_API_MATRIX_V1.md` has only the reconciliation rules (10 lines). The owner's §22-31 and §37 text is not in the repo. I judged states against the standard set the matrix references: loading, empty, error/retry, permission-denied, offline, stale-record (§30 failure screens, §37 completeness gate).

---

## P0: fix before a wider release

### P0-1 Backgrounding the app unmounts the whole navigation tree (camera, picker, payment and biometric flows lose state)
- Evidence: `src/components/AppRuntime.tsx:113-117` sets `privacyCovered` on every non-`active` AppState. `:145-151` then returns the privacy view **instead of** `children`. `:152-161` does the same for `locked`. `children` is the root `<Stack>` (`app/_layout.tsx:46`).
- Impact:
  - Each time the app goes to the background, the Stack unmounts and remounts at the initial route. On Android that happens when `ImagePicker.launchCameraAsync` opens the camera (`app/claim/[id]/evidence.tsx:54`, `app/onboarding/kyc.tsx:60`), when the document picker opens, when a mobile-money USSD or app switch happens during payment, and when a notification shade covers the app on iOS.
  - The user comes back to the home screen, not the claim-evidence screen. The picker result goes to an unmounted component, and form input is lost.
  - On resume, `unlock()` runs again (`:116`), so the user gets a biometric prompt after every photo.
  - On iOS the Face ID prompt itself makes the app `inactive`. That can re-trigger the cover-and-unlock cycle.
- Fix:
  - Keep `children` mounted always. Render the privacy cover and lock screen as an absolute-fill overlay (`StyleSheet.absoluteFill`, `zIndex`) on top.
  - Only cover on `background` (use `inactive` on iOS only for the snapshot).
  - Only re-lock after a grace period (for example, more than 60 s in the background; see P1-2).
  - Suppress the lock while an app-initiated picker or camera, or the biometric prompt, is open.
- Delivery: **OTA**

### P0-2 About 15 screens fire unhandled promise rejections and have no error state
- Evidence: bare `.then(setX)` with no `catch`, no loading state and no error state:
  - `app/services/index.tsx:10`
  - `app/services/[id].tsx:19`
  - `app/assets/index.tsx:10`
  - `app/assets/[id].tsx:10`
  - `app/delivery/[id].tsx:11`
  - `app/claim/[id]/checklist.tsx:11`
  - `app/claim/[id]/incident.tsx:14`
  - `app/claim/[id]/parties.tsx:19`
  - `app/claim/[id]/repair.tsx:17`
  - `app/claim/[id]/settlement.tsx:18`
  - `app/claim/[id]/settlement-payment.tsx:10`
  - `app/account/notifications.tsx:21`
  - (plus Preferences reads)
- Impact: on a network failure these claim and servicing screens stay blank or show a false "empty" state, and the rejection goes unobserved. `useLoad` and `usePagedList` (`src/hooks/useLoad.ts`, `usePagedList.ts`) already exist but are not used here.
- Fix: move every one of these to `useLoad` or `usePagedList` plus `StatePanel` (loading, empty, error/retry). Add a lint rule (`@typescript-eslint/no-floating-promises` with type info) so it cannot come back.
- Delivery: **OTA**

### P0-3 `verify` gate is red
- Evidence: `src/lib/masterFields.ts:88,102,261` (see §0).
- Fix: guard the indexed accesses (`arr[i] ?? 0`, early-return on `undefined`) before this file lands. The release pipeline should refuse to build or publish when `npm run verify` fails.
- Delivery: **OTA**

---

## P1: needed to meet fintech/insurance production norms

### Security

| # | Finding | Evidence | Fix | Delivery |
|---|---|---|---|---|
| P1-1 | Access and refresh tokens use SecureStore's default accessibility (iOS `WHEN_UNLOCKED`, not `...THIS_DEVICE_ONLY`, so the items can migrate in encrypted backups and device transfers). Only the step-up grant and the offline queue use `WHEN_UNLOCKED_THIS_DEVICE_ONLY`. | `src/api/client.ts:57-58` vs `:107`, `src/offline/vault.ts:30` | Pass `{ keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }` on the access and refresh token writes. Consider `requireAuthentication` for the refresh token when biometrics are enabled. | OTA (JS option). Existing items are rewritten at the next refresh. |
| P1-2 | No idle or session timeout. A restored session stays valid indefinitely. Biometric lock is opt-in, customer-only (`app/account/security.tsx` sits inside the customer guard) and fires on every resume with no grace period. | `src/components/AppRuntime.tsx:55-72,113-117`, `src/store/session.ts:93` | Record a `lastActiveAt` timestamp. After N minutes in the background (for example 5), require biometric or PIN. After M minutes idle (for example 15), or a server-configured value from runtime bootstrap, call `invalidate()`. Offer biometric setup to partner portals (carrier, broker, agent) too, since they approve claims and money. | OTA |
| P1-3 | Screenshot protection is Android-only and production-env-only, and it applies to all screens. The iOS app-switcher snapshot relies on P0-1's cover, which is currently broken by design. There is no iOS screen-capture handling. | `plugins/withOpesInsureSecurity.js:20-33`, `app.config.js:34` | Use `expo-screen-capture` (`preventScreenCaptureAsync` on policy, KYC, payment and claim screens) plus the overlay from P0-1. Keep FLAG_SECURE for the production profile. | APK (new native module) |
| P1-4 | No root, jailbreak or integrity signal. The attestation screen sends `attestation_token: null`. | `app/security/device-status.tsx:24,42`, `src/api/client.ts:1860-1870` | Add a Play Integrity / App Attest native module (for example `@expo/app-integrity` or `react-native-google-play-integrity`) and have the server enforce `device_risk_action`. | APK |
| P1-5 | No certificate pinning (`usesCleartextTraffic=false` is set, which is good). | `plugins/withOpesInsureSecurity.js:15` | Add Android `network_security_config` pin-set (with a backup pin and an expiry) via the config plugin, and TrustKit or equivalent on iOS. Or document an accepted risk for the MVP. | APK |
| P1-6 | The session bootstrap (user profile and workspaces, which is PII) is cached in plain AsyncStorage. Master data and recent proposal IDs are also stored there. | `src/store/session.ts:36,44`, `src/store/insurance.ts:40` | Keep only non-PII in AsyncStorage. Move the user/workspace cache to SecureStore (it is small), or encrypt it with a key held in SecureStore. Make sure `signOut` clears it. | OTA |
| P1-7 | The payment Idempotency-Key embeds the payer's phone digits, so the phone number goes into request headers and, on the server, into the idempotency store and logs. | `src/lib/purchase.ts:446-449` | Hash it: `sha256(proposalId:attempt:provider:phone)` via `expo-crypto`. | OTA (the server must accept the new key format; in-flight keys change once) |

### Reliability and data

| # | Finding | Evidence | Fix | Delivery |
|---|---|---|---|---|
| P1-8 | The offline queue is stored in one SecureStore key with a 6000-byte cap. After a few queued operations, `enqueue` throws `OFFLINE_SECURE_PAYLOAD_TOO_LARGE`. | `src/offline/vault.ts:8,27-28` | Store one key per operation (`queue.index` plus `op.<id>`), or put an encrypted file under `FileSystem.documentDirectory` with the key in SecureStore. Set a hard maximum queue length and show a UX message when it is reached. | OTA |
| P1-9 | The app polls `RuntimeApi.bootstrap` and a network check every 10 s, forever, including in the background, on a market with metered data. | `src/components/AppRuntime.tsx:107-112` | Check on launch, on foreground, and on NetInfo reconnect. Drop the interval, or set it to 5 min or more while foregrounded only. | OTA |
| P1-10 | There is no data cache or stale-while-revalidate layer. `useLoad` refetches on every mount, keeps nothing across screens, and does no request dedupe. Only one retry, and only for transient failures (`client.ts:191-201`). | `src/hooks/useLoad.ts` | Adopt TanStack Query (retry with backoff, `staleTime`, focus refetch, offline persistence via `persistQueryClient` to encrypted storage). Show a "last updated" or stale badge when serving cache offline. | OTA |
| P1-11 | Crash and error reporting only covers React render errors. The event carries only `error.name` (no stack, route or release). There is no global JS handler (`ErrorUtils.setGlobalHandler`), no unhandled-rejection hook, and no native crash capture or ANR reporting. The boundary text is hard-coded English. | `src/components/ProductionErrorBoundary.tsx:10-15,25-27`, `src/security/telemetry.ts` | Add `@sentry/react-native` (Expo plugin, source maps uploaded by EAS, PII scrubbing via `beforeSend`, release = appVersion plus updateId). Keep the in-house telemetry for product events. Localize the boundary and add a "Go home" action. | APK (native SDK). Boundary copy can go OTA. |
| P1-12 | Android push will not deliver in standalone builds: there is no `android.googleServicesFile` / FCM v1 credentials. `getExpoPushTokenAsync` fails or returns nothing on Android release builds. | `app.json` android block (none), `src/notifications/push.ts:29` | Add `google-services.json` via an EAS file secret (`googleServicesFile: process.env.GOOGLE_SERVICES_JSON`) and upload FCM v1 credentials to EAS. | APK |

### Release and OTA

| # | Finding | Evidence | Fix | Delivery |
|---|---|---|---|---|
| P1-13 | Two build profiles with different JS config share the `production` update channel. `production` has `SHOW_DEMO_LOGIN=false`, `production-apk` has `true`. `EXPO_PUBLIC_*` values are baked into the JS bundle at update time from the local env or `.env.production` (`SHOW_DEMO_LOGIN=false`), **not** from `eas.json` build env. So one `eas update --channel production` silently changes one population's behaviour. If someone publishes with no env set, `apiBaseUrl=""` makes `productionConfigurationIssues` return `HTTPS_API_REQUIRED`, and the whole fleet is bricked behind the configuration-error gate. | `eas.json:41-53`, `.env.production`, `src/config/environment.ts:5-6,33` | (a) Give `production-apk` its own channel (for example `production-apk`), or drop the difference. (b) Publish updates only through a script that runs `eas update --environment production` (EAS env vars) and first runs `release:doctor --production` against the bundle env. (c) Have release-doctor refuse to publish when `EXPO_PUBLIC_API_BASE_URL` is unset. | Needs a new APK to move channels. Process fix is immediate. |
| P1-14 | `runtimeVersion` policy is `appVersion`, but the version is maintained in three places that disagree: `app.json` 1.2.2, `app.config.js:8` hard-codes 1.2.2, `package.json:3` says 1.2.0, and the runtime fallback is `"1.1.0"` (`src/config/environment.ts:20`). With `appVersion`, a native change shipped without a version bump will receive incompatible OTA JS. | as cited | Switch to `runtimeVersion: { policy: "fingerprint" }` (SDK 54 supports it), which makes native changes auto-segregate. Use one source of truth for the version (read it from `package.json` in `app.config.js`). Remove the `"1.1.0"` fallback. | APK (runtime policy change) |

### Accessibility and i18n

| # | Finding | Evidence | Fix | Delivery |
|---|---|---|---|---|
| P1-15 | FR completeness: the catalogue is complete (en 706 keys = fr 706 keys, enforced by `Record<keyof typeof en>`). The problem is that about **113 of 143 screen files** still contain hard-coded English: about 411 `label=`/`title=`/`placeholder=` literals and about 123 `<Text>` literals. It is concentrated in agent (16 files), carrier (14), broker (13), quote (8) and claim (8). Customer-facing examples include `app/(customer)/(tabs)/compare.tsx:7`, `app/(customer)/(tabs)/policies.tsx:37-56`, `app/verify.tsx`, `app/checkout.tsx:54`, `app/+not-found.tsx:14`, `app/access-denied.tsx:22`, `src/components/StatePanel.tsx:35-41,47` (the shared error and loading copy) and `ProductionErrorBoundary`. `Alert.alert` literals: `app/quotes/[id].tsx:104`, `app/carrier/referrals/[id].tsx:34`. | grep counts above | Move to `t()` keys. Add a lint rule (`react-native/no-raw-text` or a custom rule banning string literals in `label`/`title`/`placeholder`/`message` props) and a test that fails on new literals. | OTA |
| P1-16 | Customer-visible dates and money bypass the locale helpers: `toLocaleDateString()` in `app/verify.tsx`. The carrier amount parse `Math.round(Number(amount.replace(/\s/g,"")) * 100)` gives `NaN` for FR input like `1 000,50`. | `app/carrier/claims/[id].tsx:47`, `app/verify.tsx` | Use `formatCameroonDate` and a locale-aware amount parser. Validate the result with `Number.isFinite` before submitting. | OTA |

### Performance

| # | Finding | Evidence | Fix | Delivery |
|---|---|---|---|---|
| P1-17 | Lists are not virtualized. Only 4 `FlatList`/`SectionList` uses exist, and about 87 screen files render `.map()` inside the global `Screen` `ScrollView` (`src/components/ui.tsx:45`). That covers policies, claims, payments, notifications, carrier claim queues and broker clients. Paged lists grow without bound. | `src/components/ui.tsx:45-56`, for example `app/services/index.tsx:25` | Add `Screen scroll={false}` plus `FlashList` (or `FlatList`) for any list backed by `usePagedList`. Use `onEndReached` to call `loadMore`, and `RefreshControl` for pull-to-refresh. | OTA (FlashList v2 is JS-only on the new architecture, so check it ships in the current native build; otherwise use `FlatList`, which is OTA) |

---

## P2: hardening and polish

| # | Finding | Evidence | Fix | Delivery |
|---|---|---|---|---|
| P2-1 | `USE_FINGERPRINT` is deprecated (API 28+). `RECORD_AUDIO` is justified (claim video) but is requested at install. The iOS microphone string uses the plugin default. | `app.json:46-48` | Remove `USE_FINGERPRINT`. Set `microphonePermission` in the `expo-image-picker` plugin with purpose text (EN; add FR via `locales`). List both in Play Data Safety (photos/videos and audio, collected for claims, not shared). | APK |
| P2-2 | `ITSAppUsesNonExemptEncryption: true` forces export-compliance paperwork. HTTPS/TLS-only apps qualify for the exemption. | `app.json:17`, `app.config.js:13` | Set it to `false` unless custom cryptography is shipped (`expo-crypto` UUIDs and hashes are exempt). | APK (iOS) |
| P2-3 | `supportsTablet: true` with portrait lock and no tablet layouts. | `app.json:11` | Set `false` until tablet QA is done, or test iPad layouts. | APK |
| P2-4 | `submit.ios` still has placeholders `SET_APPLE_TEAM_ID`/`SET_ASC_APP_ID`. `credentials/` is not in `.gitignore`, so a Play service-account key could be committed. | `eas.json:73-77`, `.gitignore` | Add `credentials/` and `*.json` keys to `.gitignore`, and use EAS-stored credentials. | none (config) |
| P2-5 | Play Store readiness: there is no privacy-policy or terms URL in app config or on the Account/Privacy screen (`app/account/privacy.tsx` sends export and delete requests by email template). Play requires an in-app and listing privacy URL, plus an account-deletion URL, for apps that create accounts. | `app/account/privacy.tsx:33-60`, `app/terms.tsx` | Add `https://insurance.opesdatacenter.tech/privacy` and `/account-deletion` links (in-app plus listing), and a Data Safety form draft: phone, name, KYC ID images, photos/videos, device IDs, crash logs; encrypted in transit; deletion on request. | OTA (links) |
| P2-6 | Contrast: `neutral400` `#8A98A6` on white is about 2.9:1, used for placeholders (`ui.tsx`, `PurchaseUi.tsx:207`, `MasterSelectField.tsx:175`). `neutral500` `#687B8E` is about 4.3:1 on white and about 4.0:1 on `neutral50`, used in 13 places as text. | `src/theme/tokens.ts:6`, grep | Placeholders are exempt from AA but still hard to read outdoors. Use `neutral500` for placeholders and `neutral600` (5.9:1) for any meta text. | OTA |
| P2-7 | Font scaling is capped only on the header and button (`maxFontSizeMultiplier 1.8`, `ui.tsx:92,165`). Body text scales without limit, and fixed-height rows can clip. Some touch targets are below 44 dp: `SupportContacts.tsx:73` (40), `FlowPrimitives.tsx:77` (42). About 107 `Pressable`s; most carry a role or label, but it is not enforced. | as cited | Use `minHeight: 48` on Android targets. Set a global `Text.defaultProps.maxFontSizeMultiplier` of about 2.0 and test at 200%. Add `eslint-plugin-react-native-a11y`. | OTA |
| P2-8 | Design-token drift: 4 raw hex colours. | `src/components/policies/PolicyDocumentsSection.tsx:69,94,95` | Replace them with `colors.*`. | OTA |
| P2-9 | `userInterfaceStyle: "light"` only, and the dark splash equals the light splash. That is acceptable, but it should be declared a product decision. | `app.json:8,102` | Document it. Dark mode is future work. | APK if changed |
| P2-10 | Business rules duplicated on the client: the claim action matrix (`src/lib/claimStatus.ts:145-156` decides which claim actions are shown), the vehicle usage to tariff mapping (`src/lib/riskSchema.ts:67`, "mirrors the server's VehicleUsageMapper"), the local risk schemas used as a fallback (`riskSchema.ts:73`, `LOCAL_SCHEMAS`), and the 30-day renewal window (`src/lib/customerLogic.ts:80-90`). The server still enforces all of these, so this is drift risk, not a security hole. | as cited | Have the API return `allowed_actions[]` per claim and `renewal_due` per policy, and have the risk-schema endpoint return `usage_type` derivation. Keep `LOCAL_SCHEMAS` only as an offline read-only fallback, and fail closed on submit if the server schema is unavailable. | OTA |
| P2-11 | The source-map `.hbc.map` (10 MB) sits in `dist/`. It is ignored by git, which is fine. The JS bundle is 3.4 MB HBC. `lucide-react-native` is imported per-icon, which is fine. There is no startup profiling, and fonts block the first render (`app/_layout.tsx:32-35`, which returns `null` until Inter loads). | as cited | Embed fonts with the `expo-font` config plugin (native, no runtime load). Measure TTI with a release build and `react-native-performance`. | APK (font embedding) |
| P2-12 | Deep links: `+native-intent.tsx` normalizes `/app/*`. That is good, and `Stack.Protected` guards and notification targets are validated (`resolveNotificationTarget`). The gap is that the `assetlinks.json` / AASA files on `insurance.opesdatacenter.tech` are not verified in-repo. | `app/+native-intent.tsx`, `app.json` intentFilters | Add a CI check that `/.well-known/assetlinks.json` contains the release SHA-256 and that `apple-app-site-association` lists `/app/*`. | none |
| P2-13 | `onSessionExpired` routes to `/session-expired`, but pending offline operations and the React state of open forms are not preserved or warned about. | `AppRuntime.tsx:104-106` | Warn when the offline queue is non-empty before `invalidate()`. The queue is already in SecureStore, so keep it scoped to the user ID. | OTA |

### Testing (T-1, P1)
- There are no unit or behaviour tests of components, hooks or stores (`useLoad`, `usePagedList`, `session.hydrate`, `resilience.syncNow`, `api()` retry, idempotency and refresh). The existing 111 node tests mostly assert on source text, which breaks on refactors and misses runtime bugs such as P0-1 and P0-2.
- Maestro has 2 flows (`maestro/smoke-customer.yaml`, `smoke-security.yaml`). They are not wired to CI. The OTP `246810` hard-coded there differs from the documented demo OTP `123456` (confirm which is current).
- Fix:
  - Add `jest-expo` plus `@testing-library/react-native`. Cover `api()` (401 refresh, one retry with the same Idempotency-Key, no retry for non-idempotent POST), `AppRuntime` lock/cover behaviour (a regression test for P0-1), `useLoad` error path, and the offline vault size limit.
  - Add Maestro flows for quote-to-pay, claim with camera, and the language switch to FR.
  - Run `verify`, a Jest coverage gate (for example 60% on `src/`), and Maestro on an EAS build in CI before `eas update` or `eas build`.

---

## What is already at standard (no action)
- Tokens are in SecureStore, not AsyncStorage. `allowBackup=false`, `usesCleartextTraffic=false` (`plugins/withOpesInsureSecurity.js`).
- The production gate is `productionConfigurationIssues()`. HTTPS is enforced. Demo mode is gated on env, not on the demo-login flag.
- Idempotency keys are held stable across retry and 401 replay (`src/api/client.ts:178-201`). Retries run only for GET or idempotent writes. There is a 15 s timeout with AbortController. Refresh rotation is single-flight.
- The runtime bootstrap provides maintenance and force-update gates.
- Stack guards per portal. Validated notification routing. `+native-intent` link normalization.
- Keyboard handling: `KeyboardAvoidingView`, `keyboardShouldPersistTaps="handled"`, and safe-area insets on the shared `Screen`.
- The EN/FR catalogue has type-enforced parity. Formatting is Cameroon-local (`fr-CM`/`en-CM`, `Africa/Douala`, FCFA).
- Telemetry scrubs PII keys and never blocks an operation.
- `UpdateNotice` surfaces fetched OTA updates, and `checkAutomatically: ON_LOAD` is used with `fallbackToCacheTimeout: 0`, which is correct for poor networks.
- Adaptive icon (1254 px) and splash are configured. The EAS production profile builds an AAB with `autoIncrement` and `appVersionSource: remote` (versionCode is managed by EAS). Expo 54 defaults to targetSdk 35 or higher, which meets the Play 2025/26 requirement.

## Delivery summary

| Needs a new APK/AAB | Can ship OTA (runtime 1.2.2) |
|---|---|
| P1-3 screen-capture module, P1-4 integrity module, P1-5 pinning, P1-11 Sentry native, P1-12 FCM `google-services.json`, P1-13 separate channel for `production-apk`, P1-14 `fingerprint` runtime policy, P2-1 permissions, P2-2 and P2-3 iOS flags, P2-11 embedded fonts | P0-1 overlay lock, P0-2 error states, P0-3 typecheck, P1-1 keychain option, P1-2 idle timeout, P1-6 cache location, P1-7 hashed key, P1-8 queue storage, P1-9 polling, P1-10 query cache, P1-15 and P1-16 i18n, P1-17 FlatList virtualization, P2-5 links, P2-6 to P2-8, P2-10, P2-13, all tests |

Recommended order:
1. Ship the OTA batch: P0-1, P0-2, P1-9, P1-1, P1-8, and P1-15 for the customer screens.
2. Cut build 1.3.0 with the native items. Switch to the `fingerprint` runtime and a dedicated `production-apk` channel in the same build, so that the next OTA cannot cross-contaminate.
