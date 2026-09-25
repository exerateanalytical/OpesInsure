<?php

declare(strict_types=1);

use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Application\Vehicles\VehicleCatalogueService;
use App\Application\Vehicles\VehicleMasterAdminService;
use App\Application\Vehicles\VehicleMasterReviewService;
use App\Application\Vehicles\VehicleUsageMapper;
use App\Filament\Admin\Resources\VehicleMakes\Pages\ListVehicleMakes;
use App\Filament\Admin\Resources\VehicleMakes\VehicleMakeResource;
use App\Filament\Admin\Resources\VehicleMasterChanges\Pages\ListVehicleMasterChanges;
use App\Filament\Admin\Resources\VehicleMasterReviews\Pages\ListVehicleMasterReviews;
use App\Filament\Admin\Resources\VehicleMasterReviews\VehicleMasterReviewResource;
use App\Filament\Admin\Resources\VehicleModels\Pages\ListVehicleModels;
use App\Filament\Admin\Resources\VehicleReferenceValues\Pages\ListVehicleReferenceValues;
use App\Models\InsuranceLine;
use App\Models\Party;
use App\Models\RiskAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicles\RiskAssetVehicle;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMakeAlias;
use App\Models\Vehicles\VehicleMasterChange;
use App\Models\Vehicles\VehicleMasterReview;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleReferenceValue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function vehicleSeed(): void
{
    test()->artisan('opesinsure:seed-vehicles')->assertExitCode(0);
}

function vehicleData(): array
{
    return json_decode(file_get_contents(database_path('data/cameroon_vehicle_master_2026.json')), true, 512, JSON_THROW_ON_ERROR);
}

function vehicleUser(string $name = 'Vehicle Admin'): User
{
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => $name, 'status' => 'ACTIVE']);

    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

it('seeds all 151 makes and every model from the canonical file', function () {
    vehicleSeed();
    $data = vehicleData();
    $modelCount = array_sum(array_map(fn ($m) => count($m['models']), $data['makes']));

    expect(VehicleMake::count())->toBe(153) // + Datsun, Mahindra curated reference (owner decision 20)
        ->and(count($data['makes']))->toBe(151)
        // Canonical file models plus the Cameroon & Africa config additions (data_source OPESINSURE_VERIFIED_OVERRIDE).
        ->and(VehicleModel::count() - App\Models\Vehicles\VehicleMasterChange::where('action', 'SEEDED')->where('entity_type', 'vehicle_model')->where('after->source', 'data/vehicle_master_config_africa_2026.json')->count())->toBe($modelCount)
        ->and($modelCount)->toBe(446)
        ->and(VehicleModel::where('code', 'TOYOTA_LAND_CRUISER_PRADO')->exists())->toBeTrue()
        ->and(VehicleMake::where('code', 'TOYOTA')->first())
        ->country_of_origin->toBe('JP')
        ->cameroon_status->toBe('OFFICIALLY_DISTRIBUTED')
        ->market_priority->toBe('HIGH')
        ->provenance->toBe('MANUAL_VERIFIED');

    expect(VehicleReferenceValue::where('group', 'body_type')->count())->toBe(29)
        ->and(VehicleReferenceValue::where('group', 'usage')->count())->toBe(28)
        ->and(VehicleReferenceValue::where('group', 'vehicle_class')->count())->toBe(23);
});

it('is idempotent and preserves admin edits', function () {
    vehicleSeed();
    $toyota = VehicleMake::where('code', 'TOYOTA')->first();
    app(VehicleMasterAdminService::class)->updateMake($toyota, ['market_priority' => 'NORMAL', 'ui_rank_cameroon' => 50], vehicleUser());

    vehicleSeed();
    vehicleSeed();

    expect(VehicleMake::count())->toBe(153)
        ->and(VehicleModel::count())->toBe(512) // 446 canonical + 66 from the Cameroon & Africa config
        ->and(VehicleMakeAlias::where('alias', 'VW')->count())->toBe(1)
        ->and($toyota->fresh()->market_priority)->toBe('NORMAL')
        ->and($toyota->fresh()->ui_rank_cameroon)->toBe(50);
});

it('refuses to delete master data', function () {
    vehicleSeed();
    expect(fn () => VehicleMake::where('code', 'TOYOTA')->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => VehicleModel::where('code', 'TOYOTA_COROLLA')->first()->delete())->toThrow(LogicException::class);
    expect(VehicleMake::count())->toBe(153);
});

it('resolves make and model aliases', function () {
    vehicleSeed();
    $svc = app(VehicleCatalogueService::class);

    expect($svc->resolveMake('Mercedes')?->code)->toBe('MERCEDES_BENZ')
        ->and($svc->resolveMake('Mercedes Benz')?->code)->toBe('MERCEDES_BENZ')
        ->and($svc->resolveMake('mercedes-benz')?->code)->toBe('MERCEDES_BENZ')
        ->and($svc->resolveMake('VW')?->code)->toBe('VOLKSWAGEN')
        ->and($svc->resolveMake('Chevy')?->code)->toBe('CHEVROLET')
        ->and($svc->resolveMake('Range Rover')?->code)->toBe('LAND_ROVER')
        ->and($svc->resolveMake('Citroen')?->code)->toBe('CITROEN')
        ->and($svc->resolveMake('SsangYong')?->code)->toBe('KGM')
        ->and($svc->resolveMake('Howo')?->code)->toBe('SINOTRUK')
        ->and($svc->resolveMake('Nonexistent Motors'))->toBeNull();

    $toyota = VehicleMake::where('code', 'TOYOTA')->first();
    expect($svc->resolveModel($toyota, 'Prado')?->code)->toBe('TOYOTA_LAND_CRUISER_PRADO')
        ->and($svc->resolveModel($toyota, 'Hi-Lux')?->code)->toBe('TOYOTA_HILUX')
        ->and($svc->resolveModel($toyota, 'land cruiser')?->code)->toBe('TOYOTA_LAND_CRUISER');
});

it('ranks make autocomplete by the Cameroon list, or the Chinese list with chinese=1', function () {
    vehicleSeed();

    $this->getJson('/api/v1/public/vehicles/makes?q=Toy')->assertOk()->assertJsonPath('data.0.code', 'TOYOTA');
    $this->getJson('/api/v1/public/vehicles/makes?q=Hav')->assertOk()->assertJsonPath('data.0.code', 'HAVAL');
    $this->getJson('/api/v1/public/vehicles/makes?q=Cher')->assertOk()->assertJsonPath('data.0.code', 'CHERY');
    $this->getJson('/api/v1/public/vehicles/makes?q=chevy')->assertOk()->assertJsonPath('data.0.code', 'CHEVROLET');
    $this->getJson('/api/v1/public/vehicles/makes?q=range')->assertOk()->assertJsonPath('data.0.code', 'LAND_ROVER');

    $top = $this->getJson('/api/v1/public/vehicles/makes?limit=5')->assertOk()
        ->assertJsonStructure(['data' => [['code', 'name', 'country_of_origin', 'segment', 'cameroon_status', 'market_priority', 'aliases', 'models_count']], 'meta' => ['total']])
        ->json('data.*.code');
    expect($top)->toBe(array_slice(vehicleData()['ui_priority_cameroon'], 0, 5));

    $cn = $this->getJson('/api/v1/public/vehicles/makes?chinese=1&limit=200')->assertOk()->json('data');
    expect(array_slice(array_column($cn, 'code'), 0, 3))->toBe(['CHERY', 'CHANGAN', 'JAC'])
        ->and(collect($cn)->pluck('country_of_origin')->unique()->values()->all())->toBe(['CN'])
        ->and(count($cn))->toBe(70);

    $commercial = $this->getJson('/api/v1/public/vehicles/makes?segment=COMMERCIAL&limit=200')->json('data.*.segment');
    expect(array_values(array_unique($commercial)))->each->toBeIn(['COMMERCIAL', 'MIXED']);
});

it('lists models for a make with alias search', function () {
    vehicleSeed();

    $this->getJson('/api/v1/public/vehicles/makes/TOYOTA/models')->assertOk()
        ->assertJsonCount(28, 'data') // 25 canonical + Vitz, Corolla Verso, Avensis Verso (Africa config)
        ->assertJsonStructure(['data' => [['code', 'name', 'segment', 'aliases', 'status']], 'make' => ['code', 'name']]);
    $this->getJson('/api/v1/public/vehicles/makes/TOYOTA/models?q=prado')->assertOk()->assertJsonPath('data.0.code', 'TOYOTA_LAND_CRUISER_PRADO');
    $this->getJson('/api/v1/public/vehicles/makes/HYUNDAI/models?q=starex')->assertOk()->assertJsonPath('data.0.code', 'HYUNDAI_H1');
    $this->getJson('/api/v1/public/vehicles/makes/NOPE/models')->assertNotFound();
});

it('serves the reference enums with EN/FR labels and a computed model year range', function () {
    vehicleSeed();
    $r = $this->getJson('/api/v1/public/vehicles/reference')->assertOk();

    $year = (int) now()->year;
    expect($r->json('data.model_years.min'))->toBe(1950)
        ->and($r->json('data.model_years.max'))->toBe($year + 1)
        ->and($r->json('data.body_types'))->toHaveCount(29)
        ->and($r->json('data.usage_types'))->toHaveCount(28)
        ->and($r->json('data.vehicle_classes'))->toHaveCount(23)
        ->and($r->json('data.powertrains.*.code'))->toContain('BEV', 'PHEV', 'MILD_HYBRID')
        ->and($r->json('data.hybrid_subtypes.*.code'))->toBe(['HEV', 'MHEV', 'PHEV']);

    $hatch = collect($r->json('data.body_types'))->firstWhere('code', 'HATCHBACK');
    expect($hatch['label']['en'])->toBe('Hatchback')->and($hatch['label']['fr'])->toBe('Berline à hayon');
    foreach (['usage_types', 'ownership_types', 'transmissions', 'drive_types', 'conditions', 'value_types'] as $group) {
        foreach ($r->json("data.$group") as $row) {
            expect($row['label']['en'])->not->toBe('')->and($row['label']['fr'])->not->toBe('');
        }
    }
});

it('queues manual "not listed" entries and admin can approve as new or merge', function () {
    vehicleSeed();
    $user = vehicleUser('Customer');
    Passport::actingAs($user);

    $res = $this->postJson('/api/v1/mobile/vehicles/master-review', [
        'make' => 'Zotye', 'model' => 'T600', 'model_year' => 2018, 'body_type' => 'SUV',
        'vin' => 'LJ1234567890ABCDE', 'registration_number' => 'LT 123 AB', 'engine_number' => 'E1', 'powertrain' => 'PETROL', 'usage' => 'PRIVATE_PERSONAL',
    ])->assertCreated()->assertJsonPath('data.status', 'MASTER_DATA_REVIEW_REQUIRED');
    $this->postJson('/api/v1/mobile/vehicles/master-review', ['make' => '', 'model' => 'x'])->assertUnprocessable();

    $admin = vehicleUser();
    $svc = app(VehicleMasterReviewService::class);
    $review = VehicleMasterReview::findOrFail($res->json('data.id'));
    expect($review->submitted_by)->toBe($user->id);

    $svc->approveAsNew($review, $admin, ['segment' => 'PASSENGER', 'country_of_origin' => 'CN']);
    $review->refresh();
    $make = VehicleMake::where('code', 'ZOTYE')->firstOrFail();
    expect($review->status)->toBe('APPROVED_NEW')
        ->and($review->reviewed_by)->toBe($admin->id)
        ->and($review->reviewed_at)->not->toBeNull()
        ->and($make->provenance)->toBe('CUSTOMER_SUBMITTED')
        ->and($make->cameroon_status)->toBe('UNVERIFIED')
        ->and(VehicleModel::where('code', 'ZOTYE_T600')->value('make_id'))->toBe($make->id)
        ->and($review->resolved_model_id)->not->toBeNull();

    $second = $svc->submit(['make' => 'Toyta', 'model' => 'Corola', 'model_year' => 2015], $user);
    $toyota = VehicleMake::where('code', 'TOYOTA')->first();
    $corolla = VehicleModel::where('code', 'TOYOTA_COROLLA')->first();
    $svc->merge($second, $admin, $toyota->id, $corolla->id);
    expect($second->fresh()->status)->toBe('MERGED')
        ->and(app(VehicleCatalogueService::class)->resolveMake('Toyta')?->code)->toBe('TOYOTA')
        ->and(app(VehicleCatalogueService::class)->resolveModel($toyota, 'Corola')?->code)->toBe('TOYOTA_COROLLA')
        ->and(VehicleMake::count())->toBe(154)
        ->and(VehicleMasterChange::where('entity_type', 'vehicle_master_review')->count())->toBe(2);
});

it('merges a duplicate make into the canonical one', function () {
    vehicleSeed();
    $admin = vehicleUser();
    $svc = app(VehicleMasterAdminService::class);
    $dup = $svc->createMake(['code' => 'TOYOTA_DUP', 'name' => 'Toyota Motors', 'segment' => 'PASSENGER'], $admin);
    $model = $svc->createModel($dup, ['name' => 'Corolla XLI'], $admin);

    $svc->mergeMake($dup, VehicleMake::where('code', 'TOYOTA')->first(), $admin);

    $dup->refresh();
    $toyota = VehicleMake::where('code', 'TOYOTA')->first();
    expect($dup->active)->toBeFalse()
        ->and($dup->merged_into_id)->toBe($toyota->id)
        ->and($model->fresh()->make_id)->toBe($toyota->id)
        ->and(app(VehicleCatalogueService::class)->resolveMake('Toyota Motors')?->code)->toBe('TOYOTA')
        ->and(VehicleMasterChange::where('action', 'MERGED')->exists())->toBeTrue();

    $this->getJson('/api/v1/public/vehicles/makes?q=Toyota')->assertJsonMissing(['code' => 'TOYOTA_DUP']);
});

it('keeps a canonical vehicle record on vehicle risk assets and backfills make/model ids', function () {
    $tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Vehicle Tenant '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Owner', 'status' => 'ACTIVE']);
    // Existing asset created before master data existed.
    $legacy = RiskAsset::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'VEHICLE', 'display_name' => 'Old car', 'facts' => ['make' => 'Mercedes Benz', 'model' => 'C-Class', 'year' => 2012, 'registration_number' => 'LT 1'], 'facts_hash' => 'h', 'status' => 'ACTIVE', 'version' => 1]);
    expect(RiskAssetVehicle::where('risk_asset_id', $legacy->id)->value('reconciliation_status'))->toBe('UNRESOLVED');

    vehicleSeed(); // seeding reconciles unresolved records

    $rec = RiskAssetVehicle::where('risk_asset_id', $legacy->id)->firstOrFail();
    expect($rec->make->code)->toBe('MERCEDES_BENZ')
        ->and($rec->make_text)->toBe('Mercedes Benz')
        ->and($rec->model_year)->toBe(2012)
        ->and($rec->registration_number)->toBe('LT 1');

    $new = RiskAsset::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'VEHICLE', 'display_name' => 'Prado', 'facts' => ['make_code' => 'TOYOTA', 'model_code' => 'TOYOTA_LAND_CRUISER_PRADO', 'make' => 'TOYOTA', 'model' => 'LAND CRUISER PRADO', 'year' => 2020, 'powertrain' => 'DIESEL', 'vehicle_usage' => 'TAXI', 'vin' => 'JT123', 'vehicle_value' => 15000000], 'facts_hash' => 'h', 'status' => 'ACTIVE', 'version' => 1]);
    $rec = RiskAssetVehicle::where('risk_asset_id', $new->id)->firstOrFail();
    expect($rec->reconciliation_status)->toBe('MATCHED')
        ->and($rec->model->code)->toBe('TOYOTA_LAND_CRUISER_PRADO')
        ->and($rec->usage)->toBe('TAXI')
        ->and($rec->vin)->toBe('JT123')
        ->and($rec->declared_value)->toBe(15000000);

    // Manual entry queued from the picker before the asset existed is linked, then reconciled on approval.
    $review = app(VehicleMasterReviewService::class)->submit(['make' => 'Zotye', 'model' => 'Z100'], null);
    $manual = RiskAsset::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'VEHICLE', 'display_name' => 'Zotye', 'facts' => ['make' => 'Zotye', 'model' => 'Z100', 'vehicle_review_id' => $review->id], 'facts_hash' => 'h', 'status' => 'ACTIVE', 'version' => 1]);
    expect(RiskAssetVehicle::where('risk_asset_id', $manual->id)->value('reconciliation_status'))->toBe('PENDING_REVIEW')
        ->and($review->fresh()->risk_asset_id)->toBe($manual->id);
    app(VehicleMasterReviewService::class)->approveAsNew($review->fresh(), vehicleUser());
    expect(RiskAssetVehicle::where('risk_asset_id', $manual->id)->first())
        ->reconciliation_status->toBe('MATCHED')
        ->make_id->toBe(VehicleMake::where('code', 'ZOTYE')->value('id'));

    // Non-vehicle assets are untouched.
    $home = RiskAsset::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'PROPERTY', 'display_name' => 'House', 'facts' => [], 'facts_hash' => 'h', 'status' => 'ACTIVE', 'version' => 1]);
    expect(RiskAssetVehicle::where('risk_asset_id', $home->id)->exists())->toBeFalse();
});

it('maps the 28 usages onto the tariff usage_type without rating on make or origin', function () {
    $data = vehicleData();
    foreach ($data['enums']['usage_types'] as $usage) {
        expect(VehicleUsageMapper::toUsageType($usage))->toBeIn(['PRIVATE', 'COMMERCIAL']);
    }
    expect(VehicleUsageMapper::toUsageType('PRIVATE_FAMILY'))->toBe('PRIVATE')
        ->and(VehicleUsageMapper::toUsageType('TAXI'))->toBe('COMMERCIAL')
        ->and(VehicleUsageMapper::withDerivedFacts(['vehicle_usage' => 'PRIVATE_PERSONAL']))->toMatchArray(['usage_type' => 'PRIVATE'])
        ->and(VehicleUsageMapper::withDerivedFacts(['vehicle_usage' => 'TAXI', 'usage_type' => 'TAXI'])['usage_type'])->toBe('TAXI');
});

it('exposes a MOTOR risk schema with make/model selectors and conditional commercial/EV fields', function () {
    $schema = RiskSchemaCatalogue::for('MOTOR');
    $fields = collect($schema['fields'])->keyBy('key');

    expect($fields['make_code']['type'])->toBe('VEHICLE_MAKE')
        ->and($fields['make_code']['source'])->toBe('/api/v1/public/vehicles/makes')
        ->and($fields['model_code']['type'])->toBe('VEHICLE_MODEL')
        ->and($fields['model_code']['depends_on'])->toBe('make_code')
        ->and($fields->has('make'))->toBeFalse()
        ->and($fields['year']['type'])->toBe('select')
        ->and($fields['year']['options'][0]['value'])->toBe((string) (now()->year + 1))
        ->and(collect($fields['year']['options'])->last()['value'])->toBe('1950')
        ->and($fields['vehicle_usage']['options'])->toHaveCount(28)
        ->and($fields['vehicle_class']['options'])->toHaveCount(23)
        ->and($fields['battery_capacity_kwh']['visible_when'])->toBe(['powertrain' => ['PHEV', 'BEV']])
        ->and($fields['gross_vehicle_weight_kg']['visible_when'])->toHaveKey('vehicle_usage')
        ->and($schema['required'])->toContain('registration_number', 'fiscal_power', 'usage_type', 'zone');
});

it('renders the Filament vehicle master screens for platform admins only and approves from the queue', function () {
    require_once base_path('tests/Feature/Wave12/Concerns/mobile_auth_helpers.php');
    vehicleSeed();
    $admin = makeMobileTestUser('+237670009901');
    makeMobileTestWorkspace($admin, ['*'], 'SYSTEM_ADMIN');
    $agent = makeMobileTestUser('+237670009902');
    makeMobileTestWorkspace($agent, ['*'], 'AGENT');
    $review = app(VehicleMasterReviewService::class)->submit(['make' => 'Toyota', 'model' => 'Mark X'], $agent);

    $this->actingAs($agent);
    expect(VehicleMakeResource::canViewAny())->toBeFalse();

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(VehicleMasterReviewResource::canViewAny())->toBeTrue()
        ->and(VehicleMakeResource::canDelete(VehicleMake::first()))->toBeFalse();

    foreach ([
        ListVehicleMakes::class,
        ListVehicleModels::class,
        ListVehicleMasterChanges::class,
        ListVehicleReferenceValues::class,
    ] as $page) {
        Livewire::test($page)->assertOk();
    }

    Livewire::test(ListVehicleMasterReviews::class)
        ->assertCanSeeTableRecords([$review])
        ->callTableAction('approveNew', $review, ['segment' => 'PASSENGER'])
        ->assertHasNoTableActionErrors();

    expect($review->fresh()->status)->toBe('APPROVED_NEW')
        ->and($review->fresh()->reviewed_by)->toBe($admin->id)
        ->and(VehicleModel::where('code', 'TOYOTA_MARK_X')->exists())->toBeTrue();
});

it('upgrades a stored free-text MOTOR wizard schema on seeding and serves the selectors', function () {
    InsuranceLine::create(['code' => 'MOTOR', 'name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => [
        'required' => ['registration_number', 'fiscal_power', 'usage_type', 'zone'],
        'steps' => [['key' => 'vehicle', 'label' => 'Vehicle']],
        'fields' => [['key' => 'make', 'label' => 'Make', 'type' => 'text', 'step' => 'vehicle', 'required' => true]],
    ]]);
    vehicleSeed();

    $schema = InsuranceLine::where('code', 'MOTOR')->first()->risk_schema;
    expect(collect($schema['fields'])->firstWhere('key', 'make_code')['type'])->toBe('VEHICLE_MAKE')
        ->and($schema['required'])->toBe(['registration_number', 'fiscal_power', 'usage_type', 'zone']);
});
