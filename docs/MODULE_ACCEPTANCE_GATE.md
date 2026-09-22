# Module Acceptance Gate

A module is `COMPLETE` only when every required box passes.

## Domain and persistence

- Domain invariants and guarded state transitions are implemented.
- Migrations run forward and backward on a clean PostgreSQL database.
- Foreign keys, uniqueness, check constraints, indexes and tenant scope are verified.
- Money uses integer minor units and immutable version references.
- Seeders/factories generate valid non-production data.

## Application and API

- Commands and queries are separated; controllers contain transport logic only.
- All use cases expose documented API contracts and stable error codes.
- Authorization is object- and action-specific.
- Idempotency, optimistic locking, audit and outbox events are applied where required.
- Every external dependency has a port, adapter, timeout, retry and failure path.

## Web interface

- List, detail, create, edit and workflow pages are implemented as applicable.
- Loading, empty, validation, permission, conflict, offline/network and server-error states exist.
- Forms preserve user input after validation failures.
- Desktop, tablet and mobile-web layouts are visually verified.
- English and French are complete; no hard-coded production strings.
- WCAG 2.2 AA keyboard, focus, contrast and semantic requirements pass.
- Icons follow the locked Lucide mapping; no generic or duplicate metaphors.

## Security and operations

- OWASP ASVS/API controls relevant to the module are tested.
- Secrets and PII never appear in URLs, client responses, logs or audit metadata.
- Maker-checker and separation-of-duty rules are enforced where required.
- Metrics, logs, traces and operational alerts exist.
- Retention, legal hold and data-access obligations are implemented.

## Tests and evidence

- Unit tests cover domain invariants.
- Feature tests cover APIs, web actions, permissions and tenant isolation.
- Browser tests cover critical workflows and responsive states.
- Negative-path and concurrency tests exist.
- OpenAPI and UI screen registers match implemented behavior.
- CI is green and a completion report links to test evidence.

