# Wave 2 Security Matrix

| Control | Enforcement |
|---|---|
| Tenant isolation | Risk assets and quotes are scoped to resolved tenant in API, policies and Filament queries. |
| Authorization | Catalogue, publishing, tariff approval and rating routes require explicit permissions. |
| Maker–checker | Product maker cannot publish; tariff maker cannot approve. |
| Integrity | Canonical tariff hash verified at approval; rating runs retain input/output snapshots. |
| Effective dating | One active product version and no overlapping approved tariff periods. |
| Idempotency | Unique quote/tariff offer index plus application-level lookup. |
| Concurrency | Risk assets require expected version and row locking. |
| Auditability | State changes write audit records and append-only status/risk events. |
| Event reliability | Material lifecycle changes write transactional outbox messages. |
| Data minimization | Quote offers snapshot only rating and coverage facts required for explanation. |
| Injection/mass assignment | Request allowlists, Eloquent fillable lists and bound query parameters. |
| Localization | Domain validation is available in English and French without leaking internals. |

Runtime penetration testing, dependency scanning, secrets scanning, database RLS verification and browser CSP verification remain release gates.
