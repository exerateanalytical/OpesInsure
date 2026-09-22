# OpesInsure Expo Mobile

Native, mobile-first customer and partner application for the OpesInsure insurance platform.

For Claude/backend implementation, read `docs/OPESINSURE_BACKEND_INTEGRATION_SEEDING_AND_DEMO_GUIDE.md` and `docs/BATCH_1_CLAUDE_HANDOFF.md` first.

## Run the complete Cameroon demo

Copy `.env.demo.example` to `.env`, start Expo, select **Choose a demo account**, and use OTP `246810`. Demo mode uses `src/data/demo/opesinsure-cameroon-demo.v1.json` through the in-app demo adapter; it never calls Laravel. Never enable demo mode in a production build.

## What is included

- Two branded startup screens, including Opesware attribution and Azure expertise statement.
- OTP authentication shell and role selection.
- RBAC-aware customer, agent, broker, carrier and platform workspaces.
- Customer discovery, quote, offer comparison, disclosure, checkout, Mobile Money status and confirmation.
- Policies, renewals, claims/FNOL, verification and account experiences.
- ASAC insurer directory with 29 life/non-life member entries checked on 22 September 2026.
- A dated MINFI broker directory that explicitly warns users that a historical listing is not proof of current licensing.
- Officially sourced product metadata only where a public insurer page was verified.
- Shared native design tokens and accessible components based on the OpesInsure Design System v2.
- Typed Laravel API client starter with bearer authentication, request IDs, timeouts and idempotency keys.

## Run locally

```bash
cp .env.example .env
npm install
npm run start
```

For a development build:

```bash
npx expo prebuild
npm run android
```

Expo Go is suitable for early layout review. Use EAS or local development builds for production authentication, secure storage, document capture, push notifications and platform-specific verification.

## Data integrity rule

Never treat static broker directory data as live licence confirmation. A backend synchronisation and approval workflow must publish `current_verified` only after checking an authoritative MINFI source. Never invent insurer prices, products, coverage, regulatory tariffs or logos.

See [docs/LARAVEL_INTEGRATION_GUIDE.md](docs/LARAVEL_INTEGRATION_GUIDE.md) and [docs/SCREEN_AND_FLOW_REGISTER.md](docs/SCREEN_AND_FLOW_REGISTER.md).
