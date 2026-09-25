<?php

declare(strict_types=1);

use App\Models\FraudRuleVersion;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

it('REQ-DUP-009: every duplicated trust/* route is a deprecated alias of the canonical compliance/* or risk-alerts action', function () {
    $routes = collect(Route::getRoutes()->getRoutes());
    $action = fn (string $method, string $uri) => $routes->first(fn ($r) => $r->uri() === 'api/v1/'.$uri && in_array($method, $r->methods(), true))?->getActionName();
    $pairs = [
        'trust/privileged-access' => 'compliance/privileged-access', 'trust/privileged-access/{x}/approve' => 'compliance/privileged-access/{x}/approve',
        'trust/privileged-access/{x}/revoke' => 'compliance/privileged-access/{x}/revoke', 'trust/data-subject-requests' => 'compliance/data-subject-requests',
        'trust/data-subject-requests/{x}/verify' => 'compliance/data-subject-requests/{x}/verify', 'trust/data-subject-requests/{x}/resolve' => 'compliance/data-subject-requests/{x}/resolve',
        'trust/fraud-alerts' => 'risk-alerts', 'trust/fraud-alerts/{alert}/decision' => 'risk-alerts/{alert}/decision',
        'trust/compliance-cases' => 'compliance/cases', 'trust/compliance-cases/{case}/transition' => 'compliance/cases/{case}/transition',
    ];
    foreach ($pairs as $alias => $canonical) {
        expect($action('POST', $alias))->not->toBeNull($alias)->toBe($action('POST', $canonical));
        $mw = $routes->first(fn ($r) => $r->uri() === 'api/v1/'.$alias)->gatherMiddleware();
        expect(collect($mw)->contains(fn ($m) => is_string($m) && str_contains($m, 'DeprecatedRouteAlias')))->toBeTrue($alias);
    }
    expect(collect($routes)->contains(fn ($r) => str_contains($r->getActionName(), 'Wave9Controller@access')))->toBeFalse();
});

it('REQ-DUP-009: the canonical DSR route is now permission-gated and uses the DSR service (tenant stamped, events written)', function () {
    $tenant = makeAuthTestTenant();
    $party = Party::create(['id' => (string) Str::uuid(), 'type' => 'PERSON', 'display_name' => 'Subject', 'legal_identity' => [], 'status' => 'ACTIVE']);

    Passport::actingAs(makeAuthTestUser($tenant, []));
    $this->postJson('/api/v1/compliance/data-subject-requests', ['party_id' => $party->id, 'type' => 'DELETION'], tenantHeader($tenant))->assertForbidden();

    Passport::actingAs(makeAuthTestUser($tenant, ['compliance.dsr.receive']));
    $id = $this->postJson('/api/v1/compliance/data-subject-requests', ['party_id' => $party->id, 'type' => 'DELETION'], tenantHeader($tenant))
        ->assertCreated()->assertJsonPath('data.status', 'RECEIVED')->json('data.id');
    $row = DB::table('data_subject_requests')->find($id);
    expect($row->tenant_id)->toBe($tenant->id)->and($row->type)->toBe('ERASURE');
    expect(DB::table('data_subject_request_events')->where('data_subject_request_id', $id)->exists())->toBeTrue();
});

it('REQ-DUP-009: legacy single-step privileged-access grant goes through PrivilegedAccessService and cannot target another tenant', function () {
    $tenant = makeAuthTestTenant();
    $other = makeAuthTestTenant();
    $approver = makeAuthTestUser($tenant, ['compliance.access.grant']);
    $target = makeAuthTestUser($tenant, []);
    Passport::actingAs($approver);
    $body = ['user_id' => $target->id, 'tenant_id' => $tenant->id, 'purpose' => 'INCIDENT', 'justification' => str_repeat('Investigating production incident. ', 3),
        'starts_at' => now()->toISOString(), 'expires_at' => now()->addHour()->toISOString()];

    $id = $this->postJson('/api/v1/compliance/privileged-access', $body, tenantHeader($tenant))->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->json('data.id'); // B7 / REQ-SEC-001: one-step grant retired → second-person approval
    expect(DB::table('privileged_access_events')->where('privileged_access_grant_id', $id)->where('event_type', 'REQUESTED')->exists())->toBeTrue();
    $this->postJson('/api/v1/compliance/privileged-access', [...$body, 'tenant_id' => $other->id], tenantHeader($tenant))->assertNotFound();
    $this->postJson('/api/v1/compliance/privileged-access', [...$body, 'user_id' => $approver->id], tenantHeader($tenant))->assertForbidden();
});

it('REQ-DUP-009: risk-alerts accepts both contracts through FraudReviewService and decisions are tenant-scoped', function () {
    $tenant = makeAuthTestTenant();
    $other = makeAuthTestTenant();
    $rule = FraudRuleVersion::create(['id' => (string) Str::uuid(), 'code' => 'R-'.Str::random(5), 'version' => 1, 'scope' => 'PAYMENT', 'status' => 'ACTIVE',
        'risk_points' => 40, 'conditions' => ['op' => 'always'], 'rule_hash' => hash('sha256', '{}'), 'effective_from' => now()->subDay()]);
    Passport::actingAs(makeAuthTestUser($tenant, ['fraud.alert.create', 'fraud.alert.decide']));
    $h = tenantHeader($tenant);

    $manual = $this->postJson('/api/v1/risk-alerts', ['subject_type' => 'PAYMENT', 'subject_id' => (string) Str::uuid(), 'alert_type' => 'VELOCITY', 'risk_score' => 85, 'signals' => ['n' => 9]], $h)
        ->assertCreated()->assertJsonPath('data.severity', 'CRITICAL')->json('data.id');
    $this->postJson('/api/v1/risk-alerts', ['subject_type' => 'PAYMENT', 'subject_id' => (string) Str::uuid(), 'alert_type' => 'VELOCITY', 'severity' => 'HIGH',
        'signals' => ['n' => 1], 'fraud_rule_version_id' => $rule->id, 'idempotency_key' => (string) Str::uuid()], $h)->assertCreated();

    $this->postJson("/api/v1/risk-alerts/{$manual}/decision", ['decision' => 'ESCALATE_COMPLIANCE', 'notes' => 'Needs a compliance look at this.'], $h)
        ->assertOk()->assertJsonPath('data.status', 'ESCALATED');
    $this->postJson("/api/v1/risk-alerts/{$manual}/decision", ['decision' => 'CLEARED', 'notes' => 'Second decision must fail.'], $h)->assertStatus(422);

    Passport::actingAs(makeAuthTestUser($other, ['fraud.alert.decide']));
    $this->postJson("/api/v1/risk-alerts/{$manual}/decision", ['decision' => 'CLEARED', 'notes' => 'Cross-tenant attempt here.'], tenantHeader($other))->assertNotFound();
});

it('REQ-CMP-003: governance registers are tenant-scoped structure with exit-plan maker-checker and no invented obligations', function () {
    $tenant = makeAuthTestTenant();
    $maker = makeAuthTestUser($tenant, ['compliance.governance.read', 'compliance.governance.manage', 'compliance.governance.approve']);
    $checker = makeAuthTestUser($tenant, ['compliance.governance.approve']);
    Passport::actingAs($maker);
    $h = tenantHeader($tenant);

    $vendor = $this->postJson('/api/v1/compliance/governance/vendors', ['vendor_code' => 'CLOUD1', 'name' => 'Cloud Co', 'is_outsourcing' => true, 'risk_rating' => 'HIGH'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/compliance/governance/vendors', ['vendor_code' => 'X', 'name' => 'X', 'risk_rating' => 'EXTREME'], $h)->assertStatus(422);
    $this->postJson('/api/v1/compliance/governance/ict-assets', ['asset_code' => 'CORE', 'name' => 'Core policy DB', 'category' => 'DATABASE', 'criticality' => 'CRITICAL', 'vendor_id' => $vendor, 'attributes' => ['rto' => 'owner-defined']], $h)->assertCreated();
    $this->postJson('/api/v1/compliance/governance/ict-incidents', ['title' => 'Outage', 'severity' => 'HIGH', 'detected_at' => now()->toISOString()], $h)
        ->assertCreated()->assertJsonPath('data.status', 'OPEN');
    $contract = $this->postJson('/api/v1/compliance/governance/outsourcing-contracts', ['vendor_id' => $vendor, 'contract_reference' => 'C-1', 'service_description' => 'Hosting', 'start_on' => '2026-01-01', 'risk_rating' => 'HIGH'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/compliance/governance/due-diligence-reviews', ['vendor_id' => $vendor, 'contract_id' => $contract, 'review_date' => '2026-09-01', 'outcome' => 'CONDITIONAL'], $h)->assertCreated();
    $plan = $this->postJson('/api/v1/compliance/governance/exit-plans', ['contract_id' => $contract, 'summary' => 'Migrate to secondary host'], $h)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/compliance/governance/exit-plans/{$plan}/approve", [], $h)->assertStatus(422);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/compliance/governance/exit-plans/{$plan}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    Passport::actingAs($maker);
    $this->patchJson("/api/v1/compliance/governance/exit-plans/{$plan}", ['summary' => 'Changed plan'], $h)->assertOk()->assertJsonPath('data.status', 'DRAFT');
    $this->getJson('/api/v1/compliance/governance/vendors', $h)->assertOk()->assertJsonPath('data.total', 1);

    $b = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($b, ['compliance.governance.read', 'compliance.governance.manage']));
    $this->getJson("/api/v1/compliance/governance/vendors/{$vendor}", tenantHeader($b))->assertNotFound();
    $this->postJson('/api/v1/compliance/governance/outsourcing-contracts', ['vendor_id' => $vendor, 'contract_reference' => 'C-2', 'service_description' => 'x', 'start_on' => '2026-01-01'], tenantHeader($b))->assertStatus(422);
});
