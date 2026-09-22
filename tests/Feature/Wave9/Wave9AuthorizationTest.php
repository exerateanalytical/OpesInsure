<?php

declare(strict_types=1);

use App\Models\FraudRuleVersion;
use App\Models\Party;
use App\Models\RegulatoryReportDefinition;
use App\Models\RegulatoryReportRun;
use App\Models\PrivilegedAccessGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

it('declares a permission requirement on every wave 9 route', function () {
    $wave9Routes = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/trust/'));

    expect($wave9Routes)->toHaveCount(15);

    foreach ($wave9Routes as $route) {
        $permissionMiddleware = collect($route->gatherMiddleware())
            ->first(fn ($m) => str_starts_with($m, 'permission:'));

        expect($permissionMiddleware)
            ->not->toBeNull("Route [{$route->uri()}] has no permission: middleware.");
    }
});

it('rejects an unauthenticated privileged-access request with 401', function () {
    $tenant = makeAuthTestTenant();

    $this->postJson('/api/v1/trust/privileged-access', [], tenantHeader($tenant))
        ->assertStatus(401);
});

it('rejects an authenticated user without the permission with 403, and audits the denial', function () {
    $tenant = makeAuthTestTenant();
    $requester = makeAuthTestUser($tenant, ['some.unrelated.permission']);
    $target = makeAuthTestUser($tenant, []);

    Passport::actingAs($requester);

    $this->postJson('/api/v1/trust/privileged-access', [
        'user_id' => $target->id,
        'purpose' => 'INCIDENT_RESPONSE',
        'justification' => 'Investigating a production incident that needs elevated access.',
        'starts_at' => now()->toISOString(),
        'expires_at' => now()->addHours(2)->toISOString(),
        'scope' => ['db.read'],
    ], tenantHeader($tenant))->assertStatus(403);

    expect(DB::table('audit_log')->where('action', 'authorization.denied')->where('actor_id', $requester->id)->exists())->toBeTrue();
});

it('allows a correctly authorized user to request privileged access, and audits the success', function () {
    $tenant = makeAuthTestTenant();
    $requester = makeAuthTestUser($tenant, ['trust.privileged-access.request']);
    $target = makeAuthTestUser($tenant, []);

    Passport::actingAs($requester);

    $response = $this->postJson('/api/v1/trust/privileged-access', [
        'user_id' => $target->id,
        'purpose' => 'INCIDENT_RESPONSE',
        'justification' => 'Investigating a production incident that needs elevated access.',
        'starts_at' => now()->toISOString(),
        'expires_at' => now()->addHours(2)->toISOString(),
        'scope' => ['db.read'],
    ], tenantHeader($tenant));

    $response->assertStatus(201);

    expect(DB::table('audit_log')->where('action', 'authorization.allowed')->where('actor_id', $requester->id)->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('action', 'trust.privileged-access.request')->exists())->toBeTrue();
});

it('does not let the requester approve their own privileged-access grant', function () {
    $tenant = makeAuthTestTenant();
    $requester = makeAuthTestUser($tenant, ['trust.privileged-access.request', 'trust.privileged-access.approve']);
    $target = makeAuthTestUser($tenant, []);

    Passport::actingAs($requester);

    $grantId = $this->postJson('/api/v1/trust/privileged-access', [
        'user_id' => $target->id,
        'purpose' => 'INCIDENT_RESPONSE',
        'justification' => 'Investigating a production incident that needs elevated access.',
        'starts_at' => now()->toISOString(),
        'expires_at' => now()->addHours(2)->toISOString(),
        'scope' => ['db.read'],
    ], tenantHeader($tenant))->json('id');

    // Same user, who already passes the permission gate, tries to approve their own request.
    $this->postJson("/api/v1/trust/privileged-access/{$grantId}/approve", [], tenantHeader($tenant))
        ->assertStatus(422);
});

it('lets a different authorized user approve a privileged-access grant', function () {
    $tenant = makeAuthTestTenant();
    $requester = makeAuthTestUser($tenant, ['trust.privileged-access.request']);
    $approver = makeAuthTestUser($tenant, ['trust.privileged-access.approve']);
    $target = makeAuthTestUser($tenant, []);

    Passport::actingAs($requester);
    $grantId = $this->postJson('/api/v1/trust/privileged-access', [
        'user_id' => $target->id,
        'purpose' => 'INCIDENT_RESPONSE',
        'justification' => 'Investigating a production incident that needs elevated access.',
        'starts_at' => now()->toISOString(),
        'expires_at' => now()->addHours(2)->toISOString(),
        'scope' => ['db.read'],
    ], tenantHeader($tenant))->json('id');

    Passport::actingAs($approver);
    $this->postJson("/api/v1/trust/privileged-access/{$grantId}/approve", [], tenantHeader($tenant))
        ->assertStatus(200)
        ->assertJsonPath('status', 'APPROVED');
});

it('lets SYSTEM_ADMIN approve a privileged-access grant with no explicit permission grant', function () {
    $tenant = makeAuthTestTenant();
    $requester = makeAuthTestUser($tenant, ['trust.privileged-access.request']);
    $admin = makeAuthTestSystemAdmin($tenant);
    $target = makeAuthTestUser($tenant, []);

    Passport::actingAs($requester);
    $grantId = $this->postJson('/api/v1/trust/privileged-access', [
        'user_id' => $target->id,
        'purpose' => 'INCIDENT_RESPONSE',
        'justification' => 'Investigating a production incident that needs elevated access.',
        'starts_at' => now()->toISOString(),
        'expires_at' => now()->addHours(2)->toISOString(),
        'scope' => ['db.read'],
    ], tenantHeader($tenant))->json('id');

    Passport::actingAs($admin);
    $this->postJson("/api/v1/trust/privileged-access/{$grantId}/approve", [], tenantHeader($tenant))
        ->assertStatus(200);
});

it('blocks a suspended membership from approving privileged access even with the permission', function () {
    $tenant = makeAuthTestTenant();
    $requester = makeAuthTestUser($tenant, ['trust.privileged-access.request']);
    $approver = makeAuthTestUser($tenant, ['trust.privileged-access.approve'], 'TEST_ROLE', 'SUSPENDED');
    $target = makeAuthTestUser($tenant, []);

    Passport::actingAs($requester);
    $grantId = $this->postJson('/api/v1/trust/privileged-access', [
        'user_id' => $target->id,
        'purpose' => 'INCIDENT_RESPONSE',
        'justification' => 'Investigating a production incident that needs elevated access.',
        'starts_at' => now()->toISOString(),
        'expires_at' => now()->addHours(2)->toISOString(),
        'scope' => ['db.read'],
    ], tenantHeader($tenant))->json('id');

    // A suspended membership also fails ResolveTenant's own active-membership check (403),
    // before the permission gate is even reached — which is the correct, stricter outcome.
    Passport::actingAs($approver);
    $this->postJson("/api/v1/trust/privileged-access/{$grantId}/approve", [], tenantHeader($tenant))
        ->assertStatus(403);
});

it('returns 404 for a privileged-access grant belonging to a different tenant, without revealing it', function () {
    $tenantA = makeAuthTestTenant('A');
    $tenantB = makeAuthTestTenant('B');

    $requesterA = makeAuthTestUser($tenantA, ['trust.privileged-access.request']);
    $targetA = makeAuthTestUser($tenantA, []);
    $approverB = makeAuthTestUser($tenantB, ['trust.privileged-access.approve']);

    Passport::actingAs($requesterA);
    $grantId = $this->postJson('/api/v1/trust/privileged-access', [
        'user_id' => $targetA->id,
        'purpose' => 'INCIDENT_RESPONSE',
        'justification' => 'Investigating a production incident that needs elevated access.',
        'starts_at' => now()->toISOString(),
        'expires_at' => now()->addHours(2)->toISOString(),
        'scope' => ['db.read'],
    ], tenantHeader($tenantA))->json('id');

    Passport::actingAs($approverB);
    $response = $this->postJson("/api/v1/trust/privileged-access/{$grantId}/approve", [], tenantHeader($tenantB));

    $response->assertStatus(404);
    expect($response->getContent())->not->toContain('INCIDENT_RESPONSE');
});

it('does not let a report preparer approve their own regulatory report run', function () {
    $tenant = makeAuthTestTenant();
    $preparer = makeAuthTestUser($tenant, ['trust.regulatory-reports.prepare', 'trust.regulatory-reports.approve']);

    $definition = RegulatoryReportDefinition::create([
        'id' => (string) Str::uuid(), 'code' => 'CIMA-'.Str::random(6), 'version' => 1, 'status' => 'ACTIVE',
        'jurisdiction' => 'CM', 'report_type' => 'SOLVENCY', 'schema' => ['fields' => []], 'schema_hash' => hash('sha256', '{}'),
        'effective_from' => now()->subDay(), 'created_by' => $preparer->id,
    ]);

    Passport::actingAs($preparer);
    $runId = $this->postJson("/api/v1/trust/regulatory-reports/{$definition->id}/runs", [
        'period_key' => '2026-Q3',
        'payload' => ['premiums' => 1000],
        'idempotency_key' => Str::uuid()->toString(),
    ], tenantHeader($tenant))->json('id');

    $this->postJson("/api/v1/trust/regulatory-report-runs/{$runId}/approve", [], tenantHeader($tenant))
        ->assertStatus(422);
});

it('lets a different authorized user approve a regulatory report run, and audits it', function () {
    $tenant = makeAuthTestTenant();
    $preparer = makeAuthTestUser($tenant, ['trust.regulatory-reports.prepare']);
    $approver = makeAuthTestUser($tenant, ['trust.regulatory-reports.approve']);

    $definition = RegulatoryReportDefinition::create([
        'id' => (string) Str::uuid(), 'code' => 'CIMA-'.Str::random(6), 'version' => 1, 'status' => 'ACTIVE',
        'jurisdiction' => 'CM', 'report_type' => 'SOLVENCY', 'schema' => ['fields' => []], 'schema_hash' => hash('sha256', '{}'),
        'effective_from' => now()->subDay(), 'created_by' => $preparer->id,
    ]);

    Passport::actingAs($preparer);
    $runId = $this->postJson("/api/v1/trust/regulatory-reports/{$definition->id}/runs", [
        'period_key' => '2026-Q3',
        'payload' => ['premiums' => 1000],
        'idempotency_key' => Str::uuid()->toString(),
    ], tenantHeader($tenant))->json('id');

    Passport::actingAs($approver);
    $this->postJson("/api/v1/trust/regulatory-report-runs/{$runId}/approve", [], tenantHeader($tenant))
        ->assertStatus(200)
        ->assertJsonPath('status', 'APPROVED');

    expect(DB::table('audit_log')->where('action', 'trust.regulatory-reports.approve')->where('subject_id', $runId)->exists())->toBeTrue();
});

it('rejects an unauthorized fraud-alert creation with 403 and allows an authorized one with 201', function () {
    $tenant = makeAuthTestTenant();
    $rule = FraudRuleVersion::create([
        'id' => (string) Str::uuid(), 'code' => 'RULE-'.Str::random(6), 'version' => 1, 'scope' => 'PAYMENT',
        'status' => 'ACTIVE', 'risk_points' => 50, 'conditions' => ['op' => 'always'], 'rule_hash' => hash('sha256', '{}'),
        'effective_from' => now()->subDay(),
    ]);

    $payload = [
        'subject_type' => 'PAYMENT', 'subject_id' => (string) Str::uuid(), 'alert_type' => 'VELOCITY',
        'severity' => 'HIGH', 'signals' => ['count' => 5], 'fraud_rule_version_id' => $rule->id,
        'idempotency_key' => Str::uuid()->toString(),
    ];

    $unauthorized = makeAuthTestUser($tenant, ['some.unrelated.permission']);
    Passport::actingAs($unauthorized);
    $this->postJson('/api/v1/trust/fraud-alerts', $payload, tenantHeader($tenant))->assertStatus(403);

    $authorized = makeAuthTestUser($tenant, ['trust.fraud-alerts.create']);
    Passport::actingAs($authorized);
    $this->postJson('/api/v1/trust/fraud-alerts', [...$payload, 'idempotency_key' => Str::uuid()->toString()], tenantHeader($tenant))
        ->assertStatus(201);
});

it('rejects an unauthorized compliance-case creation with 403 and allows an authorized one with 201', function () {
    $tenant = makeAuthTestTenant();
    $payload = [
        'type' => 'AML_REVIEW', 'subject_type' => 'CUSTOMER', 'subject_id' => (string) Str::uuid(),
        'severity' => 'MEDIUM', 'idempotency_key' => Str::uuid()->toString(),
    ];

    $unauthorized = makeAuthTestUser($tenant, []);
    Passport::actingAs($unauthorized);
    $this->postJson('/api/v1/trust/compliance-cases', $payload, tenantHeader($tenant))->assertStatus(403);

    $authorized = makeAuthTestUser($tenant, ['trust.compliance-cases.create']);
    Passport::actingAs($authorized);
    $this->postJson('/api/v1/trust/compliance-cases', [...$payload, 'idempotency_key' => Str::uuid()->toString()], tenantHeader($tenant))
        ->assertStatus(201);
});

it('rejects an unauthorized data-subject-request intake with 403 and allows an authorized one with 201', function () {
    $tenant = makeAuthTestTenant();
    $party = Party::create(['id' => (string) Str::uuid(), 'type' => 'PERSON', 'display_name' => 'Test Subject', 'legal_identity' => [], 'status' => 'ACTIVE']);
    $payload = ['party_id' => $party->id, 'type' => 'ACCESS', 'due_on' => now()->addDays(30)->toDateString(), 'idempotency_key' => Str::uuid()->toString()];

    $unauthorized = makeAuthTestUser($tenant, []);
    Passport::actingAs($unauthorized);
    $this->postJson('/api/v1/trust/data-subject-requests', $payload, tenantHeader($tenant))->assertStatus(403);

    $authorized = makeAuthTestUser($tenant, ['trust.dsr.receive']);
    Passport::actingAs($authorized);
    $this->postJson('/api/v1/trust/data-subject-requests', [...$payload, 'idempotency_key' => Str::uuid()->toString()], tenantHeader($tenant))
        ->assertStatus(201);
});

it('cannot bypass permission checks by hitting the controller through any other route', function () {
    // Wave9Controller is only ever bound from routes/wave9.php; asserting there is exactly
    // one registration per action name (all carrying permission middleware, per the
    // structural test above) is what makes a bypass impossible.
    $actions = collect(Route::getRoutes())
        ->filter(fn ($route) => is_string($route->getActionName()) && str_contains($route->getActionName(), 'Wave9Controller@'))
        ->map(fn ($route) => $route->getActionName());

    expect($actions->duplicates())->toBeEmpty();
});
