# Mobile backlog — Batch 17 (for the mobile app session)

Source: backend agent B7 (Batch 17D hardening). The backend contracts below exist on the Laravel side now.
This file lists what the **Expo app** has to do. Requirement ids are from `docs/spec/TRACEABILITY_MATRIX_V1.md`.
Nothing here is a regulatory or legal number. Where a value is still open it is marked **OWNER**.

## 1. REQ-SEC-005 — MASVS hardening (traceability: "MASVS 0/9 closed")

| # | MASVS area | App work | Backend contract | Done when |
|---|---|---|---|---|
| M1 | STORAGE-1 | Tokens, refresh tokens, and the device fingerprint only in `expo-secure-store` (Keychain / Keystore). Nothing sensitive in AsyncStorage, MMKV, or logs. | — | A grep for AsyncStorage finds no token or PII keys, and a test covers it |
| M2 | STORAGE-2 | Offline drafts (FNOL, evidence, inspection) encrypted at rest. The key lives in secure-store. Drafts are purged on logout. | `mobile/sync` (existing) | Drafts cannot be read from a backup or an extracted file |
| M3 | CRYPTO-1 | No home-made crypto. Use platform APIs only. | — | Review sign-off |
| M4 | AUTH-1/2 | Step-up before sensitive actions (payments, payout changes, consent withdrawal). Biometric unlock only gates a key held in secure-store and never replaces server auth. | `MobileStepUpService` (existing) | Sensitive endpoints are reached only after step-up |
| M5 | NETWORK-1 | TLS only, with no cleartext exceptions in the release build (`usesCleartextTraffic=false`, ATS on). | — | Release manifest / Info.plist checked in CI |
| M6 | NETWORK-2 | Certificate / public-key pinning for the API host. Pin two keys (current + backup). **OWNER**: the pin-rotation procedure and the backup key. | — | A MITM proxy is refused in the release build |
| M7 | PLATFORM-1 | Deep links are validated against an allow-list of in-app routes. No WebView JavaScript bridge to untrusted content. | App links (section 3) | Fuzzed deep links cannot reach privileged screens |
| M8 | RESILIENCE-1 | Root/jailbreak/emulator signals are sent as `signals` to the device assessment. The app obeys the server's `action` (ALLOW / LIMIT) and never decides by itself. | `POST mobile/security/device-attestation/assess` | LIMIT disables payments, payouts and profile changes |
| M9 | RESILIENCE-2 | Attestation (section 2). Release builds are obfuscated (Hermes + R8/ProGuard), and debuggable=false. | — | Release build audit |

## 2. REQ-SEC-005 — Device attestation

Flow (all calls are authenticated):
1. `POST /api/v1/mobile/security/device-attestation/nonce` returns `{nonce, expires_at}`. The nonce is single-use and valid for 10 minutes.
2. Android: request a **Play Integrity** token with that nonce (`requestIntegrityToken`, nonce = base64url of the nonce string). iOS: use **App Attest** (`DCAppAttestService`), with clientDataHash = SHA-256 of the nonce.
3. `POST /api/v1/mobile/security/device-attestation/assess` with `{nonce, platform: ANDROID|IOS, provider: PLAY_INTEGRITY|APP_ATTEST|UNAVAILABLE_MANAGED_RUNTIME, token, signals: {rooted, jailbroken, emulator}}`.
4. The response now also carries `attestation: {verdict: PASS|FAIL|UNVERIFIED, reasons[]}`.
   - The server verifies the token. The client never decides the verdict.
   - `UNVERIFIED` means the verifier is not configured (or the provider could not be reached). The app must treat it as "no assurance", not as a pass.
   - `FAIL` makes `action` = `LIMIT`.
5. Run the assessment at cold start and before sensitive actions. Cache it until `expires_at`, which is 12 hours.

Backend status: Play Integrity decoding is implemented and switched off by default (`PLAY_INTEGRITY_*` env). App Attest returns UNVERIFIED until a full verifier is written (`VERIFIER_NOT_IMPLEMENTED`). **OWNER**: provide the Google Cloud project, service account and package name, and the Apple Team ID and bundle id.

## 3. REQ-MOB-007 — App links / universal links

- The backend now serves `/.well-known/assetlinks.json` and `/.well-known/apple-app-site-association` from config (`APP_LINKS_ANDROID_PACKAGE`, `APP_LINKS_ANDROID_SHA256`, `APP_LINKS_IOS_APP_IDS`, `APP_LINKS_IOS_PATHS`, default `/app/*`). Both files are empty until they are configured.
- App: in `app.json`, add `android.intentFilters` with `autoVerify: true` for the web host, and add `ios.associatedDomains: ["applinks:<host>", "webcredentials:<host>"]`.
- **OWNER**: the production host, the release signing-certificate SHA-256 (Play App Signing key, not the upload key), and the Apple Team ID.
- Blocked on **REQ-MOB-006** (see the owner question below): which path namespace the links use.

## 4. REQ-MOB-007 — Crash reporting (no PII)

- New endpoint `POST /api/v1/mobile/runtime/crash-reports`. It works before login, is throttled to 30 requests per minute, and needs no auth.
- Body (only these fields; any other key → 422): `platform (ANDROID|IOS|WEB), app_version, build?, os_version?, error_type, message?, stack?`.
- The server scrubs emails, phone-like numbers, JWT/bearer/password tokens, UUIDs, URL query strings and user paths. It then truncates the message to 500 bytes and the stack to 8 KB, and groups repeats by fingerprint.
- The app must still **not** send user ids, device ids, phone numbers, names, form contents, or request/response bodies. Use a global JS error handler plus the native crash handler, and call the endpoint on the next launch (the stored crash is kept for one retry only).
- The existing `mobile/runtime/telemetry` event `APP_CRASHED` stays for counting. The crash-report endpoint is for stack triage.
- Optional later step: a vendor SDK (Sentry etc.). **OWNER** decides; the no-PII rules above still apply.

## 5. Security centre surfaces (for the account screen)

- `GET /api/v1/me/security/login-activity` returns the user's last 50 sign-ins (device, platform, country, `new_device`, `anomaly_flags`). Show it under Account → Security, and add a "not me? sign out everywhere" action that uses the existing revoke-all-sessions call.
- Consent: `POST /api/v1/consents` and `POST /api/v1/consents/{id}/withdraw` now accept any purpose in the processing-purposes catalogue that is marked consentable. The existing mobile consent toggles (MARKETING, PARTNER_SHARING, ANALYTICS, WHATSAPP_UPDATES) are all in the catalogue. Marketing messages are now blocked server-side without a MARKETING consent.

## 6. REQ-MOB-001 — Customer journey

The "production-ready" checklist is complete (home, categories, quote, compare, proposal, pay, wallet, renewals, claims). Follow the AUD H order. Every screen needs loading, empty, error and offline states and EN/FR text. This batch adds no new backend contract.

## 7. REQ-MOB-003 — Offline

- Drafts only for FNOL, evidence and inspection. **Never** finalise a payment, a policy issuance, or any regulatory action offline.
- Sync drafts through `mobile/sync`. Encrypt them per M2. Conflicts go through the server version (last server write wins, and the user is shown the diff).

## 8. REQ-MOB-004 — EN/FR everywhere

Currently about 4 of 121 screens are translated. Translate every title, status label, error, and push/notification text. Status labels come from the backend label APIs where they exist. Do not hard-code FR strings in components.

## 9. REQ-MOB-005 + REQ-DUP-012 — Partner workspaces

- Canonical API family: **`mobile/partner/{role}/*`** (agent, broker, carrier). Move the app off `mobile/agent/*` (wave14), the wave12 agent-mode routes, `mobile/broker/*` and `mobile/carrier/*`. The backend removes the legacy families once the app no longer calls them.
- Still to do: broker actions (not read-only), agent proposal and claims (agent-assisted FNOL: `POST mobile/partner/agent/claims`), and insurer menus with actions, all under RBAC.

## 10. REQ-NOT-001 — Notifications

- Register push tokens (existing `user_push_tokens` endpoint) after login, and delete the token on logout.
- Build the in-app notification centre with EN/FR templates rendered server-side.
- Add marketing opt-in toggles that map to the MARKETING consent (section 5). Without that consent, the server will not send marketing.

## Owner question — REQ-MOB-006 (route namespace)

The SSR specifies web-style `/app/...` paths. The Expo app uses its own expo-router paths, and today deep links map `/app/...` onto in-app paths.
**Question:** should `/app/...` be the canonical public link namespace (used in app links / AASA, emails, SMS and QR codes), with the app keeping an internal mapping table? Or should the public links use the Expo paths directly?
Recommendation (not decided): keep `/app/...` as the stable public namespace, so links survive app refactors. The AASA/assetlinks default path is `/app/*` until the owner decides.
