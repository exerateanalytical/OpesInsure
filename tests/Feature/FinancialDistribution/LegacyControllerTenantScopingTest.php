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
 * Covers what is left of the legacy commission/settlement controllers after
 * their write endpoints were removed, plus proof the removal took effect.
 *
 * The Wave6 tests next door only file_get_contents() the service classes and
 * assert substrings, so they never touch the database and cannot catch any of
 * this. Runs against an isolated database, never the shared one.
 */
function legacyCarrier(): string
{
    $party = (string) Str::uuid();
    DB::table('parties')->insert([
        'id' => $party, 'type' => 'ORGANIZATION', 'display_name' => 'Legacy Carrier '.Str::random(5),
        'status' => 'ACTIVE', 'legal_identity' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

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
 * commission_accruals.policy_id is NOT NULL and a policy needs the whole
 * proposal -> offer -> quote chain behind it, so this leans on the Wave12
 * fixture builders rather than duplicating them.
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

function legacyBatch(string $tenantId, string $preparedBy): string
{
    $id = (string) Str::uuid();
    DB::table('settlement_batches')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'carrier_id' => legacyCarrier(),
        'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(),
        'net_amount_minor' => 4500000, 'currency' => 'XAF', 'status' => 'PENDING_APPROVAL',
        'prepared_by' => $preparedBy, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('no longer exposes the legacy commission and settlement write routes', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, [
        'commission.manage', 'commission.approve', 'commission.accrue',
        'commission.vest', 'commission.clawback',
        'settlement.prepare', 'settlement.approve',
    ]);

    Passport::actingAs($user);

    $uuid = (string) Str::uuid();
    $removed = [
        '/api/v1/commission-rules',
        "/api/v1/commission-rules/{$uuid}/approve",
        '/api/v1/commissions/accrue',
        "/api/v1/commissions/{$uuid}/vest",
        "/api/v1/commissions/{$uuid}/clawback",
        '/api/v1/settlements',
        "/api/v1/settlements/{$uuid}/approve",
    ];

    // These wrote the same tables as the Wave6 domain with an incompatible
    // status vocabulary. Holding the permission must no longer reach them.
    foreach ($removed as $path) {
        $this->postJson($path, [], tenantHeader($tenant))->assertStatus(404);
    }
});

it('still serves the Wave6 commission and settlement write routes', function () {
    // The replacement path must remain reachable — a miss here would mean the
    // removal took the governed endpoints with it.
    $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->all();

    expect($routes)->toContain('api/v1/financial-distribution/commissions/accrue');
    expect($routes)->toContain('api/v1/carrier-settlements');
});

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

it('does not expose another tenant settlement batch', function () {
    $mine = makeAuthTestTenant();
    $theirs = makeAuthTestTenant();
    $user = makeAuthTestUser($mine, ['settlement.read']);

    $batch = legacyBatch($theirs->id, makeAuthTestUser($theirs, [])->id);

    Passport::actingAs($user);

    $this->getJson("/api/v1/settlements/{$batch}", tenantHeader($mine))->assertStatus(404);
});

it('serves the calling tenant own settlement batch with its approvals', function () {
    $tenant = makeAuthTestTenant();
    $preparer = makeAuthTestUser($tenant, ['settlement.prepare']);
    $reader = makeAuthTestUser($tenant, ['settlement.read']);

    $batch = legacyBatch($tenant->id, $preparer->id);

    // settlement_approvals is written only by this legacy path today; the read
    // must keep surfacing it while CarrierSettlementService adopts it.
    DB::table('settlement_approvals')->insert([
        'id' => (string) Str::uuid(), 'settlement_batch_id' => $batch, 'stage' => 'FINANCE_APPROVAL',
        'decision' => 'APPROVED', 'actor_id' => $preparer->id,
        'notes' => 'Approved during the reporting period close for this carrier.',
        'decided_at' => now(),
    ]);

    Passport::actingAs($reader);

    $data = $this->getJson("/api/v1/settlements/{$batch}", tenantHeader($tenant))
        ->assertStatus(200)->json('data');

    expect($data['batch']['id'])->toBe($batch);
    expect($data['approvals'])->toHaveCount(1);
    expect($data['approvals'][0]['decision'])->toBe('APPROVED');
});
