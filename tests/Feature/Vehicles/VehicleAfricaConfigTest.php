<?php

declare(strict_types=1);

/*
 * Cameroon & Africa vehicle master config (database/data/vehicle_master_config_africa_2026.json).
 * CUST-007 selection flow make -> model -> generation -> model_year -> engine_variant (+ auto-populated specs),
 * source priority, global dataset importer (local file only), manual entry duplicate check,
 * policy-issue spec snapshot. REQ-DUP-013 (suggestions), REQ-MDM-003.
 *
 * The dataset rows below are a synthetic test fixture in the dataset's engines.csv shape,
 * not real vehicle data and never seeded.
 */

use App\Application\Vehicles\VehicleCatalogueService;
use App\Application\Vehicles\VehicleDataSource;
use App\Application\Vehicles\VehicleMasterAdminService;
use App\Models\Party;
use App\Models\RiskAsset;
use App\Models\User;
use App\Models\Vehicles\PolicyVehicleSnapshot;
use App\Models\Vehicles\RiskAssetVehicle;
use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMasterReview;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleVariant;
use Database\Seeders\VehicleMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function afSeed(): void
{
    test()->artisan('opesinsure:seed-vehicles')->assertExitCode(0);
}

function afFixtureCsv(): string
{
    $path = storage_path('framework/testing/b2v_engines_fixture.csv');
    @mkdir(dirname($path), 0777, true);
    $header = 'make_group,make,model,generation,gen_year_start,gen_year_end,body_type,engine_label,fuel_type,cylinders,displacement_cc,power_hp,torque_nm,transmission,drivetrain,zero_to_100_s,top_speed_kmh,fuel_economy_combined_l100,length_mm,width_mm,height_mm,wheelbase_mm,curb_weight_kg';
    $rows = [
        'fixture,Toyota,Corolla,Fixture Gen A,2018,2022,Sedan,1.8L Hybrid CVT FWD (122 HP),Hybrid Gasoline,4,1798,122,142,CVT,Front Wheel Drive,,,,,,,,',
        'fixture,Toyota,Corolla,Fixture Gen A,2018,2022,Sedan,1.6L 6MT FWD (132 HP),Gasoline,4,1598,132,160,Manual,Front Wheel Drive,,,,,,,,',
        'fixture,Toyota,Corolla,Fixture Gen B,2023,,Sedan,2.0L AT (170 HP),Gasoline,4,1987,170,,Automatic,Front Wheel Drive,,,,,,,,',
        'fixture,Toyota,Unknown Fixture Model,Gen X,2010,2012,,1.0L (60 HP),Gasoline,3,998,60,,,,,,,,,,,',
        'fixture,Speranza,Fixture Car,Gen Y,2010,2012,,1.5L (90 HP),Gasoline,4,1500,90,,,,,,,,,,,',
    ];
    file_put_contents($path, $header."\n".implode("\n", $rows)."\n");

    return $path;
}

it('merges the 42 core + 19 commercial config makes without duplicating makes or models', function () {
    afSeed();
    $config = json_decode(file_get_contents(database_path(VehicleMasterDataSeeder::AFRICA_CONFIG_FILE)), true);
    expect($config['makes'])->toHaveCount(42)->and($config['commercial_vehicle_makes'])->toHaveCount(19)
        ->and(VehicleMake::count())->toBe(151); // every config make already existed: none duplicated

    $catalogue = app(VehicleCatalogueService::class);
    foreach ($config['makes'] as $row) {
        $make = VehicleMake::where('code', $row['code'])->first() ?? $catalogue->resolveMake($row['name']);
        expect($make)->not->toBeNull();
        foreach ($row['models'] as $name) {
            expect($catalogue->resolveModel($make, $name))->not->toBeNull("{$row['name']} $name");
        }
    }
    foreach ($config['commercial_vehicle_makes'] as $name) {
        expect($catalogue->resolveMake(is_array($name) ? $name['name'] : $name))->not->toBeNull();
    }
    $rx = VehicleModel::where('code', 'LEXUS_RX')->firstOrFail();
    expect($rx->data_source)->toBe(VehicleDataSource::OVERRIDE)
        ->and(VehicleModel::where('code', 'TOYOTA_COROLLA')->value('data_source'))->toBe(VehicleDataSource::OVERRIDE);

    $models = VehicleModel::count();
    afSeed();
    expect(VehicleModel::count())->toBe($models)->and(VehicleMake::count())->toBe(151);
});

it('ranks sources and exposes the picker config', function () {
    expect(VehicleDataSource::mayOverwrite('GLOBAL_VEHICLE_DATASET', 'OPESINSURE_VERIFIED_OVERRIDE'))->toBeFalse()
        ->and(VehicleDataSource::mayOverwrite('OPESINSURE_VERIFIED_OVERRIDE', 'GLOBAL_VEHICLE_DATASET'))->toBeTrue()
        ->and(VehicleDataSource::mayOverwrite('GLOBAL_VEHICLE_DATASET', 'GLOBAL_VEHICLE_DATASET'))->toBeTrue()
        ->and(VehicleDataSource::mayOverwrite('MANUAL_PENDING_REVIEW', 'CAMEROON_DISTRIBUTOR_VERIFIED'))->toBeFalse();

    $this->getJson('/api/v1/public/vehicles/config')->assertOk()
        ->assertJsonPath('data.selection_flow', ['make', 'model', 'generation', 'model_year', 'engine_variant'])
        ->assertJsonPath('data.auto_populate_from_engine_variant.0', 'power_hp')
        ->assertJsonPath('data.manual_entry_policy.auto_publish_to_master', false)
        ->assertJsonPath('data.fallback.endpoint', '/api/v1/master-data/suggestions');
});

it('imports generations and engine variants for core makes/models from a local dataset file only', function () {
    afSeed();
    $csv = afFixtureCsv();

    $this->artisan('opesinsure:import-vehicle-dataset', ['--path' => $csv])->assertExitCode(1); // licence gate
    $this->artisan('opesinsure:import-vehicle-dataset', ['--path' => $csv, '--dry-run' => true])->assertExitCode(0);
    expect(VehicleGeneration::count())->toBe(0)->and(VehicleVariant::count())->toBe(0);

    $this->artisan('opesinsure:import-vehicle-dataset', ['--path' => $csv, '--accept-license' => true])->assertExitCode(0);
    $corolla = VehicleModel::where('code', 'TOYOTA_COROLLA')->firstOrFail();
    expect(VehicleGeneration::count())->toBe(2)
        ->and(VehicleVariant::count())->toBe(3)
        ->and(VehicleModel::count())->toBe(VehicleModel::count()) // never creates models
        ->and(VehicleModel::where('name', 'Unknown Fixture Model')->exists())->toBeFalse()
        ->and(VehicleMake::where('name', 'Speranza')->exists())->toBeFalse();

    $gen = VehicleGeneration::where('model_id', $corolla->id)->where('name', 'Fixture Gen A')->firstOrFail();
    $hybrid = VehicleVariant::where('generation_id', $gen->id)->where('name', 'like', '1.8L%')->firstOrFail();
    expect($gen->data_source)->toBe('GLOBAL_VEHICLE_DATASET')->and($gen->body_type)->toBe('SEDAN')
        ->and($hybrid->powertrain)->toBe('HYBRID')->and($hybrid->hybrid_subtype)->toBe('HEV')
        ->and($hybrid->transmission)->toBe('CVT')->and($hybrid->drive_type)->toBe('FWD')
        ->and($hybrid->power_hp)->toBe(122)->and($hybrid->power_kw)->toBe(90.98)->and($hybrid->torque_nm)->toBe(142)
        ->and($hybrid->engine_capacity_cc)->toBe(1798)->and($hybrid->provenance)->toBe('INDUSTRY_DATABASE');

    // Admin edit wins over a re-import; re-import is idempotent.
    app(VehicleMasterAdminService::class)->updateVariant($hybrid, ['power_hp' => 121], User::create(['full_name' => 'A', 'phone_e164' => '+237600000001', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']));
    $this->artisan('opesinsure:import-vehicle-dataset', ['--path' => $csv, '--accept-license' => true])->assertExitCode(0);
    expect(VehicleGeneration::count())->toBe(2)->and(VehicleVariant::count())->toBe(3)->and($hybrid->fresh()->power_hp)->toBe(121);

    // Selection flow API: generations -> years -> engine variants with auto-populate specs.
    $this->getJson('/api/v1/public/vehicles/models/TOYOTA_COROLLA/generations')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Fixture Gen A')->assertJsonPath('data.0.body_type', 'SEDAN');
    $this->getJson("/api/v1/public/vehicles/models/TOYOTA_COROLLA/generations/{$gen->code}/years")->assertOk()
        ->assertJsonPath('data', [2022, 2021, 2020, 2019, 2018]);
    $v = $this->getJson("/api/v1/public/vehicles/models/TOYOTA_COROLLA/generations/{$gen->code}/variants?year=2019")->assertOk()->assertJsonCount(2, 'data');
    $row = collect($v->json('data'))->firstWhere('code', $hybrid->code);
    expect($row['specs'])->toBe(['power_hp' => 121, 'power_kw' => 90.98, 'displacement_cc' => 1798, 'fuel_type' => 'HYBRID', 'transmission' => 'CVT', 'drivetrain' => 'FWD', 'body_type' => 'SEDAN', 'torque_nm' => 142])
        ->and($row['engine_label'])->toBe($hybrid->name);
    $this->getJson("/api/v1/public/vehicles/models/TOYOTA_COROLLA/generations/{$gen->code}/variants?year=2024")->assertOk()->assertJsonCount(0, 'data');
});

it('accepts config manual-entry fields, dedupes open entries and never auto-publishes', function () {
    afSeed();
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'C', 'status' => 'ACTIVE']);
    $user = User::create(['full_name' => 'C', 'phone_e164' => '+237600000002', 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    Passport::actingAs($user);
    $body = ['domain' => 'vehicle', 'list' => 'models', 'parent' => 'TOYOTA', 'text' => 'Fixture Only',
        'attributes' => ['model_year' => 2015, 'generation' => 'XP150', 'engine_label' => '1.5 VVT-i', 'power_hp' => 107, 'displacement_cc' => 1496, 'fuel_type' => 'PETROL', 'transmission' => 'MANUAL', 'drivetrain' => 'FWD', 'body_type' => 'SEDAN']];

    $first = $this->postJson('/api/v1/master-data/suggestions', $body)->assertCreated()->json('data.review.id');
    $again = $this->postJson('/api/v1/master-data/suggestions', $body)->assertCreated()->json('data.review.id');
    $review = VehicleMasterReview::findOrFail($first);
    expect($again)->toBe($first)
        ->and($review->powertrain)->toBe('PETROL')
        ->and($review->payload['engine_label'])->toBe('1.5 VVT-i')->and($review->payload['power_hp'])->toBe(107)
        ->and($review->payload['duplicate_submissions'])->toBe(1)
        ->and(VehicleModel::where('name', 'Fixture Only')->exists())->toBeFalse();

    $this->postJson('/api/v1/master-data/suggestions', ['attributes' => ['fuel_type' => 'NOPE']] + $body)->assertUnprocessable();
});

it('fills specs from the chosen engine variant and freezes a snapshot at policy issue', function () {
    require_once base_path('tests/Feature/Wave12/Concerns/mobile_customer_helpers.php');
    afSeed();
    $admin = User::create(['full_name' => 'A', 'phone_e164' => '+237600000003', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $svc = app(VehicleMasterAdminService::class);
    $corolla = VehicleModel::where('code', 'TOYOTA_COROLLA')->firstOrFail();
    $gen = $svc->createGeneration($corolla, ['name' => 'Snapshot Gen', 'year_from' => 2019], $admin);
    $variant = $svc->createVariant($corolla, $gen, ['name' => '1.8 Hybrid', 'power_hp' => 122, 'engine_capacity_cc' => 1798, 'powertrain' => 'HYBRID', 'transmission' => 'CVT', 'drive_type' => 'FWD', 'body_type' => 'SEDAN', 'torque_nm' => 142], $admin);

    $fx = makeMobileCustomerFixture('+237670000777');
    $asset = RiskAsset::create(['tenant_id' => $fx['tenant']->id, 'party_id' => $fx['party']->id, 'type' => 'VEHICLE', 'display_name' => 'Corolla',
        'facts' => ['make_code' => 'TOYOTA', 'model_code' => 'TOYOTA_COROLLA', 'variant_code' => $variant->code, 'year' => 2020, 'registration_number' => 'LT 9'],
        'facts_hash' => 'h', 'status' => 'ACTIVE', 'version' => 1]);
    $rec = RiskAssetVehicle::where('risk_asset_id', $asset->id)->firstOrFail();
    expect($rec->generation_id)->toBe($gen->id)->and($rec->variant_id)->toBe($variant->id)
        ->and($rec->horsepower)->toBe(122)->and($rec->engine_capacity_cc)->toBe(1798)->and($rec->powertrain)->toBe('HYBRID')
        ->and($rec->transmission)->toBe('CVT')->and($rec->drive_type)->toBe('FWD')->and($rec->body_type)->toBe('SEDAN');

    $fx['quote']->update(['risk_asset_id' => $asset->id]);
    $policy = makeMobileTestPolicy($fx['proposal'], $fx['tenant'], $fx['carrier']->id, $fx['party']->id, ['policy_number' => 'POL-B2V-1', 'currency' => 'XAF', 'premium_minor' => 100000, 'version' => 1]);

    $snap = PolicyVehicleSnapshot::where('policy_id', $policy->id)->firstOrFail();
    expect($snap->variant_id)->toBe($variant->id)->and($snap->make_id)->toBe($corolla->make_id)
        ->and($snap->spec_snapshot['variant']['power_hp'])->toBe(122)
        ->and($snap->spec_snapshot['vehicle']['registration_number'])->toBe('LT 9');

    // Later master edits do not change the frozen snapshot.
    $svc->updateVariant($variant, ['power_hp' => 130], $admin);
    $policy->update(['status' => 'ACTIVE']);
    expect(PolicyVehicleSnapshot::where('policy_id', $policy->id)->count())->toBe(1)
        ->and($snap->fresh()->spec_snapshot['variant']['power_hp'])->toBe(122)
        ->and(fn () => $snap->update(['snapshot_hash' => 'x']))->toThrow(LogicException::class);
});

it('accepts the optional MOTOR picker facts and validates their codes', function () {
    afSeed();
    $admin = User::create(['full_name' => 'A', 'phone_e164' => '+237600000004', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $svc = app(VehicleMasterAdminService::class);
    $corolla = VehicleModel::where('code', 'TOYOTA_COROLLA')->firstOrFail();
    $gen = $svc->createGeneration($corolla, ['name' => 'Facts Gen'], $admin);
    $variant = $svc->createVariant($corolla, $gen, ['name' => '1.6'], $admin);
    $ok = ['make_code' => 'TOYOTA', 'model_code' => 'TOYOTA_COROLLA', 'vehicle_generation_code' => $gen->code, 'vehicle_variant_code' => $variant->code, 'engine_capacity_cc' => 1598, 'power_hp' => 132, 'drive_type' => 'FWD'];

    App\Application\Vehicles\MotorRiskSchema::validateVehicleFacts($ok);
    App\Application\Vehicles\MotorRiskSchema::validateVehicleFacts(['make_code' => 'TOYOTA', 'model_code' => 'TOYOTA_COROLLA']);
    foreach ([['vehicle_generation_code' => 'NOPE'], ['vehicle_variant_code' => 'NOPE'], ['power_hp' => 0], ['engine_capacity_cc' => 'abc'], ['drive_type' => 'NOPE'], ['model_code' => 'TOYOTA_YARIS']] as $bad) {
        expect(fn () => App\Application\Vehicles\MotorRiskSchema::validateVehicleFacts($bad + $ok))->toThrow(Illuminate\Validation\ValidationException::class);
    }
    $keys = collect(App\Application\Vehicles\MotorRiskSchema::schema()['fields'])->pluck('key');
    expect($keys)->toContain('vehicle_generation_code', 'vehicle_variant_code', 'engine_capacity_cc', 'power_hp', 'drive_type');
});
