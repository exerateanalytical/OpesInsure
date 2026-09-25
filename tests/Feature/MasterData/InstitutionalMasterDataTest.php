<?php

declare(strict_types=1);

use App\Application\Catalogue\NonMotorRiskSchemas;
use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Application\MasterData\MasterDataCache;
use App\Application\MasterData\MasterDataCatalogue;
use App\Application\MasterData\MasterDataFlows;
use App\Application\MasterData\MasterDataReviewService;
use App\Application\MasterData\MasterDataSearch;
use App\Application\MasterData\RiskFactsProcessor;
use App\Models\InsuranceLine;
use App\Models\MasterData\MasterDataAlias;
use App\Models\MasterData\MasterDataChange;
use App\Models\MasterData\MasterDataReviewItem;
use App\Models\MasterData\MasterDataValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function mdSeed(): void
{
    test()->artisan('opesinsure:seed-master-data')->assertExitCode(0);
}

function mdCore(): array
{
    return json_decode(file_get_contents(database_path('data/master_data/core_2026.json')), true, 512, JSON_THROW_ON_ERROR);
}

function mdUser(): App\Models\User
{
    require_once base_path('tests/Feature/Wave12/Concerns/mobile_auth_helpers.php');

    return makeMobileTestUser('+2376'.random_int(10000000, 99999999));
}

function mdProcess(string $line, array $facts): array
{
    return app(RiskFactsProcessor::class)->process($line, $facts);
}

it('ships the core catalogue with EN/FR labels, canonical codes and the owner seed order', function () {
    $doc = mdCore();
    $codes = array_column($doc['domains'], 'code');
    expect(array_slice($codes, 0, 7))->toBe(['geography', 'languages', 'currencies', 'organizations', 'persons', 'occupations', 'industries']);

    foreach ($doc['domains'] as $d) {
        foreach ($d['lists'] as $l) {
            $vals = array_column($l['values'], 'code');
            expect(array_unique($vals))->toHaveCount(count($vals), "{$d['code']}.{$l['code']}");
            foreach ($l['values'] as $v) {
                expect($v['code'])->toMatch('/^[A-Z0-9_]+$/')->and($v['label_en'])->not->toBeEmpty()->and($v['label_fr'])->not->toBeEmpty();
            }
        }
    }
    $lists = collect($doc['domains'])->flatMap(fn ($d) => collect($d['lists'])->mapWithKeys(fn ($l) => ["{$d['code']}.{$l['code']}" => $l]));
    $cm = collect($lists['geography.country']['values'])->firstWhere('code', 'CM');
    expect(count($lists['geography.country']['values']))->toBeGreaterThanOrEqual(245)
        ->and($cm['label_fr'])->toBe('Cameroun')
        ->and($cm['attributes'])->toMatchArray(['iso3' => 'CMR', 'currency' => 'XAF', 'dialing_code' => '+237', 'timezones' => ['Africa/Douala']])
        ->and($lists['geography.cameroon_region']['values'])->toHaveCount(10)
        ->and($lists['geography.cameroon_department']['values'])->toHaveCount(58)
        ->and($lists['geography.cameroon_arrondissement']['values'])->toBe([])
        ->and($lists['geography.cameroon_arrondissement']['structure_only'])->toBeTrue()
        ->and($lists['currencies.currency']['values'][0]['code'])->toBe('XAF')
        ->and(count($lists['occupations.occupation']['values']))->toBeGreaterThanOrEqual(200)
        ->and($lists['industries.sector']['values'])->toHaveCount(33); // 32 sectors + Other
    $doctor = collect($lists['occupations.occupation']['values'])->firstWhere('code', 'MEDICAL_DOCTOR');
    expect($doctor['label_fr'])->toBe('Médecin')->and($doctor['aliases'])->toContain('Physician', 'Doctor');
});

it('seeds every domain from all master data files with EN/FR labels, idempotently', function () {
    mdSeed();
    $first = DB::table('master_data_values')->count();
    $files = glob(database_path('data/master_data/*.json'));
    $expectedDomains = collect($files)->flatMap(fn ($f) => array_column(json_decode(file_get_contents($f), true)['domains'], 'code'))->unique();

    expect(DB::table('master_data_domains')->pluck('code')->sort()->values()->all())->toBe($expectedDomains->sort()->values()->all())
        ->and(DB::table('master_data_values')->where(fn ($q) => $q->where('label_en', '')->orWhere('label_fr', ''))->count())->toBe(0)
        ->and(DB::table('master_data_values')->where('is_seeded', false)->count())->toBe(0);
    foreach ($expectedDomains as $d) {
        $n = DB::table('master_data_values')->where('domain_code', $d)->count();
        $structureOnly = DB::table('master_data_lists')->where('domain_code', $d)->where('structure_only', false)->exists();
        expect($n > 0 || ! $structureOnly)->toBeTrue($d);
    }

    $versions = MasterDataCache::versions();
    mdSeed();
    expect(DB::table('master_data_values')->count())->toBe($first)
        ->and(MasterDataCache::versions()['geography']['version'])->toBe($versions['geography']['version']); // no change → no version bump
    // hierarchies resolved
    $wouri = MasterDataValue::where(['domain_code' => 'geography', 'list_code' => 'cameroon_department', 'code' => 'WOURI'])->first();
    expect($wouri->parent_code)->toBe('LITTORAL')->and($wouri->parent?->code)->toBe('LITTORAL');
});

it('protects seeded values from deletion but lets admins deactivate, and preserves admin edits on reseed', function () {
    mdSeed();
    $v = MasterDataValue::where(['domain_code' => 'occupations', 'list_code' => 'occupation', 'code' => 'NURSE'])->first();

    expect(fn () => $v->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('master_data_values')->where('id', $v->id)->delete()))->toThrow(Exception::class)
        ->and(fn () => DB::transaction(fn () => DB::table('master_data_lists')->where('id', $v->list_id)->delete()))->toThrow(Exception::class);

    $before = MasterDataCache::version('occupations');
    $v->update(['label_en' => 'Registered nurse', 'status' => 'INACTIVE']);
    expect($v->fresh()->admin_modified_at)->not->toBeNull()
        ->and(MasterDataCache::version('occupations'))->toBeGreaterThan($before)
        ->and(MasterDataChange::where('entity_id', $v->id)->where('action', 'DEACTIVATED')->exists())->toBeTrue();

    mdSeed();
    expect($v->fresh()->label_en)->toBe('Registered nurse')->and($v->fresh()->status)->toBe('INACTIVE');
});

it('searches accent-insensitively, by alias, abbreviation, French label and with typos, narrowing by parent', function () {
    mdSeed();
    $s = app(MasterDataSearch::class);
    $first = fn (string $d, string $l, string $q, ?string $p = null) => ($s->search($d, $l, $q, $p)[0]['code'] ?? null);

    expect($first('occupations', 'occupation', 'medecin'))->toBe('MEDICAL_DOCTOR')
        ->and($first('occupations', 'occupation', 'Physician'))->toBe('MEDICAL_DOCTOR')
        ->and($first('occupations', 'occupation', 'benskineur'))->toBe('MOTORCYCLE_TAXI_DRIVER')
        ->and($first('occupations', 'occupation', 'infirmiere'))->toBe('NURSE')
        ->and($first('occupations', 'occupation', 'accountnat'))->toBe('ACCOUNTANT')   // typo
        ->and($first('geography', 'country', 'cameroun'))->toBe('CM')
        ->and($first('organizations', 'legal_entity_type', 'sarl'))->toBe('SARL');

    $hotel = collect($s->search('industries', 'activity', null, 'HOSPITALITY'))->pluck('code');
    expect($hotel)->toContain('HOTEL', 'RESTAURANT', 'BAR', 'GUEST_HOUSE', 'CATERING')->not->toContain('BAKERY')
        ->and($hotel->last())->toBe('OTHER'); // "Other / Not listed" always last

    $this->getJson('/api/v1/master-data/occupations/search?list=occupation&q=m%C3%A9decin&locale=fr')->assertOk()
        ->assertJsonPath('data.values.0.code', 'MEDICAL_DOCTOR')->assertJsonPath('data.values.0.display', 'Médecin');
    $this->getJson('/api/v1/public/master-data/industries/activity?parent=HOSPITALITY')->assertOk()->assertJsonPath('data.allow_other', true);
});

it('serves domains, values and catalogue versions for the offline cache', function () {
    mdSeed();
    $res = $this->getJson('/api/v1/master-data/versions')->assertOk();
    $version = $res->json('data.domains.geography.version');
    expect($version)->toBeInt()->and($res->json('data.domains'))->toHaveKeys(['occupations', 'industries', 'life_insurance', 'property']);

    $domain = $this->getJson('/api/v1/master-data/geography?locale=fr')->assertOk()->assertJsonPath('data.catalog_version', $version);
    $regions = collect($domain->json('data.lists'))->firstWhere('code', 'cameroon_region');
    expect($regions['values'][0])->toHaveKeys(['code', 'label', 'display'])->and($regions['values'][0]['label'])->toHaveKeys(['en', 'fr']);
    $this->getJson('/api/v1/public/master-data/geography')->assertOk();
    $this->getJson('/api/v1/master-data/geography', ['If-None-Match' => '"md-geography-'.$version.'-en"'])->assertStatus(304);

    $id = MasterDataValue::where(['domain_code' => 'geography', 'code' => 'CM'])->value('id');
    $this->getJson("/api/v1/master-data/geography/$id")->assertOk()->assertJsonPath('data.code', 'CM');
    $this->getJson('/api/v1/master-data/nope')->assertNotFound();
});

it('files Other / Not listed suggestions, de-duplicates and counts them, and approves or merges', function () {
    mdSeed();
    $user = mdUser();
    Passport::actingAs($user);

    $this->postJson('/api/v1/mobile/master-data/review', ['domain' => 'occupations', 'list' => 'occupation', 'text' => 'Drone pilot'])->assertCreated()
        ->assertJsonPath('data.status', 'SUBMITTED');
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'occupations', 'list' => 'occupation', 'text' => 'drone  PILOT'])->assertCreated();
    $item = MasterDataReviewItem::where('normalized', 'drone pilot')->sole();
    expect($item->submission_count)->toBe(2)->and($item->raw_input)->toBe('Drone pilot');

    // Text that already matches a value (alias) is resolved, not queued.
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'occupations', 'list' => 'occupation', 'text' => 'Physician'])->assertOk()
        ->assertJsonPath('data.status', 'MATCHED')->assertJsonPath('data.value.code', 'MEDICAL_DOCTOR');
    $this->postJson('/api/v1/master-data/suggestions', ['domain' => 'occupations', 'list' => 'nope', 'text' => 'x'])->assertUnprocessable();

    $svc = app(MasterDataReviewService::class);
    $value = $svc->approve($item, ['label_en' => 'Drone pilot', 'label_fr' => 'Télépilote de drone', 'parent_code' => 'ISCO_31'], $user->id);
    expect($value->code)->toBe('DRONE_PILOT')->and($value->source_type)->toBe('USER_SUBMITTED')->and($value->parent_value_id)->not->toBeNull()
        ->and(app(MasterDataSearch::class)->search('occupations', 'occupation', 'telepilote')[0]['code'])->toBe('DRONE_PILOT');

    $dup = $svc->submit('occupations', 'occupation', 'Maçon traditionnel')['review'];
    $svc->merge(MasterDataReviewItem::find($dup['id']), MasterDataValue::where(['list_code' => 'occupation', 'code' => 'MASON'])->first(), $user->id);
    expect(MasterDataReviewItem::find($dup['id'])->status)->toBe('MERGED')
        ->and(MasterDataAlias::where('alias', 'Maçon traditionnel')->exists())->toBeTrue();
});

it('merges duplicate values with a redirect from the old code', function () {
    mdSeed();
    $svc = app(MasterDataReviewService::class);
    $list = MasterDataValue::where(['domain_code' => 'occupations', 'code' => 'MASON'])->value('list_id');
    $dupe = MasterDataValue::create(['list_id' => $list, 'domain_code' => 'occupations', 'list_code' => 'occupation', 'code' => 'BRICK_MASON', 'label_en' => 'Brick mason', 'label_fr' => 'Maçon briqueteur']);
    $svc->mergeValues($dupe, MasterDataValue::where(['list_id' => $list, 'code' => 'MASON'])->first(), null);

    expect($dupe->fresh()->status)->toBe('INACTIVE')
        ->and(app(MasterDataCatalogue::class)->value('occupations', 'occupation', 'BRICK_MASON')['code'])->toBe('MASON');
});

it('uses SELECT_MASTER fields for every non-motor line and references existing lists', function () {
    mdSeed();
    $catalogue = app(MasterDataCatalogue::class);
    $check = function (array $fields, string $line) use (&$check, $catalogue) {
        foreach ($fields as $f) {
            if (isset($f['source']) && $f['source']['domain'] !== 'vehicle') {
                expect($catalogue->listExists($f['source']['domain'], $f['source']['list']))->toBeTrue("$line.{$f['key']} → {$f['source']['domain']}.{$f['source']['list']}");
            }
            $check($f['item_fields'] ?? [], $line);
        }
    };
    foreach (NonMotorRiskSchemas::all() as $line => $schema) {
        expect(collect($schema['fields'])->whereIn('type', ['select_master', 'multi_select_master'])->count())->toBeGreaterThan(1, $line);
        $check($schema['fields'], $line);
    }
    // Specialty flows resolve too (life_insurance.occupation → core occupations).
    foreach (app(MasterDataFlows::class)->all() as $line => $schema) {
        $check($schema['fields'], $line);
    }
    expect(collect(app(MasterDataFlows::class)->schemaFor('LIFE_INSURANCE')['fields'])->firstWhere('key', 'occupation')['source'])->toBe(['domain' => 'occupations', 'list' => 'occupation']);
    expect(RiskSchemaCatalogue::for('MOTOR'))->not->toBeNull();
});

it('rejects codes that are not in the list unless they come through the Other flow', function () {
    mdSeed();
    expect(fn () => mdProcess('HOME', ['building_type' => 'CASTLE']))->toThrow(ValidationException::class)
        ->and(fn () => mdProcess('BUSINESS', ['business_sector' => 'RETAIL', 'business_activity' => 'HOTEL']))->toThrow(ValidationException::class) // wrong parent
        ->and(fn () => mdProcess('TRAVEL', ['travel_options' => ['MEDICAL_EXPENSES', 'SKYDIVING']]))->toThrow(ValidationException::class)
        ->and(fn () => mdProcess('HOME', ['building_type' => 'OTHER']))->toThrow(ValidationException::class) // Other needs its text
        ->and(fn () => mdProcess('HEALTH', ['medical_declaration' => ['OTHER']]))->toThrow(ValidationException::class); // closed list

    $facts = mdProcess('HOME', ['building_type' => 'OTHER', 'building_type_other' => 'Case traditionnelle', 'occupancy_status' => 'TENANTED', 'city' => 'DOUALA']);
    expect($facts['building_type_other'])->toBe('Case traditionnelle')
        ->and(MasterDataReviewItem::where(['domain_code' => 'property', 'list_code' => 'property_type', 'line_code' => 'HOME', 'field_key' => 'building_type'])->exists())->toBeTrue()
        ->and($facts['occupancy'])->toBe('RENTED');
});

it('derives the legacy tariff facts from master codes', function () {
    mdSeed();
    $home = mdProcess('HOME', ['building_type' => 'APARTMENT_BUILDING', 'occupancy_status' => 'OWNER_OCCUPIED', 'building_value_minor' => 1000, 'contents_value_minor' => 500, 'security_measures' => ['CCTV']]);
    expect($home)->toMatchArray(['property_type' => 'APARTMENT', 'occupancy' => 'OWNER', 'declared_value_minor' => 1500, 'security_features' => true]);

    expect(mdProcess('BUSINESS', ['business_sector' => 'HOSPITALITY', 'business_activity' => 'RESTAURANT'])['business_type'])->toBe('RESTAURANT')
        ->and(mdProcess('BUSINESS', ['business_sector' => 'AUTOMOTIVE', 'business_activity' => 'REPAIR_GARAGE'])['business_type'])->toBe('WORKSHOP')
        ->and(mdProcess('ACCIDENT', ['occupation' => 'MOTORCYCLE_TAXI_DRIVER'])['occupation_class'])->toBe('HIGH_RISK')
        ->and(mdProcess('ACCIDENT', ['occupation' => 'ACCOUNTANT'])['occupation_class'])->toBe('OFFICE')
        ->and(mdProcess('TRAVEL', ['destination_country' => 'FR'])['schengen'])->toBeTrue();

    $health = mdProcess('HEALTH', ['plan_type' => 'FAMILY', 'geographic_zone' => 'CEMAC_ZONE', 'members' => [
        ['member_type' => 'PRINCIPAL', 'full_name' => 'A', 'date_of_birth' => now()->subYears(52)->format('Y-m-d')],
        ['member_type' => 'CHILD', 'full_name' => 'B', 'date_of_birth' => now()->subYears(8)->format('Y-m-d')],
    ], 'medical_declaration' => ['CHRONIC_CONDITION']]);
    expect($health)->toMatchArray(['coverage_zone' => 'CEMAC', 'beneficiary_count' => 2, 'oldest_age' => 52, 'pre_existing_conditions' => true]);
});

it('requires life beneficiary allocations to total 100% per rank and keeps roles distinct', function () {
    mdSeed();
    $base = ['product_type' => 'TERM_LIFE', 'life_assured_is_policyholder' => false, 'life_assured_name' => 'Spouse Name', 'life_assured_relationship' => 'SPOUSE',
        'life_assured_date_of_birth' => now()->subYears(40)->format('Y-m-d'), 'occupation' => 'SECONDARY_TEACHER', 'cover_amount_minor' => 1000000000, 'policy_term' => 'Y10', 'premium_frequency' => 'MONTHLY'];
    $b = fn (string $rel, string $prio, $pct) => ['full_name' => "B $rel", 'relationship' => $rel, 'priority' => $prio, 'share_pct' => $pct];

    expect(fn () => mdProcess('LIFE', $base + ['beneficiaries' => [$b('CHILD', 'PRIMARY', 60), $b('SPOUSE', 'PRIMARY', 30)]]))->toThrow(ValidationException::class)
        ->and(fn () => mdProcess('LIFE', $base + ['beneficiaries' => [$b('CHILD', 'PRIMARY', 100), $b('ESTATE', 'CONTINGENT', 50)]]))->toThrow(ValidationException::class)
        ->and(fn () => mdProcess('LIFE', $base + ['beneficiaries' => []]))->toThrow(ValidationException::class);

    $facts = mdProcess('LIFE', $base + ['beneficiaries' => [$b('CHILD', 'PRIMARY', 50), $b('CHILD', 'PRIMARY', 50), $b('ESTATE', 'CONTINGENT', 100)]]);
    expect($facts)->toMatchArray(['purpose' => 'FAMILY_PROTECTION', 'insured_age' => 40, 'term_years' => 10])
        ->and(mdProcess('LIFE', ['product_type' => 'CREDIT_LIFE'])['purpose'])->toBe('LOAN_COVER');
});

it('serves the master-data risk schema, upgrading a stored free-text schema, with rating keys intact', function () {
    InsuranceLine::create(['code' => 'HOME', 'name' => ['en' => 'Home', 'fr' => 'Habitation'], 'status' => 'ACTIVE', 'risk_schema' => [
        'required' => ['property_type', 'occupancy', 'city', 'declared_value_minor'],
        'steps' => [['key' => 'property', 'label' => 'Property']],
        'fields' => [['key' => 'property_type', 'label' => 'Property type', 'type' => 'select', 'step' => 'property', 'required' => true]],
    ]]);
    require_once base_path('tests/Feature/Wave12/Concerns/mobile_auth_helpers.php');
    $user = makeMobileTestUser('+237670009971');
    [$tenant] = makeMobileTestWorkspace($user, ['*'], 'CUSTOMER');
    Passport::actingAs($user);

    $res = $this->withHeader('X-Tenant-Id', $tenant->id)->getJson('/api/v1/mobile/catalogue/lines/HOME/risk-schema')->assertOk();
    $fields = collect($res->json('data.fields'))->keyBy('key');
    expect($fields['building_type']['type'])->toBe('select_master')
        ->and($fields['building_type']['source'])->toMatchArray(['domain' => 'property', 'list' => 'property_type'])
        ->and($fields['department']['parent_field'])->toBe('region')
        ->and($res->json('data.required'))->toBe(['property_type', 'occupancy', 'city', 'declared_value_minor']);

    mdSeed();
    expect(InsuranceLine::where('code', 'HOME')->first()->risk_schema['version'])->toBe(NonMotorRiskSchemas::VERSION);
})->skip(fn () => ! function_exists('makeMobileTestWorkspace') && ! file_exists(base_path('tests/Feature/Wave12/Concerns/mobile_auth_helpers.php')), 'helpers missing');

it('renders the Master data admin screens for platform admins only and resolves suggestions from the queue', function () {
    require_once base_path('tests/Feature/Wave12/Concerns/mobile_auth_helpers.php');
    mdSeed();
    $admin = makeMobileTestUser('+237670009981');
    makeMobileTestWorkspace($admin, ['*'], 'SYSTEM_ADMIN');
    $agent = makeMobileTestUser('+237670009982');
    makeMobileTestWorkspace($agent, ['*'], 'AGENT');
    $review = MasterDataReviewItem::find(app(MasterDataReviewService::class)->submit('industries', 'activity', 'Moto-taxi garage', ['parent_code' => 'AUTOMOTIVE'])['review']['id']);

    $this->actingAs($agent);
    expect(App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource::canViewAny())->toBeFalse();

    $this->actingAs($admin);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    expect(App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource::canDelete(MasterDataValue::first()))->toBeFalse();
    foreach ([
        App\Filament\Admin\Resources\MasterDataDomains\Pages\ListMasterDataDomains::class, App\Filament\Admin\Resources\MasterDataLists\Pages\ListMasterDataLists::class,
        App\Filament\Admin\Resources\MasterDataValues\Pages\ListMasterDataValues::class, App\Filament\Admin\Resources\MasterDataAliases\Pages\ListMasterDataAliases::class,
        App\Filament\Admin\Resources\MasterDataChanges\Pages\ListMasterDataChanges::class, App\Filament\Admin\Resources\MasterDataImports\Pages\ListMasterDataImports::class,
        App\Filament\Admin\Resources\CarrierMasterDataMappings\Pages\ListCarrierMasterDataMappings::class, App\Filament\Admin\Resources\BrokerMasterDataMappings\Pages\ListBrokerMasterDataMappings::class,
        App\Filament\Admin\Resources\MasterDataValues\Pages\CreateMasterDataValue::class, App\Filament\Admin\Pages\MasterDataDashboard::class, App\Filament\Admin\Pages\MasterDataQuality::class,
    ] as $page) {
        try {
            Livewire\Livewire::test($page)->assertOk();
        } catch (Throwable $e) {
            $d = $e;
            while ($d->getPrevious()) {
                $d = $d->getPrevious();
            }
            throw new RuntimeException($page.": ".$d->getMessage()." | ".collect($d->getTrace())->take(12)->map(fn ($t) => ($t["file"] ?? "").":".($t["line"] ?? "")." ".($t["function"] ?? ""))->join(" | "), 0, $e);
        }
    }
    Livewire\Livewire::test(App\Filament\Admin\Resources\MasterDataReviews\Pages\ListMasterDataReviews::class)
        ->assertCanSeeTableRecords([$review])
        ->callTableAction('approve', $review, ['code' => 'MOTO_TAXI_GARAGE', 'label_en' => 'Motorcycle-taxi garage', 'label_fr' => 'Garage de motos-taxis', 'parent_code' => 'AUTOMOTIVE'])
        ->assertHasNoTableActionErrors();
    expect($review->fresh()->status)->toBe('APPROVED')->and($review->fresh()->resolved_by)->toBe($admin->id)
        ->and(app(MasterDataCatalogue::class)->value('industries', 'activity', 'MOTO_TAXI_GARAGE')['parent'])->toBe('AUTOMOTIVE');
});

it('imports a CSV through validate → duplicates → approve and exports lists', function () {
    mdSeed();
    $svc = app(App\Application\MasterData\MasterDataImportService::class);
    $csv = tempnam(sys_get_temp_dir(), 'md').'.csv';
    file_put_contents($csv, "code,label_en,label_fr,parent_code,aliases\nDIGITAL_AGENCY,Digital agency,Agence digitale,TECHNOLOGY,Web agency|Agence web\nSOFTWARE_DEVELOPMENT,Software,Logiciel,TECHNOLOGY,\n");
    $import = $svc->upload('industries', 'activity', $csv, 'activities.csv', null);
    expect($import->status)->toBe('VALIDATED')->and($import->report['new'])->toBe(['DIGITAL_AGENCY'])->and($import->report['duplicates'][0]['code'])->toBe('SOFTWARE_DEVELOPMENT');

    $svc->import($import, null);
    $v = MasterDataValue::where(['list_code' => 'activity', 'code' => 'DIGITAL_AGENCY'])->first();
    expect($v->parent_value_id)->not->toBeNull()->and($v->source_type)->toBe('MANUAL_VERIFIED')
        ->and(app(MasterDataSearch::class)->search('industries', 'activity', 'agence web')[0]['code'])->toBe('DIGITAL_AGENCY');

    foreach (['csv', 'json', 'xlsx'] as $fmt) {
        $path = $svc->export('geography', 'cameroon_region', $fmt);
        expect(filesize($path))->toBeGreaterThan(100);
        @unlink($path);
    }
});
