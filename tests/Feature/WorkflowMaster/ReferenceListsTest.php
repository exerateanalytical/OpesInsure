<?php

declare(strict_types=1);

/*
 * Workflow Institutional Data Master v1 — reference-list domains (wm2): geography, party_roles,
 * beneficiary_relationships, legal_entity_types, occupations_industries, vehicles, property_engineering,
 * marine_cargo, agriculture_livestock, aviation.
 * REQ-MDM-003 (merge by code, aliases, no duplicates), REQ-PTY-002 (party roles), REQ-DUP-013, workflow data master rules
 * (PENDING_SOURCE placeholders carry no invented values; provenance OWNER_WORKFLOW_DATA_MASTER_V1; idempotent seeding).
 */

use App\Application\Customers\Roles\PartyRoleService;
use App\Application\MasterData\MasterDataSearch;
use App\Application\MasterData\VehicleMasterSource;
use App\Application\MasterData\WorkflowDataStatuses;
use App\Application\Vehicles\VehicleUsageMapper;
use App\Domain\Tenancy\TenantContext;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function wm2Master(): array
{
    return json_decode((string) file_get_contents(database_path('data/workflow_institutional_data_master_2026.json')), true, flags: JSON_THROW_ON_ERROR);
}

function wm2Codes(string $domain, string $list): array
{
    return DB::table('master_data_values')->where(['domain_code' => $domain, 'list_code' => $list])->whereNull('tenant_id')->pluck('code')->all();
}

function wm2Hit(string $domain, string $list, string $q): ?string
{
    return app(MasterDataSearch::class)->search($domain, $list, $q, null, null, 5)[0]['code'] ?? null;
}

const WM2_DOMAINS = ['geography', 'party_roles', 'beneficiary_relationships', 'legal_entity_types', 'occupations_industries', 'vehicles',
    'property_engineering', 'marine_cargo', 'agriculture_livestock', 'aviation'];

it('uses the same 7 status codes as the dataset and records a status for every owner reference item [REQ-MDM-003]', function () {
    (new MasterDataSeeder)->run();
    $master = wm2Master();
    expect(WorkflowDataStatuses::CODES)->toBe($master['dataset']['status_codes']);

    $expected = [];
    foreach (WM2_DOMAINS as $d) {
        foreach ($master[$d] as $k => $v) {
            $item = match (true) {
                in_array($k, ['values', 'status'], true) => 'values',
                str_ends_with($k, '_status') => substr($k, 0, -7),
                default => $k,
            };
            $expected["$d.$item"] = true;
        }
    }
    $rows = DB::table('master_data_workflow_statuses')->pluck('status', 'item_key');
    expect(array_diff(array_keys($expected), $rows->keys()->all()))->toBe([])
        ->and($rows->every(fn ($s) => in_array($s, WorkflowDataStatuses::CODES, true)))->toBeTrue()
        ->and(DB::table('master_data_workflow_statuses')->where('source', '!=', 'OWNER_WORKFLOW_DATA_MASTER_V1')->count())->toBe(0);

    // Raw owner wording kept; mapped onto the 7 codes.
    expect(DB::table('master_data_workflow_statuses')->where('item_key', 'vehicles.manual_fallback')->first())
        ->owner_status->toBe('PENDING_MASTER_REVIEW')->status->toBe('UNVERIFIED');
})->group('REQ-MDM-003');

it('merges geography by code: 10 regions with owner names as aliases, hierarchy levels mapped onto existing lists, CEMAC flagged [REQ-MDM-003]', function () {
    (new MasterDataSeeder)->run();

    expect(wm2Codes('geography', 'cameroon_region'))->toHaveCount(10)
        ->and(wm2Hit('geography', 'cameroon_region', 'Far North'))->toBe('EXTREME_NORD')
        ->and(wm2Hit('geography', 'cameroon_region', 'north west'))->toBe('NORD_OUEST');

    $levels = DB::table('master_data_values')->where(['domain_code' => 'geography', 'list_code' => 'admin_level'])->orderBy('sort_order')->get();
    expect($levels->pluck('code')->all())->toBe(['REGION', 'DIVISION', 'SUBDIVISION', 'COUNCIL', 'CITY', 'LOCALITY'])
        ->and(json_decode($levels[1]->attributes, true)['maps_to_list'])->toBe('cameroon_department')
        ->and($levels[1]->source_reference)->toBe('OWNER_WORKFLOW_DATA_MASTER_V1')
        ->and(wm2Hit('geography', 'admin_level', 'departement'))->toBe('DIVISION');

    $cemac = DB::table('master_data_values')->where(['domain_code' => 'geography', 'list_code' => 'country'])->whereIn('code', wm2Master()['geography']['cemac_countries'])->get();
    expect($cemac)->toHaveCount(6)->and($cemac->every(fn ($c) => json_decode($c->attributes, true)['cemac'] === true))->toBeTrue();

    // Full administrative dataset / hazard zones / holidays: pending, nothing invented.
    expect(wm2Codes('geography', 'cameroon_arrondissement'))->toBe([])
        ->and(app(WorkflowDataStatuses::class)->forList('geography', 'cameroon_arrondissement')['status'])->toBe('PENDING_SOURCE')
        ->and(DB::table('master_data_workflow_statuses')->where('item_key', 'geography.public_holidays')->value('status'))->toBe('PENDING_SOURCE');
})->group('REQ-MDM-003');

it('maps legal entity types, beneficiary relationships, property, cargo lists by code without duplicates [REQ-MDM-003]', function () {
    (new MasterDataSeeder)->run();

    expect(wm2Hit('organizations', 'legal_entity_type', 'PARTNERSHIP'))->toBe('SNC')
        ->and(wm2Hit('organizations', 'legal_entity_type', 'PUBLIC_ENTITY'))->toBe('PUBLIC_ESTABLISHMENT')
        ->and(wm2Codes('organizations', 'legal_entity_type'))->toContain('RELIGIOUS_ORGANIZATION', 'TRUST_OR_SIMILAR', 'SARLU', 'GIE')
        ->and(wm2Codes('persons', 'relationship'))->toContain('OTHER_RELATIVE', 'TRUST', 'ORGANIZATION', 'SPOUSE', 'ESTATE')
        ->and(wm2Hit('property', 'property_type', 'RELIGIOUS_BUILDING'))->toBe('PLACE_OF_WORSHIP')
        ->and(wm2Hit('property', 'property_type', 'APARTMENT'))->toBe('APARTMENT_BUILDING')
        ->and(wm2Hit('property', 'occupancy', 'TENANT_OCCUPIED'))->toBe('TENANTED')
        ->and(wm2Hit('property', 'construction_material', 'WOOD'))->toBe('TIMBER')
        ->and(wm2Hit('cargo', 'transport_mode', 'INLAND_WATERWAY'))->toBe('INLAND_WATER')
        ->and(wm2Codes('cargo', 'incoterm'))->toHaveCount(11);

    // Every owner code resolves (exact code or alias) in its target list.
    $m = wm2Master();
    foreach ([['property', 'property_type', $m['property_engineering']['property_types']], ['property', 'occupancy', $m['property_engineering']['occupancy_types']],
        ['property', 'construction_material', $m['property_engineering']['construction_materials']], ['cargo', 'incoterm', $m['marine_cargo']['incoterms']],
        ['cargo', 'transport_mode', $m['marine_cargo']['transport_modes']], ['persons', 'relationship', $m['beneficiary_relationships']['values']],
        ['organizations', 'legal_entity_type', $m['legal_entity_types']['values']]] as [$d, $l, $codes]) {
        foreach ($codes as $code) {
            expect(wm2Hit($d, $l, $code))->not->toBeNull("$d.$l $code");
        }
    }

    $dupes = DB::table('master_data_values')->whereNull('tenant_id')->select('list_id', 'code')->groupBy('list_id', 'code')->havingRaw('count(*) > 1')->count();
    expect($dupes)->toBe(0);
    // New values carry the owner provenance; pre-existing ones keep theirs.
    expect(DB::table('master_data_values')->where(['domain_code' => 'property', 'list_code' => 'occupancy', 'code' => 'INDUSTRIAL'])->value('source_reference'))->toBe('OWNER_WORKFLOW_DATA_MASTER_V1')
        ->and(DB::table('master_data_values')->where(['domain_code' => 'organizations', 'list_code' => 'legal_entity_type', 'code' => 'SA'])->value('source_reference'))->not->toBe('OWNER_WORKFLOW_DATA_MASTER_V1');
})->group('REQ-MDM-003');

it('keeps existing occupation / industry values but flags the catalogues PENDING_SOURCE with UNVERIFIED values [REQ-MDM-003]', function () {
    (new MasterDataSeeder)->run();

    expect(count(wm2Codes('occupations', 'occupation')))->toBeGreaterThan(200);
    $s = app(WorkflowDataStatuses::class)->forList('occupations', 'occupation');
    expect($s['status'])->toBe('PENDING_SOURCE')->and($s['values_status'])->toBe('UNVERIFIED');

    $this->getJson('/api/v1/master-data/occupations/search?list=occupation&q=doctor')->assertOk()
        ->assertJsonPath('data.data_status', 'PENDING_SOURCE')->assertJsonPath('data.values_status', 'UNVERIFIED');
})->group('REQ-MDM-003');

it('serves PENDING_SOURCE placeholder lists as empty with their status and Other / Not listed [REQ-DUP-013]', function () {
    (new MasterDataSeeder)->run();
    $user = makeAuthTestUser(makeAuthTestTenant('WM2'), []);
    Passport::actingAs($user);

    foreach ([['construction', 'engineering_hazard_class'], ['agriculture', 'crop_variety']] as [$d, $l]) {
        expect(wm2Codes($d, $l))->toBe([]);
        $this->getJson("/api/v1/master-data/$d/search?list=$l")->assertOk()
            ->assertJsonPath('data.values', [])->assertJsonPath('data.allow_other', true)->assertJsonPath('data.data_status', 'PENDING_SOURCE');
        $list = collect($this->getJson("/api/v1/master-data/$d")->assertOk()->json('data.lists'))->firstWhere('code', $l);
        expect($list['data_status'])->toBe('PENDING_SOURCE')->and($list['values'])->toBe([]);
    }
    // Free text on an empty pending list goes to the review queue instead of failing.
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'construction', 'list' => 'engineering_hazard_class', 'text' => 'Tunnelling'])->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED');
})->group('REQ-DUP-013');

it('seeds idempotently and never overwrites admin-edited values or statuses [REQ-MDM-003]', function () {
    (new MasterDataSeeder)->run();
    $count = DB::table('master_data_values')->count();
    $aliases = DB::table('master_data_aliases')->count();
    DB::table('master_data_values')->where(['domain_code' => 'property', 'list_code' => 'occupancy', 'code' => 'MIXED'])->update(['label_en' => 'Mixed (admin)', 'admin_modified_at' => now()]);
    DB::table('master_data_workflow_statuses')->where('item_key', 'aviation.airport_master')->update(['status' => 'VERIFIED', 'admin_modified_at' => now()]);

    (new MasterDataSeeder)->run();

    expect(DB::table('master_data_values')->count())->toBe($count)
        ->and(DB::table('master_data_aliases')->count())->toBe($aliases)
        ->and(DB::table('master_data_values')->where(['domain_code' => 'property', 'list_code' => 'occupancy', 'code' => 'MIXED'])->value('label_en'))->toBe('Mixed (admin)')
        ->and(DB::table('master_data_workflow_statuses')->where('item_key', 'aviation.airport_master')->value('status'))->toBe('VERIFIED');
})->group('REQ-MDM-003');

it('shows admins the catalogue statuses, permission-gated [REQ-MDM-003]', function () {
    (new MasterDataSeeder)->run();
    $tenant = makeAuthTestTenant('WM2A');
    app(TenantContext::class)->set($tenant->id);

    Passport::actingAs(makeAuthTestUser($tenant, []));
    $this->getJson('/api/v1/master-data/workflow-statuses', tenantHeader($tenant))->assertForbidden();

    Passport::actingAs(makeAuthTestUser($tenant, ['master_data.workflow_status.view']));
    $res = $this->getJson('/api/v1/master-data/workflow-statuses?status=PENDING_SOURCE', tenantHeader($tenant))->assertOk();
    $rows = collect($res->json('data'))->keyBy('key');
    expect($rows['property_engineering.engineering_hazard_classes']['value_count'])->toBe(0)
        ->and($rows['property_engineering.engineering_hazard_classes']['production_ready'])->toBeFalse()
        ->and($rows['aviation.airport_master']['values_status'])->toBe('UNVERIFIED')
        ->and($res->json('meta.status_codes'))->toHaveCount(7);
})->group('REQ-MDM-003');

it('reconciles the owner party roles with the platform role set; CIMA codes stay null [REQ-PTY-002]', function () {
    expect(array_diff(wm2Master()['party_roles']['values'], array_keys(PartyRoleService::ROLES)))->toBe([])
        ->and(PartyRoleService::OWNER_WORKFLOW_ROLES)->toBe(wm2Master()['party_roles']['values'])
        ->and(collect(PartyRoleService::ROLES)->pluck('cima_code')->filter()->all())->toBe([])
        ->and(PartyRoleService::canonical('ubo'))->toBe('BENEFICIAL_OWNER')
        ->and(PartyRoleService::canonical('Dependent'))->toBe('DEPENDANT');
    $cat = collect(app(PartyRoleService::class)->catalogue())->keyBy('code');
    expect($cat['SETTLOR']['in_owner_workflow_master'])->toBeTrue()->and($cat['LIFE_ASSURED']['in_owner_workflow_master'])->toBeFalse()
        ->and($cat['BENEFICIAL_OWNER']['aliases'])->toContain('UBO');
})->group('REQ-PTY-002');

it('resolves owner vehicle usage classes onto the 28 canonical usages [REQ-MDM-003]', function () {
    test()->artisan('opesinsure:seed-vehicles')->assertExitCode(0);

    expect(DB::table('vehicle_reference_values')->where('group', 'usage')->count())->toBe(28);
    $mapping = [];
    foreach (wm2Master()['vehicles']['usage_classes'] as $code) {
        if (in_array($code, ['OTHER', 'SPECIAL_PURPOSE'], true)) {
            continue; // Other / Not listed review queue
        }
        $mapping[$code] = VehicleMasterSource::canonical('usage', $code);
        expect(app(VehicleMasterSource::class)->exists('usage', $code, null))->toBeTrue($code);
    }
    expect($mapping['CORPORATE'])->toBe('COMPANY')->and($mapping['COMMERCIAL_PASSENGER'])->toBe('PUBLIC_TRANSPORT')
        ->and(VehicleUsageMapper::toUsageType('PRIVATE'))->toBe('PRIVATE')
        ->and(VehicleUsageMapper::toUsageType('MOTORCYCLE_PRIVATE'))->toBe('PRIVATE')
        ->and(VehicleUsageMapper::toUsageType('MOTORCYCLE_COMMERCIAL'))->toBe('COMMERCIAL');

    $hit = collect(app(VehicleMasterSource::class)->search('usage', 'CORPORATE', null))->first();
    expect($hit['code'])->toBe('COMPANY');
})->group('REQ-MDM-003');
