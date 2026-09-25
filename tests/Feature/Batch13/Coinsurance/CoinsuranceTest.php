<?php

declare(strict_types=1);

use App\Application\Coinsurance\CoinsuranceService;
use App\Models\Carrier;
use App\Models\Party;
use App\Models\Policy;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b13dCarrier(): Carrier
{
    $p = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B13D Insurer '.Str::random(4), 'status' => 'ACTIVE']);

    return Carrier::create(['party_id' => $p->id, 'cima_code' => 'B13D-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE']);
}

function b13dPayload(array $carriers, array $shares, array $over = []): array
{
    $participants = [];
    foreach ($carriers as $i => $c) {
        $participants[] = ['carrier_id' => $c->id, 'role' => $i === 0 ? 'LEAD' : 'FOLLOWER', 'share_bps' => $shares[$i]];
    }

    return array_merge(['reference' => 'COI-'.Str::upper(Str::random(6)), 'effective_from' => now()->toDateString(), 'participants' => $participants], $over);
}

const B13D_ALL = ['coinsurance.view', 'coinsurance.manage', 'coinsurance.approve', 'coinsurance.apportion'];

it('REQ-COI-001 creates an arrangement with one apériteur and followers totalling 100%, maker-checker activation, audit + outbox', function () {
    $tenant = makeAuthTestTenant('b13d');
    $maker = makeAuthTestUser($tenant, B13D_ALL);
    $checker = makeAuthTestUser($tenant, B13D_ALL);
    $c = [b13dCarrier(), b13dCarrier(), b13dCarrier()];

    Passport::actingAs($maker);
    $res = $this->postJson('/api/v1/coinsurance/arrangements', b13dPayload($c, [5000, 3000, 2000]), tenantHeader($tenant))->assertCreated();
    $id = $res->json('data.id');
    expect($res->json('data.status'))->toBe('DRAFT')->and($res->json('data.kind'))->toBe('COINSURANCE')
        ->and($res->json('data.placed_bps'))->toBe(10000)->and($res->json('data.participants.0.role'))->toBe('LEAD')
        ->and($res->json('data.participants.0.master_data_role'))->toBe('LEAD');

    // Maker cannot check own arrangement.
    $this->postJson("/api/v1/coinsurance/arrangements/{$id}/activate", [], tenantHeader($tenant))->assertStatus(422);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/coinsurance/arrangements/{$id}/activate", [], tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'ACTIVE');

    expect(DB::table('audit_log')->where('subject_id', $id)->pluck('action')->all())
        ->toContain('coinsurance.arrangement.created', 'coinsurance.arrangement.activated')
        ->and(DB::table('outbox_messages')->where('aggregate_id', $id)->count())->toBe(2);
    // Never reinsurance: no cession/treaty artefacts are produced.
    expect(DB::getSchemaBuilder()->hasTable('risk_cessions') ? DB::table('risk_cessions')->count() : 0)->toBe(0);
});

it('REQ-COI-001 rejects invalid participations', function (array $shares, array $roles, bool $partial, bool $ok) {
    $tenant = makeAuthTestTenant('b13d');
    $u = makeAuthTestUser($tenant, B13D_ALL);
    $carriers = array_map(fn () => b13dCarrier(), $shares);
    $payload = b13dPayload($carriers, $shares, ['allow_partial_placement' => $partial]);
    foreach ($roles as $i => $r) {
        $payload['participants'][$i]['role'] = $r;
    }
    Passport::actingAs($u);
    $res = $this->postJson('/api/v1/coinsurance/arrangements', $payload, tenantHeader($tenant));
    $res->assertStatus($ok ? 201 : 422);
})->with([
    'under 100% without partial' => [[5000, 4000], ['LEAD', 'FOLLOWER'], false, false],
    'over 100%' => [[6000, 5000], ['LEAD', 'FOLLOWER'], true, false],
    'under 100% with partial' => [[5000, 4000], ['LEAD', 'FOLLOWER'], true, true],
    'two leads' => [[5000, 5000], ['LEAD', 'LEAD'], false, false],
    'no lead' => [[5000, 5000], ['FOLLOWER', 'FOLLOWER'], false, false],
    'single participant' => [[10000], ['LEAD'], false, false],
    'workflow role codes accepted' => [[6000, 4000], ['LEAD_INSURER', 'PARTICIPATING_INSURER'], false, true],
]);

it('REQ-COI-001 rejects a duplicate carrier and per-basis overrides not totalling 100%', function () {
    $tenant = makeAuthTestTenant('b13d');
    Passport::actingAs(makeAuthTestUser($tenant, B13D_ALL));
    $a = b13dCarrier();
    $b = b13dCarrier();
    $this->postJson('/api/v1/coinsurance/arrangements', b13dPayload([$a, $a], [5000, 5000]), tenantHeader($tenant))->assertStatus(422);

    $p = b13dPayload([$a, $b], [6000, 4000]);
    $p['participants'][0]['share_overrides_bps'] = ['COMMISSION' => 7000];
    $this->postJson('/api/v1/coinsurance/arrangements', $p, tenantHeader($tenant))->assertStatus(422);
    $p['participants'][1]['share_overrides_bps'] = ['COMMISSION' => 3000];
    $this->postJson('/api/v1/coinsurance/arrangements', $p, tenantHeader($tenant))->assertCreated();
});

it('REQ-COI-001 apportions premium/claim/commission/reserve/settlement exactly, residual to lead, idempotently', function () {
    $tenant = makeAuthTestTenant('b13d');
    $maker = makeAuthTestUser($tenant, B13D_ALL);
    $checker = makeAuthTestUser($tenant, B13D_ALL);
    $c = [b13dCarrier(), b13dCarrier(), b13dCarrier()];
    $p = b13dPayload($c, [3334, 3333, 3333]);
    $p['participants'][0]['share_overrides_bps'] = ['COMMISSION' => 5000];
    $p['participants'][1]['share_overrides_bps'] = ['COMMISSION' => 2500];
    $p['participants'][2]['share_overrides_bps'] = ['COMMISSION' => 2500];
    Passport::actingAs($maker);
    $id = $this->postJson('/api/v1/coinsurance/arrangements', $p, tenantHeader($tenant))->json('data.id');

    // Not active yet.
    $this->postJson("/api/v1/coinsurance/arrangements/{$id}/apportionments", ['basis' => 'PREMIUM', 'total_minor' => 100, 'source_type' => 'policy', 'idempotency_key' => 'k0'], tenantHeader($tenant))->assertStatus(422);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/coinsurance/arrangements/{$id}/activate", [], tenantHeader($tenant))->assertOk();

    foreach (['PREMIUM', 'CLAIM', 'RESERVE', 'SETTLEMENT', 'COMMISSION'] as $basis) {
        $r = $this->postJson("/api/v1/coinsurance/arrangements/{$id}/apportionments", ['basis' => $basis, 'total_minor' => 1000001, 'source_type' => 'test', 'idempotency_key' => "k-{$basis}"], tenantHeader($tenant))->assertCreated();
        $amounts = array_column($r->json('data.lines'), 'amount_minor');
        expect(array_sum($amounts))->toBe(1000001)->and($r->json('data.unplaced_minor'))->toBe(0);
        if ($basis === 'PREMIUM') {
            expect($amounts)->toBe([333401, 333300, 333300]);
        }
        if ($basis === 'COMMISSION') {
            expect($amounts)->toBe([500001, 250000, 250000]);
        }
    }

    // Negative (refund/recovery) amounts mirror signs.
    $neg = $this->postJson("/api/v1/coinsurance/arrangements/{$id}/preview", ['basis' => 'CLAIM', 'total_minor' => -1000001], tenantHeader($tenant))->assertOk();
    expect(array_sum(array_column($neg->json('data.lines'), 'amount_minor')))->toBe(-1000001);

    // Replay returns same row; mismatched replay rejected.
    $replay = $this->postJson("/api/v1/coinsurance/arrangements/{$id}/apportionments", ['basis' => 'PREMIUM', 'total_minor' => 1000001, 'source_type' => 'test', 'idempotency_key' => 'k-PREMIUM'], tenantHeader($tenant))->assertOk();
    expect($replay->json('data.replayed'))->toBeTrue();
    $this->postJson("/api/v1/coinsurance/arrangements/{$id}/apportionments", ['basis' => 'PREMIUM', 'total_minor' => 5, 'source_type' => 'test', 'idempotency_key' => 'k-PREMIUM'], tenantHeader($tenant))->assertStatus(422);
    expect(DB::table('coinsurance_apportionments')->where('arrangement_id', $id)->count())->toBe(5);
    $this->getJson("/api/v1/coinsurance/arrangements/{$id}/apportionments", tenantHeader($tenant))->assertOk()->assertJsonCount(5, 'data');
});

it('REQ-COI-001 partial placement leaves an unplaced remainder', function () {
    $tenant = makeAuthTestTenant('b13d');
    $u = makeAuthTestUser($tenant, B13D_ALL);
    $svc = app(CoinsuranceService::class);
    $arr = $svc->create($tenant->id, b13dPayload([b13dCarrier(), b13dCarrier()], [4000, 3500], ['allow_partial_placement' => true]), $u);
    $r = $svc->compute($arr, 'PREMIUM', 10001);
    expect(array_column($r['lines'], 'amount_minor'))->toBe([4000, 3500])->and($r['unplaced_minor'])->toBe(2501);
});

it('REQ-COI-001 links read-only to a same-tenant policy, one active arrangement per policy, terminate needs reason', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $tenant = $f['tenant'];
    $policy = Policy::create([
        'tenant_id' => $tenant->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDay(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => ['line_code' => 'AUTO'], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now(),
    ]);
    $before = $policy->fresh()->toArray();
    $maker = makeAuthTestUser($tenant, B13D_ALL);
    $checker = makeAuthTestUser($tenant, B13D_ALL);
    $svc = app(CoinsuranceService::class);

    $a1 = $svc->create($tenant->id, b13dPayload([$f['carrier'], b13dCarrier()], [7000, 3000], ['policy_id' => $policy->id]), $maker);
    $a2 = $svc->create($tenant->id, b13dPayload([$f['carrier'], b13dCarrier()], [6000, 4000], ['policy_id' => $policy->id]), $maker);
    $svc->activate($tenant->id, $a1['id'], $checker);
    expect(fn () => $svc->activate($tenant->id, $a2['id'], $checker))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($svc->activeForPolicy($tenant->id, $policy->id)['id'])->toBe($a1['id']);
    expect($policy->fresh()->toArray())->toBe($before);

    // Policy from another tenant is rejected.
    $other = makeAuthTestTenant('b13d-other');
    expect(fn () => $svc->create($other->id, b13dPayload([b13dCarrier(), b13dCarrier()], [5000, 5000], ['policy_id' => $policy->id]), makeAuthTestUser($other, B13D_ALL)))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/coinsurance/arrangements/{$a1['id']}/terminate", ['reason' => 'short'], tenantHeader($tenant))->assertStatus(422);
    $this->postJson("/api/v1/coinsurance/arrangements/{$a1['id']}/terminate", ['reason' => 'Policy cancelled by lead insurer'], tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'TERMINATED');
    expect($svc->activeForPolicy($tenant->id, $policy->id))->toBeNull();
    $this->getJson('/api/v1/coinsurance/arrangements?policy_id='.$policy->id, tenantHeader($tenant))->assertOk()->assertJsonCount(2, 'data');
});

it('REQ-COI-001 enforces permissions and tenant isolation', function () {
    $tenant = makeAuthTestTenant('b13d');
    $viewer = makeAuthTestUser($tenant, ['coinsurance.view']);
    $manager = makeAuthTestUser($tenant, B13D_ALL);
    $id = app(CoinsuranceService::class)->create($tenant->id, b13dPayload([b13dCarrier(), b13dCarrier()], [5000, 5000]), $manager)['id'];

    Passport::actingAs($viewer);
    $this->getJson("/api/v1/coinsurance/arrangements/{$id}", tenantHeader($tenant))->assertOk();
    $this->postJson('/api/v1/coinsurance/arrangements', b13dPayload([b13dCarrier(), b13dCarrier()], [5000, 5000]), tenantHeader($tenant))->assertForbidden();
    $this->postJson("/api/v1/coinsurance/arrangements/{$id}/activate", [], tenantHeader($tenant))->assertForbidden();

    $other = makeAuthTestTenant('b13d-x');
    Passport::actingAs(makeAuthTestUser($other, B13D_ALL));
    $this->getJson("/api/v1/coinsurance/arrangements/{$id}", tenantHeader($other))->assertNotFound();
});
