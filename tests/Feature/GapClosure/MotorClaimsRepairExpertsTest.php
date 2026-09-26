<?php

declare(strict_types=1);

/**
 * Gap Closure Pack v1 file 03 — motor classifications, claims taxonomies (cause of loss, damage, injury, evidence,
 * decision / rejection reasons), approved garage network and technical experts (DGTCFM). Agent GP3.
 * REQ-CLM-012 REQ-MDM-003 REQ-PRV-001 REQ-IMP-001 REQ-DUP-013
 */

use App\Application\Claims\ClaimReferenceCodes;
use App\Application\Claims\Decisions\ClaimDecisionService;
use App\Application\Claims\RepairNetwork\RepairNetworkService;
use App\Application\Claims\Taxonomy\ClaimTaxonomy;
use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\Import\ImportPipeline;
use App\Application\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function gp3File(string $csv): string
{
    $p = tempnam(sys_get_temp_dir(), 'gp3').'.csv';
    file_put_contents($p, $csv);

    return $p;
}

function gp3Codes(string $domain, string $list): array
{
    return DB::table('master_data_values')->where(['domain_code' => $domain, 'list_code' => $list])->pluck('code')->all();
}

beforeEach(function () {
    $this->tenant = makeAuthTestTenant('GP3');
    app(TenantContext::class)->set($this->tenant->id);
});

it('REQ-MDM-003 seeds every pack 03 claims taxonomy by code into the canonical lists, idempotently', function () {
    (new MasterDataSeeder)->run();
    $pack = ClaimTaxonomy::pack();

    foreach (['motor_damage_areas' => 'motor_damage_area', 'damage_severity' => 'damage_severity', 'injury_severity' => 'injury_severity',
        'evidence_types' => 'evidence_type', 'claim_decision_reasons' => 'decision_reason', 'claim_rejection_reasons' => 'rejection_reason'] as $k => $list) {
        expect(gp3Codes('claims', $list))->toEqualCanonicalizing($pack[$k]);
        $l = DB::table('master_data_lists')->where(['domain_code' => 'claims', 'code' => $list])->first();
        expect((bool) $l->structure_only)->toBeFalse();
    }
    // Every generic cause of loss resolves onto a stored, line-scoped code; no generic code was added as a parallel value.
    foreach ($pack['cause_of_loss'] as $g) {
        expect(gp3Codes('claims', 'cause_of_loss'))->toContain(ClaimTaxonomy::causeFor($g));
    }
    expect(gp3Codes('claims', 'cause_of_loss'))->not->toContain('COLLISION', 'OVERTURN')
        ->and(DB::table('master_data_values')->where(['domain_code' => 'claims', 'list_code' => 'cause_of_loss'])->where('code', '<>', 'OTHER')->whereNull('parent_value_id')->count())->toBe(0);
    $collision = DB::table('master_data_values')->where(['list_code' => 'cause_of_loss', 'code' => 'MOTOR__COLLISION'])->value('id');
    expect(DB::table('master_data_aliases')->where('value_id', $collision)->pluck('alias')->all())->toContain('COLLISION');
    expect(ClaimTaxonomy::causeFor('FIRE', 'PROPERTY'))->toBe('PROPERTY__FIRE')->and(ClaimTaxonomy::causeFor('FIRE', 'LIFE'))->toBeNull();

    // Damage-type / injury-type (nature) stay empty: nothing invented.
    expect(gp3Codes('claims', 'damage_type'))->toBe([])->and(gp3Codes('claims', 'injury_type'))->toBe([]);
    // Expert specialties are aliases of the existing adjuster types; only 2 genuinely new types.
    expect(gp3Codes('partners', 'adjuster_type'))->toContain('MISCELLANEOUS_DAMAGE_EXPERT', 'INDUSTRIAL_EQUIPMENT_EXPERT')->not->toContain('AUTOMOBILE', 'FIRE_RISK');
    expect(gp3Codes('partners', 'garage_service'))->toContain('INSPECTION');

    // Statuses registered with provenance (GAP_CLOSURE_PACK_V1).
    $st = DB::table('master_data_workflow_statuses')->whereIn('item_key', ['claims.cause_of_loss', 'claims.evidence_type', 'repair_network.garage_master'])->pluck('status', 'item_key')->all();
    expect($st)->toBe(['claims.cause_of_loss' => 'PLATFORM_NORMALIZED', 'claims.evidence_type' => 'PLATFORM_NORMALIZED', 'repair_network.garage_master' => 'PENDING_SOURCE'])
        ->and(DB::table('master_data_workflow_statuses')->where('item_key', 'claims.cause_of_loss')->value('source'))->toBe('GAP_CLOSURE_PACK_V1');

    $n = DB::table('master_data_values')->count();
    (new MasterDataSeeder)->run();
    expect(DB::table('master_data_values')->count())->toBe($n);
});

it('REQ-CLM-012 merges decision / rejection reasons into claim_decision_reason_codes: synonyms are aliases, the decision endpoint accepts them', function () {
    $pack = ClaimTaxonomy::pack();
    foreach ([...$pack['claim_decision_reasons'], ...$pack['claim_rejection_reasons']] as $code) {
        expect(DB::table('claim_decision_reason_codes')->where('code', ClaimTaxonomy::reasonCode($code))->where('status', 'ACTIVE')->exists())->toBeTrue($code);
    }
    // No parallel row for a synonym.
    expect(DB::table('claim_decision_reason_codes')->whereIn('code', ['NO_ACTIVE_COVER', 'LIMIT_APPLIED', 'DUPLICATE', 'EXCLUDED_EVENT'])->count())->toBe(0)
        ->and(json_decode(DB::table('claim_decision_reason_codes')->where('code', 'POLICY_NOT_IN_FORCE')->value('aliases'), true))->toContain('NO_ACTIVE_COVER', 'POLICY_NOT_EFFECTIVE')
        ->and(ClaimTaxonomy::reasonCode('duplicate'))->toBe('DUPLICATE_CLAIM')
        ->and(DB::table('claim_decision_reason_codes')->where('code', 'OUTSIDE_TERRITORY')->value('reason_group'))->toBe('REJECTION');

    $reasons = (new ReflectionClass(ClaimDecisionService::class))->getMethod('reasons');
    $svc = app(ClaimDecisionService::class);
    expect($reasons->invoke($svc, 'DECLINE', ['no_active_cover', 'OUTSIDE_TERRITORY']))->toBe(['POLICY_NOT_IN_FORCE', 'OUTSIDE_TERRITORY'])
        ->and($reasons->invoke($svc, 'APPROVE', ['COVERED_EVENT_CONFIRMED']))->toBe(['COVERED_EVENT_CONFIRMED']);
    expect(fn () => $reasons->invoke($svc, 'APPROVE', ['OUTSIDE_TERRITORY']))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(ClaimReferenceCodes::TAXONOMY_STATUS['cause_of_loss'])->toBe('PLATFORM_NORMALIZED')->and(ClaimReferenceCodes::TAXONOMY_STATUS['damage_type'])->toBe('PENDING_SOURCE');
});

it('seeds the commercial and motorcycle classes into the vehicle master with provenance and serves them', function () {
    $pack = ClaimTaxonomy::pack();
    expect(DB::table('vehicle_reference_values')->where('group', 'commercial_vehicle_class')->pluck('code')->all())->toEqualCanonicalizing($pack['commercial_vehicle_classes'])
        ->and(DB::table('vehicle_reference_values')->where('group', 'motorcycle_class')->pluck('code')->all())->toEqualCanonicalizing($pack['motorcycle_classes'])
        ->and(DB::table('vehicle_reference_values')->whereIn('group', ['commercial_vehicle_class', 'motorcycle_class'])->distinct()->pluck('source')->all())->toBe(['GAP_CLOSURE_PACK_V1']);

    $u = makeAuthTestUser($this->tenant, ['vehicle_power.view', 'claims.view']);
    Passport::actingAs($u);
    $this->getJson('/api/v1/motor/vehicle-classes', tenantHeaderFor($this->tenant))->assertOk()
        ->assertJsonCount(16, 'data.commercial_vehicle_class')->assertJsonPath('data.commercial_vehicle_class.6.vehicle_class', 'TRACTOR_UNIT');
    (new MasterDataSeeder)->run();
    $this->getJson('/api/v1/claims/taxonomies?locale=fr', tenantHeaderFor($this->tenant))->assertOk()
        ->assertJsonCount(27, 'data.motor_damage_area.values')->assertJsonPath('data.injury_severity.data_status', 'PLATFORM_NORMALIZED');
    $this->getJson('/api/v1/claims/taxonomies/cause-of-loss/resolve?code=THEFT&category=MARINE', tenantHeaderFor($this->tenant))->assertOk()
        ->assertJsonPath('data.cause_of_loss', 'MARINE__THEFT_PILFERAGE');
    Passport::actingAs(makeAuthTestUser($this->tenant, [], 'NOBODY'));
    $this->getJson('/api/v1/claims/taxonomies', tenantHeaderFor($this->tenant))->assertForbidden();
    $this->getJson('/api/v1/repair-network/garages', tenantHeaderFor($this->tenant))->assertForbidden();
});

it('REQ-IMP-001 imports the garage network through the pipeline as PENDING_VERIFICATION; approval is gated until a second user verifies the source', function () {
    (new MasterDataSeeder)->run();
    $maker = makeAuthTestUser($this->tenant, ['imports.create', 'imports.approve', 'providers.view', 'providers.manage', 'providers.credential'], 'GP3_MAKER');
    $checker = makeAuthTestUser($this->tenant, ['imports.approve', 'providers.view', 'providers.credential'], 'GP3_CHECKER');
    $pipe = app(ImportPipeline::class);
    $csv = "legal_name,city,phone,services,bodywork,glass,inspection,source\n"
        ."Garage Fixture Alpha,DOUALA,+237600000001,PAINTING,yes,1,x,Insurer network list FIXTURE-1\n"
        ."Garage Fixture Beta,YAOUNDE,,NOT_A_SERVICE,,,,FIXTURE-1\n"
        .",DOUALA,,,,,,FIXTURE-1\n";
    $batch = $pipe->upload('repair_garages', [], gp3File($csv), 'garages.csv', $maker, [], $this->tenant->id);
    expect($batch->report['new'])->toBe(['Garage Fixture Alpha'])->and($batch->report['errors'])->toHaveCount(2)->and($batch->status)->toBe('FAILED');
    $batch = $pipe->upload('repair_garages', [], gp3File("legal_name,city,phone,services,bodywork,glass,inspection,source
Garage Fixture Alpha,DOUALA,+237600000001,PAINTING,yes,1,x,Insurer network list FIXTURE-1
"), 'garages.csv', $maker, [], $this->tenant->id);
    $pipe->approve($pipe->submit($batch, $maker, 'insurer network'), $checker);

    $g = collect(app(RepairNetworkService::class)->list('GARAGE', []))->firstWhere('name', 'Garage Fixture Alpha');
    expect($g->data_status)->toBe('PENDING_VERIFICATION')->and($g->capabilities['SERVICE'])->toEqualCanonicalizing(['PAINTING', 'BODYWORK', 'WINDSCREEN', 'INSPECTION']);

    // Re-import of the same garage is a duplicate, never overwritten.
    $again = $pipe->upload('repair_garages', [], gp3File("legal_name,source\nGarage Fixture Alpha,FIXTURE-2\n"), 'g2.csv', $maker, [], $this->tenant->id);
    expect($again->report['duplicates'])->toHaveCount(1)->and($again->report['valid'])->toBe(0);

    $reg = app(ProviderRegistry::class);
    $reg->transition($g->id, 'APPLICATION', null, null, $maker->id);
    $reg->transition($g->id, 'UNDER_REVIEW', null, null, $maker->id);
    expect(fn () => $reg->transition($g->id, 'APPROVED', null, null, $maker->id))->toThrow(ApiProblemException::class);

    // Maker-checker: the importer (checker approved the batch → created_by) cannot verify; another user can.
    $third = makeAuthTestUser($this->tenant, ['providers.credential', 'providers.view'], 'GP3_THIRD');
    expect(fn () => app(RepairNetworkService::class)->verifySource($g->id, [], $checker))->toThrow(ApiProblemException::class);
    Passport::actingAs($third);
    $this->postJson("/api/v1/repair-network/providers/{$g->id}/verify-source", ['source_reference' => 'NETWORK-AGREEMENT-FIXTURE'], tenantHeaderFor($this->tenant))
        ->assertOk()->assertJsonPath('data.data_status', 'VERIFIED')->assertJsonPath('data.production_usable', true);
    expect($reg->transition($g->id, 'APPROVED', null, null, $maker->id)->credentialing_status)->toBe('APPROVED');

    $row = collect(app(DataReadinessRegistry::class)->domain('repair_network'))->firstWhere('item', 'garage_master');
    expect($row['status'])->toBe('VERIFIED');
});

it('REQ-PRV-001 imports DGTCFM technical experts with specialties mapped onto adjuster types, pending verification', function () {
    (new MasterDataSeeder)->run();
    $u = makeAuthTestUser($this->tenant, ['imports.create', 'imports.approve'], 'GP3_IMP');
    $checker = makeAuthTestUser($this->tenant, ['imports.approve'], 'GP3_CHK');
    $csv = "name,decision_reference,registration_number,specialties,city\n"
        ."Expert Fixture One,DEC-FIXTURE-001,REG-F1,AUTOMOBILE|MISCELLANEOUS_DAMAGE,DOUALA\n"
        ."Expert Fixture Two,,REG-F2,AUTOMOBILE,DOUALA\n"
        ."Expert Fixture Three,DEC-FIXTURE-003,REG-F3,ASTROLOGY,DOUALA\n";
    $pipe = app(ImportPipeline::class);
    $b = $pipe->upload('technical_experts', [], gp3File($csv), 'experts.csv', $u, [], $this->tenant->id);
    expect($b->report['new'])->toBe(['Expert Fixture One'])->and($b->report['errors'])->toHaveCount(2);
    $b = $pipe->upload('technical_experts', [], gp3File("name,decision_reference,registration_number,specialties,city
Expert Fixture One,DEC-FIXTURE-001,REG-F1,AUTOMOBILE|MISCELLANEOUS_DAMAGE,DOUALA
"), 'experts.csv', $u, [], $this->tenant->id);
    $pipe->approve($pipe->submit($b, $u, 'official list'), $checker);

    $e = collect(app(RepairNetworkService::class)->list('EXPERT', []))->firstWhere('name', 'Expert Fixture One');
    expect($e->provider_type_code)->toBe('MOTOR_EXPERT')->and($e->decision_reference)->toBe('DEC-FIXTURE-001')
        ->and($e->source_url)->toBe(RepairNetworkService::DGTCFM_EXPERTS_URL)->and($e->data_status)->toBe('PENDING_VERIFICATION')
        ->and($e->capabilities['SPECIALTY'])->toEqualCanonicalizing(['MOTOR_EXPERT', 'MISCELLANEOUS_DAMAGE_EXPERT']);

    $row = collect(app(DataReadinessRegistry::class)->domain('repair_network'))->firstWhere('item', 'adjuster_expert_master');
    expect($row['status'])->toBe('PENDING_SOURCE')->and($row['production_usable'])->toBeFalse();
    expect(fn () => app(RepairNetworkService::class)->setCapabilities($e->id, 'SERVICE', ['BODYWORK'], null))->toThrow(ApiProblemException::class);
});

it('registers computed readiness gates for claims taxonomies and vehicle technical / fiscal power', function () {
    $reg = app(DataReadinessRegistry::class);
    $claims = collect($reg->domain('claims'))->keyBy('item');
    expect($claims['cause_of_loss']['status'])->toBe('PENDING_SOURCE');   // master data not seeded yet: computed, not declared
    (new MasterDataSeeder)->run();
    $claims = collect($reg->domain('claims'))->keyBy('item');
    foreach (['cause_of_loss', 'damage_taxonomy', 'injury_taxonomy', 'evidence_type', 'decision_reason', 'rejection_reason', 'decision_reason_codes'] as $i) {
        expect($claims[$i]['status'])->toBe('PLATFORM_NORMALIZED', $i);
    }
    $v = collect($reg->domain('vehicles'))->keyBy('item');
    expect($v['fiscal_power_cv']['status'])->toBe('PENDING_SOURCE')
        ->and($v['automobile_stamp_duty_rates']['status'])->toBe('CONFIG_REQUIRED')
        ->and($v['full_variant_population']['production_usable'])->toBeFalse()
        ->and($v['commercial_vehicle_classes']['status'])->toBe('PLATFORM_NORMALIZED');
});

it('stores the pack 03 required variant fields on the canonical variant (power PS / fiscal CV stay in the power master)', function () {
    $svc = app(\App\Application\Vehicles\VehicleMasterAdminService::class);
    $make = \App\Models\Vehicles\VehicleMake::create(['code' => 'GP3MK', 'name' => 'Gp3 Make', 'normalized_name' => 'GP3 MAKE', 'provenance' => 'MANUAL_VERIFIED']);
    $model = \App\Models\Vehicles\VehicleModel::create(['code' => 'GP3MK_M', 'make_id' => $make->id, 'name' => 'Model', 'normalized_name' => 'MODEL', 'provenance' => 'MANUAL_VERIFIED']);
    $v = $svc->createVariant($model, null, ['name' => '2.8 TD', 'engine_code' => '1GD-FTV', 'seat_count' => '5', 'curb_weight_kg' => 2100,
        'gross_vehicle_weight_kg' => 3000, 'payload_kg' => 900], null);
    expect($v->fresh()->only(['engine_code', 'seat_count', 'gross_vehicle_weight_kg', 'payload_kg']))
        ->toBe(['engine_code' => '1GD-FTV', 'seat_count' => 5, 'gross_vehicle_weight_kg' => 3000, 'payload_kg' => 900]);
    expect(fn () => $svc->createVariant($model, null, ['name' => 'bad', 'payload_kg' => -1], null))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(array_keys(app(\App\Application\Import\ImportTargetRegistry::class)->get('vehicle_variants')->fields()))->toContain('engine_code', 'payload_kg')
        ->not->toContain('fiscal_power_cv', 'power_ps');
});
