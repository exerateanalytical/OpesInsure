<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/**
 * First behavioural coverage for the legacy commission/settlement controllers.
 *
 * The Wave6 tests next door only file_get_contents() the service classes and
 * assert substrings, so they cannot catch any of this. Every case below fails
 * against the pre-fix controllers.
 */
function legacyCarrier(): string
{
    $party = DB::table('parties')->insertGetId([
        'id' => (string) Str::uuid(), 'type' => 'ORGANIZATION',
        'display_name' => 'Legacy Carrier '.Str::random(5), 'status' => 'ACTIVE',
        'legal_identity' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ], 'id');

    $id = (string) Str::uuid();
    DB::table('carriers')->insert([
        'id' => $id, 'party_id' => $party, 'cima_code' => 'CIMA-'.Str::random(6),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function legacyPartner(string $tenantId): string
{
    $party = (string) Str::uuid();
    DB::table('parties')->insert([
        'id' => $party, 'type' => 'ORGANIZATION', 'display_name' => 'Legacy Partner '.Str::random(5),
        'status' => 'ACTIVE', 'legal_identity' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = (string) Str::uuid();
    DB::table('partners')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'party_id' => $party, 'type' => 'BROKER',
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/**
 * A policy row in the given tenant. commission_accruals.policy_id is NOT NULL,
 * and a policy needs the whole proposal -> offer -> quote chain behind it, so
 * this leans on the Wave12 fixture builders rather than duplicating them.
 */
function legacyPolicy(string $tenantId): string
{
    $fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $tenant = App\Models\Tenant::find($tenantId);

    return makeMobileTestPolicy($fx['proposal'], $tenant, $fx['carrier']->id, $fx['party']->id)->id;
}

function legacyAccrual(string $tenantId, string $partnerId, int $amount = 500000): string
{
    $id = (string) Str::uuid();
    DB::table('commission_accruals')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'partner_id' => $partnerId,
        'policy_id' => legacyPolicy($tenantId),
        'amount_minor' => $amount, 'vested_minor' => 0, 'paid_minor' => 0, 'clawed_back_minor' => 0,
        'currency' => 'XAF', 'status' => 'ACCRUED', 'rule_version' => '1',
        'vests_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('does not leak another tenant commission balance', function () {
    $mine = makeAuthTestTenant();
    $theirs = makeAuthTestTenant();
    $user = makeAuthTestUser($mine, ['commission.read']);

    $theirPartner = legacyPartner($theirs->id);
    legacyAccrual($theirs->id, $theirPartner, 900000);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/partners/{$theirPartner}/commission-balance", tenantHeader($mine));

    $response->assertStatus(200);
    // Pre-fix this returned the other tenant's aggregated money.
    expect($response->json('data'))->toBe([]);
});

it('reports only the calling tenant balance when the same partner id exists in both', function () {
    $mine = makeAuthTestTenant();
    $theirs = makeAuthTestTenant();
    $user = makeAuthTestUser($mine, ['commission.read']);

    $partner = legacyPartner($mine->id);
    legacyAccrual($mine->id, $partner, 100000);
    legacyAccrual($theirs->id, $partner, 777000);

    Passport::actingAs($user);

    $rows = $this->getJson("/api/v1/partners/{$partner}/commission-balance", tenantHeader($mine))
        ->assertStatus(200)->json('data');

    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]['earned_minor'])->toBe(100000);
});

it('cannot vest an accrual belonging to another tenant', function () {
    $mine = makeAuthTestTenant();
    $theirs = makeAuthTestTenant();
    $user = makeAuthTestUser($mine, ['commission.vest']);

    $accrual = legacyAccrual($theirs->id, legacyPartner($theirs->id));

    Passport::actingAs($user);

    $this->postJson("/api/v1/commissions/{$accrual}/vest", [], tenantHeader($mine))->assertStatus(409);

    expect(DB::table('commission_accruals')->where('id', $accrual)->value('status'))->toBe('ACCRUED');
});

it('cannot claw back an accrual belonging to another tenant', function () {
    $mine = makeAuthTestTenant();
    $theirs = makeAuthTestTenant();
    $user = makeAuthTestUser($mine, ['commission.clawback']);

    $accrual = legacyAccrual($theirs->id, legacyPartner($theirs->id));

    Passport::actingAs($user);

    $this->postJson("/api/v1/commissions/{$accrual}/clawback", [
        'amount_minor' => 1000,
        'reason_code' => 'POLICY_CANCELLED',
        'notes' => 'Attempting a cross-tenant clawback which must be refused.',
    ], tenantHeader($mine))->assertStatus(404);

    expect((int) DB::table('commission_accruals')->where('id', $accrual)->value('clawed_back_minor'))->toBe(0);
});

it('stamps tenant_id and created_by on a commission rule so Wave6 governance can see it', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['commission.manage']);

    Passport::actingAs($user);

    $id = $this->postJson('/api/v1/commission-rules', [
        'carrier_id' => legacyCarrier(),
        'effective_from' => now()->toDateString(),
        'basis_points' => 1200,
        'vesting_days' => 30,
        'holdback_basis_points' => 100,
    ], tenantHeader($tenant))->assertStatus(201)->json('data.id');

    $row = DB::table('commission_rule_versions')->where('id', $id)->first();

    // tenant_id NULL made the row permanently unapprovable through Wave6,
    // whose owns() check compares tenant_id.
    expect($row->tenant_id)->toBe($tenant->id);
    // created_by NULL meant Wave6's maker-checker could never fire: NULL is
    // never equal to the approving actor, so self-approval always passed.
    expect($row->created_by)->toBe($user->id);
});

it('refuses to let the maker approve their own commission rule', function () {
    $tenant = makeAuthTestTenant();
    $maker = makeAuthTestUser($tenant, ['commission.manage', 'commission.approve']);

    Passport::actingAs($maker);

    $id = $this->postJson('/api/v1/commission-rules', [
        'carrier_id' => legacyCarrier(),
        'effective_from' => now()->toDateString(),
        'basis_points' => 1000,
        'vesting_days' => 30,
        'holdback_basis_points' => 0,
    ], tenantHeader($tenant))->assertStatus(201)->json('data.id');

    $this->postJson("/api/v1/commission-rules/{$id}/approve", [
        'reason' => 'Self approval must be refused to preserve maker-checker separation.',
    ], tenantHeader($tenant))->assertStatus(403);

    expect(DB::table('commission_rule_versions')->where('id', $id)->value('status'))->toBe('DRAFT');
});

it('does not expose another tenant settlement batch', function () {
    $mine = makeAuthTestTenant();
    $theirs = makeAuthTestTenant();
    $user = makeAuthTestUser($mine, ['settlement.read']);

    $batch = (string) Str::uuid();
    DB::table('settlement_batches')->insert([
        'id' => $batch, 'tenant_id' => $theirs->id, 'carrier_id' => legacyCarrier(),
        'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(),
        'net_amount_minor' => 4500000, 'currency' => 'XAF', 'status' => 'PENDING_APPROVAL',
        'prepared_by' => makeAuthTestUser($theirs, [])->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Passport::actingAs($user);

    $this->getJson("/api/v1/settlements/{$batch}", tenantHeader($mine))->assertStatus(404);
});

it('cannot approve another tenant settlement batch', function () {
    $mine = makeAuthTestTenant();
    $theirs = makeAuthTestTenant();
    $preparer = makeAuthTestUser($theirs, ['settlement.prepare']);
    $attacker = makeAuthTestUser($mine, ['settlement.approve']);

    $batch = (string) Str::uuid();
    DB::table('settlement_batches')->insert([
        'id' => $batch, 'tenant_id' => $theirs->id, 'carrier_id' => legacyCarrier(),
        'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(),
        'net_amount_minor' => 4500000, 'currency' => 'XAF', 'status' => 'PENDING_APPROVAL',
        'prepared_by' => $preparer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Passport::actingAs($attacker);

    $this->postJson("/api/v1/settlements/{$batch}/approve", [
        'notes' => 'Cross-tenant approval attempt which must be refused outright.',
    ], tenantHeader($mine))->assertStatus(409);

    expect(DB::table('settlement_batches')->where('id', $batch)->value('status'))->toBe('PENDING_APPROVAL');
});
