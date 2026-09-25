<?php

declare(strict_types=1);

use App\Application\Catalogue\CatalogueService;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Application\Regulatory\CimaProductMappingService;
use App\Application\Regulatory\CimaPublicationGuard;
use App\Application\Regulatory\RegulatoryTerminologyService;
use App\Models\Carrier;
use App\Models\CoverageDefinition;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\MicroinsuranceBranch;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\Regulatory\RegulatoryReportingCategory;
use App\Models\Regulatory\RegulatoryTerm;
use App\Models\Regulatory\RegulatoryTermTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function cimaSeed(): void
{
    test()->artisan('opesinsure:seed-cima')->assertExitCode(0);
}

function cimaUser(string $name = 'Maker'): User
{
    $party = Party::create(['type' => 'PERSON', 'display_name' => $name, 'status' => 'ACTIVE']);

    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'party_id' => $party->id, 'password' => 'x', 'locale' => 'fr', 'status' => 'ACTIVE']);
}

function cimaCarrier(bool $demo = false): Carrier
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Test Assurances '.Str::random(4), 'status' => 'ACTIVE']);

    return Carrier::create(['party_id' => $party->id, 'cima_code' => 'T-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => [], 'is_demo' => $demo]);
}

/** @param list<string> $coverageCodes */
function cimaProduct(Carrier $carrier, string $line = 'MOTOR', array $coverageCodes = ['THIRD_PARTY'], string $status = 'DRAFT', User $creator = null): InsuranceProduct
{
    $l = InsuranceLine::firstOrCreate(['code' => $line], ['name' => ['en' => $line, 'fr' => $line], 'description' => ['en' => $line], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $product = InsuranceProduct::create([
        'carrier_id' => $carrier->id, 'line_code' => $line, 'code' => $line.'-'.Str::random(5), 'name' => "$line product", 'version' => 1,
        'effective_from' => '2026-01-01', 'status' => $status, 'coverages' => [], 'eligibility_rules' => ['conditions' => []],
        'created_by' => ($creator ?? cimaUser())->id, 'regulatory_reference' => 'REF',
    ]);
    if ($status === 'DRAFT') {
        // These tests exercise the CIMA guard on the direct submit path, which owner decision 28 keeps open only to
        // pre-cutover (LEGACY_GRANDFATHERED) versions.
        DB::table('insurance_products')->where('id', $product->id)->update(['governance_mode' => 'LEGACY_GRANDFATHERED']);
    }
    foreach ($coverageCodes as $i => $code) {
        $c = CoverageDefinition::firstOrCreate(['insurance_line_id' => $l->id, 'code' => $code], ['name' => ['en' => $code], 'description' => ['en' => $code], 'limit_type' => 'AMOUNT', 'mandatory' => false, 'status' => 'ACTIVE']);
        $product->coverageDefinitions()->attach($c->id, ['display_order' => $i, 'configuration' => '{}']);
    }

    return $product;
}

function cimaAuthorize(Carrier $carrier, array $branchCodes): InsurerRegulatoryAuthorization
{
    $svc = app(CimaAuthorizationService::class);
    $auth = $svc->record($carrier, ['authorization_reference' => 'ARRETE-2026-001', 'source' => 'REGULATOR_DECREE', 'source_document' => 'ARCHIVE-1', 'effective_from' => '2026-01-01'], $branchCodes, cimaUser('Recorder'));

    return $svc->approve($auth, cimaUser('Approver'));
}

it('seeds 23 Article 328 branches with branch 19 reserved and 14/15 never accessory', function () {
    cimaSeed();
    expect(RegulatoryBranch::count())->toBe(23)
        ->and(RegulatoryBranch::where('reserved', true)->pluck('number')->all())->toBe([19])
        ->and(RegulatoryBranch::where('accessory_allowed', false)->orderBy('number')->pluck('number')->all())->toBe([14, 15])
        ->and(RegulatoryBranch::where('complementary_covers_allowed', true)->orderBy('number')->pluck('number')->all())->toBe([20, 21])
        ->and(RegulatoryBranch::where('number', 10)->first())->is_compulsory->toBeTrue()->label_fr->toBe('Responsabilité civile véhicules terrestres automoteurs')
        ->and(DB::table('regulatory_branch_subclasses')->count())->toBe(0)
        ->and(DB::table('compulsory_insurance_rules')->count())->toBe(2)
        ->and(DB::table('regulatory_authorities')->count())->toBe(5)
        ->and(DB::table('legal_references')->count())->toBe(14);
});

it('seeds 11 Article 717 micro branches and 23 Article 411 categories plus 7 Article 557 measures', function () {
    cimaSeed();
    expect(MicroinsuranceBranch::count())->toBe(11)
        ->and(MicroinsuranceBranch::orderBy('number')->pluck('number')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 11, 12, 13, 14])
        ->and(RegulatoryReportingCategory::where('kind', 'ART_411_CATEGORY')->count())->toBe(23)
        ->and(RegulatoryReportingCategory::where('kind', 'ART_557_MEASURE')->count())->toBe(7);
});

it('loads the bilingual terminology with fr and en labels', function () {
    cimaSeed();
    $terms = RegulatoryTerm::where('namespace', 'CIMA_TERM')->with('translations')->get();
    expect($terms)->toHaveCount(148)
        ->and($terms->every(fn ($t) => $t->label('fr') && $t->label('en')))->toBeTrue()
        ->and(RegulatoryTerm::where('namespace', 'CIMA_PARTY_ROLE')->count())->toBe(16);

    $svc = app(RegulatoryTerminologyService::class);
    expect($svc->label('POLICY', 'fr'))->toBe("Police d'assurance")
        ->and($svc->label('POLICY', 'en'))->toBe('Insurance Policy')
        ->and($svc->label('POLICYHOLDER', 'fr'))->toBe('Souscripteur')
        ->and($svc->label('CLAIM_NOTIFICATION', 'fr'))->toBe('Déclaration de sinistre');
});

it('serves the public terminology and branch APIs with labels per locale', function () {
    cimaSeed();
    $this->getJson('/api/v1/public/regulatory/terms?regime=CIMA&locale=fr&code=POLICY')->assertOk()
        ->assertJsonPath('data.0.label', "Police d'assurance")->assertJsonPath('data.0.source_article', 'Article 8');
    $claims = $this->getJson('/api/v1/public/regulatory/terms?regime=CIMA&locale=en&category=CLAIM')->assertOk()->json('data');
    expect($claims)->toHaveCount(22)->and(collect($claims)->every(fn ($t) => $t['category'] === 'CLAIM' && filled($t['label'])))->toBeTrue();

    $this->getJson('/api/v1/public/regulatory/cima/branches?locale=en')->assertOk()->assertJsonCount(23, 'data')->assertJsonPath('data.9.label', 'Motor Third-Party Liability');
    $this->getJson('/api/v1/public/regulatory/cima/micro-branches')->assertOk()->assertJsonCount(11, 'data');
    $this->getJson('/api/v1/public/regulatory/cima/reporting-categories')->assertOk()->assertJsonCount(23, 'data.categories')->assertJsonCount(7, 'data.intermediary_measures');
});

it('seeds idempotently and never duplicates or deletes', function () {
    cimaSeed();
    $tables = ['regulatory_branches', 'microinsurance_branches', 'regulatory_reporting_categories', 'regulatory_terms', 'regulatory_term_translations', 'regulatory_authorities', 'legal_references', 'compulsory_insurance_rules', 'regulatory_class_defaults', 'regulatory_regimes'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
    cimaSeed();
    cimaSeed();
    expect(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all())->toBe($before->all());
});

it('blocks deletion and in-place edits of seeded regulatory rows', function () {
    cimaSeed();
    $branch = RegulatoryBranch::where('number', 10)->first();
    expect(fn () => $branch->delete())->toThrow(LogicException::class);
    expect(fn () => RegulatoryBranch::whereKey($branch->id)->first()->update(['label_fr' => 'Autre']))->toThrow(LogicException::class);
    expect(fn () => RegulatoryTermTranslation::first()->delete())->toThrow(LogicException::class);
    // Database-level trigger too (bypasses Eloquent).
    expect(fn () => DB::transaction(fn () => DB::table('regulatory_branches')->where('id', $branch->id)->delete()))->toThrow(Illuminate\Database\QueryException::class);
    expect(RegulatoryBranch::count())->toBe(23);
    // Closing a version is allowed.
    $branch->update(['effective_until' => '2099-12-31']);
    expect($branch->refresh()->effective_until->toDateString())->toBe('2099-12-31');
});

it('maps products automatically from their class defaults', function () {
    cimaSeed();
    $carrier = cimaCarrier();
    $motor = cimaProduct($carrier, 'MOTOR', ['THIRD_PARTY', 'OWN_DAMAGE', 'ASSISTANCE']);
    $basic = cimaProduct($carrier, 'MOTOR', ['THIRD_PARTY']);
    $mapper = app(CimaProductMappingService::class);
    expect($mapper->applyClassDefaults($motor))->toBe(3)->and($mapper->applyClassDefaults($motor))->toBe(0)->and($mapper->applyClassDefaults($basic))->toBe(1);

    $rows = ProductRegulatoryMapping::where('insurance_product_id', $motor->id)->get()->mapWithKeys(fn ($m) => [$m->branch_code => $m->relationship_type])->all();
    ksort($rows);
    expect($rows)->toBe(['CIMA_03_LAND_VEHICLE_DAMAGE' => 'PRIMARY', 'CIMA_10_MOTOR_LIABILITY' => 'PRIMARY', 'CIMA_18_ASSISTANCE' => 'ACCESSORY']);
});

it('blocks publication when the insurer is not authorized for a CIMA branch, with an actionable reason', function () {
    cimaSeed();
    $product = cimaProduct(cimaCarrier(), 'MOTOR', ['THIRD_PARTY']);
    try {
        app(CatalogueService::class)->submit($product, cimaUser(), 'ready');
        $this->fail('expected block');
    } catch (ValidationException $e) {
        expect($e->errors()['cima'][0])->toBe("Publication blocked (BLOCK_NEW_PRODUCT_PUBLICATION): insurer not authorized for CIMA branch 10 — Responsabilité civile véhicules terrestres automoteurs. Record the insurer's CIMA authorization for branch 10 in Insurer setup.");
    }
    expect($product->refresh()->status)->toBe('DRAFT');
});

it('blocks publication without any PRIMARY mapping', function () {
    cimaSeed();
    $product = cimaProduct(cimaCarrier(), 'MARINE', ['HULL']);
    expect(fn () => app(CimaPublicationGuard::class)->assertPublishable($product))->toThrow(ValidationException::class, 'no PRIMARY CIMA branch mapping');
});

it('allows publication once the insurer authorization is approved (maker-checker)', function () {
    cimaSeed();
    $carrier = cimaCarrier();
    $product = cimaProduct($carrier, 'MOTOR', ['THIRD_PARTY', 'OWN_DAMAGE', 'ASSISTANCE']);

    $svc = app(CimaAuthorizationService::class);
    $maker = cimaUser('Maker');
    $pending = $svc->record($carrier, ['authorization_reference' => 'ARRETE-1', 'source' => 'REGULATOR_DECREE', 'source_document' => 'ARCHIVE-1', 'effective_from' => '2026-01-01'], ['CIMA_10_MOTOR_LIABILITY', 'CIMA_03_LAND_VEHICLE_DAMAGE'], $maker);
    expect(fn () => $svc->approve($pending, $maker))->toThrow(ValidationException::class);
    // Pending is not yet an authorization.
    expect(app(CimaPublicationGuard::class)->violations($product))->not->toBeEmpty();
    $svc->approve($pending, cimaUser('Checker'));

    // Branch 18 is ACCESSORY: no authorization needed.
    expect(app(CimaPublicationGuard::class)->violations($product))->toBe([]);
    app(CatalogueService::class)->submit($product, cimaUser(), 'ready');
    expect($product->refresh()->status)->toBe('IN_REVIEW');
});

it('requires a source and reference for an insurer authorization and refuses DEMO for real insurers', function () {
    cimaSeed();
    $svc = app(CimaAuthorizationService::class);
    $real = cimaCarrier(false);
    expect(fn () => $svc->record($real, ['authorization_reference' => '', 'source' => 'X', 'effective_from' => '2026-01-01'], ['CIMA_10_MOTOR_LIABILITY'], null))->toThrow(ValidationException::class)
        ->and(fn () => $svc->record($real, ['authorization_reference' => 'R', 'source' => 'DEMO', 'effective_from' => '2026-01-01'], ['CIMA_10_MOTOR_LIABILITY'], null))->toThrow(ValidationException::class)
        ->and(fn () => $svc->record($real, ['authorization_reference' => 'R', 'source' => 'X', 'effective_from' => '2026-01-01'], ['CIMA_19_RESERVED'], null))->toThrow(ValidationException::class);
});

it('never allows branches 14 or 15 as accessory', function () {
    cimaSeed();
    $carrier = cimaCarrier();
    $product = cimaProduct($carrier, 'MOTOR', ['THIRD_PARTY']);
    $mapper = app(CimaProductMappingService::class);
    foreach (['CIMA_14_CREDIT', 'CIMA_15_SURETY'] as $code) {
        expect(fn () => $mapper->propose($product, ['branch_code' => $code, 'relationship_type' => 'ACCESSORY', 'effective_from' => '2026-01-01'], cimaUser()))
            ->toThrow(ValidationException::class, 'accessory');
    }
    // Even if such a row exists (e.g. imported), the guard blocks it.
    cimaAuthorize($carrier, ['CIMA_10_MOTOR_LIABILITY']);
    $mapper->applyClassDefaults($product);
    ProductRegulatoryMapping::create(['insurance_product_id' => $product->id, 'product_version' => 1, 'branch_code' => 'CIMA_14_CREDIT', 'relationship_type' => 'ACCESSORY', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'source' => 'ADMIN']);
    expect(app(CimaPublicationGuard::class)->violations($product))->toHaveCount(1)
        ->and(app(CimaPublicationGuard::class)->violations($product)[0])->toContain('CIMA branch 14 — Crédit can never be covered as an accessory risk');
});

it('lets an approved admin mapping override the class defaults', function () {
    cimaSeed();
    $carrier = cimaCarrier();
    $product = cimaProduct($carrier, 'TRAVEL', ['MEDICAL']);
    $mapper = app(CimaProductMappingService::class);
    $mapper->applyClassDefaults($product);
    $maker = cimaUser();
    $m = $mapper->propose($product, ['branch_code' => 'CIMA_02_SICKNESS', 'relationship_type' => 'PRIMARY', 'effective_from' => '2026-01-01'], $maker);
    expect(fn () => $mapper->approve($m, $maker))->toThrow(ValidationException::class, 'Maker-checker');
    $mapper->approve($m, cimaUser('Checker'));
    expect(app(CimaPublicationGuard::class)->activeMappings($product)->pluck('branch_code')->all())->toBe(['CIMA_02_SICKNESS']);
});

it('creates DEMO authorizations only for demo carriers, and grandfathers already published products', function () {
    $demo = cimaCarrier(true);
    $real = cimaCarrier(false);
    $demoProduct = cimaProduct($demo, 'HEALTH', ['HOSPITALISATION'], 'ACTIVE');
    $realProduct = cimaProduct($real, 'HEALTH', ['HOSPITALISATION'], 'ACTIVE');
    $realProduct->update(['published_at' => now()->subYear()]);
    cimaSeed();

    expect(InsurerRegulatoryAuthorization::where('carrier_id', $demo->id)->where('source', 'DEMO')->where('is_demo', true)->where('status', 'ACTIVE')->count())->toBe(1)
        ->and(InsurerRegulatoryAuthorization::where('carrier_id', $real->id)->count())->toBe(0)
        ->and(app(CimaPublicationGuard::class)->violations($demoProduct))->toBe([])
        ->and($realProduct->refresh()->status)->toBe('ACTIVE');

    $guard = app(CimaPublicationGuard::class);
    expect($guard->isGrandfathered($realProduct))->toBeTrue();
    $guard->assertResumable($realProduct); // no exception
    $new = cimaProduct($real, 'HEALTH', ['HOSPITALISATION'], 'RETIRED');
    $new->update(['published_at' => now()->addMinute()]);
    expect(fn () => $guard->assertResumable($new->refresh()))->toThrow(ValidationException::class, 'CIMA branch 2');
});

it('renders every CIMA admin screen for platform admins and hides them from other roles', function () {
    require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
    cimaProduct(cimaCarrier(), 'MOTOR', ['THIRD_PARTY']);
    cimaSeed();
    $tenant = App\Models\Tenant::create(['type' => 'CARRIER', 'legal_name' => 'CIMA Admin Tenant', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr']);
    $admin = makeMobileTenantStaffUser($tenant, '+237670001201', 'PLATFORM_ADMIN');
    $branch = RegulatoryBranch::where('number', 10)->first();
    $term = RegulatoryTerm::where('code', 'POLICY')->first();
    $urls = ['/admin/cima-regulatory-dictionary', '/admin/cima-branches', "/admin/cima-branches/{$branch->id}", '/admin/cima-micro-branches', '/admin/cima-reporting-categories',
        '/admin/cima-terms', "/admin/cima-terms/{$term->id}", '/admin/cima-product-mappings', '/admin/cima-insurer-authorizations', '/admin/cima-compulsory-insurance',
        '/admin/cima-legal-references', '/admin/cima-authorities', '/admin/cima-reporting-mappings'];

    $this->actingAs($admin, 'web');
    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }
    $this->get('/admin/cima-regulatory-dictionary')->assertSee('Products blocked for publication')->assertSee('insurer not authorized for CIMA branch 10');

    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(makeMobileTenantStaffUser($tenant, '+237670001202', 'CLAIMS_OFFICER'), 'web');
    $this->get('/admin/cima-branches')->assertForbidden();
    $this->get('/admin/cima-regulatory-dictionary')->assertForbidden();
});
