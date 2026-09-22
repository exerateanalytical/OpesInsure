# Wave 1 Customer and Partner Security Matrix

| Risk | Enforced control | Evidence |
|---|---|---|
| Cross-tenant customer disclosure | Customer queries require resolved tenant; party, consent, partner and attribution access checks relationship ownership | API controllers and policies |
| Duplicate/fragmented identities | Globally normalized unique phone/email; keyed identifier hash | `PartyService` and database constraints |
| Identity document exposure | Encrypted value, separate deterministic hash and masked display; sensitive fields hidden from serialization | `party_identifiers`, `PartyIdentifier` |
| Consent fabrication | Required affirmative action, notice version, channel, evidence hash and append-only event | `ConsentService` |
| Silent consent replacement | Prior active consent expires with an event when a newer notice is granted | `ConsentService::grant` |
| Unlicensed distribution | Partners start pending; only verified, unexpired licence activates them | `LicensingService` |
| Self-approved/changed licensing evidence | Decision actor, timestamp and notes retained; pending-only decision | `partner_licences`, status history |
| Client poaching | PostgreSQL partial unique index guarantees one active origin per canonical party | migration `000010` |
| Retry-created lock conflicts | Exact retries are idempotent; different origin fails closed | `AttributionService::lock` |
| Unauthorized ownership change | Reassignment only through an open dispute, active licensed target and append-only event | `AttributionService::resolve` |
| Undetected side effects | Audit chain and transactional outbox emitted with domain mutations | application services |

Production acceptance still requires migration execution, authorization integration tests, encryption-key rotation testing, vulnerability scanning and browser verification.
