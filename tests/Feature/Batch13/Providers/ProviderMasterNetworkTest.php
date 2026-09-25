<?php

declare(strict_types=1);

/** Batch 13A — REQ-PRV-001, REQ-PRV-002, REQ-PRV-004 (provider master + network). */

use App\Models\Party;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

const PRV_ALL = ['providers.view', 'providers.manage', 'providers.credential', 'provider_networks.view', 'provider_networks.manage', 'provider_tariffs.approve'];

beforeEach(function () {
    $this->tenant = makeAuthTestTenant('prv');
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $this->admin = makeAuthTestUser($this->tenant, PRV_ALL);
    Passport::actingAs($this->admin, [], 'api');
});

function prvActivate(string $id): void
{
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        test()->postJson("/api/v1/providers/{$id}/credentialing", ['to' => $to], test()->h)->assertOk();
    }
}

function prvCreate(string $category = 'HEALTH', string $name = 'Hôpital Laquintinie'): string
{
    return test()->postJson('/api/v1/providers', ['category' => $category, 'name' => $name, 'provider_type_code' => $category === 'HEALTH' ? 'HOSPITAL' : 'MOTOR_EXPERT'], test()->h)
        ->assertCreated()->json('data.id');
}

it('REQ-PRV-001: a provider is a party + canonical partner + profile, not a parallel identity', function () {
    $id = prvCreate();
    $p = DB::table('provider_profiles')->where('id', $id)->first();
    expect($p->credentialing_status)->toBe('PROSPECT')
        ->and(DB::table('parties')->where('id', $p->party_id)->value('display_name'))->toBe('Hôpital Laquintinie')
        ->and(DB::table('partners')->where('id', $p->partner_id)->value('type'))->toBe('HEALTH_PROVIDER')
        ->and(DB::table('audit_log')->where('action', 'provider.registered')->exists())->toBeTrue()
        ->and(DB::table('outbox_messages')->where('event_name', 'provider.registered')->count())->toBe(1);

    // an existing party can be registered; the same party+category twice is refused
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Garage Central', 'status' => 'ACTIVE']);
    $this->postJson('/api/v1/providers', ['category' => 'GARAGE', 'name' => 'Garage Central', 'provider_type_code' => 'BODYWORK', 'party_id' => $party->id], $this->h)->assertCreated();
    $this->postJson('/api/v1/providers', ['category' => 'GARAGE', 'name' => 'Garage Central', 'provider_type_code' => 'BODYWORK', 'party_id' => $party->id], $this->h)->assertStatus(409);
});

it('REQ-PRV-001: credentialing machine enforces transitions, reasons and keeps append-only history', function () {
    $id = prvCreate();
    $this->postJson("/api/v1/providers/{$id}/credentialing", ['to' => 'ACTIVE'], $this->h)->assertStatus(409);
    prvActivate($id);
    $this->postJson("/api/v1/providers/{$id}/credentialing", ['to' => 'SUSPENDED'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/providers/{$id}/credentialing", ['to' => 'SUSPENDED', 'reason' => 'Licence lapsed'], $this->h)->assertOk()->assertJsonPath('data.credentialing_status', 'SUSPENDED');
    $this->postJson("/api/v1/providers/{$id}/credentialing", ['to' => 'ACTIVE'], $this->h)->assertOk();
    $this->postJson("/api/v1/providers/{$id}/credentialing", ['to' => 'TERMINATED', 'reason' => 'Fraud confirmed'], $this->h)->assertOk();
    $this->postJson("/api/v1/providers/{$id}/credentialing", ['to' => 'ACTIVE'], $this->h)->assertStatus(409);

    $hist = $this->getJson("/api/v1/providers/{$id}/credentialing", $this->h)->assertOk()->json('data');
    expect(array_column($hist, 'to_status'))->toBe(['PROSPECT', 'APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE', 'SUSPENDED', 'ACTIVE', 'TERMINATED']);
    expect(fn () => DB::transaction(fn () => DB::table('provider_credentialing_events')->where('provider_profile_id', $id)->delete()))->toThrow(QueryException::class);
});

it('REQ-PRV-001: provider → facility → specialty → service tree', function () {
    $id = prvCreate();
    $svc = $this->postJson('/api/v1/medical-services', ['code' => 'CONS_GP', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT'], $this->h)->assertCreated()->json('data.id');
    $fac = $this->postJson("/api/v1/providers/{$id}/facilities", ['code' => 'MAIN', 'name' => 'Site principal', 'specialties' => ['general_practice', 'PAEDIATRICS']], $this->h)
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/provider-facilities/{$fac}/services", ['medical_service_id' => $svc, 'specialty_code' => 'CARDIOLOGY'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/provider-facilities/{$fac}/services", ['medical_service_id' => $svc, 'specialty_code' => 'GENERAL_PRACTICE'], $this->h)->assertCreated();

    $tree = $this->getJson("/api/v1/providers/{$id}", $this->h)->assertOk()->json('data');
    expect($tree['facilities'][0]['specialties'])->toBe(['GENERAL_PRACTICE', 'PAEDIATRICS'])
        ->and($tree['facilities'][0]['services'][0]['code'])->toBe('CONS_GP');

    // provider code mapping
    $this->postJson("/api/v1/providers/{$id}/code-mappings", ['provider_code' => 'C-01', 'medical_service_id' => $svc], $this->h)->assertCreated();
    $other = $this->postJson('/api/v1/medical-services', ['code' => 'XRAY', 'name' => 'X-ray', 'category_code' => 'IMAGING'], $this->h)->json('data.id');
    $this->postJson("/api/v1/providers/{$id}/code-mappings", ['provider_code' => 'C-01', 'medical_service_id' => $other], $this->h)->assertStatus(409);
});

it('REQ-PRV-002: insurer networks with dated memberships for ACTIVE providers only', function () {
    $id = prvCreate();
    $net = $this->postJson('/api/v1/provider-networks', ['code' => 'GOLD', 'name' => 'Réseau Or', 'network_type_code' => 'PREFERRED'], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/provider-networks/{$net}/members", ['provider_id' => $id, 'effective_from' => '2026-01-01'], $this->h)->assertStatus(409);
    prvActivate($id);
    $m = $this->postJson("/api/v1/provider-networks/{$net}/members", ['provider_id' => $id, 'effective_from' => '2026-01-01'], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/provider-networks/{$net}/members", ['provider_id' => $id, 'effective_from' => '2026-06-01'], $this->h)->assertStatus(409);

    expect($this->getJson("/api/v1/provider-networks/{$net}/members?as_of=2026-03-01", $this->h)->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/provider-networks/{$net}/members?as_of=2025-12-31", $this->h)->json('data'))->toHaveCount(0);

    $this->postJson("/api/v1/provider-network-memberships/{$m}/end", ['effective_to' => '2026-07-01', 'reason' => 'Contract not renewed'], $this->h)->assertOk();
    expect($this->getJson("/api/v1/provider-networks/{$net}/members?as_of=2026-08-01", $this->h)->json('data'))->toHaveCount(0);

    // A garage cannot join a HEALTH network; networks are tenant-scoped.
    $g = prvCreate('GARAGE', 'Garage Akwa');
    prvActivate($g);
    $this->postJson("/api/v1/provider-networks/{$net}/members", ['provider_id' => $g, 'effective_from' => '2026-01-01'], $this->h)->assertStatus(422);
    $other = makeAuthTestTenant('prv-other');
    $this->getJson("/api/v1/provider-networks/{$net}/members", ['X-Tenant-ID' => $other->id])->assertStatus(403);
});

it('REQ-PRV-002: contracts with versioned, maker-checker tariffs that freeze on approval', function () {
    $id = prvCreate();
    prvActivate($id);
    $svc = $this->postJson('/api/v1/medical-services', ['code' => 'CONS_SPEC', 'name' => 'Specialist consultation', 'category_code' => 'OUTPATIENT'], $this->h)->json('data.id');
    $net = $this->postJson('/api/v1/provider-networks', ['code' => 'STD', 'name' => 'Standard', 'network_type_code' => 'OPEN'], $this->h)->json('data.id');
    $c = $this->postJson("/api/v1/provider-networks/{$net}/contracts", ['provider_id' => $id, 'contract_number' => 'PC-001', 'effective_from' => '2026-01-01', 'settlement_mode' => 'BOTH'], $this->h)
        ->assertCreated()->json('data.id');

    $bad = ['effective_from' => '2026-01-01', 'lines' => [['medical_service_id' => $svc, 'price_minor' => 10000, 'contracted_price_minor' => 12000, 'insurer_share_percent' => 80]]];
    $this->postJson("/api/v1/provider-contracts/{$c}/tariffs", $bad, $this->h)->assertStatus(422);

    $v1 = $this->postJson("/api/v1/provider-contracts/{$c}/tariffs", ['effective_from' => '2026-01-01', 'lines' => [
        ['medical_service_id' => $svc, 'price_minor' => 20000, 'contracted_price_minor' => 15000, 'copay_minor' => 3000, 'insurer_share_percent' => 80],
    ]], $this->h)->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

    // maker cannot approve
    $this->postJson("/api/v1/provider-tariffs/{$v1}/approve", [], $this->h)->assertStatus(403);
    $checker = makeAuthTestUser($this->tenant, PRV_ALL);
    Passport::actingAs($checker, [], 'api');
    $this->postJson("/api/v1/provider-tariffs/{$v1}/approve", [], $this->h)->assertOk()->assertJsonPath('data.status', 'APPROVED');
    expect(fn () => DB::transaction(fn () => DB::table('provider_tariff_lines')->where('provider_tariff_version_id', $v1)->update(['price_minor' => 1])))->toThrow(QueryException::class);

    Passport::actingAs($this->admin, [], 'api');
    $v2 = $this->postJson("/api/v1/provider-contracts/{$c}/tariffs", ['effective_from' => '2026-07-01', 'lines' => [
        ['medical_service_id' => $svc, 'price_minor' => 22000, 'contracted_price_minor' => 17000, 'insurer_share_percent' => 85],
    ]], $this->h)->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');
    Passport::actingAs($checker, [], 'api');
    $this->postJson("/api/v1/provider-tariffs/{$v2}/approve", [], $this->h)->assertOk();

    $this->getJson("/api/v1/provider-contracts/{$c}/price?medical_service_id={$svc}&as_of=2026-03-01", $this->h)
        ->assertOk()->assertJsonPath('data.contracted_price_minor', 15000)->assertJsonPath('data.version', 1);
    $this->getJson("/api/v1/provider-contracts/{$c}/price?medical_service_id={$svc}&as_of=2026-08-01", $this->h)
        ->assertOk()->assertJsonPath('data.contracted_price_minor', 17000)->assertJsonPath('data.version', 2);
});

it('REQ-PRV-004: garages / adjusters / experts are canonical partners with explicit relationships to insurers', function () {
    $expert = prvCreate('EXPERT', 'Cabinet Expertise Douala');
    $insurer = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Assureur SA', 'status' => 'ACTIVE']);
    $this->postJson("/api/v1/providers/{$expert}/relationships", ['to_party_id' => $insurer->id, 'type' => 'PANEL_EXPERT_FOR', 'valid_from' => '2026-01-01'], $this->h)->assertCreated();
    $this->postJson("/api/v1/providers/{$expert}/relationships", ['to_party_id' => $insurer->id, 'type' => 'PANEL_EXPERT_FOR'], $this->h)->assertStatus(409);
    $this->postJson("/api/v1/providers/{$expert}/relationships", ['to_party_id' => $insurer->id, 'type' => 'BEST_FRIENDS'], $this->h)->assertStatus(422);

    $rel = DB::table('party_relationships')->where('to_party_id', $insurer->id)->first();
    expect($rel->type)->toBe('PANEL_EXPERT_FOR')->and($rel->tenant_id)->toBe($this->tenant->id)
        ->and(DB::table('partners')->where('party_id', $rel->from_party_id)->value('type'))->toBe('EXPERT');
    expect($this->getJson("/api/v1/providers/{$expert}", $this->h)->json('data.relationships'))->toHaveCount(1);
});

it('permissions gate the provider API', function () {
    Passport::actingAs(makeAuthTestUser($this->tenant, ['providers.view']), [], 'api');
    $this->getJson('/api/v1/providers', $this->h)->assertOk();
    $this->postJson('/api/v1/providers', ['category' => 'HEALTH', 'name' => 'X', 'provider_type_code' => 'CLINIC'], $this->h)->assertStatus(403);
    $this->postJson('/api/v1/provider-networks', ['code' => 'X', 'name' => 'X', 'network_type_code' => 'OPEN'], $this->h)->assertStatus(403);
});
