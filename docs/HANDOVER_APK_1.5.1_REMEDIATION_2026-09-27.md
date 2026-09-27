# OpesInsure Android 1.5.1 — remediation handover (27 September 2026)

Source plan: "OpesInsure Android 1.5.0 (versionCode 19) – remediation plan" (findings OPS-01 to OPS-11).
Release: **1.5.1**, EAS build `3efbef9d` (versionCode 20 expected), channel `production-apk`, commit `fc3b410`.
Checks at build time: `npm run verify` — typecheck, lint 0 errors, **274/274 tests**.
Build status and download: see section 5.

## 1. Changelog by finding

| ID | Severity | Status | What changed | Needs new APK |
|---|---|---|---|---|
| OPS-01 | high | **Done in code; push works once the owner uploads the FCM key** | `google-services.json` (project `opesinsure`, package `com.opesware.opesinsure`) is built in via `android.googleServicesFile` (env var `GOOGLE_SERVICES_JSON` or the local file; kept out of git). Push registration rewritten (`src/notifications/push.ts`): Android channels created first, POST_NOTIFICATIONS asked only when allowed, EAS projectId passed, token sent to `POST /mobile/account/push-tokens` with provider/platform, re-registered on token change and login, failures sent as `PUSH_REGISTRATION_FAILED`. Duplicate raw-FCM registration removed. | Yes |
| OPS-02 | high | **Done in code; owner must enable Play Integrity** | Local Expo module `modules/opes-integrity` implementing the existing `OpesIntegrity` contract (`requestToken(nonce)`, `localSignals()`): Play Integrity Standard API on Android (token bound to the server nonce), App Attest with DeviceCheck fallback on iOS. JS guard kept, server decides. Without the Cloud project number it reports `NOT_CONFIGURED`. Native code was hand-checked; the EAS build is its first compile. | Yes |
| OPS-03 | medium | **Done; owner must back up the key** | Code-signing key pair generated (private key **outside the repo** at `C:\Users\PC\.opesinsure-keys\codesigning\`), certificate in `certs/certificate.pem`, `updates.codeSigningCertificate` + metadata in `app.json`. `publish-update.mjs` signs every update and refuses to publish without the key; release doctor checks certificate, metadata and that no private key is inside the project. Existing 1.5.0 phones ignore signatures, so updates keep reaching them. | Yes (enforced from 1.5.1) |
| OPS-04 | medium | Done | Security config plugin forces `com.canhub.cropper.CropImageActivity` to `exported=false` (configurable list, `tools:replace`). Upgrading the image picker within SDK 54 does not fix it upstream. | Yes |
| OPS-05 | medium | **Done in code; owner must create Sentry** | `@sentry/react-native` added, **off unless `EXPO_PUBLIC_SENTRY_DSN` is set**. No default PII, no screenshots; scrubber removes phones, emails, ID numbers, policy/claim/proposal references, UUIDs, tokens, headers, bodies. Tags: app version, runtime, update id, channel, role only. Source-map upload only when Sentry secrets exist. | Native crashes: yes |
| OPS-06 | low | Done | 20 permissions blocked (overlay, silent download, write storage, boot receiver, all launcher-badge permissions). READ_EXTERNAL_STORAGE limited to Android 12 and below. EN/FR explanations for camera, microphone, photos, location, Face ID. | Yes |
| OPS-07 | low | Done | Recent proposals hold ids only; payment retry keys deleted once the purchase is issued; both wiped at sign-out and sign-out everywhere. | No (OTA) |
| OPS-08 | low | Done | 50 queued actions measured at 26.5 KB. Queue moved to an AES-256 + HMAC encrypted file, key kept in SecureStore; old queues migrate on first read; cap raised to 300 actions / 2 MB; agent home shows pending count, "Sync now" and a warning from 80%. | No (OTA) |
| OPS-09 | low | Done (config) | Play profile already builds an app bundle. New profile `production-apk-arm64` (arm64 only, estimated 43–48 MB vs 68 MB, not measured). Native PDF engine kept because the owner requires native PDF viewing. | For the arm64 file |
| OPS-10 | check | **Checked: keys match**; v3 deferred | 1.4.0 and 1.5.0 share the signing certificate SHA-256 `8764cfd0628c58cab2098aad18cb8776271900c874bf7a26b01f58bd90f04f18`, so in-place upgrades keep data. APK v3 signing not enabled: EAS injects its own signing config and there is no device here to prove an altered config still installs. | — |
| OPS-11 | info | Done | The literal `${EXPO_PUBLIC_API_BASE_URL}` removed from `app.json`; a test evaluates every build profile and fails on any leftover `${`. | No |

## 2. Also in this release
- **Backend security batch wired in** (works before and after the backend deploy):
  - step-up checks for sign out everywhere, payout-number change and email change;
  - capabilities and `allowed_actions` can hide menus and actions, never show more;
  - device model / OS / app version headers, richer devices and login-activity screens;
  - staff security card for broker admins (suspend access, force re-authentication);
  - security alerts open account security;
  - finance officers get the insurer finance screens.
- **Bug fixed:** a server "step-up required" answer (401) used to sign the user out; it now opens the step-up check instead.
- **Filtering:** every list page has one search field and one filter icon; all status tabs, chips and sort buttons live in the filter sheet.
- **Broker account:** Earnings filters fixed, commission shown per sale, one list standard, report a claim.
- **Money:** FCFA shown as whole francs everywhere.
- **Demo mode removed from the app** (server demo mode is still on until SMS sign-in codes work).

## 3. Owner actions (in this order)
1. **Firebase push key:** from the mobile app folder run `eas credentials` → Android → production → Google Service Account → "Manage your Google Service Account Key for Push Notifications (FCM V1)" → upload the `…b4960a48d9.json` key. Then store that key in your vault and delete other copies; it was shared in chat, so consider generating a fresh key later.
2. **Back up the update-signing private key** `C:\Users\PC\.opesinsure-keys\codesigning\private-key.pem` to a vault with two holders. If it is lost, 1.5.1 phones cannot receive updates until a new APK ships.
3. **Play Integrity:** Play Console → App integrity → Play Integrity API → link the Google Cloud project (`opesinsure`, number 646209714302) and enable the API. Then run `eas env:create --name PLAY_INTEGRITY_CLOUD_PROJECT_NUMBER --value 646209714302 --environment production` and rebuild. Give the backend a service account with Play Integrity decode rights.
4. **Sentry (optional):** create an EU React Native project, then add `EXPO_PUBLIC_SENTRY_DSN`, `SENTRY_AUTH_TOKEN`, `SENTRY_ORG`, `SENTRY_PROJECT` as EAS secrets for the production profiles and in your shell before publishing updates.
5. **Expo account:** turn on 2FA for every member and limit who can publish updates.
6. **Keystore backup:** `eas credentials` → Android → production → download the keystore and store it in the vault.
7. **SMS and MoMo:** add SMS provider credentials and fix MTN MoMo authentication on the server so the backend can switch demo mode off.

## 4. Smoke test on a real phone (not possible on this machine)
Install 1.5.1 over 1.5.0 and confirm you stay signed in. Then check: sign-in with code, quote with the vehicle dropdowns, proposal, payment screen, claim with photo, video with sound and crop, KYC upload, document open/save/share (native PDF), fingerprint lock, location prompt on a new claim, QR verification scan, push notification (after step 1), agent offline queue and "Sync now", sign out everywhere (step-up prompt appears once the backend enables it). On Android 13+ also check the notification permission prompt and the photo picker.

## 5. Build and download
(Filled in when the build finishes.)

## 6. Coordination with the backend session
- It is **holding** the new step-up enforcement (sign out everywhere, payout change, email change) until told. 1.5.1 handles it; 1.5.0 does not. Recommended: enable after 1.5.1 is on the download page and most users have updated.
- Backend security review in progress: role and tenant authorization, IDOR, MoMo/Orange callback verification, payout cooling-off, OTP/login rate limits, upload scanning (`docs/SECURITY_REVIEW_MOBILE_2026-09-27.md` in the backend repo).
- Open backend lists: `docs/BACKEND_NEEDS_MOBILE_AUDIT_2026-09-27.md` (sections A–F plus A7 filter params and D8 commission fields).

## 7. Known gaps
- Notification inbox still shows the severity icon for security alerts (detail screen shows the shield).
- APK v3 signing and the iOS App Attest entitlement are not enabled.
- Nothing in this release has run on a physical device.
