<?php

declare(strict_types=1);

/**
 * P9 launch check: one provider walks the whole /provider journey on the web screens (eligibility by health-card scan →
 * admission pre-authorisation → insurer decision visible → admission → stay extension → discharge → claim capture and
 * submit → paid settlement → DOC-198 statement download). Every provider step runs through the panel page (same
 * controller action as the API); insurer steps use the insurer services. Another provider sees none of it.
 * Fixture: Concerns/provider_journey_fixture.php (shared with LaunchProviderE2ETest).
 */

use App\Application\Health\Preauth\PreauthorizationService;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Application\Providers\Workspace\Filament\Pages\AdmissionsPage;
use App\Application\Providers\Workspace\Filament\Pages\ClaimsPage;
use App\Application\Providers\Workspace\Filament\Pages\EligibilityPage;
use App\Application\Providers\Workspace\Filament\Pages\PreauthorizationsPage;
use App\Application\Providers\Workspace\Filament\Pages\SettlementsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/Concerns/provider_journey_fixture.php';

uses(RefreshDatabase::class);

beforeEach(fn () => pjFixture($this));

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
