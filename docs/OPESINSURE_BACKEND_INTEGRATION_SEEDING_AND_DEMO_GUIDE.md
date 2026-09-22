# OpesInsure Expo ↔ Laravel Integration, Institutional Seeding and Demo Guide

## Purpose and boundary

This document tells Claude how to connect `opesinsure-mobile` to the existing OpesInsure Laravel API, import the institutional Cameroon insurance directory into PostgreSQL, and create safe demo tenants, accounts and transactional data.

Expo is the client. Laravel remains authoritative for identity, tenancy, permissions, tariffs, offers, payments, policies, claims, documents, audit events and publication status. Do not duplicate those rules in Expo or replace existing Laravel domains blindly; map these contracts onto the existing model.

## Source files

| File | Purpose |
|---|---|
| `src/data/insurers.ts` | ASAC-sourced insurer snapshot and verified public product links |
| `src/data/brokers.ts` | Transcription of the dated MINFI broker publication |
| `src/types/domain.ts` | Mobile institutional-data shapes |
| `src/api/client.ts` | Typed authentication, workspace and insurance contracts |
| `docs/INSTITUTIONAL_DATA_PROVENANCE.md` | Source and legal-use notes |
| `docs/BATCH_1_CLAUDE_HANDOFF.md` | Complete mobile adapter contract |

The TypeScript lists are import sources, not the permanent production database. After seeding, Expo should retrieve published records from Laravel and retain only a versioned offline bootstrap.

## Institutional model

Reuse equivalent existing tables. Minimum carrier/broker fields are: stable UUID, type, legal and trading names, slug, life/non-life branch, city/country, E.164 phone, website, regulator, regulator reference, source URL/publication date/check date, verification status, verification expiry, visibility, approved logo asset, source hash, timestamps and soft deletion.

Statuses should include `SOURCE_LISTED`, `CURRENTLY_VERIFIED`, `UNVERIFIED`, `SUSPENDED` and `WITHDRAWN`. Brokers imported from the 2022 MINFI publication must default to `SOURCE_LISTED`, never `CURRENTLY_VERIFIED`.

Institutional products need institution ID, source product ID, insurance line, name, summary, source URL/check date, digital action (`INFORMATION`, `QUOTE`, `PURCHASE`), approval/effective dates and source hash. A public product page is not proof of OpesInsure tariff integration.

## Seeder/import architecture

Create these in Laravel, not Expo:

1. `CameroonInstitutionalDirectorySeeder`
2. `CameroonCarrierProductSeeder`
3. `DemoTenantSeeder`
4. `DemoIdentityAndMembershipSeeder`
5. `DemoInsuranceCatalogueSeeder`
6. `DemoTransactionalScenarioSeeder`

All must be idempotent. Use stable source keys such as `asac:carrier:activa` and `minfi:broker:<normalized-name>`, never mutable display names alone. Normalize values, retain the exact source name, calculate SHA-256 hashes, record import batches, audit status changes, respect field-ownership rules and archive missing source records for review rather than deleting them.

Demo seeders must refuse to run when `APP_ENV=production`.

## Moving the bundled directory to Laravel

Convert the reviewed TypeScript arrays into Laravel-owned versioned JSON fixtures:

- `database/data/cameroon/insurers.v1.json`
- `database/data/cameroon/brokers.minfi-2022.v1.json`
- `database/data/cameroon/public-products.v1.json`

Preserve every source field and verify record counts. Store fixture versions and hashes. Do not scrape websites while seeding; use a separate scheduled review/import workflow.

Expose:

- `GET /api/v1/public/institutions/carriers`
- `GET /api/v1/public/institutions/carriers/{slug}`
- `GET /api/v1/public/institutions/brokers`
- `GET /api/v1/public/institutions/brokers/{slug}`

Support search, city, branch, verification filters, cursor pagination, ETags and dataset-version metadata. Responses must include source dates and verification labels. Replace Expo static reads with API hooks; retain the bundle only as a labelled offline snapshot.

## Logo governance

Do not republish logos merely because they are online. Store only institution-supplied or legally approved assets. Retain owner, licence basis, checksum, MIME type, dimensions, approved placements/date and revocation status. Use the neutral initials tile when approval is absent.

## Demo accounts

| Persona | Tenant | Suggested email | Workspace |
|---|---|---|---|
| Retail customer | OpesInsure Demo B2C | `customer@demo.opesinsure.example.invalid` | Customer |
| Freelance agent | OpesInsure Demo Agents | `agent@demo.opesinsure.example.invalid` | Freelance Agent |
| Broker administrator | Horizon Demo Brokerage | `broker.admin@demo.opesinsure.example.invalid` | Broker Admin |
| Broker staff | Horizon Demo Brokerage | `broker.staff@demo.opesinsure.example.invalid` | Broker Staff |
| Carrier administrator | Demo Assurance Cameroun | `carrier.admin@demo.opesinsure.example.invalid` | Carrier Admin |
| Underwriter | Demo Assurance Cameroun | `underwriter@demo.opesinsure.example.invalid` | Carrier Underwriter |
| Claims officer | Demo Assurance Cameroun | `claims@demo.opesinsure.example.invalid` | Carrier Claims |
| Platform administrator | OpesInsure Platform | `platform.admin@demo.opesinsure.example.invalid` | System Admin |
| Finance operator | OpesInsure Platform | `finance@demo.opesinsure.example.invalid` | Finance Operations |
| Compliance officer | OpesInsure Platform | `compliance@demo.opesinsure.example.invalid` | Compliance Operations |
| Support operator | OpesInsure Platform | `support@demo.opesinsure.example.invalid` | Support Operations |

Use fictional reserved contacts and capture-only communication. Never seed plaintext tokens. Never add a production OTP bypass. A fixed OTP may exist only in a fake provider restricted to local/testing/isolated-demo environments and unable to boot in production. Demo users must still pass normal memberships and policies.

## Deterministic demo scenarios

Use stable scenario keys and a configurable `DEMO_CLOCK_DATE`.

- Customer: vehicle; expired/active/renewal-due policies; draft quote; successful and failed payments; issued policy; open and settled claims; notification/device records.
- Agent: origin-linked clients; assisted quotes; pending/available/reversed/paid commissions; withdrawal and renewal tasks.
- Broker: two offices; separated admin/staff permissions; private ledger; fleet/individual clients; quotes, policies, receivables, claims, marketplace publication, bordereau and compliance examples.
- Carrier: agreement/authority limits; tariff versions; underwriting referrals; issuance queue; claim review; settlement/bordereau.
- Platform: reconciliation exceptions; webhook success/retry/dead-letter; fraud/compliance cases; privileged-access approval; regulatory reports; audit and health metrics.

Never use real national IDs, phone numbers, payment references or fictional organizations with real licence numbers.

## Demo adapters

Use isolated fake adapters: configurable payment transitions, capture-only SMS/email, deterministic carrier quote/referral/issuance, deterministic courier events and a signed demo webhook receiver. Mark payloads `demo=true`; never reuse production credentials, wallets, merchants or webhooks.

## Connection order

1. Set `EXPO_PUBLIC_API_BASE_URL=https://host/api/v1`.
2. Implement the OTP/session contracts in `BATCH_1_CLAUDE_HANDOFF.md`.
3. Return server-issued workspaces, tenants, roles and permissions.
4. Import institutions and connect public directory endpoints.
5. Connect quote → rate → offer → proposal → payment → issuance.
6. Connect policies, certificates, renewals and service requests.
7. Connect claims, multipart evidence, declaration, timeline and appeal.
8. Connect profile, devices, notifications and push tokens.
9. Connect permission-filtered partner dashboard read models.
10. Run contract, authorization, tenant-isolation and end-to-end scenario tests.

## Required acceptance tests

- Fixture counts/hashes match reviewed source files and reseeding creates no duplicates.
- Historical broker records remain dated and unverified.
- Cross-tenant records are inaccessible.
- Every persona sees only assigned workspaces/permissions.
- Origin attribution survives direct renewal.
- Payment success without issuance never displays coverage.
- Interrupted and failed payments recover correctly.
- Evidence rejects unsafe MIME types, oversize files and cross-tenant IDs.
- Certificate links expire and are policy-owner scoped.
- Notification deep links are allowlisted.
- Demo adapters/fixed OTP cannot boot in production.
- Production seeding never creates demo identities or transactions.

Recommended command intent (adapt names to the existing Laravel application):

```bash
php artisan opesinsure:import-institutions --source=reviewed-fixtures --dry-run
php artisan opesinsure:import-institutions --source=reviewed-fixtures --approve
php artisan db:seed --class=DemoEnvironmentSeeder
php artisan test --testsuite=Feature
```

Integration is done only when Expo uses live Laravel data, institutional data remains provenance-labelled, demo personas use normal authorization, demo scenarios are deterministic, security tests pass and production cannot seed demo identities.
