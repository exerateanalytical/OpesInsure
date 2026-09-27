# Security and Release Notes

## Controls included in the scaffold

- SecureStore boundary for access credentials.
- No confidential OAuth client secret in application code.
- Request correlation IDs, explicit timeouts and idempotency keys.
- Local role/permission map for presentation; documentation requires server-side enforcement.
- Tenant-safe denied-state pattern for workspace deep links.
- No seeded identity documents, payment credentials, regulatory prices or customer secrets.
- No unapproved third-party logo files or remote hotlinks.
- Durable payment-state design rather than an indefinite spinner.
- Explicit dated-data warnings for broker licensing.
- Disclosure/consent step before financial authorization.

## Verification completed

- TypeScript strict check: passed.
- Expo ESLint: passed.
- Expo Doctor: 18/18 checks passed.
- Android production Metro export: passed.

## Dependency advisory

An `npm audit --omit=dev` check on 22 September 2026 reported transitive advisories inside the Expo 54/Metro build toolchain. The automated proposed remedy is a breaking SDK change and was not blindly applied. Before store release:

1. Upgrade through Expo’s supported SDK migration process to a release containing patched Metro, PostCSS, image-size, UUID and query-string dependencies.
2. Re-run Expo Doctor, TypeScript, lint, native prebuild and Android/iOS builds.
3. Run SAST, dependency review, secret scanning, mobile application security testing and an OWASP MASVS-aligned assessment.
4. Produce and archive an SBOM and signed release provenance.

This scaffold is validated for development handoff; a store release requires these gates and live backend security verification.

## Mobile security decisions (audit 2026-09-27)

- **Transport (SEC-001):** production API is HTTPS only; `productionConfigurationIssues()` rejects a non-HTTPS base URL and the Android manifest sets `usesCleartextTraffic=false`.
- **Secrets (SEC-002):** tokens, refresh tokens and step-up grants live in SecureStore only; `allowBackup=false` keeps them out of Android backup/device transfer. AsyncStorage holds only non-secret preferences.
- **Screen capture (SEC-006):** `FLAG_SECURE` is applied app-wide by `plugins/withOpesInsureSecurity.js`. Documents and receipts are shared through the OS share sheet / download, which FLAG_SECURE does not block. No change made.
- **Certificate pinning (SEC-009): not used.** Decision: rely on system trust + HTTPS-only + short-lived tokens. Pinning without a tested rotation path risks a full outage when the server certificate (Let's Encrypt, 90-day) rotates. If adopted later: pin the SPKI of the issuing intermediate *and* a backup key, ship via `network_security_config.xml` with an `expiration` date, and rehearse rotation on a preview build first.
- **Device attestation (SEC-007/SEC-008):** `src/security/attestation.ts` calls an optional native module `OpesIntegrity` (Play Integrity / App Attest token + local root/emulator/debug hints) through `requireOptionalNativeModule`, so OTA updates on older APKs report `UNAVAILABLE_MANAGED_RUNTIME` instead of crashing. The server verifies the token and returns ALLOW/LIMIT/BLOCK; local signals only feed risk and step-up, never a hard client lock.
- **OTA signing (SEC-010):** channels are separated in `eas.json` (development / preview / production). To enforce signed updates (needs the owner's keys, not stored in the repo):
  1. `npx expo-updates codesigning:generate --key-output-directory keys --certificate-output-directory certs --certificate-validity-duration-years 10 --certificate-common-name "OpesInsure"` on a trusted machine; keep `keys/` out of git (private key in the password vault / EAS secret).
  2. Add to `app.json` > `expo.updates`: `"codeSigningCertificate": "./certs/certificate.pem", "codeSigningMetadata": { "keyid": "main", "alg": "rsa-v1_5-sha256" }` and commit only the public certificate.
  3. Build a new APK (the certificate is embedded natively), then publish with `eas update --channel production --private-key-path keys/private-key.pem`.
  4. Rollback: `eas update:republish` / `eas update:rollback` on the production channel.
- **APK signing (SEC-011):** release keystore is held by EAS credentials (v2+ signing); never commit keystores.
