<?php

declare(strict_types=1);

use App\Models\Claim;
use App\Models\CustomerAttribution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function c2MobilePayload(string $policyId, array $o = []): array
{
    return array_merge([
        'policy_id' => $policyId, 'incident_at' => now()->subDays(20)->toIso8601String(), 'incident_location' => 'Douala',
        'description' => 'Rear-ended at a traffic light while stationary.', 'incident_type' => 'COLLISION', 'estimated_loss_minor' => 150000,
    ], $o);
}

function c2Versions(string $tenantId, string $policyId): array
{
    $v1 = (string) Str::uuid();
    $v2 = (string) Str::uuid();
    $base = ['tenant_id' => $tenantId, 'policy_id' => $policyId, 'kind' => 'ISSUANCE', 'schema_version' => 1, 'snapshot_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];
    DB::table('policy_versions')->insert(array_merge($base, ['id' => $v1, 'version_no' => 1, 'valid_from' => now()->subMonths(6), 'valid_to' => now()->subDays(10), 'recorded_at' => now()->subMonths(6), 'snapshot' => json_encode(['sum_insured_minor' => 1000])]));
    DB::table('policy_versions')->insert(array_merge($base, ['id' => $v2, 'version_no' => 2, 'kind' => 'ENDORSEMENT', 'valid_from' => now()->subDays(10), 'recorded_at' => now()->subDays(10), 'snapshot' => json_encode(['sum_insured_minor' => 2000])]));

    return [$v1, $v2];
}

it('mobile FNOL returns a claim reference at once and freezes an immutable snapshot of the policy version at the loss date', function () {
    $f = makeMobileCustomerFixture('+237670110201');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);
    [$v1] = c2Versions($f['tenant']->id, $policy->id);

    Passport::actingAs($f['user']);
    $headers = array_merge(tenantHeaderFor($f['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $payload = c2MobilePayload($policy->id);
    $res = $this->postJson('/api/v1/mobile/claims', $payload, $headers)->assertStatus(201);
    expect($res->json('data.claim_number'))->toStartWith('CLM-')->and($res->json('data.status'))->toBe('SUBMITTED');

    $snap = DB::table('claim_fnol_snapshots')->where('claim_id', $res->json('data.id'))->first();
    expect($snap)->not->toBeNull()
        ->and($snap->channel)->toBe('MOBILE')->and($snap->reporter_role)->toBe('CUSTOMER')
        ->and($snap->reporter_user_id)->toBe($f['user']->id)->and($snap->claimant_party_id)->toBe($f['party']->id)
        ->and($snap->policy_version_id)->toBe($v1)->and((int) $snap->policy_version_no)->toBe(1)
        ->and($snap->claim_number)->toBe($res->json('data.claim_number'))
        ->and(json_decode($snap->reported_facts, true)['loss_details']['description'])->toBe('Rear-ended at a traffic light while stationary.')
        ->and(json_decode($snap->policy_snapshot, true)['version']['snapshot']['sum_insured_minor'])->toBe(1000);

    // CLAIMS_INTAKE capability pinned (the old raw-insert path bypassed this).
    expect(DB::table('capability_pins')->where(['subject_type' => 'claim', 'subject_id' => $snap->claim_id, 'capability' => 'CLAIMS_INTAKE'])->exists())->toBeTrue();

    // Replay: same claim, still exactly one snapshot.
    $again = $this->postJson('/api/v1/mobile/claims', $payload, $headers)->assertStatus(201);
    expect($again->json('data.id'))->toBe($snap->claim_id)->and(DB::table('claim_fnol_snapshots')->count())->toBe(1);

    // Immutable.
    expect(fn () => DB::table('claim_fnol_snapshots')->where('id', $snap->id)->update(['channel' => 'WEB']))->toThrow(QueryException::class);
});

it('agent-assisted FNOL files for a customer in the agent book and records the agent as reporter (AGT-052)', function () {
    $a = makeMobileAgentFixture('+237680110201');
    $chain = makeMobileFinanceProposalChain($a['tenant']);
    CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $a['partner']->id, 'origin_type' => 'AGENT', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $a['user']->id]);
    $policy = makeMobileTestPolicy($chain['proposal'], $a['tenant'], $chain['carrier']->id, $chain['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);

    Passport::actingAs($a['user']);
    $payload = ['policy_id' => $policy->id, 'claimant_party_id' => $chain['party']->id, 'loss_occurred_at' => now()->subDay()->toIso8601String(), 'loss_details' => ['description' => 'Windscreen broken'], 'idempotency_key' => (string) Str::uuid()];
    $res = $this->postJson('/api/v1/mobile/partner/agent/claims', $payload, tenantHeaderFor($a['tenant']))->assertStatus(201);
    expect($res->json('data.claim_number'))->toStartWith('CLM-');

    $snap = DB::table('claim_fnol_snapshots')->where('claim_id', $res->json('data.id'))->first();
    expect($snap->channel)->toBe('AGENT')->and($snap->reporter_role)->toBe('AGENT')
        ->and($snap->acting_partner_id)->toBe($a['partner']->id)->and($snap->reporter_user_id)->toBe($a['user']->id)
        ->and($snap->claimant_party_id)->toBe($chain['party']->id);
});

it('refuses agent-assisted FNOL for a customer outside the agent book', function () {
    $a = makeMobileAgentFixture('+237680110202');
    $chain = makeMobileFinanceProposalChain($a['tenant']);
    $policy = makeMobileTestPolicy($chain['proposal'], $a['tenant'], $chain['carrier']->id, $chain['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);

    Passport::actingAs($a['user']);
    $payload = ['policy_id' => $policy->id, 'claimant_party_id' => $chain['party']->id, 'loss_occurred_at' => now()->subDay()->toIso8601String(), 'loss_details' => ['description' => 'x'], 'idempotency_key' => (string) Str::uuid()];
    $this->postJson('/api/v1/mobile/partner/agent/claims', $payload, tenantHeaderFor($a['tenant']))->assertStatus(422)->assertJsonValidationErrors('claimant_party_id');
    expect(Claim::count())->toBe(0);
});

it('a customer cannot use the agent-assisted FNOL endpoint', function () {
    $f = makeMobileCustomerFixture('+237670110203');
    Passport::actingAs($f['user']);
    $this->postJson('/api/v1/mobile/partner/agent/claims', [], tenantHeaderFor($f['tenant']))->assertStatus(403);
});

it('staff FNOL records the intake channel and exposes the snapshot', function () {
    $f = makeMobileCustomerFixture('+237670110204');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);
    $staff = makeMobileTenantStaffUser($f['tenant'], '+237670110205', 'CLAIMS_OFFICER');

    Passport::actingAs($staff);
    $res = $this->postJson('/api/v1/claims/fnol', ['policy_id' => $policy->id, 'claimant_party_id' => $f['party']->id, 'loss_occurred_at' => now()->subDay()->toIso8601String(), 'loss_details' => ['description' => 'Reported by phone'], 'channel' => 'PHONE', 'idempotency_key' => (string) Str::uuid()], tenantHeaderFor($f['tenant']))->assertStatus(201);

    $this->getJson('/api/v1/claims/'.$res->json('data.id').'/fnol-snapshot', tenantHeaderFor($f['tenant']))->assertStatus(200)
        ->assertJsonPath('data.channel', 'PHONE')->assertJsonPath('data.reporter_role', 'STAFF')
        ->assertJsonPath('data.reported_facts.loss_details.description', 'Reported by phone');
});

it('legacy ClaimController::store and MobileClaimService go through the one FNOL path', function () {
    $legacy = file_get_contents(app_path('Interfaces/Http/Controllers/Api/V1/Claims/ClaimController.php'));
    $mobile = file_get_contents(app_path('Application/Claims/MobileClaimService.php'));
    expect($legacy)->toContain('FnolService')->not->toContain("DB::table('claims')->insert")
        ->and($mobile)->toContain('FnolService')->not->toContain('$this->lifecycle->fnol(');
});
