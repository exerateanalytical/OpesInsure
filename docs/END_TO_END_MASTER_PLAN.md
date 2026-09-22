# OpesInsure Web Platform — End-to-End Master Plan

## Delivery rule

The earlier batches are architectural foundations and provisional backend code. They are not accepted modules. From this milestone onward, a module is complete only after satisfying `MODULE_ACCEPTANCE_GATE.md` and passing automated tests in the supported runtime.

## Web applications

1. **Platform Administration** — OpesInsure staff manage partners, carriers, products, tariffs, payments, settlements, compliance, security and configuration.
2. **Broker ERP** — licensed broker organizations manage offices, staff, clients, quotations, policies, renewals, claims, bordereaux, commissions and reports.
3. **Agent Web Workspace** — freelance agents manage assisted clients, quotes, payment requests, policies, renewals and commissions from a responsive browser.
4. **Customer Web Marketplace** — consumers compare eligible products, request quotes, pay, manage policies, submit claims and track physical fulfilment.
5. **Carrier Portal** — carriers manage products/tariffs, referrals, issuance, servicing, claims exchange, bordereaux, settlements and delegated authority.
6. **Public Verification Portal** — privacy-minimised certificate/sticker and broker/carrier verification.

Native mobile applications are excluded from the current scope.

## Delivery waves

| Wave | End-to-end modules |
|---|---|
| 0 | Runtime, CI, design system, localization, tenancy, identity, RBAC, audit, observability |
| 1 | Parties/customers, consent, agents/brokers/carriers, licensing, attribution/disputes |
| 2 | Catalogue, coverages, tariff governance, risk assets, rating, quote comparison |
| 3 | Proposal, disclosures, documents, underwriting/referrals, payment requests |
| 4 | Provider payment adapters, webhooks, reconciliation, double-entry posting, refunds/chargebacks |
| 5 | Issuance, certificates/stickers, policy servicing, renewals, cancellations, reinstatement |
| 6 | Commissions, partner statements, payouts, carrier settlements, bordereaux |
| 7 | Claims/FNOL, evidence, assignment, carrier exchange, dispute and closure |
| 8 | Logistics, notifications, support, complaints and customer communications |
| 9 | Fraud review, compliance, data rights, regulatory reporting and privileged access |
| 10 | Broker ERP completion, Carrier Portal, Agent Workspace, Customer Marketplace, Admin completion |
| 11 | Security verification, performance, accessibility, disaster recovery, UAT and release certification |

## Required flow coverage

Every module must cover happy paths, validation failures, permission failures, duplicate/idempotent requests, stale-version conflicts, downstream timeouts, retry/reversal paths, cancellation, audit evidence and bilingual UI states.

## Release environments

- Local: Docker Compose with PostgreSQL, Redis, MinIO and Mailpit.
- CI: clean dependency install, migrations, seeders, Pint, Larastan, unit/feature/browser tests, OpenAPI validation and vulnerability scanning.
- Staging: production-like infrastructure with provider sandboxes and synthetic data.
- Production: managed secrets/KMS, encrypted backups, monitoring, WAF/rate controls and audited deployments.

