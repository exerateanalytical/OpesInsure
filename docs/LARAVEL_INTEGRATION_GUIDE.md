# Laravel Integration Guide

## Boundary

The Expo application is a client of the existing Laravel API. It must not duplicate tariff calculations, commissions, attribution locks, ledger posting, payment state machines, maker-checker rules or tenant permissions locally. Laravel remains the system of record.

## Required API conventions

- Base path: `/api/v1`.
- OAuth 2.1/OIDC-compatible user authorization with short-lived access tokens and rotating refresh tokens.
- PKCE for mobile authorization; never ship a confidential client secret in the app.
- JSON:API-style success/error envelopes consistent with the existing platform conventions.
- `X-Request-ID` on every request and response.
- `Idempotency-Key` on creates, payment requests, claim submission and retryable financial actions.
- RFC 3339 UTC timestamps and ISO dates.
- FCFA monetary values transmitted as integer minor accounting units or decimal strings—never binary floating point.
- Server-provided effective permissions and tenant context on session bootstrap.
- Cursor pagination and ETags/version values for concurrent updates.

## Authentication sequence

1. App submits the E.164 phone number to `/auth/otp/request`.
2. Laravel rate-limits by phone, device, IP and risk signal, then writes a notification outbox item.
3. User verifies through `/auth/otp/verify`.
4. Backend returns short-lived access token, rotating refresh token, user, memberships, effective permissions and permitted workspaces.
5. Tokens are stored only in platform secure storage.
6. High-risk actions request MFA/step-up authorization.
7. Logout revokes the refresh-token family and clears local protected state.

The current backend outbox still needs a real sending consumer before OTP can work outside a mocked environment.

## Endpoint mapping

| Mobile capability | Required API |
|---|---|
| Session bootstrap | `GET /me` and `GET /me/workspaces` |
| Catalogue | `GET /catalogue/products`, `/catalogue/carriers` |
| Institutional insurer data | `GET /directory/insurers` |
| Broker directory | `GET /directory/brokers?licence_status=&as_of=` |
| Quote | `POST /quotes`, `GET /quotes/{id}` |
| Offers | `GET /quotes/{id}/offers`, `POST /offers/{id}/accept` |
| Proposal | `GET/PATCH /proposals/{id}` and disclosure/document endpoints |
| Payment | `POST /proposals/{id}/payment-requests`, `GET /payment-requests/{id}` |
| Policy | `GET /me/policies`, `GET /policies/{id}`, certificate endpoint |
| Renewal | `POST /policies/{id}/renewal-quotes` |
| FNOL/claim | `POST /policies/{id}/claims`, evidence upload, timeline endpoint |
| Delivery | `GET /fulfilments/{id}` and proof-of-delivery state |
| Agent | client, assisted-sale, portfolio, ledger, withdrawal and dispute APIs |
| Broker | tenant-scoped clients, policies, receivables, claims, bordereaux and reports |
| Carrier | referrals, issuance exchange, products, tariffs and settlement APIs |
| Platform | operations read models and explicitly authorised command APIs |

## Quote contract

`POST /quotes` accepts stable risk facts and returns a quote identifier, tariff version, status and expiry. Offers contain carrier, coverage, excesses, exclusions, fulfilment, gross premium, platform fee, processing fee, total, effective dates and the reason behind any recommendation label.

The app must never calculate a CIMA premium itself. When authoritative tariff data is unavailable, the API returns a defined `TARIFF_UNAVAILABLE` response and the interface offers recovery—not a fabricated estimate.

## Payment state handling

The app recognises at least `created`, `requesting_authorization`, `customer_action_required`, `provider_pending`, `confirmed`, `failed`, `expired`, `cancelled`, `reversed` and `unknown_reconciliation_required`. Closing the app must not lose the transaction. On restart, query by the server payment-request ID and recover the state.

## Uploads

Use backend-issued short-lived upload reservations. Strip unnecessary image metadata on-device, enforce file type/size, malware-scan server-side, preserve evidence hashes and never place permanent object-storage credentials in the app.

## Institutional data synchronisation

- Backend owns insurer and broker records, source URLs, source publication dates and verification timestamps.
- `current_verified` requires a current authoritative source and reviewer approval.
- Historical official publications remain searchable but visibly dated.
- Product offers need carrier ownership, source evidence, effective dates, approval and expiry.
- Logos require a carrier-supplied or officially authorised asset. The neutral monogram fallback remains mandatory.

## Offline policy

Read-only policy summaries and certificates may be encrypted locally for the authenticated user. Quote drafts, FNOL drafts and document uploads may queue with client-generated IDs. Payments, withdrawals, approvals and final submissions must obtain a server acknowledgement and are never represented as complete while offline.

## Security acceptance

- Enforce every permission and tenant boundary in Laravel; mobile RBAC is presentation only.
- Device-bound refresh rotation, certificate pinning decision, jailbreak/root risk response and remote session revocation.
- No secrets, full payment payloads or identity documents in logs or crash analytics.
- Redact notifications on locked devices.
- Deep links validate route, role, tenant and resource ownership.
- Accessibility, bilingual expansion and 320–430 px checks are release gates.
- Threat-model OTP abuse, account takeover, IDOR, replay, webhook spoofing, document substitution and offline queue tampering.
