# Architecture

## Architecture style

Start as a modular monolith to preserve transactional integrity while keeping every bounded domain extractable. HTTP controllers translate transport data into commands/queries. Application handlers orchestrate use cases. Domain models own invariants. Infrastructure adapters own databases and third-party APIs.

Dependencies point inward:

`Interfaces → Application → Domain ← Infrastructure`

No domain imports another domain's persistence model. Cross-domain collaboration uses application ports, stable identifiers and domain/integration events.

## CQRS

- Commands mutate one aggregate per transaction and return identifiers/status, not read projections.
- Queries use dedicated read models and cannot mutate domain state.
- Command deduplication uses `(actor_id, idempotency_key, command_type)`.
- Optimistic concurrency uses aggregate versions.
- Long workflows are process managers/sagas with compensating commands.

## Event delivery

Domain events are persisted in `outbox_messages` in the same transaction as state changes. Workers publish them after commit. Consumers store message IDs in `inbox_messages` before applying effects. Delivery is at-least-once; consumers must be idempotent. Event schemas are versioned and contain no unnecessary personal data.

## Tenancy and isolation

- `SYSTEM` represents the platform context; every business tenant is an organization UUID.
- Tenant is resolved from authenticated membership and, for service clients, the OAuth client grant—not trusted from an arbitrary header alone.
- All tenant-owned tables carry `tenant_id`; repository queries require a tenant context.
- PostgreSQL row-level security is defense-in-depth in production.
- Platform operators use explicitly audited support elevation; no silent cross-tenant access.
- Customers can have relationships with several organizations, while attribution records determine commercial rights. Identity and “ownership” are not conflated.

## Security baseline

- OAuth 2.0 Authorization Code + PKCE for mobile/web; client credentials for partner APIs.
- Short-lived access tokens, rotating refresh tokens, MFA for privileged roles.
- Argon2id credentials, encrypted sensitive fields, KMS-managed production keys.
- Request validation, object authorization, rate limits, webhook HMAC/mTLS where supported.
- PII-safe logs, immutable audit entries, malware scanning, private object storage and signed URLs.
- Maker-checker approval for settlements, manual ledger adjustments, refunds, commission changes and sensitive administration.

## Financial correctness

All money is integer minor units (`BIGINT`) plus ISO currency. Each journal balances debits and credits. Posted entries are immutable; correction uses reversal journals. Provider collection, internal allocation and bank movement are separate facts. Escrow/fiduciary classification is enabled only after legal and banking confirmation.

Illustrative accounts: provider receivable, cash-at-bank, premium payable to carrier, platform fee revenue, processing fee revenue, commission receivable, agent/broker commission payable, taxes/levies payable, refund payable, settlement clearing and suspense.

## Reliability

- Idempotency on quote submission, payment creation, webhook processing, issuance and payouts.
- Retries use exponential backoff with jitter and dead-letter queues.
- Circuit breakers and timeout budgets protect provider adapters.
- Point-in-time database recovery, encrypted backups and tested restoration.
- OpenTelemetry traces, structured logs, metrics, SLOs and alert runbooks.

## Deployment topology

API, worker and scheduler containers run independently behind a load balancer. PostgreSQL is the source of truth; Redis backs cache/queues/locks; S3-compatible storage holds encrypted documents. Read replicas and domain extraction are introduced only when measured load warrants them.

