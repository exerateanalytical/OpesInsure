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
