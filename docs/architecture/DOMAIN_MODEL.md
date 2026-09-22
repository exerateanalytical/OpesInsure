# Core Domain Model and Corrections

## Customer attribution

The proposed `customers.registered_by_type/registered_by_id` polymorphic column cannot enforce referential integrity and a single global phone uniqueness rule is unsafe. Use:

- `parties`: canonical person/organization identity.
- `party_contacts`: normalized and verified phone/email values.
- `tenant_customers`: a tenant's client record and private metadata.
- `customer_attributions`: append-only commercial attribution with origin type/id, effective dates, terms version and evidence.
- `attribution_disputes`: controlled review and resolution without rewriting history.

At renewal, an attribution policy evaluates the effective record, partner contract, channel rules, expiry, consent and dispute state. The result is snapshotted on the transaction so later rule changes cannot rewrite accounting history.

## Tariffs

A rating result references immutable versions: product, tariff, tax/levy, commission agreement, fee schedule and input facts. Each offer stores a machine-readable calculation breakdown. “CIMA formula” is never represented as one global formula; approved schedules differ by product, jurisdiction, risk and effective period.

## Policy issuance

Payment success does not alone activate a policy. It transitions to `PAID_PENDING_ISSUANCE`; carrier confirmation, policy/certificate identifiers and effective coverage must be recorded before `ACTIVE` unless the signed carrier integration contract explicitly authorizes delegated issuance.

## Commission

Commission moves through `ACCRUED → VESTED → PAYABLE → PAID`, and can be `HELD`, `CLAWED_BACK` or `DISPUTED`. A displayed wallet balance has pending, available and held components. Withdrawal requires identity/licence status, limits, risk checks and provider availability.

## Audit evidence

Business decisions capture actor, tenant, source channel, reason code, correlation/causation IDs, policy/rule version, timestamps, before/after hashes and supporting evidence references. Secrets and unnecessary PII are excluded.

