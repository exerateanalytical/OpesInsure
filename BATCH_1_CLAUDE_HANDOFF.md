# OpesInsure Expo — Batch 1 Laravel Handoff

## Scope boundary

This package changes the Expo application only. It contains no Laravel source. Claude should connect the contracts below to the existing Laravel application without weakening its tenant middleware, policies, audit trail, idempotency, or payment state machine.

## Environment

Set `EXPO_PUBLIC_API_BASE_URL=https://your-host.example/api/v1` before release builds. Production deliberately fails closed when it is absent. Android emulators use `http://10.0.2.2:8000/api/v1` only in development.

Every authenticated request sends `Authorization: Bearer …`, `X-Tenant-Id`, `X-Request-ID`, and JSON headers. Mutations also send an idempotency key. TLS is mandatory outside local development.

## Required mobile authentication adapter

All responses use `{ "data": ... }` and errors use the platform JSON error envelope.

| Method | Route | Purpose |
|---|---|---|
| POST | `/auth/mobile/otp/request` | Queue OTP; return `challenge_id`, `delivery_status`, `expires_in` |
| POST | `/auth/mobile/otp/verify` | Verify challenge/device; return access/rotating refresh tokens plus bootstrap |
| GET | `/auth/mobile/session` | Return current user and authorised workspaces |
| POST | `/auth/mobile/refresh` | Rotate refresh token; revoke replayed token family |
| POST | `/auth/mobile/logout` | Revoke the mobile session and refresh-token family |

The bootstrap must return `user` and `workspaces[]`. Each workspace requires `membership_id`, `tenant_id`, `tenant_name`, `tenant_type`, `role_code`, `permissions[]`, and nullable `customer_id`. Laravel remains the authority: never accept a role or tenant selected only by the client.

Security requirements: hashed OTPs; short expiry; attempt and resend limits; IP/device/phone throttles; enumeration-safe responses; refresh rotation; session/device revocation; audit events; no OTP or token logging.

## Existing insurance contracts consumed

The app calls the existing quote, rate, offer acceptance, proposal, payment initiation/status, policy list and policy-detail routes under `/api/v1`. Preserve their current resource authorization and `X-Tenant-Id` enforcement. Monetary properties are consumed as integer minor units.

Motor quote `risk_facts` currently sends `registration_number`, numeric `fiscal_power`, `usage_type`, and `zone`. Align these keys with the production tariff schema or publish the schema from Laravel; do not silently default a tariff.

## One required purchase-status adapter

Add `GET /api/v1/mobile/purchases/{proposal}/status`, authorised to the proposal owner and active tenant. Response:

```json
{
  "data": {
    "status": "ISSUANCE_PENDING",
    "payment": { "id": "...", "proposal_id": "...", "status": "SUCCEEDED" },
    "policy": null
  }
}
```

Allowed aggregate states are `PAYMENT_PENDING`, `PAYMENT_PROCESSING`, `PAYMENT_FAILED`, `ISSUANCE_PENDING`, and `POLICY_ISSUED`. `POLICY_ISSUED` is legal only when `policy` is non-null and persisted. This endpoint closes the dangerous gap between successful collection and actual insurance coverage.

## Deployment checklist

1. Implement/map the six mobile adapter routes above.
2. Confirm Passport token scopes and every workspace membership server-side.
3. Configure MTN/Orange callbacks and real provider state mapping.
4. Run `npm ci && npm run verify`.
5. Set the production API URL and build with EAS; keep secrets out of `EXPO_PUBLIC_*` variables.
6. Test OTP abuse controls, tenant switching, refresh replay, interrupted payment recovery, failed payment, delayed issuance, and issued policy on real devices.

The app intentionally keeps health, travel, home, life, claims, uploads, and non-customer dashboards closed until their dedicated Laravel contracts exist. It does not show demo prices, invented KPIs, or false coverage.

## Gap-fill adapter contracts added after Batch 1

- `POST /api/v1/public/insurance/verify` accepts `{ reference }` without authentication and returns only `reference`, `result`, optional carrier/product/coverage dates, and `verified_at`. Apply throttling and enumeration-resistant responses; never expose customer data.
- `GET /api/v1/claims` returns the authenticated customer’s tenant-scoped claims.
- `POST /api/v1/claims` creates a first notice of loss from `policy_id`, ISO-8601 `incident_at`, `incident_location`, and `description`. Laravel must verify policy ownership and coverage dates.
- `GET /api/v1/claims/{claim}` returns one authorised claim. Never trust a client-supplied customer or tenant identifier.

Health, travel, home and life quote forms now have distinct mobile inputs; they remain fail-closed until Laravel publishes and validates their product-specific risk schemas. Claim evidence, declaration, timeline and appeal screens likewise require the contracts below.

## Completed Expo gap contracts

The app now provides dedicated risk forms for motor, health, travel, home and life. Laravel must validate each `risk_facts` payload against the active product schema and return field-level 422 errors. Never permit the client to select tariff versions or calculate premiums.

Additional authenticated contracts consumed:

- `POST /claims/{claim}/evidence` multipart upload with `type` and `file`; enforce MIME detection, size limits, malware scanning, immutable hashes and tenant ownership.
- `POST /claims/{claim}/declaration` seals submitted evidence; return the updated claim.
- `POST /claims/{claim}/appeals` accepts `{ reason }` only for appealable decisions.
- `GET /policies/{policy}/certificate` returns a short-lived signed download URL.
- `POST /policies/{policy}/renewal-quote` returns `{ quote, offers }` and preserves origin attribution.
- `POST /policies/{policy}/service-requests` accepts an endorsement or cancellation-review request.
- `/mobile/account/profile`, `/locale`, `/devices`, `/notification-preferences`, and `/push-tokens` support the corresponding account screens.
- `GET /mobile/workspace/dashboard` and `/mobile/workspace/modules/{key}` return role- and permission-filtered partner read models. Never let the client module key bypass policies.

The app displays an offline transaction warning, allows only allowlisted notification deep links, registers native push tokens after permission, and supports opt-in biometric locking. Offline transaction queuing is intentionally not performed: insurance purchases, policy changes and financial actions require an authoritative online response.
