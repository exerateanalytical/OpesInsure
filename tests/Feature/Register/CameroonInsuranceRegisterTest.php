<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\InsuranceClass;
use App\Models\InsuranceProduct;
use App\Models\IntermediaryAuthorization;
use App\Models\InsurerAuthorization;
use App\Models\SeedCatalogVersion;
use App\Models\Partner;
use App\Models\Party;
use App\Models\User;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedCatalogue(): void
{
    // PlatformCatalogueSeeder stamps created_by with the oldest user.
    User::firstOrCreate(['phone_e164' => '+237600000001'], ['full_name' => 'System']);
    test()->seed(PlatformCatalogueSeeder::class);
}

function seedRegister(): void
{
    test()->seed(CameroonInsuranceRegisterSeeder::class);
}

it('seeds all 29 licensed insurers and 123 authorized brokers from the official register', function () {
    seedRegister();

    expect(Carrier::where('is_official_register', true)->count())->toBe(29)
        ->and(Carrier::where('is_official_register', true)->where('licence_branch', 'IARD')->count())->toBe(18)
        ->and(Carrier::where('is_official_register', true)->where('licence_branch', 'LIFE')->count())->toBe(11)
        ->and(Partner::where('type', 'BROKER')->where('is_official_register', true)->count())->toBe(123);

    $chanas = Carrier::where('short_name', 'CHANAS')->firstOrFail();
    expect($chanas->legal_name)->toBe('CHANAS ASSURANCES')
        ->and($chanas->trade_name)->toBe('Chanas Assurances')
        ->and($chanas->canonical_id)->toBe('CM-INS-IARD-008')
        ->and($chanas->insurer_code)->toBe('CHANAS')
        ->and($chanas->data_origin)->toBe('REGULATORY')
        ->and($chanas->source_authority)->toBe('DGTCFM/MINFI')
        ->and($chanas->reference_year)->toBe(2026)
        ->and($chanas->regulatory_status)->toBe('AUTHORIZED')
        ->and($chanas->country_code)->toBe('CM')
        ->and($chanas->currency)->toBe('XAF')
        ->and($chanas->product_families_origin)->toBe('CARRIER_PUBLISHED')
        ->and($chanas->product_families_status)->toBe('UNVERIFIED')
        ->and($chanas->regulator_sequence)->toBe(8)
        ->and($chanas->register_source)->toBe('DGTCFM_2026')
        ->and($chanas->status)->toBe('ACTIVE')
        ->and($chanas->product_families)->toContain('Automobile');

    expect(Carrier::where('canonical_id', 'CM-INS-IARD-012')->value('insurer_code'))->toBe('NSIA_IARD')
        ->and(Carrier::where('canonical_id', 'CM-INS-LIFE-001')->value('insurer_code'))->toBe('ACAM_VIE')
        ->and(Carrier::where('canonical_id', 'CM-INS-LIFE-011')->value('insurer_code'))->toBe('WAFA_VIE')
        ->and(Carrier::whereNotNull('canonical_id')->distinct()->count('canonical_id'))->toBe(29)
        ->and(Partner::whereNotNull('canonical_id')->distinct()->count('canonical_id'))->toBe(123)
        ->and(InsurerAuthorization::where('reference_year', 2026)->where('status', 'AUTHORIZED')->count())->toBe(29)
        ->and(IntermediaryAuthorization::where('reference_year', 2026)->where('status', 'AUTHORIZED')->where('intermediary_type', 'BROKER')->count())->toBe(123)
        ->and(IntermediaryAuthorization::first()->effective_from->toDateString())->toBe('2026-01-01')
        ->and(IntermediaryAuthorization::first()->effective_until)->toBeNull();

    $version = SeedCatalogVersion::where('dataset', 'CM_INSURANCE_MARKET')->firstOrFail();
    expect($version->version)->toBe('2026.1')->and($version->status)->toBe('ACTIVE')->and($version->source)->toBe('DGTCFM/MINFI');

    $first = Partner::where('type', 'BROKER')->where('regulator_sequence', 1)->firstOrFail();
    expect($first->legal_name)->toBe('ACACE SARL')
        ->and($first->canonical_id)->toBe('CM-BRK-2026-001')
        ->and($first->licence_number)->toBeNull()
        ->and($first->party->legal_identity)->toBe(['country' => 'CM'])
        ->and($first->status)->toBe('ACTIVE')
        ->and($first->register_source)->toBe('DGTCFM_2026')
        ->and($first->party->display_name)->toBe('ACACE SARL');
    expect(Partner::where('type', 'BROKER')->where('regulator_sequence', 123)->exists())->toBeTrue();
});

it('is idempotent: re-seeding keeps counts at 29/123 and ids stable', function () {
    seedRegister();
    $ids = Carrier::orderBy('id')->pluck('id')->all();
    $classes = InsuranceClass::count();
    seedRegister();
    $this->artisan('opesinsure:seed-regulatory', ['--country' => 'CM', '--year' => 2026])->assertSuccessful();
    $this->artisan('opesinsure:seed-regulatory', ['--country' => 'GA', '--year' => 2026])->assertFailed();

    expect(Carrier::where('is_official_register', true)->count())->toBe(29)
        ->and(Partner::where('type', 'BROKER')->where('is_official_register', true)->count())->toBe(123)
        ->and(Carrier::orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(InsuranceClass::count())->toBe($classes)
        ->and(InsurerAuthorization::count())->toBe(29)
        ->and(IntermediaryAuthorization::count())->toBe(123)
        ->and(SeedCatalogVersion::count())->toBe(1)
        ->and(Party::where('display_name', 'ACACE SARL')->count())->toBe(1);
});

it('merges demo carriers in place instead of duplicating them, in either seed order', function () {
    seedCatalogue();
    $chanas = Carrier::where('cima_code', 'ASAC-CHANAS')->firstOrFail();
    $productCount = InsuranceProduct::where('carrier_id', $chanas->id)->count();
    $demoCarriers = Carrier::count();

    seedRegister();
    seedCatalogue();

    $chanas->refresh();
    expect($chanas->is_official_register)->toBeTrue()
        ->and($chanas->licence_branch)->toBe('IARD')
        ->and(InsuranceProduct::where('carrier_id', $chanas->id)->count())->toBe($productCount)
        ->and(Carrier::where('short_name', 'SANLAMALLIANZ VIE')->value('cima_code'))->toBe('ASAC-SANLAMALLIANZ_VIE')
        // every demo carrier is in the official list, so total = 29
        ->and(Carrier::count())->toBe(29)
        ->and($demoCarriers)->toBe(8);
});

it('leaves demo-only carriers outside the register unflagged', function () {
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Demo Only Mutual', 'status' => 'ACTIVE']);
    $demo = Carrier::create(['party_id' => $party->id, 'cima_code' => 'ASAC-DEMOONLY', 'status' => 'ACTIVE']);
    seedRegister();

    $demo->refresh();
    expect($demo->is_official_register)->toBeFalse()
        ->and($demo->data_origin)->toBe('DEMO_SYNTHETIC')
        ->and($demo->is_demo)->toBeTrue()
        ->and(Carrier::count())->toBe(30);
    $demo->delete();
    expect(Carrier::count())->toBe(29);
});

it('refuses to delete official carriers, brokers and classes at model and database level', function () {
    seedRegister();
    $carrier = Carrier::where('is_official_register', true)->firstOrFail();
    $broker = Partner::where('is_official_register', true)->firstOrFail();
    $class = InsuranceClass::where('is_official_register', true)->firstOrFail();

    expect(fn () => $carrier->delete())->toThrow(LogicException::class);
    expect(fn () => $broker->delete())->toThrow(LogicException::class);
    expect(fn () => $class->delete())->toThrow(LogicException::class);

    expect(fn () => DB::table('carriers')->where('id', $carrier->id)->delete())->toThrow(QueryException::class);
});

it('refuses database-level deletes of official brokers', function () {
    seedRegister();
    $broker = Partner::where('is_official_register', true)->firstOrFail();
    // savepoint so the trigger's exception doesn't abort the test transaction
    expect(fn () => DB::transaction(fn () => DB::table('partners')->where('id', $broker->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('intermediary_authorizations')->delete()))->toThrow(QueryException::class);
    expect(Partner::where('is_official_register', true)->count())->toBe(123);
});

it('seeds the insurance class taxonomy with EN/FR names', function () {
    seedRegister();

    $motor = InsuranceClass::whereNull('parent_id')->where('code', 'MOTOR')->firstOrFail();
    expect($motor->branch)->toBe('IARD')
        ->and($motor->name['en'])->toBe('Motor Insurance')
        ->and($motor->name['fr'])->toBe('Assurance Automobile')
        ->and($motor->data_origin)->toBe('PLATFORM_NORMALIZED')
        ->and($motor->children()->count())->toBe(8);
    expect(InsuranceClass::whereNull('parent_id')->where('branch', 'LIFE')->count())->toBe(1);
    foreach (['HOME_MULTIRISK', 'PROFESSIONAL_LIABILITY', 'MARINE_CARGO', 'INLAND_TRANSIT', 'SURETY_BONDS', 'TERM_LIFE', 'SAVINGS', 'RETIREMENT', 'EDUCATION', 'CREDIT_LIFE', 'GROUP_LIFE', 'FUNERAL', 'PERSONAL_ACCIDENT', 'BUSINESS_MULTIRISK', 'CONSTRUCTION'] as $code) {
        expect(InsuranceClass::where('code', $code)->exists())->toBeTrue();
    }

    $response = $this->getJson('/api/v1/public/insurance-classes')->assertOk();
    expect($response->json('data'))->toHaveCount(13)
        ->and($response->json('data.0.code'))->toBe('MOTOR')
        ->and($response->json('data.0.name.fr'))->toBe('Assurance Automobile')
        ->and($response->json('data.0.sub_classes'))->toHaveCount(8)
        ->and($response->json('source'))->toBe('DGTCFM_2026');
});

it('lists all 29 insurers with branch, short name and families, filterable', function () {
    seedRegister();

    $all = $this->getJson('/api/v1/public/institutions?type=insurer')->assertOk();
    expect($all->json('data'))->toHaveCount(29);
    $row = collect($all->json('data'))->firstWhere('short_name', 'CHANAS');
    expect($row)->toHaveKeys(['id', 'type', 'name', 'initials', 'code', 'city', 'phone', 'website', 'products', 'branch', 'short_name', 'regulator_sequence', 'product_families', 'is_official_register', 'licensed', 'canonical_id', 'data_origin', 'product_families_status'])
        ->and($row['canonical_id'])->toBe('CM-INS-IARD-008')
        ->and($row['name'])->toBe('Chanas Assurances')
        ->and($row['branch'])->toBe('IARD')
        ->and($row['is_official_register'])->toBeTrue()
        ->and($all->json('meta.counts'))->toBe(['insurer' => 29, 'broker' => 123, 'IARD' => 18, 'LIFE' => 11]);

    expect($this->getJson('/api/v1/public/institutions?type=insurer&branch=LIFE')->json('data'))->toHaveCount(11);
    expect($this->getJson('/api/v1/public/institutions?type=insurer&branch=IARD')->json('data'))->toHaveCount(18);
    $q = $this->getJson('/api/v1/public/institutions?type=insurer&q=sanlam')->json('data');
    expect(collect($q)->pluck('short_name')->sort()->values()->all())->toBe(['SANLAMALLIANZ', 'SANLAMALLIANZ VIE']);
    $this->getJson('/api/v1/public/institutions?branch=MARINE')->assertStatus(422);
});

it('lists all 123 brokers in regulator order with regulator number and search', function () {
    seedRegister();

    $brokers = $this->getJson('/api/v1/public/institutions?type=broker')->assertOk()->json('data');
    expect($brokers)->toHaveCount(123)
        ->and($brokers[0]['regulator_number'])->toBe(1)
        ->and($brokers[0]['name'])->toBe('ACACE SARL')
        ->and($brokers[0]['licensed'])->toBeTrue()
        ->and($brokers[0]['canonical_id'])->toBe('CM-BRK-2026-001')
        ->and($brokers[122]['regulator_number'])->toBe(123);

    $hit = $this->getJson('/api/v1/public/institutions?type=broker&q=ascoma')->json('data');
    expect($hit)->toHaveCount(1)->and($hit[0]['name'])->toBe('ASCOMA');
    expect($this->getJson('/api/v1/public/institutions?type=broker&q=15')->json('data.0.regulator_number'))->toBe(15);

    $detail = $this->getJson('/api/v1/public/institutions/'.$hit[0]['id'])->assertOk();
    expect($detail->json('data.regulator_number'))->toBe(15);
});

it('shows insurer detail with families and linked products', function () {
    seedCatalogue();
    seedRegister();
    $chanas = Carrier::where('short_name', 'CHANAS')->firstOrFail();

    $d = $this->getJson("/api/v1/public/institutions/{$chanas->id}")->assertOk();
    expect($d->json('data.branch'))->toBe('IARD')
        ->and($d->json('data.product_families'))->not->toBeEmpty()
        ->and($d->json('data.products'))->not->toBeEmpty();
    $this->getJson('/api/v1/public/institutions/'.Str::uuid())->assertNotFound();
});

it('matches alias names so NSIA variants never duplicate', function () {
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'NSIA Assurances', 'status' => 'ACTIVE']);
    $nsia = Carrier::create(['party_id' => $party->id, 'cima_code' => 'LEGACY-NSIA', 'status' => 'ACTIVE']);
    seedRegister();

    expect($nsia->refresh()->canonical_id)->toBe('CM-INS-IARD-012')
        ->and(Carrier::count())->toBe(29)
        ->and(Carrier::where('canonical_id', 'CM-INS-LIFE-006')->value('id'))->not->toBe($nsia->id);
});
