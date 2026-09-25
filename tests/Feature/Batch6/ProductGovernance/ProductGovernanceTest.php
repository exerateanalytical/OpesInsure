<?php

declare(strict_types=1);

use App\Application\Capabilities\CapabilityProfileService;
use App\Application\Catalogue\Governance\Models\ProductGovernance;
use App\Application\Catalogue\Governance\ProductCompletenessService;
use App\Application\Catalogue\ProductConfigurationService;
use App\Application\Catalogue\ProductModelService;
use App\Application\Catalogue\Sandbox\ProductSandbox;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Filament\Admin\Resources\InsuranceProducts\Pages\ViewInsuranceProduct;
use App\Models\Carrier;
use App\Models\CoverageDefinition;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const B6A_RULES = [
    'required_facts' => ['usage'],
    'base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'vehicle_value', 'rate_ppm' => 20000],
    'loadings' => [['code' => 'TAXI', 'type' => 'HIGH_RISK_USE', 'fact' => 'usage', 'operator' => 'EQUALS', 'value' => 'TAXI', 'basis_points' => 2500]],
    'rounding' => ['unit_minor' => 100, 'mode' => 'HALF_UP'],
];

const B6A_ALL = ['catalogue.view', 'catalogue.manage', 'catalogue.review', 'catalogue.publish', 'catalogue.test'];

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    $this->tenant = makeMobileCustomerFixture('+237671116600')['tenant'];
    $this->maker = makeAuthTestUser($this->tenant, ['catalogue.view', 'catalogue.manage', 'catalogue.test'], 'PLATFORM_ADMIN');
    $this->tech = makeAuthTestUser($this->tenant, ['catalogue.view', 'catalogue.review'], 'PLATFORM_ADMIN');
    $this->compliance = makeAuthTestUser($this->tenant, ['catalogue.view', 'catalogue.review'], 'PLATFORM_ADMIN');
    $this->business = makeAuthTestUser($this->tenant, ['catalogue.view', 'catalogue.publish'], 'PLATFORM_ADMIN');

    $line = InsuranceLine::firstOrCreate(['code' => 'B6A_MOTOR'], ['name' => ['en' => 'Motor', 'fr' => 'Auto'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $rc = CoverageDefinition::firstOrCreate(['insurance_line_id' => $line->id, 'code' => 'RC'], ['name' => ['en' => 'RC', 'fr' => 'RC'], 'limit_type' => 'FIXED_AMOUNT', 'mandatory' => true, 'status' => 'ACTIVE']);
    $this->carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B6A Assurances', 'status' => 'ACTIVE'])->id, 'cima_code' => 'B6A-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);
    $svc = app(ProductModelService::class);
    $family = $svc->createFamily(['code' => 'B6A_PRIVATE_'.Str::random(4), 'class_code' => 'MOTOR', 'line_code' => 'B6A_MOTOR', 'default_branch_code' => 'CIMA_10_MOTOR_LIABILITY',
        'name' => ['en' => 'Private motor', 'fr' => 'Automobile particulier']], $this->maker);
    $this->cp = $svc->createCarrierProduct(['carrier_id' => $this->carrier->id, 'product_family_id' => $family->id, 'code' => 'AUTO_'.Str::random(4), 'line_code' => 'B6A_MOTOR',
        'name' => ['en' => 'Auto Plus', 'fr' => 'Auto Plus'], 'customer_type' => 'INDIVIDUAL', 'currency' => 'XAF', 'market' => 'CM'], $this->maker);
    $this->v = $svc->newVersion($this->cp, ['effective_from' => '2026-01-01', 'regulatory_reference' => 'B6A-REF'], $this->maker);
    app(ProductConfigurationService::class)->configureCoverage($this->v, $rc, ['inclusion' => 'MANDATORY', 'territory' => 'CEMAC'], $this->maker);
});

function b6aAs(User $user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/catalogue/'.$uri, $body, tenantHeaderFor(test()->tenant));
}

function b6aTariff(InsuranceProduct $v, string $status = 'APPROVED'): string
{
    $id = (string) Str::uuid();
    DB::table('tariff_versions')->insert(['id' => $id, 'insurance_product_id' => $v->id, 'version' => 1, 'effective_from' => '2026-01-01', 'status' => $status,
        'input_schema' => '{}', 'rules' => json_encode(B6A_RULES), 'rules_hash' => hash('sha256', json_encode(B6A_RULES)), 'regulatory_reference' => 'DEMO-UNVERIFIED',
        'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** CIMA dictionary, insurer authorization, capability profile (pricing, underwriting, accounting, claims) — everything but tests. */
function b6aConfigure(InsuranceProduct $v): void
{
    test()->artisan('opesinsure:seed-cima')->assertExitCode(0);
    $auth = app(CimaAuthorizationService::class);
    $a = $auth->record(Carrier::findOrFail($v->carrier_id), ['authorization_reference' => 'ARRETE-B6A', 'source' => 'REGULATOR_DECREE',
        'source_document' => 'https://example.test/arrete.pdf', 'effective_from' => '2025-01-01'], ['CIMA_10_MOTOR_LIABILITY'], test()->tech);
    $auth->approve($a, test()->business);
    $caps = app(CapabilityProfileService::class);
    $p = $caps->draft(Carrier::findOrFail($v->carrier_id), [
        ['capability' => 'RATING', 'mode' => 'MANUAL_PREMIUM'], ['capability' => 'UNDERWRITING', 'mode' => 'NOT_REQUIRED'],
        ['capability' => 'ACCOUNTING', 'mode' => 'EXPORT_ONLY'], ['capability' => 'CLAIMS_INTAKE', 'mode' => 'BROKER_ASSISTED'],
        ['capability' => 'CLAIMS_DECISION', 'mode' => 'MANUAL_CARRIER'],
    ], test()->tech);
    $caps->submit($p, test()->tech);
    $caps->approve($p, test()->business);
    b6aTariff($v);
}

function b6aFailing(array $report): array
{
    return collect($report['blockers'])->pluck('code')->sort()->values()->all();
}

it('REQ-PRD-010 REQ-PRD-007 completeness lists every publication blocker until the version is fully configured and tested', function () {
    $svc = app(ProductCompletenessService::class);
    $r = $svc->evaluate($this->v);
    expect($r['status'])->toBe('INCOMPLETE')->and($r['publishable'])->toBeFalse()
        ->and(b6aFailing($r))->toBe(['ACCOUNTING_MAPPING', 'CAPABILITY_PROFILE', 'CIMA_BRANCH_AUTHORIZED', 'CLAIMS_CONFIGURATION', 'PRICING_MODE', 'PRODUCT_TESTS', 'UNDERWRITING_MODE'])
        ->and(collect($r['checks'])->firstWhere('code', 'REGULATORY_MAPPING')['passed'])->toBeTrue();

    // A version whose family has no default branch and no mapping: missing regulatory mapping.
    $bare = InsuranceProduct::create(['carrier_id' => $this->carrier->id, 'line_code' => 'B6A_MOTOR', 'code' => 'BARE', 'name' => 'Bare', 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'DRAFT', 'coverages' => []]);
    expect(b6aFailing($svc->evaluate($bare)))->toContain('REGULATORY_MAPPING');

    b6aConfigure($this->v);
    $r = $svc->evaluate($this->v->refresh());
    expect(b6aFailing($r))->toBe(['PRODUCT_TESTS']);

    // Endpoint (read-only; never writes a governance row).
    $body = b6aAs($this->maker, 'GET', "versions/{$this->v->id}/completeness")->assertOk()->json('data');
    expect($body['status'])->toBe('INCOMPLETE')->and(collect($body['blockers'])->pluck('code')->all())->toBe(['PRODUCT_TESTS'])
        ->and(ProductGovernance::count())->toBe(0);
});

it('REQ-PRD-008 sandbox runs a test policy pack through rules + rating + documents without writing business records', function () {
    b6aTariff($this->v, 'DRAFT');
    $before = fn () => collect(['rating_runs', 'engine_evaluations', 'quotes', 'policies', 'outbox_messages'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $counts = $before();

    $adhoc = b6aAs($this->maker, 'POST', "versions/{$this->v->id}/sandbox", ['facts' => ['usage' => 'TAXI', 'vehicle_value' => 5000000]])->assertOk()->json('data');
    expect($adhoc['eligibility']['outcome'])->toBe('ELIGIBLE')
        ->and($adhoc['rating']['status'])->toBe('PRICED')->and($adhoc['rating']['tariff_source'])->toBe('CANDIDATE')
        ->and($adhoc['rating']['pricing']['base_minor'])->toBe(100000)->and($adhoc['rating']['trace'])->not->toBeEmpty()
        ->and($adhoc)->toHaveKey('documents')->and($adhoc['passed'])->toBeTrue();
    expect($before())->toBe($counts);

    $total = $adhoc['rating']['pricing']['total_minor'];
    b6aAs($this->maker, 'POST', "versions/{$this->v->id}/test-cases", ['code' => 'TAXI', 'name' => 'Taxi 5M', 'facts' => ['usage' => 'TAXI', 'vehicle_value' => 5000000],
        'expected' => ['eligibility' => 'ELIGIBLE', 'premium_total_minor' => $total]])->assertCreated();
    b6aAs($this->maker, 'POST', "versions/{$this->v->id}/test-cases", ['code' => 'MISSING_USAGE', 'name' => 'Missing usage fails rating', 'facts' => ['vehicle_value' => 1000000],
        'expected' => ['rating_fails' => true]])->assertCreated();
    b6aAs($this->tech, 'POST', "versions/{$this->v->id}/test-cases", ['code' => 'X', 'name' => 'x', 'facts' => ['a' => 1]])->assertForbidden();

    $run = b6aAs($this->maker, 'POST', "versions/{$this->v->id}/test-runs")->assertCreated()->json('data');
    expect($run['status'])->toBe('PASSED')->and($run['cases_total'])->toBe(2)->and($run['cases_failed'])->toBe(0);
    expect(app(ProductSandbox::class)->latestRun($this->v)['current'])->toBeTrue();

    // A wrong expectation fails the pack.
    b6aAs($this->maker, 'POST', "versions/{$this->v->id}/test-cases", ['code' => 'WRONG', 'name' => 'Wrong premium', 'facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 5000000],
        'expected' => ['premium_total_minor' => 1]])->assertCreated();
    $failed = b6aAs($this->maker, 'POST', "versions/{$this->v->id}/test-runs")->assertCreated()->json('data');
    expect($failed['status'])->toBe('FAILED')->and($failed['cases_failed'])->toBe(1)
        ->and(collect($failed['results'])->firstWhere('code', 'WRONG')['assertions'][0]['passed'])->toBeFalse();

    // A configuration change (tariff rules) makes the last run stale.
    DB::table('tariff_versions')->where('insurance_product_id', $this->v->id)->update(['rules_hash' => str_repeat('b', 64)]);
    expect(app(ProductSandbox::class)->latestRun($this->v)['current'])->toBeFalse();
    expect(b6aAs($this->maker, 'GET', "versions/{$this->v->id}/test-runs")->assertOk()->json('meta.latest_is_current'))->toBeFalse();
});

it('REQ-PRD-007 runs DRAFT → CONFIGURATION → TECHNICAL_REVIEW → COMPLIANCE_REVIEW → BUSINESS_APPROVAL → READY → PUBLISHED with four eyes and scheduled publication', function () {
    $id = $this->v->id;
    b6aAs($this->maker, 'POST', "versions/{$id}/governance/advance")->assertOk()->assertJsonPath('data.stage', 'CONFIGURATION');
    // Submission is refused while blockers exist.
    b6aAs($this->maker, 'POST', "versions/{$id}/governance/advance")->assertStatus(422)->assertJsonValidationErrors('completeness');

    b6aConfigure($this->v);
    b6aAs($this->maker, 'POST', "versions/{$id}/test-cases", ['code' => 'PRIVATE', 'name' => 'Private car', 'facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 4000000], 'expected' => ['eligibility' => 'ELIGIBLE']])->assertCreated();
    b6aAs($this->maker, 'POST', "versions/{$id}/test-runs")->assertCreated()->assertJsonPath('data.status', 'PASSED');
    b6aAs($this->maker, 'PATCH', "versions/{$id}/governance", ['owner_user_id' => $this->business->id, 'target_market' => ['Private individuals'], 'prohibited_market' => ['Taxi fleets'], 'next_review_date' => '2027-10-01'])
        ->assertOk()->assertJsonPath('data.target_market', ['Private individuals']);

    b6aAs($this->maker, 'POST', "versions/{$id}/governance/advance", ['notes' => 'Ready for review'])->assertOk()->assertJsonPath('data.stage', 'TECHNICAL_REVIEW');
    expect($this->v->refresh()->status)->toBe('IN_REVIEW');

    // The maker has no review permission; a reviewer who is also the maker is refused by the service.
    b6aAs($this->maker, 'POST', "versions/{$id}/governance/advance")->assertForbidden();
    b6aAs($this->tech, 'POST', "versions/{$id}/governance/advance")->assertOk()->assertJsonPath('data.stage', 'COMPLIANCE_REVIEW');
    // Segregation of duties: the technical reviewer cannot also complete the compliance review.
    b6aAs($this->tech, 'POST', "versions/{$id}/governance/advance")->assertStatus(422)->assertJsonValidationErrors('actor');
    b6aAs($this->compliance, 'POST', "versions/{$id}/governance/advance")->assertOk()->assertJsonPath('data.stage', 'BUSINESS_APPROVAL');
    $approvalId = ProductGovernance::find($id)->approval_request_id;
    expect(DB::table('approval_requests')->where('id', $approvalId)->value('action_code'))->toBe('product.publish');

    b6aAs($this->compliance, 'POST', "versions/{$id}/governance/advance")->assertForbidden(); // business approval needs catalogue.publish
    b6aAs($this->business, 'POST', "versions/{$id}/governance/advance")->assertOk()->assertJsonPath('data.stage', 'SANDBOX_TESTS');
    // Owner decision 28: SANDBOX_TESTS re-runs the test pack on the approved configuration before READY.
    b6aAs($this->tech, 'POST', "versions/{$id}/governance/advance")->assertForbidden(); // needs catalogue.test
    b6aAs($this->maker, 'POST', "versions/{$id}/governance/advance")->assertOk()->assertJsonPath('data.stage', 'READY');
    expect($this->v->refresh()->status)->toBe('APPROVED')->and($this->v->snapshot_hash)->not->toBeNull()
        ->and(DB::table('approval_requests')->where('id', $approvalId)->value('status'))->toBe('APPROVED');

    // Future-dated publication, executed by the scheduler command.
    b6aAs($this->business, 'POST', "versions/{$id}/governance/publish", ['publish_at' => '2026-10-11 08:00:00'])->assertOk()->assertJsonPath('data.stage', 'READY');
    $this->artisan('catalogue:publish-scheduled')->assertExitCode(0);
    expect($this->v->refresh()->status)->toBe('APPROVED');
    $this->travelTo(now()->setDate(2026, 10, 11)->setTime(9, 0));
    $this->artisan('catalogue:publish-scheduled')->assertExitCode(0);
    expect($this->v->refresh()->status)->toBe('ACTIVE')->and(ProductGovernance::find($id)->stage)->toBe('PUBLISHED');

    $h = b6aAs($this->maker, 'GET', "versions/{$id}/governance")->assertOk()->json('data');
    expect($h['status'])->toBe('PUBLISHED')->and(collect($h['history'])->pluck('to_stage')->all())
        ->toBe(['CONFIGURATION', 'TECHNICAL_REVIEW', 'COMPLIANCE_REVIEW', 'BUSINESS_APPROVAL', 'SANDBOX_TESTS', 'READY', 'READY', 'PUBLISHED']);

    // A new version shows its configuration diff against the published base.
    $v2 = app(ProductModelService::class)->newVersion($this->cp, ['effective_from' => '2027-01-01'], $this->maker);
    DB::table('product_coverages')->where('insurance_product_id', $v2->id)->update(['territory' => 'CAMEROON']);
    $diff = b6aAs($this->maker, 'GET', "versions/{$v2->id}/diff")->assertOk()->json('data');
    expect($diff['against_version_id'])->toBe($id)->and(collect($diff['changes'])->pluck('to')->all())->toContain('CAMEROON');
});

it('REQ-PRD-007 a reviewer rejects a version in review; carriers cannot govern another insurer\'s products', function () {
    b6aConfigure($this->v);
    app(ProductSandbox::class)->addCase($this->v, ['code' => 'OK', 'name' => 'ok', 'facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 1000000]], $this->maker);
    app(ProductSandbox::class)->runPack($this->v, $this->maker);
    b6aAs($this->maker, 'POST', "versions/{$this->v->id}/governance/advance")->assertOk();
    b6aAs($this->maker, 'POST', "versions/{$this->v->id}/governance/advance")->assertOk()->assertJsonPath('data.stage', 'TECHNICAL_REVIEW');

    b6aAs($this->tech, 'POST', "versions/{$this->v->id}/governance/reject", ['reason' => 'Wording is not bilingual yet'])->assertOk()->assertJsonPath('data.stage', 'REJECTED');
    expect($this->v->refresh()->status)->toBe('REJECTED')
        ->and(DB::table('product_status_history')->where('insurance_product_id', $this->v->id)->where('to_status', 'REJECTED')->exists())->toBeTrue();
    b6aAs($this->tech, 'POST', "versions/{$this->v->id}/governance/advance")->assertStatus(422);

    $other = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other', 'status' => 'ACTIVE'])->id, 'cima_code' => 'OTH-'.Str::random(5), 'status' => 'ACTIVE']);
    $insurer = makeAuthTestUser($this->tenant, B6A_ALL, 'CARRIER_ADMIN');
    TenantMembership::where('user_id', $insurer->id)->update(['carrier_id' => $other->id]);
    b6aAs($insurer, 'GET', "versions/{$this->v->id}/completeness")->assertForbidden();
    b6aAs($insurer, 'POST', "versions/{$this->v->id}/sandbox", ['facts' => ['usage' => 'PRIVATE']])->assertForbidden();
});

it('REQ-PRD-010 renders the INS-PRODUCT-VERSION product builder screen with its tabs and runs the test pack from it', function () {
    require_once base_path('tests/Feature/Wave12/Concerns/mobile_auth_helpers.php');
    b6aTariff($this->v, 'DRAFT');
    $admin = makeMobileTestUser('+237670006611');
    makeMobileTestWorkspace($admin, ['*'], 'SYSTEM_ADMIN');
    $this->actingAs($admin);
    app(\App\Domain\Tenancy\TenantContext::class)->set($this->tenant->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    app(ProductSandbox::class)->addCase($this->v, ['code' => 'OK', 'name' => 'Private car', 'facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 1000000]], $this->maker);
    Livewire::test(ViewInsuranceProduct::class, ['record' => $this->v->id])->assertOk()
        ->assertSee(__('product_builder.tabs.governance'))->assertSee(__('product_builder.tabs.completeness'))->assertSee(__('product_builder.checks.PRODUCT_TESTS'))
        ->callAction('runTests')->assertHasNoActionErrors();
    expect(DB::table('product_test_runs')->where('insurance_product_id', $this->v->id)->value('status'))->toBe('PASSED');
});
