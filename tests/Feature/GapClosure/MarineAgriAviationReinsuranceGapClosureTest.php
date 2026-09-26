<?php

declare(strict_types=1);

/*
 * Gap Closure Pack v1 — files 05 (marine / cargo / agriculture / livestock / aviation) and 07 (reinsurance / co-insurance).
 * REQ-MDM-002, REQ-MDM-003 (merge by code + alias, no duplicates), REQ-REI-001 (reinsurer directory, treaty master),
 * REQ-REI-003 (facultative security gate), REQ-COI-001 (co-insurance record view), REQ-IMP-001 (directory import target),
 * REQ-DRM-003 (readiness gates).
 */

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\FinancialDistribution\ReinsuranceReference;
use App\Application\Import\ImportTargetRegistry;
use App\Application\Reinsurance\Directory\ReinsuranceDirectoryServiceProvider;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function gp5Pack(string $file): array
{
    return json_decode((string) file_get_contents(database_path("data/gap_closure_2026/$file")), true, flags: JSON_THROW_ON_ERROR);
}

/** Resolves a Gap Closure code onto a list by code or seeded alias; returns the canonical code or null. */
function gp5Resolve(string $domain, string $list, string $code): ?string
{
    $v = DB::table('master_data_values')->where(['domain_code' => $domain, 'list_code' => $list, 'code' => $code])->value('code');
    if ($v) {
        return $v;
    }

    return DB::table('master_data_aliases as a')->join('master_data_values as v', 'v.id', '=', 'a.value_id')
        ->where(['v.domain_code' => $domain, 'v.list_code' => $list])->whereRaw('upper(a.alias) = ?', [$code])->value('v.code');
}

it('merges every Gap Closure 05 normalized list into the master data without duplicates [REQ-MDM-003]', function () {
    (new MasterDataSeeder)->run();
    $p = gp5Pack('05_marine_agriculture_livestock_aviation.json');
    $targets = [
        ['cargo', 'incoterm', $p['marine_cargo']['incoterms']], ['cargo', 'transport_mode', $p['marine_cargo']['transport_modes']],
        ['cargo', 'packaging', $p['marine_cargo']['packaging_types']], ['cargo', 'cargo_category', $p['marine_cargo']['cargo_families']],
        ['cargo', 'risk_class', $p['marine_cargo']['risk_classes']], ['agriculture', 'crop_family', $p['agriculture']['crop_families']],
        ['agriculture', 'farm_type', $p['agriculture']['farm_types']], ['agriculture', 'peril', $p['agriculture']['perils']],
        ['livestock', 'animal_type', $p['livestock']['species']], ['livestock', 'production_system', $p['livestock']['production_systems']],
        ['livestock', 'peril', $p['livestock']['perils']], ['aviation_insurance', 'airframe_category', $p['aviation']['aircraft_categories']],
        ['aviation_insurance', 'aircraft_use', $p['aviation']['usage_types']],
    ];
    foreach ($targets as [$d, $l, $codes]) {
        foreach ($codes as $c) {
            expect(gp5Resolve($d, $l, $c))->not->toBeNull("$d.$l: $c unresolved");
        }
    }
    // Aliases, not duplicate values.
    expect(gp5Resolve('cargo', 'packaging', 'BAG'))->toBe('BAG_SACK')
        ->and(gp5Resolve('cargo', 'cargo_category', 'LIVE_ANIMALS'))->toBe('LIVESTOCK')
        ->and(gp5Resolve('aviation_insurance', 'aircraft_use', 'COMMERCIAL_CARGO'))->toBe('CARGO')
        ->and(gp5Resolve('livestock', 'animal_type', 'BEE'))->toBe('BEES');
    expect(DB::table('master_data_values')->where(['domain_code' => 'cargo', 'list_code' => 'packaging', 'code' => 'BAG'])->exists())->toBeFalse();
    // Every crop carries its Gap Closure family; the family exists.
    $families = DB::table('master_data_values')->where(['domain_code' => 'agriculture', 'list_code' => 'crop_family'])->pluck('code')->all();
    foreach (DB::table('master_data_values')->where(['domain_code' => 'agriculture', 'list_code' => 'crop'])->whereNull('tenant_id')->get() as $crop) {
        expect($families)->toContain(json_decode((string) $crop->attributes, true)['crop_family'] ?? null);
    }
    // Provenance on new values.
    $risk = DB::table('master_data_values')->where(['domain_code' => 'cargo', 'list_code' => 'risk_class', 'code' => 'HAZARDOUS'])->first();
    expect($risk->source_reference)->toContain('05_marine_agriculture_livestock_aviation.json')
        ->and(json_decode((string) $risk->attributes, true)['source'])->toBe('GAP_CLOSURE_PACK_V1');

    // Idempotent.
    $before = DB::table('master_data_values')->count();
    $aliases = DB::table('master_data_aliases')->count();
    (new MasterDataSeeder)->run();
    expect(DB::table('master_data_values')->count())->toBe($before)->and(DB::table('master_data_aliases')->count())->toBe($aliases);
});

it('keeps the Gap Closure 05 gated masters as empty / unverified placeholders with their status [REQ-MDM-002]', function () {
    (new MasterDataSeeder)->run();
    foreach ([['cargo', 'route'], ['agriculture', 'agro_zone'], ['agriculture', 'crop_variety']] as [$d, $l]) {
        $list = DB::table('master_data_lists')->where(['domain_code' => $d, 'code' => $l])->first();
        expect($list)->not->toBeNull()->and((bool) $list->allow_other)->toBeTrue();
        expect(DB::table('master_data_values')->where(['domain_code' => $d, 'list_code' => $l])->whereNull('tenant_id')->where('code', '!=', 'OTHER')->count())->toBe(0);
    }
    $statuses = DB::table('master_data_workflow_statuses')->pluck('status', 'item_key');
    foreach (['marine_cargo.port_location_master', 'agriculture_livestock.crop_variety', 'agriculture_livestock.agro_zone_master', 'agriculture_livestock.breed_master',
        'aviation.aircraft_make_model', 'aviation.airport_master', 'aviation.pilot_license_mapping'] as $gate) {
        expect($statuses[$gate] ?? null)->toBe('PENDING_SOURCE', $gate);
    }
    expect($statuses['marine_cargo.route_master'])->toBe('CONFIG_REQUIRED')
        ->and($statuses['marine_cargo.packaging_catalogue'])->toBe('PLATFORM_NORMALIZED')
        ->and($statuses['aviation.aircraft_categories'])->toBe('PLATFORM_NORMALIZED');
    // Gated lists stay importable through the generic pipeline target.
    expect(app(ImportTargetRegistry::class)->get('master_data_values')->params(['domain' => 'cargo', 'list' => 'route']))->toBe(['domain' => 'cargo', 'list' => 'route']);
});

it('maps every Gap Closure 07 treaty type, role and arrangement status onto the engine [REQ-REI-001][REQ-COI-001]', function () {
    $p = gp5Pack('07_reinsurance_coinsurance.json');
    foreach ($p['treaty_types'] as $t) {
        expect(ReinsuranceReference::treatyFamily($t))->toBeIn(ReinsuranceReference::TREATY_TYPES);
    }
    foreach ($p['coinsurance']['roles'] as $r) {
        expect(ReinsuranceReference::coinsuranceRole($r))->toBeIn(['LEAD', 'FOLLOWER']);
    }
    foreach ($p['coinsurance']['arrangement_statuses'] as $s) {
        expect(ReinsuranceReference::COINSURANCE_STATUSES)->toHaveKey($s);
    }
    expect(ReinsuranceReference::coinsuranceWorkflowStatus('DRAFT', null))->toBe('PROPOSED')
        ->and(ReinsuranceReference::coinsuranceWorkflowStatus('ACTIVE', '2020-01-01', '2026-09-26'))->toBe('EXPIRED')
        ->and(ReinsuranceReference::coinsuranceWorkflowStatus('ACTIVE', '2030-01-01', '2026-09-26'))->toBe('ACTIVE')
        ->and(ReinsuranceReference::coinsuranceWorkflowStatus('TERMINATED', null))->toBe('CANCELLED');
});

describe('reinsurer directory and approved-security gate', function () {
    beforeEach(function () {
        $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
        $this->tenant = $this->f['tenant'];
        $this->h = ['X-Tenant-Id' => $this->tenant->id];
        $this->maker = makeAuthTestUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage']);
        $this->checker = makeAuthTestUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.approve']);
        $this->security = makeAuthTestUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.reinsurers.approve_security']);
    });

    it('creates directory entries PENDING_VERIFICATION and blocks treaty activation until approved as security [REQ-REI-001]', function () {
        Passport::actingAs($this->maker, [], 'api');
        $re = $this->postJson('/api/v1/reinsurance/reinsurers', ['code' => 'AFRICARE', 'name' => 'Africa Re', 'country_code' => 'NG', 'regulator' => 'NAICOM',
            'ratings' => [['agency' => 'AM Best', 'rating' => 'A']], 'website' => 'https://www.africa-re.com', 'approved_security_status' => 'CIMA_APPROVED'], $this->h)
            ->assertCreated()->json('data');
        expect($re['approved_security_status'])->toBe('PENDING_VERIFICATION')->and($re['verification_status'])->toBe('PENDING_VERIFICATION');

        $treaty = $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'PRXL26', 'name' => 'Property per-risk XL', 'treaty_type' => 'PER_RISK_EXCESS_OF_LOSS',
            'currency' => 'XAF', 'treaty_number' => 'T-2026-01', 'territories' => ['CAMEROON'], 'bordereau_frequency' => 'QUARTERLY'], $this->h)->assertCreated()->json('data');
        expect($treaty['treaty_type'])->toBe('EXCESS_OF_LOSS')->and($treaty['treaty_form'])->toBe('PER_RISK_EXCESS_OF_LOSS');
        $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'BAD', 'name' => 'x', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF', 'bordereau_frequency' => 'DAILY'], $this->h)
            ->assertUnprocessable();

        $version = $this->postJson("/api/v1/reinsurance/treaties/{$treaty['id']}/versions", ['effective_from' => '2026-01-01',
            'layers' => [['layer' => 1, 'attachment_minor' => 10_000_000, 'limit_minor' => 90_000_000, 'rate_percent' => 2.5]],
            'claims_cooperation_threshold_minor' => 50_000_000, 'cash_call_threshold_minor' => 100_000_000, 'profit_commission' => ['percent' => 20],
            'participants' => [['reinsurer_id' => $re['id'], 'share_percent' => 100, 'is_lead' => true]]], $this->h)->assertCreated()->json('data');
        expect((int) $version['cash_call_threshold_minor'])->toBe(100_000_000);

        Passport::actingAs($this->checker, [], 'api');
        $this->postJson("/api/v1/reinsurance/treaty-versions/{$version['id']}/activate", ['reason' => 'Signed slip'], $this->h)
            ->assertUnprocessable()->assertJsonPath('errors.participants.0', fn ($m) => str_contains($m, 'AFRICARE'));

        // The maker cannot approve security; the security approver can. CIMA_APPROVED needs its source.
        Passport::actingAs($this->maker, [], 'api');
        $this->postJson("/api/v1/reinsurance/reinsurers/{$re['id']}/security", ['approved_security_status' => 'TENANT_APPROVED', 'reason' => 'Board list'], $this->h)->assertForbidden();
        Passport::actingAs($this->security, [], 'api');
        $this->postJson("/api/v1/reinsurance/reinsurers/{$re['id']}/security", ['approved_security_status' => 'CIMA_APPROVED', 'reason' => 'CIMA list'], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/reinsurance/reinsurers/{$re['id']}/security", ['approved_security_status' => 'TENANT_APPROVED', 'reason' => 'Approved by the security committee'], $this->h)
            ->assertOk()->assertJsonPath('data.approved_security_status', 'TENANT_APPROVED')->assertJsonPath('data.verification_status', 'TENANT_APPROVED');

        Passport::actingAs($this->checker, [], 'api');
        $this->postJson("/api/v1/reinsurance/treaty-versions/{$version['id']}/activate", ['reason' => 'Signed slip'], $this->h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $dir = $this->getJson('/api/v1/reinsurance/directory?security=APPROVED', $this->h)->assertOk()->json('data');
        expect($dir)->toHaveCount(1)->and($dir[0]['ratings'][0]['agency'])->toBe('AM Best')->and($dir[0]['approved_security'])->toBeTrue();
        $this->getJson('/api/v1/reinsurance/reference', $this->h)->assertOk()->assertJsonPath('data.treaty_forms.CATASTROPHE_EXCESS_OF_LOSS', 'EXCESS_OF_LOSS');
    });

    it('imports the directory through the generic pipeline target without granting security [REQ-IMP-001]', function () {
        $t = app(ImportTargetRegistry::class)->get('reinsurers');
        $params = $t->params(['tenant_id' => $this->tenant->id]);
        $seen = [];
        expect($t->check(['code' => 'SCOR', 'legal_name' => 'SCOR SE', 'jurisdiction' => 'FR', 'source_url' => 'https://www.scor.com'], $params, $seen)['status'])->toBe('NEW')
            ->and($t->check(['code' => 'X', 'legal_name' => 'X', 'role' => 'CEDANT'], $params, $seen)['status'])->toBe('ERROR')
            ->and($t->check(['code' => 'SCOR', 'legal_name' => 'again'], $params, $seen)['status'])->toBe('ERROR');
        Passport::actingAs($this->maker, [], 'api');
        $id = $t->import(['code' => 'SCOR', 'legal_name' => 'SCOR SE', 'jurisdiction' => 'fr', 'rating_agency' => 'S&P', 'rating' => 'AA-', 'contact_email' => 're@example.test'], $params, $this->maker, 'batch-1');
        $row = DB::table('reinsurers')->find($id);
        expect($row->approved_security_status)->toBe('PENDING_VERIFICATION')->and($row->country_code)->toBe('FR')->and($row->data_source)->toBe('IMPORT:batch-1');
        $seen = [];
        expect($t->check(['code' => 'SCOR', 'legal_name' => 'SCOR SE'], $params, $seen)['status'])->toBe('DUPLICATE');
    });

    it('reports the reinsurance gates in the Data Readiness registry [REQ-DRM-003]', function () {
        $rows = collect(ReinsuranceDirectoryServiceProvider::readiness())->keyBy('item');
        expect($rows['reinsurer_directory']['status'])->toBe('PENDING_SOURCE')->and($rows['reinsurer_directory']['production_usable'])->toBeFalse()
            ->and($rows['treaty_master']['status'])->toBe('CONFIG_REQUIRED')->and($rows['coinsurance']['status'])->toBe('CONFIG_REQUIRED');
        $domain = collect(app(DataReadinessRegistry::class)->domain('reinsurance_coinsurance'))->keyBy('item');
        expect($domain)->toHaveKeys(['reinsurer_directory', 'reinsurance_broker_directory', 'treaty_master', 'coinsurance']);
    });
});
