<?php

declare(strict_types=1);

use App\Application\Catalogue\CatalogueService;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Application\Catalogue\ExclusionLegalTextService;
use App\Application\Catalogue\IndemnityCalculator;
use App\Application\Catalogue\ProductConfigurationService;
use App\Application\Catalogue\ProductHierarchyService;
use App\Application\Catalogue\ProductModelService;
use App\Application\Catalogue\ProductVersionSnapshot;
use App\Application\Catalogue\ProductVersionStatus;
use App\Models\Carrier;
use App\Models\Catalogue\CarrierProduct;
use App\Models\Catalogue\ProductFamily;
use App\Models\CoverageDefinition;
use App\Models\ExclusionDefinition;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function b5aUser(string $name = 'User'): User
{
    $party = Party::create(['type' => 'PERSON', 'display_name' => $name, 'status' => 'ACTIVE']);

    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'party_id' => $party->id, 'password' => 'x', 'locale' => 'fr', 'status' => 'ACTIVE']);
}

function b5aCarrier(): Carrier
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B5A Assurances '.Str::random(4), 'status' => 'ACTIVE']);

    return Carrier::create(['party_id' => $party->id, 'cima_code' => 'B5A-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);
}

/** @return array{line:InsuranceLine,rc:CoverageDefinition,dommages:CoverageDefinition,vol:CoverageDefinition,excl:ExclusionDefinition} */
function b5aLine(): array
{
    $line = InsuranceLine::firstOrCreate(['code' => 'B5A_MOTOR'], ['name' => ['en' => 'Motor', 'fr' => 'Auto'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $mk = fn (string $code, bool $mandatory) => CoverageDefinition::firstOrCreate(['insurance_line_id' => $line->id, 'code' => $code],
        ['name' => ['en' => $code, 'fr' => $code], 'limit_type' => 'FIXED_AMOUNT', 'mandatory' => $mandatory, 'status' => 'ACTIVE']);

    return ['line' => $line, 'rc' => $mk('RC', true), 'dommages' => $mk('DOMMAGES', false), 'vol' => $mk('VOL', false),
        'excl' => ExclusionDefinition::firstOrCreate(['insurance_line_id' => $line->id, 'code' => 'DRUNK_DRIVING'], ['name' => ['en' => 'Drunk driving', 'fr' => 'Conduite en état d\'ivresse'], 'status' => 'ACTIVE'])];
}

/** A carrier product with a DRAFT v1 carrying RC (mandatory) + DOMMAGES + VOL. */
function b5aLegacy(InsuranceProduct $v): void
{
    DB::table('insurance_products')->where('id', $v->id)->update(['governance_mode' => 'LEGACY_GRANDFATHERED']);
}

function b5aDraft(User $maker): array
{
    $l = b5aLine();
    $family = app(ProductModelService::class)->createFamily(['code' => 'MOTOR_PRIVATE_'.Str::random(4), 'class_code' => 'MOTOR', 'line_code' => 'B5A_MOTOR',
        'default_branch_code' => 'CIMA_10_MOTOR_LIABILITY', 'name' => ['en' => 'Private motor', 'fr' => 'Automobile particulier']], $maker);
    $cp = app(ProductModelService::class)->createCarrierProduct(['carrier_id' => b5aCarrier()->id, 'product_family_id' => $family->id, 'code' => 'AUTO_'.Str::random(4),
        'line_code' => 'B5A_MOTOR', 'name' => ['en' => 'Auto Plus', 'fr' => 'Auto Plus'], 'description' => ['en' => 'Private car cover', 'fr' => 'Assurance voiture particulière'],
        'customer_type' => 'INDIVIDUAL', 'currency' => 'XAF', 'market' => 'CM'], $maker);
    $v = app(ProductModelService::class)->newVersion($cp, ['effective_from' => '2026-01-01', 'regulatory_reference' => 'B5A-REF'], $maker);
    b5aLegacy($v); // these lifecycle tests use the direct path, open only to pre-cutover versions (owner decision 28)
    $cfg = app(ProductConfigurationService::class);
    $cfg->configureCoverage($v, $l['rc'], ['inclusion' => 'MANDATORY', 'territory' => 'CEMAC'], $maker);
    $cfg->configureCoverage($v, $l['dommages'], ['inclusion' => 'DEFAULT', 'waiting_period_days' => 0], $maker);
    $cfg->configureCoverage($v, $l['vol'], ['inclusion' => 'OPTIONAL', 'waiting_period_days' => 30], $maker);

    return $l + ['family' => $family, 'cp' => $cp, 'v' => $v->refresh()];
}

function b5aTariff(InsuranceProduct $v): void
{
    DB::table("tariff_versions")->insert(["id" => (string) Str::uuid(), "insurance_product_id" => $v->id, "version" => 1, "effective_from" => "2026-01-01",
        "status" => "APPROVED", "input_schema" => "{}", "rules" => "{}", "rules_hash" => str_repeat("a", 64), "created_at" => now(), "updated_at" => now()]);
}

/** CIMA dictionary + an ACTIVE insurer authorization for the motor liability branch (publication gate). */
function b5aAuthorize(InsuranceProduct $v): void
{
    test()->artisan("opesinsure:seed-cima")->assertExitCode(0);
    $svc = app(CimaAuthorizationService::class);
    $auth = $svc->record(Carrier::findOrFail($v->carrier_id), ["authorization_reference" => "ARRETE-B5A", "source" => "REGULATOR_DECREE",
        "source_document" => "https://example.test/arrete.pdf", "effective_from" => "2025-01-01"], ["CIMA_10_MOTOR_LIABILITY"], b5aUser("Auth maker"));
    $svc->approve($auth, b5aUser("Auth checker"));
}

/** Runs a statement that must be rejected by the database, inside a savepoint so the test transaction survives. */
function b5aSqlFails(callable $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

it('REQ-PRD-002 REQ-PRD-003 builds branch → class → family → carrier product → version with EN/FR attributes', function () {
    $maker = b5aUser();
    $d = b5aDraft($maker);
    $tree = app(ProductHierarchyService::class)->forVersion($d['v']);

    expect($tree['class']['code'])->toBe('MOTOR')
        ->and($tree['family']['code'])->toBe($d['family']->code)
        ->and($tree['cima_branches'][0]['code'])->toBe('CIMA_10_MOTOR_LIABILITY')
        ->and($tree['cima_branches'][0]['is_family_default'])->toBeTrue()
        ->and($tree['carrier_product']['name'])->toBe(['en' => 'Auto Plus', 'fr' => 'Auto Plus'])
        ->and($tree['carrier_product']['customer_type'])->toBe('INDIVIDUAL')
        ->and($tree['version']['status'])->toBe('DRAFT')
        ->and(collect($tree['coverages'])->pluck('inclusion', 'code')->all())->toBe(['RC' => 'MANDATORY', 'DOMMAGES' => 'DEFAULT', 'VOL' => 'OPTIONAL'])
        ->and($tree)->toHaveKey('document_requirements');

    b5aSqlFails(fn () => ProductFamily::create(['code' => 'X', 'class_code' => 'NOT_A_CLASS', 'name' => []]));
    b5aSqlFails(fn () => CarrierProduct::create(['carrier_id' => $d['cp']->carrier_id, 'code' => 'Y', 'line_code' => 'B5A_MOTOR', 'name' => [], 'customer_type' => 'ALIEN']));
});

it('REQ-PRD-001 legacy versions are attached to a carrier product and exposed through the product_versions view', function () {
    $carrier = b5aCarrier();
    b5aLine();
    $legacy = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'B5A_MOTOR', 'code' => 'LEGACY', 'name' => 'Legacy Motor', 'version' => 1,
        'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'coverages' => [], 'eligibility_rules' => []]);

    expect($legacy->carrier_product_id)->not->toBeNull()
        ->and(CarrierProduct::find($legacy->carrier_product_id)->code)->toBe('LEGACY');
    $row = DB::table('product_versions')->where('id', $legacy->id)->first();
    expect($row->status)->toBe('PUBLISHED')->and($row->storage_status)->toBe('ACTIVE')->and((int) $row->version_number)->toBe(1);
});

it('REQ-PRD-001 runs DRAFT → REVIEW → APPROVED → PUBLISHED → SUSPENDED → PUBLISHED → RETIRED with maker-checker and a frozen reproducible snapshot', function () {
    $maker = b5aUser('Maker');
    $checker = b5aUser('Checker');
    $d = b5aDraft($maker);
    $v = $d['v'];
    b5aTariff($v);
    b5aAuthorize($v);
    $svc = app(ProductModelService::class);

    expect(fn () => $svc->approve($v, $checker, 'Not yet in review'))->toThrow(ValidationException::class);
    $v = app(CatalogueService::class)->submit($v, $maker, 'Ready for technical review');
    expect(ProductModelService::specStatus($v))->toBe(ProductVersionStatus::REVIEW);
    expect(fn () => $svc->approve($v, $maker, 'Self approval attempt'))->toThrow(ValidationException::class);

    $v = $svc->approve($v, $checker, 'Configuration checked');
    expect($v->status)->toBe('APPROVED')->and($v->snapshot_hash)->toHaveLength(64)
        ->and(ProductVersionSnapshot::hash($v->snapshot))->toBe($v->snapshot_hash);

    // Never edited live — neither through the service nor directly in SQL.
    expect(fn () => app(ProductConfigurationService::class)->addPlan($v, ['code' => 'LATE', 'name' => ['en' => 'x', 'fr' => 'x']], $maker))->toThrow(ValidationException::class);
    b5aSqlFails(fn () => DB::table('insurance_products')->where('id', $v->id)->update(['name' => 'Tampered']));

    $v = app(CatalogueService::class)->publish($v, $checker, 'Approved by compliance and business owners');
    expect(ProductModelService::specStatus($v))->toBe('PUBLISHED')
        ->and(app(ProductVersionSnapshot::class)->verify($v))->toMatchArray(['frozen' => true, 'intact' => true, 'drift' => false]);

    $v = $svc->suspend($v, $checker, 'Regulator query pending');
    expect($v->status)->toBe('SUSPENDED')->and($v->suspended_at)->not->toBeNull();
    $v = $svc->reinstate($v, $checker, 'Regulator query closed');
    expect($v->status)->toBe('ACTIVE');
    $v = $svc->retire($v, $checker, 'Product withdrawn from sale');
    expect(ProductModelService::specStatus($v))->toBe('RETIRED')
        ->and(fn () => $svc->suspend($v, $checker, 'Too late for this'))->toThrow(ValidationException::class)
        ->and(DB::table('product_status_history')->where('insurance_product_id', $v->id)->pluck('to_status')->all())
        ->toBe(['IN_REVIEW', 'APPROVED', 'ACTIVE', 'SUSPENDED', 'ACTIVE', 'RETIRED']);
});

it('REQ-PRD-001 new versions clone the configuration, are effective-dated via the Temporal engine and supersede on publish', function () {
    $maker = b5aUser('Maker');
    $checker = b5aUser('Checker');
    $d = b5aDraft($maker);
    $cfg = app(ProductConfigurationService::class);
    $plan = $cfg->addPlan($d['v'], ['code' => 'GOLD', 'name' => ['en' => 'Gold', 'fr' => 'Or'], 'tier' => 'GOLD', 'is_default' => true,
        'coverages' => [['coverage_definition_id' => $d['rc']->id], ['coverage_definition_id' => $d['vol']->id, 'inclusion' => 'DEFAULT']]], $maker);
    $cfg->addLimit($d['v'], ['coverage_definition_id' => $d['vol']->id, 'product_plan_id' => $plan->id, 'limit_type' => 'PERCENT_OF_SUM_INSURED', 'percentage_bp' => 8000], $maker);
    $cfg->attachExclusion($d['v'], $d['excl'], ['level' => 'PLAN', 'product_plan_id' => $plan->id], $maker);
    b5aTariff($d['v']);
    b5aAuthorize($d['v']);
    $v1 = app(CatalogueService::class)->publish(app(CatalogueService::class)->submit($d['v'], $maker, 'Submit version one'), $checker, 'Publishing version one after review');

    $svc = app(ProductModelService::class);
    $v2 = $svc->newVersion($d['cp'], ['effective_from' => '2026-07-01'], $maker);
    expect($v2->version)->toBe(2)->and($v2->base_version_id)->toBe($v1->id)->and($v2->status)->toBe('DRAFT')
        ->and($v2->plans()->count())->toBe(1)->and($v2->limits()->first()->product_plan_id)->toBe($v2->plans()->first()->id)
        ->and(DB::table('product_exclusions')->where('insurance_product_id', $v2->id)->value('level'))->toBe('PLAN')
        ->and(fn () => $svc->newVersion($d['cp'], ['effective_from' => '2026-08-01'], $maker))->toThrow(ValidationException::class);

    expect($svc->resolveAt($d['cp']->carrier_id, $d['cp']->code, '2026-03-01')->id)->toBe($v1->id);
    b5aTariff($v2);
    DB::table('tariff_versions')->where('insurance_product_id', $v2->id)->update(['effective_from' => '2026-07-01']);
    // Owner decision 28: a new version (even of a grandfathered product) is GOVERNED — the direct path is refused.
    expect(DB::table('insurance_products')->where('id', $v2->id)->value('governance_mode'))->toBe('GOVERNED')
        ->and(fn () => app(CatalogueService::class)->submit($v2, $maker, 'Direct submit of a new version'))->toThrow(ValidationException::class, 'governance workflow');
    b5aLegacy($v2); // simulate a pre-cutover version to keep exercising supersession on the direct path
    app(CatalogueService::class)->publish(app(CatalogueService::class)->submit($v2, $maker, 'Submit version two'), $checker, 'Publishing version two after review');
    expect($v1->refresh()->status)->toBe('RETIRED')
        ->and($svc->resolveAt($d['cp']->carrier_id, $d['cp']->code, '2026-08-01')->id)->toBe($v2->id);
});

it('REQ-PRD-004 plans carry coverage sets, a single default and a pricing reference; mandatory coverages cannot be dropped', function () {
    $maker = b5aUser();
    $d = b5aDraft($maker);
    $cfg = app(ProductConfigurationService::class);
    $tp = $cfg->addPlan($d['v'], ['code' => 'TP', 'name' => ['en' => 'Third party', 'fr' => 'Tiers'], 'tier' => 'TP', 'is_default' => true, 'pricing_reference' => 'TARIF-TP',
        'coverages' => [['coverage_definition_id' => $d['rc']->id]]], $maker);
    $comp = $cfg->addPlan($d['v'], ['code' => 'COMP', 'name' => ['en' => 'Comprehensive', 'fr' => 'Tous risques'], 'tier' => 'COMPREHENSIVE', 'is_default' => true,
        'coverages' => [['coverage_definition_id' => $d['rc']->id], ['coverage_definition_id' => $d['dommages']->id], ['coverage_definition_id' => $d['vol']->id]]], $maker);

    expect($tp->refresh()->is_default)->toBeFalse()->and($comp->is_default)->toBeTrue()
        ->and($comp->coverages->pluck('pivot.inclusion', 'code')->all())->toBe(['RC' => 'MANDATORY', 'DOMMAGES' => 'DEFAULT', 'VOL' => 'OPTIONAL'])
        ->and($tp->pricing_reference)->toBe('TARIF-TP');
    expect(fn () => $cfg->syncPlanCoverages($tp, [['coverage_definition_id' => $d['vol']->id]]))->toThrow(ValidationException::class);
    $other = CoverageDefinition::create(['insurance_line_id' => $d['line']->id, 'code' => 'NOT_ON_VERSION', 'name' => ['en' => 'x'], 'limit_type' => 'FIXED_AMOUNT', 'mandatory' => false, 'status' => 'ACTIVE']);
    expect(fn () => $cfg->syncPlanCoverages($tp, [['coverage_definition_id' => $d['rc']->id], ['coverage_definition_id' => $other->id]]))->toThrow(ValidationException::class);
});

it('REQ-PRD-005 typed limits and deductibles compute the indemnifiable amount (5% min 50 000 XAF)', function () {
    $maker = b5aUser();
    $d = b5aDraft($maker);
    $cfg = app(ProductConfigurationService::class);
    $cfg->addDeductible($d['v'], ['coverage_definition_id' => $d['dommages']->id, 'deductible_type' => 'COMBINED', 'percentage_bp' => 500, 'minimum_minor' => 50000], $maker);
    $cfg->addLimit($d['v'], ['coverage_definition_id' => $d['dommages']->id, 'limit_type' => 'PERCENT_OF_SUM_INSURED', 'percentage_bp' => 10000], $maker);
    $cfg->addLimit($d['v'], ['coverage_definition_id' => $d['dommages']->id, 'limit_type' => 'PER_EVENT', 'amount_minor' => 5000000], $maker);
    $calc = app(IndemnityCalculator::class);

    // 5% of 400 000 = 20 000 → floored at 50 000.
    expect($calc->compute($d['v'], $d['dommages']->id, ['loss_minor' => 400000, 'sum_insured_minor' => 3000000]))
        ->toMatchArray(['deductible_minor' => 50000, 'indemnifiable_minor' => 350000]);
    // 5% of 2 000 000 = 100 000; capped by sum insured 1 500 000.
    expect($calc->compute($d['v'], $d['dommages']->id, ['loss_minor' => 2000000, 'sum_insured_minor' => 1500000]))
        ->toMatchArray(['deductible_minor' => 100000, 'limit_cap_minor' => 1500000, 'indemnifiable_minor' => 1500000]);
    // Loss below the deductible pays nothing.
    expect($calc->compute($d['v'], $d['dommages']->id, ['loss_minor' => 30000])['indemnifiable_minor'])->toBe(0);

    $cfg->addDeductible($d['v'], ['coverage_definition_id' => $d['vol']->id, 'deductible_type' => 'DAYS', 'days' => 30], $maker);
    $cfg->addLimit($d['v'], ['coverage_definition_id' => $d['vol']->id, 'limit_type' => 'AGGREGATE', 'amount_minor' => 1000000], $maker);
    expect($calc->compute($d['v'], $d['vol']->id, ['loss_minor' => 800000, 'consumed_minor' => 600000]))
        ->toMatchArray(['waiting_days' => 30, 'limit_cap_minor' => 400000, 'indemnifiable_minor' => 400000]);

    expect(fn () => $cfg->addDeductible($d['v'], ['coverage_definition_id' => $d['vol']->id, 'deductible_type' => 'COMBINED', 'percentage_bp' => 500], $maker))->toThrow(ValidationException::class)
        ->and(fn () => $cfg->addLimit($d['v'], ['coverage_definition_id' => $d['vol']->id, 'limit_type' => 'PER_PERSON'], $maker))->toThrow(ValidationException::class)
        ->and(fn () => $cfg->configureCoverage($d['v'], $d['rc'], ['inclusion' => 'OPTIONAL'], $maker))->toThrow(ValidationException::class);
    b5aSqlFails(fn () => DB::table('coverage_limits')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $d['v']->id, 'coverage_definition_id' => $d['vol']->id,
        'limit_type' => 'MADE_UP', 'amount_minor' => 1]));
});

it('REQ-PRD-006 exclusions attach at every level and their legal text is versioned, approved and effective-dated', function () {
    $maker = b5aUser('Maker');
    $checker = b5aUser('Checker');
    $d = b5aDraft($maker);
    $cfg = app(ProductConfigurationService::class);
    foreach (['PRODUCT', 'CUSTOMER', 'RISK', 'CLAIM'] as $level) {
        $cfg->attachExclusion($d['v'], $d['excl'], ['level' => $level, 'condition' => ['fact' => 'driver.bac', 'op' => '>', 'value' => 0.5]], $maker);
    }
    $cfg->attachExclusion($d['v'], $d['excl'], ['level' => 'COVERAGE', 'coverage_definition_id' => $d['dommages']->id], $maker);
    expect(fn () => $cfg->attachExclusion($d['v'], $d['excl'], ['level' => 'PLAN'], $maker))->toThrow(ValidationException::class)
        ->and(DB::table('product_exclusions')->where('insurance_product_id', $d['v']->id)->count())->toBe(5);

    $extension = ExclusionDefinition::create(['insurance_line_id' => $d['line']->id, 'code' => 'GLASS_EXT', 'name' => ['en' => 'Glass breakage', 'fr' => 'Bris de glace'],
        'status' => 'ACTIVE', 'kind' => 'EXTENSION', 'effects' => ['premium' => ['loading_bp' => 300]]]);
    $cfg->attachExclusion($d['v'], $extension, ['level' => 'PRODUCT'], $maker);

    $texts = app(ExclusionLegalTextService::class);
    expect(fn () => $texts->draft($d['excl'], ['text' => ['en' => 'Only English'], 'effective_from' => '2026-01-01'], $maker))->toThrow(ValidationException::class);
    $t1 = $texts->draft($d['excl'], ['text' => ['en' => 'Losses while intoxicated are excluded.', 'fr' => 'Les sinistres en état d\'ivresse sont exclus.'], 'effective_from' => '2026-01-01', 'legal_reference' => 'Code CIMA Art. 8'], $maker);
    expect(fn () => $texts->approve($t1, $maker))->toThrow(ValidationException::class);
    $texts->approve($t1, $checker);
    $t2 = $texts->draft($d['excl'], ['text' => ['en' => 'Revised wording.', 'fr' => 'Libellé révisé.'], 'effective_from' => '2026-07-01'], $maker);
    $texts->approve($t2, $checker);

    expect($texts->at($d['excl'], '2026-03-01')->id)->toBe($t1->id)
        ->and($texts->at($d['excl'], '2026-09-01')->id)->toBe($t2->id)
        ->and($texts->at($d['excl'], '2025-06-01'))->toBeNull()
        ->and($t1->refresh()->effective_until->toDateString())->toBe('2026-06-30');
    b5aSqlFails(fn () => DB::table('exclusion_legal_texts')->where('id', $t1->id)->update(['text' => json_encode(['en' => 'tampered'])]));

    $snapshot = app(ProductVersionSnapshot::class)->build($d['v']);
    expect(collect($snapshot['exclusions'])->firstWhere('level', 'PRODUCT')['legal_text']['id'])->toBe($t1->id)
        ->and(collect($snapshot['exclusions'])->firstWhere('code', 'GLASS_EXT')['kind'])->toBe('EXTENSION');
});

it('REQ-PRD-001 REQ-PRD-004 exposes the product model API with permissions and insurer scoping', function () {
    $tenant = makeAuthTestTenant();
    $h = tenantHeaderFor($tenant);
    $maker = makeAuthTestUser($tenant, ['catalogue.view', 'catalogue.manage']);
    $d = b5aDraft($maker);

    Passport::actingAs(makeAuthTestUser($tenant, ['partners.read']));
    $this->getJson('/api/v1/catalogue/carrier-products', $h)->assertForbidden();

    Passport::actingAs($maker);
    $this->getJson('/api/v1/catalogue/carrier-products', $h)->assertOk()->assertJsonPath('data.total', 1);
    $this->getJson("/api/v1/catalogue/versions/{$d['v']->id}/hierarchy", $h)->assertOk()->assertJsonPath('data.class.code', 'MOTOR');
    $this->postJson("/api/v1/catalogue/versions/{$d['v']->id}/plans", ['code' => 'SILVER', 'name' => ['en' => 'Silver', 'fr' => 'Argent'], 'tier' => 'SILVER',
        'coverages' => [['coverage_definition_id' => $d['rc']->id]]], $h)->assertCreated()->assertJsonPath('data.tier', 'SILVER');
    $this->postJson("/api/v1/catalogue/versions/{$d['v']->id}/deductibles", ['coverage_definition_id' => $d['dommages']->id, 'deductible_type' => 'COMBINED',
        'percentage_bp' => 500, 'minimum_minor' => 50000], $h)->assertCreated();
    $this->postJson("/api/v1/catalogue/versions/{$d['v']->id}/indemnity-preview", ['coverage_definition_id' => $d['dommages']->id, 'loss_minor' => 400000], $h)
        ->assertOk()->assertJsonPath('data.indemnifiable_minor', 350000);
    $this->postJson("/api/v1/catalogue/versions/{$d['v']->id}/approve", ['reason' => 'Trying without publish right'], $h)->assertForbidden();

    // An insurer user linked to another carrier cannot see or change this product.
    $foreign = makeAuthTestUser($tenant, ['catalogue.view', 'catalogue.manage']);
    TenantMembership::where('user_id', $foreign->id)->update(['carrier_id' => b5aCarrier()->id]);
    Passport::actingAs($foreign);
    $this->getJson('/api/v1/catalogue/carrier-products', $h)->assertOk()->assertJsonPath('data.total', 0);
    $this->getJson("/api/v1/catalogue/carrier-products/{$d['cp']->id}", $h)->assertForbidden();
    $this->postJson("/api/v1/catalogue/versions/{$d['v']->id}/plans", ['code' => 'X', 'name' => ['en' => 'x', 'fr' => 'x']], $h)->assertForbidden();
});
