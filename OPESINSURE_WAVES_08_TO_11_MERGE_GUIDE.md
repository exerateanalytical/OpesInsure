# OpesInsure Waves 8–11 Integration and Merge Guide

**Target:** Existing OpesInsure cumulative checkpoint v14  
**Patch:** `OpesInsure_Waves_08_to_11_Combined_Patch_v1.0.0.zip`  
**Stack:** Laravel 12, PHP 8.3, PostgreSQL, Redis, Filament 4  
**Scope:** Web platform only; no mobile application code

## 1. What this patch adds

| Wave | Capability groups |
|---|---|
| 8 | Logistics and fulfilment, courier custody, delivery OTP/POD, notification delivery, communication history, support, complaints and SLA controls |
| 9 | Fraud review, compliance cases, data-subject requests, privileged access and regulatory-report submission controls |
| 10 | Platform Admin, Broker ERP, Carrier Portal, Agent Workspace and Customer Marketplace web-experience foundation |
| 11 | Security, performance, accessibility, disaster recovery, UAT, OpenAPI/data-migration gates and maker-checker release certification |

This is an additive patch. It deliberately does not contain or replace the full cumulative application, `routes/api.php`, `bootstrap/app.php`, environment files or deployment secrets.

## 2. Pre-merge safety checks

1. Use a new Git branch from the exact v14 codebase to which Waves 8–11 will be applied.
2. Commit or safely store all local modifications.
3. Back up the PostgreSQL database and uploaded document storage.
4. Confirm PHP 8.3+, PostgreSQL and Redis are available.
5. Run the existing test suite before copying the patch. Record failures so they are not confused with merge regressions.
6. Never apply directly to production. Merge and validate in development, then staging.

Suggested branch:

```bash
git switch -c feature/opesinsure-waves-08-11
```

## 3. Copy the patch

Extract the combined ZIP into the OpesInsure project root while preserving its directory structure. Review overwrites before accepting them. Files such as Wave 8 logistics/support models may intentionally complete provisional files already present in v14.

After copying, confirm these migration files exist in order:

```text
2026_09_21_000017_complete_wave_eight_operations.php
2026_09_21_000018_complete_wave_nine_trust_compliance.php
2026_09_21_000019_complete_wave_ten_web_experiences.php
2026_09_21_000020_complete_wave_eleven_release_assurance.php
```

Do not rename or reorder migrations 17–20.

## 4. Register the route fragments

Open `routes/api.php`. Locate the existing protected group:

```php
Route::middleware(['auth:api', 'tenant'])->group(function (): void {
```

Inside that group, next to the existing `require __DIR__.'/wave7.php';`, add:

```php
require __DIR__.'/wave8.php';
require __DIR__.'/wave9.php';
require __DIR__.'/wave10.php';
require __DIR__.'/wave11.php';
```

The correct result is structurally equivalent to:

```php
Route::middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    // Existing protected API routes...
    require __DIR__.'/wave7.php';
    require __DIR__.'/wave8.php';
    require __DIR__.'/wave9.php';
    require __DIR__.'/wave10.php';
    require __DIR__.'/wave11.php';
});
```

Do not place these includes outside the `/v1` prefix or outside authentication and tenant resolution. Doing so would either change the documented URLs or weaken tenant isolation.

## 5. Register the Wave 11 JSON middleware safely

In `bootstrap/app.php`, import:

```php
use App\Interfaces\Http\Middleware\EnforceJsonApi;
```

Extend the existing middleware aliases:

```php
$middleware->alias([
    'tenant' => ResolveTenant::class,
    'permission' => RequirePermission::class,
    'json.api' => EnforceJsonApi::class,
]);
```

Then add `json.api` only to the protected group shown in section 4. Do **not** prepend it to the entire API stack until every external provider webhook has been confirmed to send JSON. Payment gateways may use form-encoded callbacks; those public webhook routes must remain compatible with the verified provider contract.

The middleware enforces JSON mutation requests and adds `no-store`, `nosniff`, restrictive referrer and browser-permission headers.

## 6. Authorization and permissions

Laravel policy discovery will locate:

- `MarketplacePublicationPolicy`
- `ReleaseCandidatePolicy`
- the Wave 8 and Wave 9 policies included in the patch

Add the following abilities to the existing permission catalogue and assign them using least privilege:

```text
marketplace.publications.view
marketplace.publications.create
marketplace.publications.approve
releases.view
releases.create
releases.assess
releases.certify
```

Publication creators must not approve their own marketplace publication. Release creators must not certify their own release. Preserve these maker-checker separations when configuring roles.

Review Wave 8–9 controller routes and attach the project’s existing permission middleware where the deployment’s role catalogue requires more restrictive controls. Authentication and tenant scoping are mandatory for every fragment.

## 7. Dependencies, autoload and configuration

No new Composer dependency is introduced. Run:

```bash
composer install --no-interaction
composer dump-autoload
php artisan optimize:clear
```

Review `config/release-assurance.php`, then provide environment-specific values where the defaults are not acceptable:

```dotenv
RELEASE_API_P95_MS=500
RELEASE_ERROR_RATE=1.0
RELEASE_RTO_MINUTES=240
RELEASE_RPO_MINUTES=60
```

Do not store credentials, provider secrets, encryption keys or production personal data in configuration committed to Git.

## 8. Database migration

First inspect migration SQL in staging:

```bash
php artisan migrate --pretend
```

Then run:

```bash
php artisan migrate --force
```

Verify that migrations 17, 18, 19 and 20 are recorded in the migrations table. Confirm indexes and uniqueness constraints exist, particularly tenant identifiers, idempotency keys, marketplace publication versions and release-gate uniqueness.

If a migration fails, stop. Do not manually mark it complete. Correct the schema conflict, restore from the verified backup when necessary, and rerun in a clean staging environment.

## 9. Front-end asset integration

Wave 10 adds:

```text
public/css/opesinsure-portals.css
resources/views/wave10/portal.blade.php
```

The stylesheet is namespaced with `oi-` classes to reduce collisions with Filament and existing application styles. Verify the five portal contexts at desktop, tablet and mobile web widths. Check English and French labels, focus order, keyboard operation, contrast, overflow, empty states, validation states and long translated text.

The template is a shared experience shell, not a replacement for Filament’s internal resource pages. Preserve existing Filament theme registration.

## 10. Queues, scheduler and workers

Wave 8 notification delivery and Wave 9 regulatory submissions depend on reliable background processing in production. Confirm:

- Redis connectivity and isolated queue names;
- Horizon workers and supervisors restart after deployment;
- retry/backoff and dead-letter monitoring are active;
- failed jobs trigger operational alerts;
- scheduled reconciliation, SLA and reporting tasks use a single scheduler leader;
- notification and regulatory jobs are idempotent before replay.

After deployment:

```bash
php artisan queue:restart
php artisan horizon:terminate
```

Allow the process supervisor to restart Horizon cleanly.

## 11. Required validation

Run at minimum:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
php artisan route:list --path=api/v1
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Validate both OpenAPI files for each wave and run contract tests against the registered routes. Confirm no duplicate route names or conflicting URIs.

### Mandatory end-to-end scenarios

1. Create fulfilment order, assign courier, record custody, validate OTP/POD and process failed delivery.
2. Create notification, exercise retry, delivery receipt, cancellation and dead-letter handling.
3. Open support ticket/complaint, enforce SLA, escalate and close with audit evidence.
4. Trigger fraud alert, perform independent review and record decision.
5. Process a data-subject request with identity verification and auditable resolution.
6. Request, approve, expire and revoke privileged access.
7. Prepare, approve, submit, retry and acknowledge a regulatory report.
8. Open all five web portal contexts and verify tenant isolation.
9. Draft and independently approve a marketplace publication with stale-version rejection.
10. Save and retrieve a customer comparison without exposing another user’s record.
11. Create a release candidate, record all seven gates and prove certification is blocked by a failed gate, missing gate, self-approval or unresolved high/critical finding.

## 12. Cybersecurity verification

Before production certification, verify:

- tenant A cannot read or mutate tenant B data;
- every state-changing request is authenticated, authorized and audited;
- rate limits exist on abuse-prone endpoints;
- UUID validation and route-model ownership checks fail closed;
- secrets and tokens never appear in logs or API responses;
- uploads are malware-scanned and served through authorized, expiring access;
- privileged grants expire automatically and are revocable;
- dependency audit, SAST, secret scanning and penetration tests have no unresolved high/critical issue;
- database and object-storage backups are encrypted and a restoration exercise meets RTO/RPO;
- payment webhooks retain signature validation, replay protection and idempotency.

## 13. Deployment sequence

1. Deploy to staging and run migrations.
2. Start/restart workers.
3. Execute automated tests and the end-to-end scenarios above.
4. Complete accessibility, performance, security and recovery evidence.
5. Obtain business UAT from Admin, Broker, Carrier, Agent and Customer roles.
6. Create the production release candidate and attach all seven gate results.
7. Obtain independent certification.
8. Take a fresh production backup.
9. Deploy with canary or rolling traffic and run smoke tests.
10. Monitor authentication, tenancy, payments, ledger, queues, latency and error rate.

## 14. Rollback

Application rollback and database rollback are separate decisions. Prefer rolling the application back to the previous artifact while preserving data when new migrations are backward compatible. Run `php artisan migrate:rollback` only after confirming that removing the four waves’ tables will not destroy production records created after deployment.

Never run destructive rollback commands without a verified backup and an approved incident plan. Record the decision, operator, time, affected release and reconciliation outcome.

## 15. Acceptance decision

The merge is complete only when:

- migrations 17–20 succeed in a production-like PostgreSQL environment;
- routes are protected by authentication, tenant context and required permissions;
- all automated tests pass;
- EN/FR UI and API errors are verified;
- the five web experiences pass browser/UAT checks;
- all Wave 11 gates pass with evidence;
- no unresolved high or critical security finding exists;
- an independent approver certifies the release.

Until those conditions are met, label the merged build **staging candidate**, not production-ready.
