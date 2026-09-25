<?php

declare(strict_types=1);

/**
 * Owner decisions 2026-09-25 — items 10, 20, 28, 30, 31 + demo document marking
 * (docs/spec/OWNER_DECISIONS_2026-09-25.md). REQ-PRD-007 REQ-RAT-003 REQ-PRP-004 REQ-SEED-005.
 */

use App\Application\Catalogue\CatalogueService;
use App\Application\Catalogue\Governance\GovernanceCutover;
use App\Application\Documents\DemoDocumentMark;
use App\Application\Documents\IssuanceDocumentAcceptance;
use App\Application\Rating\ChargeTableService;
use App\Application\Vehicles\CuratedVehicleReference;
use App\Application\Vehicles\VehicleDataSource;
use App\Application\Vehicles\VehicleDatasetImporter;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\ProposalDocument;
use App\Models\User;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = makeMobileCustomerFixture('+237671117700')['tenant'];
});

function odAs(User $user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/'.$uri, $body, tenantHeaderFor(test()->tenant));
}

function odProduct(string $status = 'DRAFT'): InsuranceProduct
{
    $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'OD Carrier', 'status' => 'ACTIVE'])->id, 'cima_code' => 'OD-'.Str::random(6), 'status' => 'ACTIVE']);

    return InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'OD-'.Str::random(4), 'name' => 'OD', 'version' => 1,
        'effective_from' => '2026-01-01', 'status' => $status, 'coverages' => [], 'regulatory_reference' => 'REF']);
}

it('REQ-PRD-007 owner decision 28: new versions are GOVERNED and the direct submit / publish path is refused outside the workflow', function () {
    $v = odProduct();
    expect(GovernanceCutover::mode($v))->toBe('GOVERNED')->and(GovernanceCutover::isLegacy($v))->toBeFalse();
    $maker = makeAuthTestUser($this->tenant, ['catalogue.view', 'catalogue.manage', 'catalogue.publish'], 'PLATFORM_ADMIN');

    odAs($maker, 'POST', "catalogue/products/{$v->id}/submit", ['notes' => 'Direct submission attempt'])->assertStatus(422)->assertJsonValidationErrors('governance');
    odAs($maker, 'POST', "catalogue/products/{$v->id}/publish", ['reason' => 'Direct publication attempt after cutover'])->assertStatus(422)->assertJsonValidationErrors('governance');
    expect($v->refresh()->status)->toBe('DRAFT');

    // Inside the governance workflow the same CatalogueService call is the workflow's own step (the CIMA guard answers next).
    try {
        GovernanceCutover::withinWorkflow(fn () => app(CatalogueService::class)->submit($v, $maker, 'workflow step'));
    } catch (ValidationException $e) {
        expect($e->errors())->not->toHaveKey('governance');
    }

    // Grandfathered (pre-cutover) versions keep the direct path.
    DB::table('insurance_products')->where('id', $v->id)->update(['governance_mode' => 'LEGACY_GRANDFATHERED']);
    expect(GovernanceCutover::isLegacy($v->refresh()))->toBeTrue();
    expect(fn () => GovernanceCutover::assertDirectPathAllowed($v, 'publication'))->not->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('insurance_products')->where('id', $v->id)->update(['governance_mode' => 'WHATEVER'])))->toThrow(QueryException::class);
});

it('REQ-PRD-007 owner decision 28: governance stages include SANDBOX_TESTS and a missing capability profile is a publication blocker', function () {
    expect(App\Application\Catalogue\Governance\ProductGovernanceService::NEXT)->toMatchArray(['BUSINESS_APPROVAL' => 'SANDBOX_TESTS', 'SANDBOX_TESTS' => 'READY', 'READY' => 'PUBLISHED'])
        ->and(App\Application\Catalogue\Governance\ProductCompletenessService::BLOCKING)->toContain('CAPABILITY_PROFILE');
    $r = app(App\Application\Catalogue\Governance\ProductCompletenessService::class)->evaluate(odProduct());
    expect(collect($r['blockers'])->pluck('code'))->toContain('CAPABILITY_PROFILE');
});

it('REQ-RAT-003 owner decision 10: tax/levy rates become OWNER_CONFIRMED only through an audited maker-checker verification', function () {
    $maker = makeAuthTestUser($this->tenant, ['rating.charges.view', 'rating.charges.manage'], 'OD_MAKER');
    $checker = makeAuthTestUser($this->tenant, ['rating.charges.view', 'rating.charges.approve', 'rating.charges.verify'], 'OD_CHECKER');
    $rules = ['charges' => [['code' => 'TAX', 'basis' => 'PREMIUM', 'basis_points' => 1925]]];

    // No silent confirmation at creation, even with a source.
    odAs($maker, 'POST', 'rating/charge-tables/tax', ['line_code' => 'MOTOR', 'effective_from' => '2026-01-01', 'data_status' => 'OWNER_CONFIRMED', 'source_reference' => 'CGI art. X', 'rules' => $rules])
        ->assertStatus(422)->assertJsonValidationErrors('data_status');
    $t = odAs($maker, 'POST', 'rating/charge-tables/tax', ['line_code' => 'MOTOR', 'effective_from' => '2026-01-01', 'rules' => $rules])->assertCreated()->json('data');
    expect($t['data_status'])->toBe('DEMO_UNVERIFIED')->and($t['verification_status'])->toBe('DEMO');

    // The database refuses a direct flip.
    expect(fn () => DB::transaction(fn () => DB::table('tax_levy_versions')->where('id', $t['id'])->update(['data_status' => 'OWNER_CONFIRMED'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('tax_levy_versions')->where('id', $t['id'])->update(['data_status' => 'OWNER_CONFIRMED', 'verification_status' => 'VERIFIED', 'legal_basis' => 'x', 'source_reference' => 'y', 'verified_by' => $maker->id, 'verification_requested_by' => $maker->id, 'verified_at' => now()])))
        ->toThrow(QueryException::class); // same person twice

    $base = "rating/charge-tables/tax/{$t['id']}/verification";
    odAs($maker, 'POST', $base, ['source_reference' => 'Loi de finances 2026'])->assertStatus(422)->assertJsonValidationErrors('legal_basis');
    odAs($maker, 'POST', $base, ['legal_basis' => 'Loi de finances 2026, article (to be cited)', 'source_reference' => 'LF-2026', 'source_document' => 'archive://lf-2026.pdf'])
        ->assertOk()->assertJsonPath('data.verification_status', 'PENDING_VERIFICATION')->assertJsonPath('data.data_status', 'DEMO_UNVERIFIED');
    odAs($maker, 'POST', "{$base}/decide", ['decision' => 'CONFIRM', 'notes' => 'Self verification attempt'])->assertForbidden(); // no rating.charges.verify
    $self = makeAuthTestUser($this->tenant, ['rating.charges.verify'], 'OD_SELF');
    DB::table('tax_levy_versions')->where('id', $t['id'])->update(['verification_requested_by' => $self->id]);
    odAs($self, 'POST', "{$base}/decide", ['decision' => 'CONFIRM', 'notes' => 'Self verification attempt'])->assertStatus(422)->assertJsonValidationErrors('actor');

    odAs($checker, 'POST', "{$base}/decide", ['decision' => 'CONFIRM', 'notes' => 'Checked against the official gazette.'])
        ->assertOk()->assertJsonPath('data.verification_status', 'VERIFIED')->assertJsonPath('data.data_status', 'OWNER_CONFIRMED');
    expect(DB::table('audit_log')->where('subject_id', $t['id'])->whereIn('action', ['rating.charge_table.verification_requested', 'rating.charge_table.verification_confirmed'])->count())->toBe(2);
    odAs($checker, 'POST', "{$base}/decide", ['decision' => 'CONFIRM', 'notes' => 'Second confirmation attempt'])->assertStatus(422);
});

it('REQ-RAT-003 owner decision 10: unverified rates are refused on a real production host', function () {
    $row = (object) ['id' => 'x', 'data_status' => 'DEMO_UNVERIFIED'];
    expect(fn () => ChargeTableService::assertUsable('tax_levy_versions', $row))->not->toThrow(DomainException::class); // testing env
    app()->detectEnvironment(fn () => 'production');
    try {
        config(['demo.enabled' => true]); // production keeps demo ON (owner decision): labelled, not refused
        expect(fn () => ChargeTableService::assertUsable('tax_levy_versions', $row))->not->toThrow(DomainException::class);
        config(['demo.enabled' => false]);
        expect(fn () => ChargeTableService::assertUsable('tax_levy_versions', $row))->toThrow(DomainException::class, 'cannot be used in production');
        expect(fn () => ChargeTableService::assertUsable('tax_levy_versions', (object) ['id' => 'y', 'data_status' => 'OWNER_CONFIRMED']))->not->toThrow(DomainException::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('REQ-PRP-004 owner decision 31: issuance acceptance needs a named reviewer or an approved automated control', function () {
    $uw = User::factory()->create();
    $link = fn (array $a) => (new ProposalDocument)->forceFill($a + ['status' => 'VERIFIED']);
    expect(IssuanceDocumentAcceptance::accepted($link([])))->toBeFalse()
        ->and(IssuanceDocumentAcceptance::accepted($link(['verified_by' => $uw->id, 'verification_method' => 'MANUAL'])))->toBeTrue()
        ->and(IssuanceDocumentAcceptance::acceptedBy($link(['verified_by' => $uw->id])))->toBe('MANUAL')
        ->and(IssuanceDocumentAcceptance::accepted($link(['verification_method' => 'AUTOMATED_CONTROL', 'automated_control_code' => 'OCR_ID_MATCH'])))->toBeFalse()
        ->and(IssuanceDocumentAcceptance::accepted($link(['status' => 'SUBMITTED', 'verified_by' => $uw->id])))->toBeFalse();

    config(['proposals.documents.approved_automated_controls' => ['OCR_ID_MATCH']]);
    expect(IssuanceDocumentAcceptance::accepted($link(['verification_method' => 'AUTOMATED_CONTROL', 'automated_control_code' => 'OCR_ID_MATCH'])))->toBeTrue()
        ->and(IssuanceDocumentAcceptance::acceptedBy($link(['verification_method' => 'AUTOMATED_CONTROL', 'automated_control_code' => 'OCR_ID_MATCH'])))->toBe('AUTOMATED_CONTROL:OCR_ID_MATCH');

    // Submission may accept UPLOADED_NOT_YET_REVIEWED only where the product permits.
    $p = odProduct();
    expect(IssuanceDocumentAcceptance::submissionAcceptsUnreviewed($p))->toBeTrue();
    DB::table('insurance_products')->where('id', $p->id)->update(['submission_accepts_unreviewed_documents' => false]);
    expect(IssuanceDocumentAcceptance::submissionAcceptsUnreviewed($p->refresh()))->toBeFalse();
});

it('REQ-SEED-005 documents generated while demo mode is on carry the DEMONSTRATION overlay', function () {
    config(['demo.enabled' => false]);
    expect(DemoDocumentMark::html())->toBe('')->and(trim(view('pdf._demo_overlay')->render()))->toBe('');
    config(['demo.enabled' => true]);
    expect(view('pdf._demo_overlay')->render())->toContain('DEMONSTRATION / DÉMONSTRATION — NOT VALID INSURANCE');
    foreach (['engine-document', 'payment-receipt', 'policy-certificate', 'policy-schedule'] as $view) {
        expect(file_get_contents(resource_path("views/pdf/{$view}.blade.php")))->toContain("@include('pdf._demo_overlay')");
    }
    expect(file_get_contents(app_path('Application/Quotes/QuoteDocumentRenderer.php')))->toContain('DemoDocumentMark::html()');
});

it('owner decision 30: Terms / Privacy show DRAFT_LEGAL_REVIEW_REQUIRED and declarations are UNVERIFIED_LEGAL_WORDING', function () {
    $this->get('/terms')->assertOk()->assertSee('DRAFT_LEGAL_REVIEW_REQUIRED');
    $this->get('/privacy')->assertOk()->assertSee('DRAFT_LEGAL_REVIEW_REQUIRED');
    expect(collect(config('proposals.declarations'))->pluck('legal_status')->unique()->values()->all())->toBe(['UNVERIFIED_LEGAL_WORDING']);
});

it('owner decision 20: Datsun and Mahindra are curated reference makes; the ODbL importer is hard-disabled', function () {
    expect(app(CuratedVehicleReference::class)->apply())->toBe(['DATSUN', 'MAHINDRA'])
        ->and(app(CuratedVehicleReference::class)->apply())->toBe([]);
    foreach (['DATSUN', 'MAHINDRA'] as $code) {
        $make = VehicleMake::where('code', $code)->firstOrFail();
        expect($make->data_source)->toBe(VehicleDataSource::CURATED_REFERENCE)->and(VehicleModel::where('make_id', $make->id)->exists())->toBeFalse();
    }
    expect(VehicleDataSource::mayOverwrite(VehicleDataSource::GLOBAL_DATASET, VehicleDataSource::CURATED_REFERENCE))->toBeFalse()
        ->and(VehicleMake::whereIn('name', ['McLaren', 'Lada', 'UAZ'])->exists())->toBeFalse();

    $csv = tempnam(sys_get_temp_dir(), 'od').'.csv';
    file_put_contents($csv, "make,model\n");
    config(['vehicles.global_dataset_import_enabled' => false]);
    $this->artisan('opesinsure:import-vehicle-dataset', ['--path' => $csv, '--accept-license' => true])->expectsOutputToContain('disabled by owner decision 20')->assertExitCode(1);
    expect(fn () => app(VehicleDatasetImporter::class)->import($csv))->toThrow(RuntimeException::class, 'disabled');
    @unlink($csv);
});
