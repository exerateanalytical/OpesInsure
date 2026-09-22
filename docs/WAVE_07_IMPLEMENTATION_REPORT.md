# Wave 7 implementation report — Claims

Wave 7 replaces the former basic status endpoint with a governed claims lifecycle covering FNOL, assignment, evidence custody, reserves, carrier exchanges, decisions, disputes, claim payments, recovery/subrogation, closure and reopening.

## Delivered

- Tenant-safe and idempotent FNOL with policy, claimant and coverage validation.
- Assignment history and optimistic version increments under pessimistic database locks.
- Malware-clean, SHA-256-bound evidence and append-only custody events.
- Maker-checker reserve requests and decisions.
- Effective delegated claim authority checks without invented statutory values.
- Hashed carrier exchange payloads with retry scheduling and acknowledgement.
- Disputes and recovery/subrogation registers.
- Decision-linked payments with amount caps, approval separation, retry, paid and reversal paths.
- Filament work queues for claims, reserves, decisions and payments.
- English/French messages, OpenAPI contract, security matrix and structural tests.

## Integration

Include `routes/wave7.php` inside the existing authenticated, tenant-resolved `/api/v1` route group. Grant only the named `claims.*` permissions. Claims-specific staff also require panel admission in the shared `User::canAccessPanel()` role list.

## Runtime acceptance

Migration, Pest and Filament browser acceptance must be run in a PHP 8.3/PostgreSQL environment. The current build workspace does not provide PHP, Composer or Docker.
