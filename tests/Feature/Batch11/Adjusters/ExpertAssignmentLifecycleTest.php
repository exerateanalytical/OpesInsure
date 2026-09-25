<?php

declare(strict_types=1);

/** Agent C9 — REQ-CLM-009 / WF-053 expert & adjuster assignment lifecycle. */

use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Models\Party;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function c9Expert(string $name, bool $activate = true, string $category = 'ADJUSTER'): object
{
    $reg = app(ProviderRegistry::class);
    $p = $reg->register(['category' => $category, 'name' => $name, 'provider_type_code' => 'MOTOR_EXPERT'], null);
    if ($activate) {
        foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
            $reg->transition($p->id, $to, null, null, null);
        }
    }

    return $reg->find($p->id);
}

function c9AdjusterUser($tenant, object $provider): User
{
    $u = makeAuthTestUser($tenant, ['claims.experts.work'], 'ADJUSTER');
    $u->update(['party_id' => $provider->party_id]);

    return $u->refresh();
}

beforeEach(function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $f['tenant'];
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $policy = makeMobileTestPolicy($f['proposal'], $this->tenant, $f['carrier']->id, $f['party']->id);
    $this->claim = makeMobileTestClaim($this->tenant, $policy, $f['party']);
    $this->staff = makeAuthTestUser($this->tenant, ['claims.view', 'claims.experts.assign', 'claims.experts.review']);
    $checker = makeAuthTestUser($this->tenant, []);

    $net = app(ProviderNetworkService::class);
    $this->expert = c9Expert('Cabinet Expertise Douala');
    $this->network = $net->createNetwork($this->tenant->id, ['code' => 'ADJ_PANEL', 'name' => 'Adjuster panel', 'network_type_code' => 'PANEL', 'category' => 'ADJUSTER'], null);
    $net->addMember($this->tenant->id, $this->network->id, ['provider_id' => $this->expert->id, 'effective_from' => now()->subMonth()->toDateString()], null);
    $this->service = $net->addMedicalService(['code' => 'EXP_MOTOR_INSPECTION', 'name' => 'Motor inspection', 'category_code' => 'EXPERTISE']);
    $contract = $net->createContract($this->tenant->id, $this->network->id, ['provider_id' => $this->expert->id, 'contract_number' => 'ADJ-001', 'effective_from' => now()->subMonth()->toDateString()], null);
    $tariff = $net->draftTariff($this->tenant->id, $contract->id, now()->subMonth()->toDateString(), 'XAF',
        [['medical_service_id' => $this->service->id, 'price_minor' => 60000, 'contracted_price_minor' => 50000, 'insurer_share_percent' => 100]], $this->staff->id);
    $net->approveTariff($this->tenant->id, $tariff->id, $checker->id);

    $this->adjuster = c9AdjusterUser($this->tenant, $this->expert);
    Passport::actingAs($this->staff, [], 'api');
});

function c9Assign(array $over = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/claims/'.test()->claim->id.'/assignments/experts', array_merge([
        'provider_id' => test()->expert->id, 'network_id' => test()->network->id, 'fee_service_id' => test()->service->id, 'instructions' => 'Inspect the rear bumper.',
    ], $over), test()->h);
}

it('REQ-CLM-009: full lifecycle with tariff fee, case SLA, history, events and adjuster-only API', function () {
    $a = c9Assign()->assertCreated()->assertJsonPath('data.status', 'ASSIGNMENT_PENDING')->assertJsonPath('data.fee_amount_minor', 50000)->json('data');
    expect($a['fee_currency'])->toBe('XAF')->and($a['assignment_type'])->toBe('EXPERT');
    $case = DB::table('cases')->where('id', $a['case_id'])->first();
    expect($case->case_type_code)->toBe('CLAIM_EXPERT_ASSIGNMENT')->and($case->status)->toBe('ASSIGNMENT_PENDING')
        ->and(DB::table('sla_clocks')->where('case_id', $case->id)->pluck('metric')->sort()->values()->all())->toBe(['FIRST_RESPONSE', 'RESOLUTION']);

    // Staff cannot perform the adjuster steps.
    $this->postJson("/api/v1/adjuster/assignments/{$a['id']}/accept", [], $this->h)->assertForbidden();

    Passport::actingAs($this->adjuster, [], 'api');
    $mine = $this->getJson('/api/v1/adjuster/assignments', $this->h)->assertOk()->json('data');
    expect($mine)->toHaveCount(1)->and($mine[0]['claim_number'])->toBe($this->claim->claim_number);
    $this->getJson("/api/v1/adjuster/assignments/{$a['id']}", $this->h)->assertOk()->assertJsonPath('data.claim.description', 'Rear-ended at a traffic light.')
        ->assertJsonMissingPath('data.claim.claimant_party_id');

    $this->postJson("/api/v1/adjuster/assignments/{$a['id']}/report", ['summary' => str_repeat('x', 30), 'assessed_loss_minor' => 1], $this->h)->assertStatus(409);
    $this->postJson("/api/v1/adjuster/assignments/{$a['id']}/accept", [], $this->h)->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
    expect(DB::table('cases')->where('id', $case->id)->value('first_responded_at'))->not->toBeNull();
    $this->postJson("/api/v1/adjuster/assignments/{$a['id']}/inspection", ['scheduled_for' => now()->addDay()->toIso8601String(), 'location' => 'Garage Akwa'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'INSPECTION_SCHEDULED');
    $this->postJson("/api/v1/adjuster/assignments/{$a['id']}/inspection", ['scheduled_for' => now()->addDays(2)->toIso8601String()], $this->h)->assertOk();
    $this->postJson("/api/v1/adjuster/assignments/{$a['id']}/inspected", ['notes' => 'Bumper and tail light damaged.'], $this->h)->assertOk()->assertJsonPath('data.status', 'INSPECTED');
    $report = ['summary' => 'Rear bumper replacement and tail light; labour 4h.', 'assessed_loss_minor' => 350000];
    $this->postJson("/api/v1/adjuster/assignments/{$a['id']}/report", $report, $this->h)->assertOk()->assertJsonPath('data.status', 'REPORT_SUBMITTED');
    // The expert cannot review their own report (route permission, then maker-checker in the service).
    $this->postJson("/api/v1/claims/{$this->claim->id}/assignments/{$a['id']}/report/accept", [], $this->h)->assertForbidden();

    Passport::actingAs($this->staff, [], 'api');
    $this->postJson("/api/v1/claims/{$this->claim->id}/assignments/{$a['id']}/report/return", [], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/claims/{$this->claim->id}/assignments/{$a['id']}/report/return", ['reason' => 'Add photos of the chassis.'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'REPORT_RETURNED')->assertJsonPath('data.return_count', 1);

    Passport::actingAs($this->adjuster, [], 'api');
    $this->postJson("/api/v1/adjuster/assignments/{$a['id']}/report", $report, $this->h)->assertOk()->assertJsonPath('data.status', 'REPORT_SUBMITTED');

    Passport::actingAs($this->staff, [], 'api');
    $done = $this->postJson("/api/v1/claims/{$this->claim->id}/assignments/{$a['id']}/report/accept", ['notes' => 'OK'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'REPORT_ACCEPTED')->json('data');
    expect($done['released_at'])->not->toBeNull()
        ->and(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('REPORT_ACCEPTED')
        ->and(DB::table('sla_clocks')->where('case_id', $case->id)->whereNull('stopped_at')->count())->toBe(0);

    $show = $this->getJson("/api/v1/claims/{$this->claim->id}/assignments/{$a['id']}", $this->h)->assertOk()->json('data');
    expect(array_column($show['history'], 'to_status'))->toBe(['ASSIGNMENT_PENDING', 'ACCEPTED', 'INSPECTION_SCHEDULED', 'INSPECTION_SCHEDULED', 'INSPECTED',
        'REPORT_SUBMITTED', 'REPORT_RETURNED', 'REPORT_SUBMITTED', 'REPORT_ACCEPTED']);
    expect(fn () => DB::transaction(fn () => DB::table('claim_assignment_events')->where('claim_assignment_id', $a['id'])->delete()))->toThrow(QueryException::class);
    foreach (['claim.expert.assigned', 'claim.expert.accepted', 'claim.expert.inspection_scheduled', 'claim.expert.inspected', 'claim.expert.report_submitted',
        'claim.expert.report_returned', 'claim.expert.report_accepted'] as $e) {
        expect(DB::table('outbox_messages')->where('event_name', $e)->exists())->toBeTrue($e);
    }
    $this->getJson("/api/v1/claims/{$this->claim->id}/assignments", $this->h)->assertOk()->assertJsonCount(1, 'data');
});

it('REQ-CLM-009: only ACTIVE in-network ADJUSTER/EXPERT providers with a priced tariff can be appointed', function () {
    $net = app(ProviderNetworkService::class);
    $inactive = c9Expert('Expert Prospect', false);
    c9Assign(['provider_id' => $inactive->id])->assertStatus(409)->assertJsonPath('code', 'PROVIDER_NOT_ACTIVE');

    $outside = c9Expert('Expert Hors Réseau');
    c9Assign(['provider_id' => $outside->id])->assertStatus(409)->assertJsonPath('code', 'PROVIDER_NOT_IN_NETWORK');

    $garage = app(ProviderRegistry::class)->register(['category' => 'GARAGE', 'name' => 'Garage X', 'provider_type_code' => 'BODYWORK'], null);
    c9Assign(['provider_id' => $garage->id])->assertStatus(422)->assertJsonPath('code', 'PROVIDER_NOT_EXPERT');

    $other = $net->addMedicalService(['code' => 'EXP_UNPRICED', 'name' => 'Unpriced', 'category_code' => 'EXPERTISE']);
    c9Assign(['fee_service_id' => $other->id])->assertStatus(409)->assertJsonPath('code', 'EXPERT_TARIFF_MISSING');

    c9Assign()->assertCreated();
    c9Assign()->assertStatus(409)->assertJsonPath('code', 'EXPERT_ALREADY_ASSIGNED');

    $this->claim->update(['status' => 'CLOSED']);
    $this->claim->refresh();
    DB::table('claim_assignments')->update(['status' => 'CANCELLED']);
    c9Assign()->assertStatus(409)->assertJsonPath('code', 'CLAIM_NOT_ASSIGNABLE');
});

it('REQ-CLM-009: adjusters only see and act on their own assignments; decline and cancel need reasons', function () {
    $a = c9Assign()->assertCreated()->json('data.id');

    $otherExpert = c9Expert('Autre Cabinet');
    $stranger = c9AdjusterUser($this->tenant, $otherExpert);
    Passport::actingAs($stranger, [], 'api');
    expect($this->getJson('/api/v1/adjuster/assignments', $this->h)->assertOk()->json('data'))->toBe([]);
    $this->getJson("/api/v1/adjuster/assignments/{$a}", $this->h)->assertNotFound();
    $this->postJson("/api/v1/adjuster/assignments/{$a}/accept", [], $this->h)->assertNotFound();

    // An employee of the expert firm (EMPLOYED_BY) acts for it.
    $emp = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Jean Expert', 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $emp->id, 'to_party_id' => $this->expert->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $employee = makeAuthTestUser($this->tenant, ['claims.experts.work'], 'ADJUSTER');
    $employee->update(['party_id' => $emp->id]);
    Passport::actingAs($employee->refresh(), [], 'api');
    $this->postJson("/api/v1/adjuster/assignments/{$a}/decline", [], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/adjuster/assignments/{$a}/decline", ['reason' => 'Conflict of interest'], $this->h)->assertOk()->assertJsonPath('data.status', 'DECLINED');
    expect(DB::table('cases')->where('source_id', $a)->value('status'))->toBe('DECLINED');

    // Cancel by the insurer on a fresh appointment.
    Passport::actingAs($this->staff, [], 'api');
    $b = c9Assign()->assertCreated()->json('data.id');
    $this->postJson("/api/v1/claims/{$this->claim->id}/assignments/{$b}/cancel", [], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/claims/{$this->claim->id}/assignments/{$b}/cancel", ['reason' => 'Claim withdrawn by insured'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->postJson("/api/v1/claims/{$this->claim->id}/assignments/{$b}/cancel", ['reason' => 'Claim withdrawn by insured'], $this->h)->assertStatus(409);
});

it('REQ-CLM-009: the existing handler assignment still works on the extended table', function () {
    $handler = makeAuthTestUser($this->tenant, []);
    DB::table('claim_assignments')->insert(['id' => (string) Str::uuid(), 'claim_id' => $this->claim->id, 'assignee_id' => $handler->id, 'assigned_by' => $this->staff->id,
        'reason_code' => 'WORKLOAD', 'assigned_at' => now()]);
    expect(DB::table('claim_assignments')->value('assignment_type'))->toBe('HANDLER');
    expect(fn () => DB::transaction(fn () => DB::table('claim_assignments')->insert(['id' => (string) Str::uuid(), 'claim_id' => $this->claim->id, 'assignee_id' => null,
        'assigned_by' => $this->staff->id, 'reason_code' => 'X', 'assigned_at' => now()->addSecond()])))->toThrow(QueryException::class);
});
