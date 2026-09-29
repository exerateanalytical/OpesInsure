<?php

declare(strict_types=1);

/**
 * P9 launch check: one provider walks the whole /provider journey on the web screens (eligibility by health-card scan →
 * admission pre-authorisation → insurer decision visible → admission → stay extension → discharge → claim capture and
 * submit → paid settlement → DOC-198 statement download). Every provider step runs through the panel page (same
 * controller action as the API); insurer steps use the insurer services. Another provider sees none of it.
 */

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Application\Providers\Workspace\Filament\Pages\AdmissionsPage;
use App\Application\Providers\Workspace\Filament\Pages\ClaimsPage;
use App\Application\Providers\Workspace\Filament\Pages\EligibilityPage;
use App\Application\Providers\Workspace\Filament\Pages\PreauthorizationsPage;
use App\Application\Providers\Workspace\Filament\Pages\SettlementsPage;
use App\Domain\Tenancy\TenantContext;
use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentTemplate;
use App\Models\Party;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const PJ_PERMS = ['provider.dashboard.view', 'provider.patient.search', 'provider.eligibility.check', 'provider.benefits.view', 'provider.preauth.create', 'provider.preauth.view',
    'provider.preauth.respond_to_query', 'provider.admission.create', 'provider.admission.extend', 'provider.treatment.view', 'provider.treatment.update', 'provider.claim.create',
    'provider.claim.submit', 'provider.claim.view', 'provider.claim.respond_to_query', 'provider.contract.view', 'provider.settlement.view', 'provider.documents.view'];

function pjEmployee(object $t, object $provider): User
{
    $person = Party::create(['type' => 'PERSON', 'display_name' => 'Staff '.Str::random(4), 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $person->id, 'to_party_id' => $provider->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $u = makeAuthTestUser($t->tenant, PJ_PERMS, 'PROVIDER_ADMIN');
    $u->update(['party_id' => $person->id]);

    return $u->refresh();
}

function pjActAs(object $t, User $u, object $provider): void
{
    test()->actingAs($u, 'web');
    app(TenantContext::class)->set($t->tenant->id);
    app()->instance(ProviderScope::class.'@panel', new ProviderScope($provider->id, [$provider->id], $provider->party_id, null));
}

function pjInsurer(object $t, array $perms): User
{
    $u = makeAuthTestUser($t->tenant, $perms);
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $t->f['carrier']->id, 'holder_type' => 'USER', 'holder_id' => $u->id,
        'authority_type' => PreauthLifecycle::authorityType(), 'max_amount_minor' => 10_000_000, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    return $u;
}

beforeEach(function () {
    $root = storage_path('framework/testing/disks/pj-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));

    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->f = $f;
    $this->tenant = $f['tenant'];
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    DocumentIssuanceProfile::create(['carrier_id' => $f['carrier']->id, 'issuance_mode' => 'OPES_GENERATED', 'opes_rendering_authorized' => true, 'authorization_reference' => 'AUTH-PJ', 'default_language' => 'BILINGUAL']);
    $admin = makeAuthTestUser($this->tenant, ['documents.templates.manage']);
    foreach (DocumentTemplate::where('status', 'REVIEW')->where('ownership', 'PLATFORM')->get() as $tpl) {
        app(DocumentTemplateService::class)->approveAndPublishSystem($tpl, $admin);
    }

    $this->policy = Policy::create(['tenant_id' => $this->tenant->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonths(2)->toDateString(), 'coverage_ends_at' => now()->addMonths(10)->toDateString(),
        'terms_snapshot' => ['line_code' => 'HEALTH'], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1_000_000, 'issued_at' => now()]);
    $version = (string) Str::uuid();
    DB::table('policy_versions')->insert(['id' => $version, 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'version_no' => 1, 'kind' => 'ISSUANCE',
        'valid_from' => now()->subMonths(2)->toDateString(), 'recorded_at' => now()->subDay(), 'snapshot' => '{}', 'snapshot_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
    foreach (['OUTPATIENT', 'INPATIENT'] as $cov) {
        DB::table('policy_coverages')->insert(['id' => (string) Str::uuid(), 'policy_id' => $this->policy->id, 'policy_version_id' => $version, 'coverage_code' => $cov,
            'limit_minor' => 5_000_000, 'deductible_minor' => 0, 'currency' => 'XAF', 'starts_at' => now()->subMonths(2)->toDateString(), 'ends_at' => now()->addMonths(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

    $net = app(ProviderNetworkService::class);
    $this->cons = $net->addMedicalService(['code' => 'CONS_PJ', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT']);
    DB::table('health_benefit_rules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'service_category_code' => 'OUTPATIENT', 'coverage_code' => 'OUTPATIENT',
        'benefit_code' => 'OP', 'created_at' => now(), 'updated_at' => now()]);

    $reg = app(ProviderRegistry::class);
    $this->clinic = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique PJ '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    $this->other = $reg->register(['category' => 'HEALTH', 'name' => 'Autre PJ '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    foreach ([$this->clinic, $this->other] as $p) {
        foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
            $reg->transition($p->id, $to, null, null, null);
        }
    }
    $this->main = $reg->addFacility($this->clinic->id, ['code' => 'MAIN', 'name' => 'Main']);
    $network = $net->createNetwork($this->tenant->id, ['code' => 'PJNET', 'name' => 'PJ network', 'network_type_code' => 'PREFERRED', 'category' => 'HEALTH', 'carrier_id' => $f['carrier']->id], null);
    $net->addMember($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'effective_from' => now()->subMonths(2)->toDateString()], null);
    DB::table('health_policy_networks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'provider_network_id' => $network->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->contract = $net->createContract($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'contract_number' => 'PJ-001', 'effective_from' => now()->subMonths(2)->toDateString()], null);
    $t = $net->draftTariff($this->tenant->id, $this->contract->id, now()->subMonths(2)->toDateString(), 'XAF',
        [['medical_service_id' => $this->cons->id, 'price_minor' => 20000, 'contracted_price_minor' => 15000, 'copay_minor' => 3000, 'insurer_share_percent' => 80]], (string) Str::uuid());
    $net->approveTariff($this->tenant->id, $t->id, (string) Str::uuid());

    $this->user = pjEmployee($this, $this->clinic);
});

it('a provider walks eligibility → admission preauth → decision → admit → extend → discharge → claim submit → settlement statement download; another provider sees none of it', function () {
    pjActAs($this, $this->user, $this->clinic);
    $today = now()->toDateString();

    // 1. Eligibility by health-card QR scan, through the API controller action.
    $el = Livewire::test(EligibilityPage::class)->set('search_method', 'QR_CODE')->set('member_ref', $this->f['party']->id)->set('policy_id', $this->policy->id)
        ->set('service_code', 'CONS_PJ')->call('check')->assertHasNoErrors();
    expect($el->get('result'))->toHaveKey('verification_reference')->and($el->get('result')['coverage_status'] ?? null)->not->toBeNull();
    expect(DB::table('health_eligibility_checks')->count())->toBe(1);
    // A missing service code is a field error; nothing is recorded.
    Livewire::test(EligibilityPage::class)->set('member_ref', 'X')->set('service_code', '')->call('check')->assertHasErrors('service_code');
    expect(DB::table('health_eligibility_checks')->count())->toBe(1);

    // 2. Admission pre-authorisation.
    $pa = Livewire::test(PreauthorizationsPage::class)->set('request_type', 'ADMISSION')->set('policy_id', $this->policy->id)->set('member_ref', $this->f['party']->id)
        ->set('facility_id', $this->main->id)->set('service_code', 'CONS_PJ')->set('quantity', '1')
        ->set('details', ['admission_date' => $today, 'expected_discharge_date' => now()->addDays(3)->toDateString(), 'diagnosis_code' => 'K35.8', 'admission_reason' => 'Appendicitis'])
        ->call('submitRequest')->assertSet('state', 'SUCCESS');
    $id = $pa->get('selected');
    expect(DB::table('health_preauthorizations')->where('id', $id)->value('status'))->toBe('REQUESTED');

    // 3. Insurer decision (maker-checker) is visible on the provider's admissions screen.
    $svc = app(PreauthorizationService::class);
    $svc->propose($this->tenant->id, $id, ['decision' => 'APPROVED', 'valid_until' => now()->addDays(10)->toDateString()], pjInsurer($this, ['health.preauth.view', 'health.preauth.review']));
    $svc->decide($this->tenant->id, $id, pjInsurer($this, ['health.preauth.view', 'health.preauth.approve']));
    pjActAs($this, $this->user, $this->clinic);
    expect(DB::table('health_preauthorizations')->where('id', $id)->value('status'))->toBe('APPROVED');

    // 4-6. Admit, request a stay extension, discharge — all on /provider/admissions.
    $adm = Livewire::test(AdmissionsPage::class)->call('open', $id)->assertSee('APPROVED')->set('admitted_on', $today)->call('admit')->assertSet('state', 'SUCCESS');
    expect(DB::table('health_preauthorizations')->where('id', $id)->value('status'))->toBe('ADMITTED');
    $adm->set('requested_until', now()->addDays(5)->toDateString())->set('extension_reason', 'Post-operative complication')->call('requestExtension')->assertSet('state', 'SUCCESS');
    $ext = DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $id)->first();
    expect($ext->status)->toBe('REQUESTED');
    // Discharge is refused while the extension is undecided (shown to the user, nothing changes).
    $adm->set('discharged_on', $today)->call('discharge')->assertSet('state', 'VALIDATION_FAILED');
    expect(DB::table('health_preauthorizations')->where('id', $id)->value('status'))->toBe('ADMITTED');
    $svc->proposeExtension($this->tenant->id, $id, $ext->id, ['decision' => 'APPROVED', 'approved_until' => now()->addDays(5)->toDateString()], pjInsurer($this, ['health.preauth.view', 'health.preauth.review']));
    $svc->decideExtension($this->tenant->id, $id, $ext->id, pjInsurer($this, ['health.preauth.view', 'health.preauth.approve']));
    pjActAs($this, $this->user, $this->clinic);
    expect(DB::table('health_preauthorization_extensions')->where('id', $ext->id)->value('status'))->toBe('APPROVED');
    $adm = Livewire::test(AdmissionsPage::class)->call('open', $id)->set('discharged_on', $today)->call('discharge')->assertSet('state', 'SUCCESS');
    expect(DB::table('health_preauthorizations')->where('id', $id)->value('status'))->toBe('DISCHARGED');

    // 7. Claim capture with lines, submit.
    $c = Livewire::test(ClaimsPage::class)->set('contract_id', $this->contract->id)->set('invoice_reference', 'PJ-INV-1')->set('service_date', $today)
        ->set('policy_id', $this->policy->id)->set('facility_id', $this->main->id)
        ->set('lines', [['medical_service_id' => $this->cons->id, 'provider_code' => null, 'quantity' => '1', 'unit_price_minor' => '15000']])
        ->call('createClaim')->assertSet('state', 'SUCCESS');
    $claim = $c->get('selected');
    $c->call('submitClaim')->assertSet('state', 'SUCCESS');
    expect(DB::table('health_provider_claims')->where('id', $claim)->value('status'))->toBe('SUBMITTED');

    // 8. Insurer adjudicates, pays a settlement batch: the DOC-198 statement is issued and downloadable on /provider/settlements.
    $adj = makeAuthTestUser($this->tenant, []);
    $claims = app(ProviderClaimService::class);
    $claims->startReview($this->tenant->id, $claim, $adj->id);
    $claims->adjudicate($this->tenant->id, $claim, [], null, $adj->id);
    $claims->markPayable($this->tenant->id, $claim, $adj->id);
    $batch = app(ProviderSettlementService::class)->createBatch($this->tenant->id, $this->clinic->id, 'XAF', null, $adj->id);
    app(ProviderSettlementService::class)->payBatch($this->tenant->id, $batch->id, 'VIR-PJ', $adj->id);
    pjActAs($this, $this->user, $this->clinic);
    $statement = DB::table('documents')->where('document_type_code', 'PROVIDER_SETTLEMENT_STATEMENT')->first();
    expect($statement)->not->toBeNull()->and($statement->subject_key)->toBe('provider-settlement:'.$batch->id)->and($statement->provider_profile_id)->toBe($this->clinic->id);

    Livewire::test(SettlementsPage::class)->assertSee($batch->batch_number)->assertSee(__('provider_workspace.ui.download_statement'))
        ->call('open', $batch->id)->assertSee('PJ-INV-1')
        ->call('downloadStatement', $batch->id)->assertFileDownloaded();
    expect(DB::table('audit_log')->where('action', 'document.provider.downloaded')->exists())->toBeTrue();

    // Another provider: nothing of this journey is visible or downloadable.
    pjActAs($this, pjEmployee($this, $this->other), $this->other);
    Livewire::test(SettlementsPage::class)->assertDontSee($batch->batch_number)
        ->call('downloadStatement', $batch->id)->assertSet('state', 'VALIDATION_FAILED')->assertNoFileDownloaded();
    Livewire::test(ClaimsPage::class)->assertDontSee('PJ-INV-1');
    Livewire::test(AdmissionsPage::class)->assertDontSee(DB::table('health_preauthorizations')->where('id', $id)->value('preauth_number'));
});
