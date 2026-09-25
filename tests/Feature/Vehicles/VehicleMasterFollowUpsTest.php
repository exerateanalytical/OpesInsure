<?php

declare(strict_types=1);

/*
 * Vehicle master follow-ups.
 * REQ-MDM-003 / REQ-DUP-013: vehicle "not listed" goes through master-data/suggestions (domain "vehicle");
 *   mobile/vehicles/master-review stays as a deprecated alias.
 * CUST-007: generations / variants API + admin screens, empty by default.
 * Two/three-wheeler classes and body types; fleet legacy codes MOTORCYCLE / TRICYCLE resolve.
 * Candidate makes (Datsun, McLaren, Mahindra, Lada, UAZ) are review items, not catalogue rows.
 */

use App\Application\MasterData\VehicleMasterSource;
use App\Application\Vehicles\MotorRiskSchema;
use App\Application\Vehicles\VehicleMasterAdminService;
use App\Application\Vehicles\VehicleMasterReviewService;
use App\Filament\Admin\Resources\VehicleGenerations\Pages\ListVehicleGenerations;
use App\Filament\Admin\Resources\VehicleVariants\Pages\ListVehicleVariants;
use App\Models\Party;
use App\Models\User;
use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMasterChange;
use App\Models\Vehicles\VehicleMasterReview;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleReferenceValue;
use App\Models\Vehicles\VehicleVariant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function b2vSeed(): void
{
    test()->artisan('opesinsure:seed-vehicles')->assertExitCode(0);
}

function b2vUser(string $name = 'B2V User'): User
{
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => $name, 'status' => 'ACTIVE']);

    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

it('adds two/three-wheeler vehicle classes and body types with EN/FR labels [REQ-MDM-003]', function () {
    b2vSeed();
    b2vSeed(); // idempotent

    foreach (['MOPED', 'MOTORCYCLE', 'TRICYCLE', 'QUADRICYCLE'] as $code) {
        $row = VehicleReferenceValue::where(['group' => 'vehicle_class', 'code' => $code])->first();
        expect($row)->not->toBeNull()->and($row->label_en)->not->toBe('')->and($row->label_fr)->not->toBe('');
    }
    foreach (['MOPED', 'SCOOTER', 'MOTORCYCLE', 'TRICYCLE_PASSENGER', 'TRICYCLE_CARGO', 'QUAD'] as $code) {
        expect(VehicleReferenceValue::where(['group' => 'body_type', 'code' => $code])->exists())->toBeTrue();
    }
    expect(VehicleReferenceValue::where(['group' => 'vehicle_class', 'code' => 'MOTORCYCLE'])->value('label_fr'))->toBe('Motocyclette');

    $r = $this->getJson('/api/v1/public/vehicles/reference')->assertOk();
    expect($r->json('data.vehicle_classes.*.code'))->toContain('MOTORCYCLE', 'TRICYCLE')
        ->and($r->json('data.body_types.*.code'))->toContain('SCOOTER', 'TRICYCLE_CARGO');

    // Former specialty fleet codes now resolve to canonical vehicle classes.
    $source = app(VehicleMasterSource::class);
    expect($source->exists('vehicle_class', 'MOTORCYCLE', null))->toBeTrue()
        ->and($source->exists('vehicle_class', 'TRICYCLE', null))->toBeTrue()
        ->and(collect($source->search('vehicle_class', 'moto', null))->pluck('code'))->toContain('MOTORCYCLE');

    $fields = collect(MotorRiskSchema::schema()['fields'])->keyBy('key');
    expect(collect($fields['vehicle_class']['options'])->pluck('value'))->toContain('MOTORCYCLE', 'TRICYCLE')
        ->and(collect($fields['body_type']['options'])->pluck('value'))->toContain('MOTORCYCLE', 'SCOOTER');
});

it('lists McLaren, Lada and UAZ as admin review items; Datsun and Mahindra are curated reference makes without models (owner decision 20)', function () {
    b2vSeed();
    b2vSeed();

    foreach (['McLaren', 'Lada', 'UAZ'] as $name) {
        expect(VehicleMake::where('name', $name)->exists())->toBeFalse()
            ->and(VehicleMasterReview::where('make_text', $name)->where('status', VehicleMasterReview::STATUS_PENDING)->count())->toBe(1);
    }
    foreach (['DATSUN' => 'Datsun', 'MAHINDRA' => 'Mahindra'] as $code => $name) {
        $make = VehicleMake::where('code', $code)->firstOrFail();
        expect(VehicleMake::where('name', $name)->count())->toBe(1)
            ->and($make->data_source)->toBe('OPESINSURE_CURATED_REFERENCE')->and($make->data_source)->not->toBe('OPESINSURE_VERIFIED_OVERRIDE')
            ->and($make->provenance)->toBe('CURATED_REFERENCE')->and($make->cameroon_status)->toBe('UNVERIFIED')
            ->and(VehicleModel::where('make_id', $make->id)->count())->toBe(0)
            ->and(VehicleMasterReview::where('make_text', $name)->where('status', VehicleMasterReview::STATUS_PENDING)->count())->toBe(0);
    }
    $lada = VehicleMasterReview::where('make_text', 'Lada')->first();
    expect($lada->model_text)->toBe('')->and($lada->payload['source'])->toBe('CATALOGUE_CANDIDATE');

    // Make-only approval creates the make (UNVERIFIED) and no model.
    $admin = b2vUser('Admin');
    app(VehicleMasterReviewService::class)->approveAsNew($lada, $admin, ['segment' => 'PASSENGER', 'country_of_origin' => 'RU']);
    $make = VehicleMake::where('code', 'LADA')->firstOrFail();
    expect($make->cameroon_status)->toBe('UNVERIFIED')->and($make->provenance)->toBe('CUSTOMER_SUBMITTED')
        ->and(VehicleModel::where('make_id', $make->id)->count())->toBe(0)
        ->and($lada->fresh()->status)->toBe('APPROVED_NEW');

    b2vSeed(); // approved make is not re-queued
    expect(VehicleMasterReview::where('make_text', 'Lada')->count())->toBe(1);
});

it('routes vehicle suggestions through the canonical master-data/suggestions endpoint [REQ-DUP-013]', function () {
    b2vSeed();
    $user = b2vUser('Customer');
    Passport::actingAs($user);

    // Known make + model: matched, nothing queued.
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'vehicle', 'list' => 'models', 'parent' => 'TOYOTA', 'text' => 'corolla'])
        ->assertOk()->assertJsonPath('data.status', 'MATCHED')->assertJsonPath('data.value.model', 'TOYOTA_COROLLA');

    $before = VehicleMasterReview::count();
    $res = $this->postJson('/api/v1/master-data/suggestions', [
        'domain' => 'vehicle', 'list' => 'models', 'parent' => 'Zotye', 'text' => 'T600',
        'attributes' => ['model_year' => 2018, 'body_type' => 'SUV', 'vin' => 'lj1234567890abcde', 'usage' => 'PRIVATE_PERSONAL'],
    ])->assertCreated()->assertJsonPath('data.status', 'MASTER_DATA_REVIEW_REQUIRED')->assertJsonPath('data.review.domain', 'vehicle');
    $review = VehicleMasterReview::findOrFail($res->json('data.review.id'));
    expect(VehicleMasterReview::count())->toBe($before + 1)
        ->and($review->make_text)->toBe('Zotye')->and($review->model_text)->toBe('T600')
        ->and($review->model_year)->toBe(2018)->and($review->vin)->toBe('LJ1234567890ABCDE')
        ->and($review->submitted_by)->toBe($user->id);

    // Typed make resolved to canonical name; make-only entry; validation.
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'vehicle', 'list' => 'models', 'parent' => 'toyota', 'text' => 'Mark X'])->assertCreated()
        ->assertJsonPath('data.review.make', 'Toyota');
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'vehicle', 'list' => 'makes', 'text' => 'Haojue'])->assertCreated()
        ->assertJsonPath('data.review.list', 'makes')->assertJsonPath('data.review.model', null);
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'vehicle', 'list' => 'models', 'text' => 'X'])->assertUnprocessable();
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'vehicle', 'list' => 'body_type', 'text' => 'Hovercraft'])->assertUnprocessable();
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'vehicle', 'list' => 'models', 'parent' => 'TOYOTA', 'text' => 'Y', 'attributes' => ['body_type' => 'NOPE']])->assertUnprocessable();
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'vehicle', 'list' => 'models', 'parent' => 'TOYOTA', 'text' => 'Y', 'attributes' => ['risk_asset_id' => (string) Str::uuid()]])->assertUnprocessable();

    // Deprecated alias still works, same queue, flagged.
    $legacy = $this->postJson('/api/v1/mobile/vehicles/master-review', ['make' => 'Bajaj', 'model' => 'Boxer', 'body_type' => 'MOTORCYCLE'])
        ->assertCreated()->assertJsonPath('data.status', 'MASTER_DATA_REVIEW_REQUIRED')->assertHeader('Deprecation', 'true');
    expect($legacy->headers->get('Link'))->toContain('/api/v1/master-data/suggestions')
        ->and(VehicleMasterReview::find($legacy->json('data.id'))->body_type)->toBe('MOTORCYCLE');

    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'vehicle', 'list' => 'models', 'text' => 'x'])->assertUnprocessable();
});

it('serves empty generations/variants and exposes admin-added ones [CUST-007]', function () {
    b2vSeed();
    expect(VehicleGeneration::count())->toBe(0)->and(VehicleVariant::count())->toBe(0);

    $this->getJson('/api/v1/public/vehicles/models/TOYOTA_COROLLA/generations')->assertOk()
        ->assertJsonPath('model.code', 'TOYOTA_COROLLA')->assertJsonPath('model.make', 'TOYOTA')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/public/vehicles/models/NOPE/generations')->assertNotFound();
    $this->getJson('/api/v1/public/vehicles/models/TOYOTA_COROLLA/generations/NOPE/variants')->assertNotFound();

    $admin = b2vUser('Admin');
    $svc = app(VehicleMasterAdminService::class);
    $corolla = VehicleModel::where('code', 'TOYOTA_COROLLA')->firstOrFail();
    $gen = $svc->createGeneration($corolla, ['name' => 'E210', 'year_from' => 2018], $admin);
    expect($gen->code)->toBe('TOYOTA_COROLLA_E210')
        ->and(fn () => $svc->createGeneration($corolla, ['name' => 'e 210'], $admin))->toThrow(ValidationException::class)
        ->and(fn () => $svc->createGeneration($corolla, ['name' => 'X', 'year_from' => 2020, 'year_to' => 2010], $admin))->toThrow(ValidationException::class);

    $variant = $svc->createVariant($corolla, $gen, ['name' => '1.8 Hybrid', 'body_type' => 'SEDAN', 'powertrain' => 'HYBRID', 'hybrid_subtype' => 'HEV', 'engine_capacity_cc' => '1798'], $admin);
    expect($variant->code)->toBe('TOYOTA_COROLLA_E210_1_8_HYBRID')->and($variant->engine_capacity_cc)->toBe(1798)
        ->and(fn () => $svc->createVariant($corolla, $gen, ['name' => 'Bad', 'body_type' => 'NOPE'], $admin))->toThrow(ValidationException::class);
    $other = VehicleModel::where('code', '!=', 'TOYOTA_COROLLA')->first();
    expect(fn () => $svc->createVariant($other, $gen, ['name' => 'X'], $admin))->toThrow(ValidationException::class);

    $this->getJson('/api/v1/public/vehicles/models/toyota_corolla/generations')->assertOk()
        ->assertJsonPath('data.0.code', 'TOYOTA_COROLLA_E210')->assertJsonPath('data.0.year_from', 2018)->assertJsonPath('data.0.variants_count', 1);
    $this->getJson('/api/v1/public/vehicles/models/TOYOTA_COROLLA/generations/TOYOTA_COROLLA_E210/variants')->assertOk()
        ->assertJsonPath('generation.name', 'E210')->assertJsonPath('data.0.powertrain', 'HYBRID')->assertJsonPath('data.0.engine_capacity_cc', 1798);

    $svc->updateVariant($variant, ['active' => false], $admin);
    $svc->updateGeneration($gen, ['year_to' => 2025], $admin);
    expect($gen->fresh()->year_to)->toBe(2025)->and($gen->fresh()->admin_modified_at)->not->toBeNull();
    $this->getJson('/api/v1/public/vehicles/models/TOYOTA_COROLLA/generations/TOYOTA_COROLLA_E210/variants')->assertOk()->assertJsonCount(0, 'data');
    expect(VehicleMasterChange::whereIn('entity_type', ['vehicle_generation', 'vehicle_variant'])->count())->toBe(4)
        ->and(fn () => $gen->delete())->toThrow(LogicException::class);
});

it('renders the generation and variant admin screens and adds rows from them [CUST-007]', function () {
    require_once base_path('tests/Feature/Wave12/Concerns/mobile_auth_helpers.php');
    b2vSeed();
    $admin = makeMobileTestUser('+237670009911');
    makeMobileTestWorkspace($admin, ['*'], 'SYSTEM_ADMIN');
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $corolla = VehicleModel::where('code', 'TOYOTA_COROLLA')->firstOrFail();

    Livewire::test(ListVehicleGenerations::class)->assertOk()
        ->callAction('addGeneration', ['model_id' => $corolla->id, 'name' => 'E170', 'year_from' => 2013, 'year_to' => 2019])
        ->assertHasNoActionErrors();
    $gen = VehicleGeneration::where('code', 'TOYOTA_COROLLA_E170')->firstOrFail();

    Livewire::test(ListVehicleVariants::class)->assertOk()
        ->callAction('addVariant', ['model_id' => $corolla->id, 'generation_id' => $gen->id, 'name' => '1.6 Manual', 'transmission' => 'MANUAL', 'powertrain' => 'PETROL'])
        ->assertHasNoActionErrors();
    expect(VehicleVariant::where('generation_id', $gen->id)->value('transmission'))->toBe('MANUAL');
});
