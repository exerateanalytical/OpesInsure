<?php

declare(strict_types=1);

/**
 * REQ-RSK-001 (insured objects: types, master-data link, versions, WF-077 duplicate check)
 * REQ-SRC-001 (global search, RBAC data scope + tenant isolation)
 */

use App\Application\Risks\RiskAssetTypes;
use App\Models\Party;
use App\Models\Policy;
use App\Models\RiskAsset;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b4dCustomer(Tenant $tenant, string $name): Party
{
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => $name, 'status' => 'ACTIVE']);
    TenantCustomer::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'customer_number' => 'CUST-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE']);

    return $party;
}

function b4dStaff(Tenant $tenant, array $perms = ['customers.read', 'policies.read', 'risk_assets.read', 'claims.view', 'quotes.read', 'documents.read']): User
{
    return makeAuthTestUser($tenant, $perms, 'OPERATIONS_OFFICER'); // unknown code => TENANT scope
}

function b4dVehicle(Tenant $tenant, Party $party, string $plate, string $vin, string $name = 'Toyota Corolla'): RiskAsset
{
    return app(\App\Application\Risks\RiskAssetService::class)->create($tenant, $party, ['type' => 'VEHICLE', 'display_name' => $name, 'facts' => ['make' => 'TOYOTA', 'model' => 'COROLLA', 'year' => 2019, 'registration_number' => $plate, 'vin' => $vin]], null);
}

// ---------- REQ-RSK-001 ----------

it('REQ-RSK-001 publishes one insured-object type catalogue linked to master data', function () {
    $tenant = makeAuthTestTenant();
    Passport::actingAs(b4dStaff($tenant));
    $types = collect($this->getJson('/api/v1/risk-asset-types', tenantHeader($tenant))->assertOk()->json('data'))->keyBy('code');

    foreach (['VEHICLE', 'PROPERTY', 'PERSON', 'CARGO', 'EQUIPMENT', 'TRAVELLER', 'HEALTH_MEMBER', 'LIVESTOCK'] as $code) {
        expect($types)->toHaveKey($code);
    }
    expect($types['VEHICLE']['master_data_link'])->toBe('risk_asset_vehicles')
        ->and($types['CARGO']['line_code'])->toBe('CARGO')
        ->and($types['PROPERTY']['schema_available'])->toBeTrue();
    expect(RiskAssetTypes::isVehicle('VEHICLE'))->toBeTrue()->and(RiskAssetTypes::isVehicle('CARGO'))->toBeFalse();
});

it('REQ-RSK-001 accepts new object types through the canonical risk-assets API and versions every change', function () {
    $tenant = makeAuthTestTenant();
    $party = b4dCustomer($tenant, 'Cargo Owner SA');
    Passport::actingAs(b4dStaff($tenant));
    $h = tenantHeader($tenant);

    $id = $this->postJson('/api/v1/risk-assets', ['party_id' => $party->id, 'type' => 'EQUIPMENT', 'display_name' => 'Generator 250kVA', 'facts' => ['serial' => 'GEN-1']], $h)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/risk-assets/{$id}", ['version' => 1, 'display_name' => 'Generator 250kVA (site B)', 'facts' => ['serial' => 'GEN-1', 'site' => 'B']], $h)->assertOk()->assertJsonPath('data.version', 2);

    $versions = $this->getJson("/api/v1/risk-assets/{$id}/versions", $h)->assertOk()->json('data');
    expect($versions['current_version'])->toBe(2)->and(collect($versions['versions'])->pluck('version')->all())->toBe([2, 1]);

    $v1 = $this->getJson("/api/v1/risk-assets/{$id}/versions/1", $h)->assertOk()->json('data');
    expect($v1['display_name'])->toBe('Generator 250kVA')->and($v1['facts'])->toBe(['serial' => 'GEN-1']);
    $this->getJson("/api/v1/risk-assets/{$id}/versions/9", $h)->assertNotFound();

    $this->postJson('/api/v1/risk-assets', ['party_id' => $party->id, 'type' => 'SPACESHIP', 'display_name' => 'x', 'facts' => ['a' => 1]], $h)->assertStatus(422);
});

it('REQ-RSK-001 validates non-vehicle facts against master data (unknown code rejected)', function () {
    $tenant = makeAuthTestTenant();
    $this->artisan('opesinsure:seed-master-data')->assertExitCode(0);
    $party = b4dCustomer($tenant, 'Home Owner');
    Passport::actingAs(b4dStaff($tenant));

    $this->postJson('/api/v1/risk-assets', ['party_id' => $party->id, 'type' => 'PROPERTY', 'display_name' => 'House', 'facts' => ['building_type' => 'NOT_A_REAL_CODE_XYZ']], tenantHeader($tenant))->assertStatus(422);
    $this->postJson('/api/v1/risk-assets', ['party_id' => $party->id, 'type' => 'PROPERTY', 'display_name' => 'House', 'facts' => ['address' => 'Bonapriso']], tenantHeader($tenant))->assertCreated();
});

it('REQ-RSK-001 rejects a second active asset for the same vehicle (VIN or plate, any formatting) within a tenant', function () {
    $tenant = makeAuthTestTenant();
    $a = b4dCustomer($tenant, 'Alice');
    $b = b4dCustomer($tenant, 'Bob');
    $first = b4dVehicle($tenant, $a, 'LT 123 AB', 'JT1234567890ABCDE');
    expect(DB::table('risk_asset_vehicles')->where('risk_asset_id', $first->id)->value('registration_number'))->toBe('LT 123 AB');

    Passport::actingAs(b4dStaff($tenant));
    $h = tenantHeader($tenant);
    $post = fn (array $facts) => $this->postJson('/api/v1/risk-assets', ['party_id' => $b->id, 'type' => 'VEHICLE', 'display_name' => 'Twin', 'facts' => ['make' => 'TOYOTA', 'model' => 'COROLLA', 'year' => 2019] + $facts], $h);

    $post(['registration_number' => 'lt-123-ab'])->assertStatus(409)->assertJsonValidationErrors('registration_number');
    $post(['vin' => 'jt1234567890abcde', 'registration_number' => 'CE 999 ZZ'])->assertStatus(409)->assertJsonValidationErrors('vin');
    $post(['registration_number' => 'CE 1 AA', 'vin' => 'OTHERVIN0000001'])->assertCreated();

    // Another tenant may insure the same physical vehicle.
    $other = makeAuthTestTenant();
    expect(b4dVehicle($other, b4dCustomer($other, 'Carol'), 'LT 123 AB', 'JT1234567890ABCDE')->exists)->toBeTrue();

    // Updating the original asset with its own plate is not a duplicate.
    app(\App\Application\Risks\RiskAssetService::class)->update($first, 1, ['facts' => ['make' => 'TOYOTA', 'registration_number' => 'LT123AB', 'vin' => 'JT1234567890ABCDE']], null);
    expect($first->fresh()->version)->toBe(2);
});

// ---------- REQ-SRC-001 ----------

it('REQ-SRC-001 finds customers, policies and vehicles by name, number, plate and VIN inside the tenant only', function () {
    $f = makeMobileCustomerFixture('+237670400001');
    $tenant = $f['tenant'];
    $party = b4dCustomer($tenant, 'Jean-Baptiste Mbarga');
    b4dVehicle($tenant, $party, 'LT 456 CD', 'VF1ABCDEF12345678', 'Renault Duster');
    Policy::create(['tenant_id' => $tenant->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $party->id, 'policy_number' => 'POL-SRCH-000777', 'status' => 'ACTIVE', 'coverage_starts_at' => now(), 'coverage_ends_at' => now()->addYear(), 'terms_snapshot' => []]);

    // Same names/plates in another tenant must never leak.
    $other = makeAuthTestTenant();
    $otherParty = b4dCustomer($other, 'Jean-Baptiste Mbarga');
    b4dVehicle($other, $otherParty, 'LT 456 CD', 'VF1ABCDEF12345678');

    Passport::actingAs(b4dStaff($tenant));
    $h = tenantHeaderFor($tenant);

    $plate = $this->getJson('/api/v1/search?q=lt456cd', $h)->assertOk()->json('data');
    $vehicles = collect($plate['results'])->where('type', 'vehicles');
    expect($vehicles)->toHaveCount(1)->and($vehicles->first()['registration_number'])->toBe('LT 456 CD');

    $vin = collect($this->getJson('/api/v1/search?q=VF1ABCDEF1&types[]=vehicles', $h)->json('data.results'));
    expect($vin)->toHaveCount(1);

    $name = collect($this->getJson('/api/v1/search?q=mbarga', $h)->json('data.results'));
    expect($name->where('type', 'customers'))->toHaveCount(1)
        ->and($name->where('type', 'policies')->pluck('title')->all())->toBe(['POL-SRCH-000777']);

    $policy = collect($this->getJson('/api/v1/search?q=POL-SRCH-000777', $h)->json('data.results'))->where('type', 'policies');
    expect((float) $policy->first()['score'])->toBe(1.0);

    $this->getJson('/api/v1/search?q=a', $h)->assertStatus(422);
    $this->getJson('/api/v1/search?q=abc&types[]=bogus', $h)->assertStatus(422);
});

it('REQ-SRC-001 searches only entities the caller holds a read permission for', function () {
    $tenant = makeAuthTestTenant();
    $party = b4dCustomer($tenant, 'Permission Probe');
    b4dVehicle($tenant, $party, 'SW 1 PP', 'PPVIN000000001');

    Passport::actingAs(b4dStaff($tenant, ['customers.read']));
    $data = $this->getJson('/api/v1/search?q=probe', tenantHeader($tenant))->assertOk()->json('data');
    expect($data['searched'])->toBe(['customers'])->and(collect($data['results'])->pluck('type')->unique()->all())->toBe(['customers']);
    expect($this->getJson('/api/v1/search?q=SW1PP', tenantHeader($tenant))->json('data.results'))->toBe([]);
});

it('REQ-SRC-001 a customer (OWN scope) only finds their own records and never other customers', function () {
    $f = makeMobileCustomerFixture('+237670400002');
    $tenant = $f['tenant'];
    makeMobileTestTenantCustomer($tenant, $f['party']);
    b4dVehicle($tenant, $f['party'], 'OW 11 AA', 'OWNVIN0000000001', 'My Hilux');
    $stranger = b4dCustomer($tenant, 'Stranger Danger');
    b4dVehicle($tenant, $stranger, 'OW 11 AB', 'OWNVIN0000000002', 'Their Hilux');

    Passport::actingAs($f['user']);
    $data = $this->getJson('/api/v1/search?q=OW11', tenantHeaderFor($tenant))->assertOk()->json('data');
    expect($data['scope'])->toBe('OWN')->and($data['searched'])->not->toContain('customers');
    expect(collect($data['results'])->where('type', 'vehicles')->pluck('registration_number')->all())->toBe(['OW 11 AA']);
    expect(collect($this->getJson('/api/v1/search?q=Hilux', tenantHeaderFor($tenant))->json('data.results'))->pluck('title')->all())->toBe(['My Hilux']);
});

it('REQ-SRC-001 platform administrators get no business rows (REQ-RBAC-004)', function () {
    $tenant = makeAuthTestTenant();
    b4dCustomer($tenant, 'Hidden From Admin');
    Passport::actingAs(makeAuthTestUser($tenant, ['*'], 'SYSTEM_ADMIN'));
    $data = $this->getJson('/api/v1/search?q=hidden', tenantHeader($tenant))->assertOk()->json('data');
    expect($data['searched'])->toBe([])->and($data['results'])->toBe([]);
});
