<?php

declare(strict_types=1);

/*
 * Owner decisions 2026-09-25 items 1-9, 16, 23, 24 (docs/spec/OWNER_DECISIONS_2026-09-25.md).
 * REQ-CIMA-002 REQ-CIMA-003 REQ-CIMA-005 REQ-CIMA-006 REQ-DUP-018 REQ-RAT-005
 */

use App\Application\Regulatory\CimaAuthorizationService;
use App\Application\Regulatory\CimaComplianceReport;
use App\Application\Regulatory\CimaLegacyAuthorizationService;
use App\Application\Regulatory\CimaProductMappingService;
use App\Application\Regulatory\CimaPublicationGuard;
use App\Application\Regulatory\CimaReadinessChecklist;
use App\Application\Regulatory\ReconstructedChecklists;
use App\Application\Regulatory\Reporting\BranchPremiumAllocation;
use App\Application\Regulatory\Reporting\RegulatoryExportCodeMap;
use App\Models\Carrier;
use App\Models\CoverageDefinition;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Regulatory\LegacyProductAuthorization;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function cimaOdUser(string $name = 'User'): User
{
    $party = Party::create(['type' => 'PERSON', 'display_name' => $name, 'status' => 'ACTIVE']);

    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'party_id' => $party->id, 'password' => 'x', 'locale' => 'fr', 'status' => 'ACTIVE']);
}

function cimaOdCarrier(bool $demo = false): Carrier
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'OD Assurances '.Str::random(4), 'status' => 'ACTIVE']);

    return Carrier::create(['party_id' => $party->id, 'cima_code' => 'OD-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => [], 'is_demo' => $demo]);
}

function cimaOdProduct(Carrier $carrier, string $line, array $coverages, string $status = 'DRAFT', ?string $code = null, int $version = 1): InsuranceProduct
{
    $l = InsuranceLine::firstOrCreate(['code' => $line], ['name' => ['en' => $line, 'fr' => $line], 'description' => ['en' => $line], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $p = InsuranceProduct::create([
        'carrier_id' => $carrier->id, 'line_code' => $line, 'code' => $code ?? $line.'-'.Str::random(5), 'name' => "$line product", 'version' => $version,
        'effective_from' => '2026-01-01', 'status' => $status, 'coverages' => [], 'eligibility_rules' => ['conditions' => []],
        'created_by' => cimaOdUser()->id, 'regulatory_reference' => 'REF',
    ]);
    foreach ($coverages as $i => $c) {
        $def = CoverageDefinition::firstOrCreate(['insurance_line_id' => $l->id, 'code' => $c], ['name' => ['en' => $c], 'description' => ['en' => $c], 'limit_type' => 'AMOUNT', 'mandatory' => false, 'status' => 'ACTIVE']);
        $p->coverageDefinitions()->attach($def->id, ['display_order' => $i, 'configuration' => '{}']);
    }

    return $p;
}

function cimaOdSeed(): void
{
    test()->artisan('opesinsure:seed-cima')->assertExitCode(0);
}

it('makes insurance_branches canonical with a compatibility view and no duplicated rows (item 16)', function () {
    cimaOdSeed();
    expect(Schema::hasTable('insurance_branches'))->toBeTrue()
        ->and(DB::table('insurance_branches')->count())->toBe(23)
        ->and(DB::table('regulatory_branches')->count())->toBe(23)
        ->and(DB::selectOne("select count(*) c from information_schema.views where table_name = 'regulatory_branches'")->c)->toBe(1)
        ->and(DB::selectOne("select count(*) c from information_schema.tables where table_name = 'regulatory_branches' and table_type = 'BASE TABLE'")->c)->toBe(0);
    $b = RegulatoryBranch::where('number', 2)->first();
    $regime = DB::table('regulatory_regimes')->where('code', 'CIMA')->value('id');
    expect($b->getTable())->toBe('insurance_branches')
        ->and($b->regulatory_regime_id)->toBe($regime)
        ->and($b->name)->toBe($b->label_fr)
        ->and(RegulatoryBranch::whereNull('regulatory_regime_id')->count())->toBe(0);
})->group('REQ-DUP-018');

it('seeds the Q1 coverage rules and maps products PRODUCT -> COVERAGE -> BRANCH (items 1-6)', function () {
    cimaOdSeed();
    $carrier = cimaOdCarrier();
    $travel = cimaOdProduct($carrier, 'TRAVEL', ['MEDICAL', 'CANCELLATION', 'REPATRIATION', 'BAGGAGE']);
    $home = cimaOdProduct($carrier, 'HOME', ['FIRE', 'LIABILITY']);
    $life = cimaOdProduct($carrier, 'LIFE', ['DEATH', 'DISABILITY']);
    $motor = cimaOdProduct($carrier, 'MOTOR', ['THIRD_PARTY', 'THEFT_FIRE']);
    $mapper = app(CimaProductMappingService::class);
    foreach ([$travel, $home, $life, $motor] as $p) {
        $mapper->applyClassDefaults($p);
    }

    $tree = $mapper->coverageTree($travel);
    expect($tree['product'])->toBe([])
        ->and($tree['coverages']['MEDICAL'][0]['branch_code'])->toBe('CIMA_02_SICKNESS')
        ->and($tree['coverages']['CANCELLATION'][0]['branch_code'])->toBe('CIMA_16_FINANCIAL_LOSS')
        ->and($tree['coverages']['REPATRIATION'][0]['branch_code'])->toBe('CIMA_18_ASSISTANCE')
        ->and($tree['coverages'])->not->toHaveKey('BAGGAGE');   // not in Q1: never guessed

    $homeTree = $mapper->coverageTree($home);
    expect($homeTree['coverages']['LIABILITY'][0]['branch_code'])->toBe('CIMA_13_GENERAL_LIABILITY')
        ->and($homeTree['coverages']['FIRE'][0]['branch_code'])->toBe('CIMA_08_FIRE_NATURAL');

    $dis = ProductRegulatoryMapping::where('insurance_product_id', $life->id)->where('coverage_code', 'DISABILITY')->first();
    expect($dis->branch_code)->toBe('CIMA_20_LIFE_DEATH')->and($dis->relationship_type)->toBe('COMPLEMENTARY')->and($dis->separate_premium)->toBeTrue()
        ->and($dis->branch_allocation_status)->toBe('PENDING_CARRIER_ALLOCATION');
    expect(ProductRegulatoryMapping::where('insurance_product_id', $motor->id)->where('coverage_code', 'THEFT_FIRE')->value('branch_code'))->toBe('CIMA_03_LAND_VEHICLE_DAMAGE');

    // Travel's wholesale branch-18 rule is superseded, never deleted.
    expect(DB::table('regulatory_class_defaults')->where('line_code', 'TRAVEL')->whereNull('requires_coverage_code')->value('status'))->toBe('SUPERSEDED');
})->group('REQ-CIMA-003');

it('blocks new product publication when authorization is unknown - never warning-only (item 7)', function () {
    cimaOdSeed();
    $p = cimaOdProduct(cimaOdCarrier(), 'TRAVEL', ['MEDICAL']);
    expect(fn () => app(CimaPublicationGuard::class)->assertPublishable($p))->toThrow(ValidationException::class, 'BLOCK_NEW_PRODUCT_PUBLICATION');
    expect(app(CimaPublicationGuard::class)->authorizationStatus($p))->toBe(CimaPublicationGuard::BLOCKED);
})->group('REQ-CIMA-002');

it('records live products as LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION and enforces its limits (item 8)', function () {
    $carrier = cimaOdCarrier();
    $live = cimaOdProduct($carrier, 'HEALTH', ['HOSPITALISATION'], 'ACTIVE', 'HEALTH-LEGACY');
    $live->update(['published_at' => now()->subYear()]);
    cimaOdSeed();

    $legacy = LegacyProductAuthorization::where('insurance_product_id', $live->id)->first();
    $guard = app(CimaPublicationGuard::class);
    expect($legacy->status)->toBe('LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION')->and($legacy->branch_codes)->toBe(['CIMA_02_SICKNESS'])
        ->and($guard->authorizationStatus($live))->toBe(LegacyProductAuthorization::STATUS)
        ->and($guard->regulatoryClaimsAllowed($live))->toBeFalse()
        ->and(app(CimaLegacyAuthorizationService::class)->register())->toBe(0);   // idempotent

    // A maintenance version on the same branch is allowed; a new branch is not.
    $v2 = cimaOdProduct($carrier, 'HEALTH', ['HOSPITALISATION'], 'DRAFT', 'HEALTH-LEGACY', 2);
    expect($guard->violations($v2))->toBe([]);
    expect(fn () => app(CimaProductMappingService::class)->propose($v2, ['branch_code' => 'CIMA_01_ACCIDENT', 'relationship_type' => 'PRIMARY', 'effective_from' => '2026-01-01'], cimaOdUser()))
        ->toThrow(ValidationException::class, 'new branch');
    ProductRegulatoryMapping::create(['insurance_product_id' => $v2->id, 'product_version' => 1, 'branch_code' => 'CIMA_01_ACCIDENT', 'relationship_type' => 'PRIMARY', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'source' => 'ADMIN']);
    expect(implode(' ', $guard->violations($v2)))->toContain('new branch');

    // A new product (different code) for the same insurer stays blocked.
    $other = cimaOdProduct($carrier, 'HEALTH', ['HOSPITALISATION']);
    expect(implode(' ', $guard->violations($other)))->toContain('BLOCK_NEW_PRODUCT_PUBLICATION');

    $report = app(CimaComplianceReport::class)->summary();
    expect($report['counts']['products_legacy_authorization_pending'])->toBe(1);

    // A verified authorization resolves the legacy record.
    $svc = app(CimaAuthorizationService::class);
    $auth = $svc->record($carrier, ['authorization_reference' => 'ARRETE-9', 'source' => 'REGULATOR_DECREE', 'source_document' => 'ARCHIVE-9',
        'source_authority' => 'minfi', 'evidence' => ['pages' => 2], 'effective_from' => '2026-01-01'], ['CIMA_02_SICKNESS'], cimaOdUser('Maker'));
    expect($auth->verification_status)->toBe('UNVERIFIED')->and($auth->source_authority)->toBe('MINFI');
    $auth = $svc->approve($auth, cimaOdUser('Checker'));
    expect($auth->verification_status)->toBe('VERIFIED')->and($legacy->refresh()->status)->toBe('VERIFIED')
        ->and($guard->authorizationStatus($live))->toBe(CimaPublicationGuard::AUTHORIZED);

    $svc->changeStatus($auth, 'REVOKED', cimaOdUser('Admin'), 'withdrawn');
    expect($auth->refresh()->revocation_date?->toDateString())->toBe(now()->toDateString())
        ->and($svc->isAuthorized($carrier->id, 'CIMA_02_SICKNESS'))->toBeFalse();
})->group('REQ-CIMA-002');

it('never invents branch allocations and excludes estimates from regulatory totals (item 9)', function () {
    expect(BranchPremiumAllocation::normalize(null))->toBe('PENDING_CARRIER_ALLOCATION')
        ->and(BranchPremiumAllocation::normalize('PENDING_OQ_24'))->toBe('PENDING_CARRIER_ALLOCATION')
        ->and(BranchPremiumAllocation::isRegulatory('ESTIMATED_NON_REGULATORY'))->toBeFalse()
        ->and(BranchPremiumAllocation::isRegulatory('ALLOCATED'))->toBeTrue();

    $engine = new App\Domain\Rating\DeterministicRatingEngine;
    $m = new ReflectionMethod($engine, 'allocateBranches');
    expect($m->invoke($engine, 1000, [], null)[1])->toBe('PENDING_CARRIER_ALLOCATION')
        ->and($m->invoke($engine, 1000, [['branch_code' => 'CIMA_02_SICKNESS', 'basis_points' => 10000]], 'ESTIMATED_NON_REGULATORY')[1])->toBe('ESTIMATED_NON_REGULATORY')
        ->and($m->invoke($engine, 1000, [['branch_code' => 'CIMA_02_SICKNESS', 'basis_points' => 10000]], null)[1])->toBe('ALLOCATED');
})->group('REQ-RAT-005');

it('labels reconstructed checklists truthfully (item 23)', function () {
    cimaOdSeed();
    $r = app(CimaReadinessChecklist::class)->evaluate();
    expect($r['checklist_code'])->toBe('OPESINSURE_CIMA_READINESS_V1_DRAFT')->and($r['source_note'])->toStartWith('OPESINSURE_CIMA_READINESS_V1_DRAFT')
        ->and(ReconstructedChecklists::DOD)->toBe('OPESINSURE_DOD_V1_RECONSTRUCTED');
})->group('REQ-CIMA-006');

it('translates internal reporting codes only in the regulatory export adapter (item 24)', function () {
    $map = new RegulatoryExportCodeMap;
    expect($map->translate('ISSUED'))->toBe(['measure' => 'PREMIUM_WRITTEN', 'intermediary_type' => null])
        ->and($map->translate('COLLECTED')['measure'])->toBe('PREMIUM_COLLECTED')
        ->and($map->translate('BROKER_COMMISSION', 'ISSUED'))->toBe(['measure' => 'COMMISSION_RECORDED', 'intermediary_type' => 'BROKER_COMMISSION'])
        ->and($map->translate('AGENT_COMMISSION', 'COLLECTED')['measure'])->toBe('COMMISSION_COLLECTED');
    expect(fn () => $map->translate('BROKER_COMMISSION'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $map->translate('WHATEVER'))->toThrow(InvalidArgumentException::class);
    // Every target is a seeded Article 557 measure.
    cimaOdSeed();
    $measures = DB::table('regulatory_reporting_categories')->where('kind', 'ART_557_MEASURE')->pluck('code')->all();
    expect(array_diff($map->measures(), $measures))->toBe([]);
})->group('REQ-CIMA-005');
