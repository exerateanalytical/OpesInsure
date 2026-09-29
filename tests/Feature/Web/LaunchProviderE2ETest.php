<?php

declare(strict_types=1);

/**
 * R8 launch E2E (/provider): every provider form and row action is exercised with invalid input (empty, malformed ids,
 * another provider's ids) and must answer with a field error or a clear refusal — never an exception (500) and never
 * another provider's data. Then the back half of the journey runs on the web screens: claim query response → insurer
 * payment → reconciliation (payment + allocation) → dispute → provider documents (DOC-064..072/198/215/216) listed with
 * their verification link and downloadable → staff assignment / revocation → departments. The front half (eligibility →
 * settlement statement) is ProviderJourneyTest. Fixture: Concerns/provider_journey_fixture.php.
 */

use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Application\Providers\Workspace\Filament\Pages\AdmissionsPage;
use App\Application\Providers\Workspace\Filament\Pages\ClaimsPage;
use App\Application\Providers\Workspace\Filament\Pages\ContractsPage;
use App\Application\Providers\Workspace\Filament\Pages\DisputesPage;
use App\Application\Providers\Workspace\Filament\Pages\DocumentsPage;
use App\Application\Providers\Workspace\Filament\Pages\EligibilityPage;
use App\Application\Providers\Workspace\Filament\Pages\FacilitiesPage;
use App\Application\Providers\Workspace\Filament\Pages\PreauthorizationsPage;
use App\Application\Providers\Workspace\Filament\Pages\ReconciliationsPage;
use App\Application\Providers\Workspace\Filament\Pages\ReportsPage;
use App\Application\Providers\Workspace\Filament\Pages\SettlementsPage;
use App\Application\Providers\Workspace\Filament\Pages\TreatmentEpisodesPage;
use App\Application\Providers\Workspace\Filament\Pages\UsersPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/Concerns/provider_journey_fixture.php';

uses(RefreshDatabase::class);

const LPE_EXTRA = ['provider.dispute.view', 'provider.dispute.create', 'provider.reconciliation.view', 'provider.reconciliation.match', 'provider.users.manage',
    'provider.settings.manage', 'provider.finance.view', 'provider.reports.view', 'provider.reports.export', 'provider.audit.view', 'provider.tariff.view', 'provider_portal.profile.view', 'provider_portal.network.view'];

beforeEach(function () {
    pjFixture($this);
    $this->admin = pjEmployee($this, $this->clinic, LPE_EXTRA);
    DB::table('provider_users')->insert(['id' => (string) Str::uuid(), 'provider_profile_id' => $this->clinic->id, 'user_id' => $this->admin->id, 'provider_role' => 'PROVIDER_ADMIN',
        'facility_scope' => 'ALL', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
});

/** A submitted claim of the clinic (created through the panel), returns its id. */
function lpeClaim(object $t, string $invoice): string
{
    $c = Livewire::test(ClaimsPage::class)->set('contract_id', $t->contract->id)->set('invoice_reference', $invoice)->set('service_date', now()->toDateString())
        ->set('policy_id', $t->policy->id)->set('facility_id', $t->main->id)
        ->set('lines', [['medical_service_id' => $t->cons->id, 'provider_code' => null, 'quantity' => '1', 'unit_price_minor' => '15000']])
        ->call('createClaim')->assertSet('state', 'SUCCESS');
    $c->call('submitClaim')->assertSet('state', 'SUCCESS');

    return (string) $c->get('selected');
}

it('refuses every invalid provider submission with a field error or a clear message: never a 500, nothing written', function () {
    pjActAs($this, $this->admin, $this->clinic);
    $counts = fn () => collect(['health_eligibility_checks', 'health_preauthorizations', 'health_provider_claims', 'provider_disputes', 'provider_reconciliations', 'provider_departments', 'treatment_episodes'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $before = $counts();
    $refused = fn ($lw) => expect($lw->get('state'))->toBe('VALIDATION_FAILED')->and((string) $lw->get('stateMessage'))->not->toBe('');

    // Empty forms: field errors on the right fields.
    Livewire::test(EligibilityPage::class)->set('member_ref', '')->set('service_code', '')->call('check')->assertHasErrors(['member_ref'])->assertOk();
    $refused(Livewire::test(PreauthorizationsPage::class)->call('submitRequest')->assertOk());
    $refused(Livewire::test(ClaimsPage::class)->set('lines', [['medical_service_id' => null, 'provider_code' => null, 'quantity' => '0', 'unit_price_minor' => '-1']])->call('createClaim')->assertOk());
    $refused(Livewire::test(DisputesPage::class)->set('description', '')->call('openDispute')->assertHasErrors(['description'])->assertOk());
    $refused(Livewire::test(ReconciliationsPage::class)->set('amount_minor', 'abc')->call('recordPayment')->assertHasErrors(['payment_reference', 'amount_minor'])->assertOk());
    $refused(Livewire::test(UsersPage::class)->set('user_id', 'not-a-uuid')->call('assign')->assertHasErrors(['user_id'])->assertOk());
    $refused(Livewire::test(FacilitiesPage::class)->set('department_facility', $this->main->id)->call('addDepartment')->assertHasErrors(['department_code', 'department_name'])->assertOk());
    $refused(Livewire::test(TreatmentEpisodesPage::class)->call('openEpisode')->assertOk());

    // Malformed and unknown ids: a clear refusal, not a database error.
    foreach (['not-a-uuid', (string) Str::uuid()] as $bad) {
        $refused(Livewire::test(FacilitiesPage::class)->set('department_facility', $bad)->set('department_code', 'X')->set('department_name', 'X')->call('addDepartment')->assertOk());
        $refused(Livewire::test(UsersPage::class)->call('revoke', $bad)->assertOk());
        $refused(Livewire::test(DocumentsPage::class)->call('download', $bad)->assertOk()->assertNoFileDownloaded());
        $refused(Livewire::test(SettlementsPage::class)->call('downloadStatement', $bad)->assertOk()->assertNoFileDownloaded());
        $refused(Livewire::test(ClaimsPage::class)->call('open', $bad)->set('query_response', 'Answer')->call('respond')->assertOk());
        $refused(Livewire::test(PreauthorizationsPage::class)->call('open', $bad)->set('answer', 'Answer')->call('respond')->assertOk());
        $refused(Livewire::test(AdmissionsPage::class)->call('open', $bad)->set('admitted_on', now()->toDateString())->call('admit')->assertOk());
        $refused(Livewire::test(ReconciliationsPage::class)->call('open', $bad)->set('claim_id', $bad)->set('allocation_minor', '100')->set('reason', 'Allocation')->call('allocate')->assertOk());
        $refused(Livewire::test(DisputesPage::class)->set('claim_id', $bad)->set('description', 'Tariff not applied')->call('openDispute')->assertOk());
        foreach ([ClaimsPage::class, AdmissionsPage::class, DisputesPage::class, ReconciliationsPage::class, SettlementsPage::class, ContractsPage::class, TreatmentEpisodesPage::class] as $page) {
            Livewire::test($page)->call('open', $bad)->assertOk();
        }
    }
    $rep = Livewire::test(ReportsPage::class)->set('filters', ['date_from' => 'garbage', 'date_to' => '2026-13-45', 'facility_id' => 'not-a-uuid', 'claim_status' => null])->assertOk();
    expect($rep->instance()->viewPayload()['state'])->not->toBe('ERROR');
    $rep->call('exportCsv')->assertFileDownloaded();
    expect($counts())->toBe($before);
});

it('walks claim query response → payment → reconciliation → dispute → documents with verification → staff and departments; another provider sees and touches none of it', function () {
    pjActAs($this, $this->admin, $this->clinic);

    // Claim query response (SUBMITTED claim): recorded on the claim timeline.
    $claim = lpeClaim($this, 'LPE-INV-1');
    Livewire::test(ClaimsPage::class)->call('open', $claim)->set('query_response', '')->call('respond')->assertHasErrors('query_response');
    Livewire::test(ClaimsPage::class)->call('open', $claim)->set('query_response', 'Operative report attached')->call('respond')->assertSet('state', 'SUCCESS');
    expect(DB::table('health_provider_claim_events')->where(['health_provider_claim_id' => $claim, 'event' => 'PROVIDER_RESPONSE'])->exists())->toBeTrue();

    // Insurer adjudicates and pays.
    $adj = makeAuthTestUser($this->tenant, []);
    $svc = app(ProviderClaimService::class);
    $svc->startReview($this->tenant->id, $claim, $adj->id);
    $svc->adjudicate($this->tenant->id, $claim, [], null, $adj->id);
    $svc->markPayable($this->tenant->id, $claim, $adj->id);
    $batch = app(ProviderSettlementService::class)->createBatch($this->tenant->id, $this->clinic->id, 'XAF', null, $adj->id);
    app(ProviderSettlementService::class)->payBatch($this->tenant->id, $batch->id, 'VIR-LPE', $adj->id);
    pjActAs($this, $this->admin, $this->clinic);
    $approved = (int) DB::table('health_provider_claims')->where('id', $claim)->value('insurer_share_minor');

    // Reconciliation: record the payment received; a duplicate reference is refused; allocate to the claim.
    $rec = Livewire::test(ReconciliationsPage::class)->set('payment_reference', 'BANK-LPE-1')->set('received_on', now()->toDateString())->set('amount_minor', (string) max(1, $approved))
        ->call('recordPayment')->assertSet('state', 'SUCCESS');
    $recId = (string) $rec->get('selected');
    expect($recId)->not->toBe('');
    Livewire::test(ReconciliationsPage::class)->set('payment_reference', 'BANK-LPE-1')->set('received_on', now()->toDateString())->set('amount_minor', '100')
        ->call('recordPayment')->assertSet('state', 'VALIDATION_FAILED');
    expect(DB::table('provider_reconciliations')->where('payment_reference', 'BANK-LPE-1')->count())->toBe(1);
    Livewire::test(ReconciliationsPage::class)->call('open', $recId)->assertSee('BANK-LPE-1')->set('claim_id', $claim)->set('allocation_minor', (string) max(1, $approved))
        ->set('reason', 'Bank transfer VIR-LPE')->call('allocate')->assertSet('state', 'SUCCESS');

    // Dispute on the claim.
    $d = Livewire::test(DisputesPage::class)->set('subject_type', 'CLAIM')->set('claim_id', $claim)->set('reason_code', 'TARIFF_DIFFERENCE')->set('disputed_amount_minor', '2000')
        ->set('description', 'Contracted tariff not applied on line 1')->call('openDispute')->assertSet('state', 'SUCCESS');
    $disputeNo = DB::table('provider_disputes')->where('id', $d->get('selected'))->value('dispute_number');
    Livewire::test(DisputesPage::class)->assertSee($disputeNo);

    // Provider documents: each is listed with a verification link and downloads (audited).
    $docs = DB::table('documents')->where('provider_profile_id', $this->clinic->id)->get();
    expect($docs->pluck('document_type_code'))->toContain('PROVIDER_SETTLEMENT_STATEMENT');
    $docs = $docs->whereIn('document_type_code', \App\Application\Providers\Workspace\ProviderDocumentService::PROVIDER_TYPES);
    expect($docs->pluck('security_level'))->toContain('MEDICAL_RESTRICTED'); // the EOB
    // PROVIDER_ADMIN is non-clinical: medical documents (EOB) are neither listed nor downloadable; the others are, with their verification link.
    $page = Livewire::test(DocumentsPage::class)->assertOk();
    foreach ($docs as $doc) {
        if ($doc->security_level === 'MEDICAL_RESTRICTED') {
            $page->assertDontSee($doc->document_number);
            Livewire::test(DocumentsPage::class)->call('download', $doc->id)->assertSet('state', 'VALIDATION_FAILED')->assertNoFileDownloaded();

            continue;
        }
        $page->assertSee($doc->document_number)->assertSeeHtml(e(route('public.verify', ['ref' => $doc->document_number])));
        $this->get(route('public.verify', ['ref' => $doc->document_number]))->assertOk();
        pjActAs($this, $this->admin, $this->clinic);
        Livewire::test(DocumentsPage::class)->call('download', $doc->id)->assertFileDownloaded();
    }
    // A practitioner (clinical role) sees and downloads the EOB.
    $doctor = pjEmployee($this, $this->clinic);
    DB::table('provider_users')->insert(['id' => (string) Str::uuid(), 'provider_profile_id' => $this->clinic->id, 'user_id' => $doctor->id, 'provider_role' => 'PRACTITIONER',
        'facility_scope' => 'ALL', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    pjActAs($this, $doctor, $this->clinic);
    foreach ($docs->where('security_level', 'MEDICAL_RESTRICTED') as $doc) {
        Livewire::test(DocumentsPage::class)->assertSee($doc->document_number)->call('download', $doc->id)->assertFileDownloaded();
    }
    pjActAs($this, $this->admin, $this->clinic);
    Livewire::test(DocumentsPage::class)->set('type', 'PROVIDER_SETTLEMENT_STATEMENT')->assertOk();
    expect(DB::table('audit_log')->where('action', 'document.provider.downloaded')->count())->toBeGreaterThanOrEqual(1);

    // Staff: assign a colleague a clinical role on assigned facilities, then revoke; a non-employee is refused.
    $nurse = pjEmployee($this, $this->clinic);
    Livewire::test(UsersPage::class)->assertSee($nurse->email)->set('user_id', $nurse->id)->set('provider_role', 'NURSE')->set('facility_scope', 'ASSIGNED')->set('facility_ids', [$this->main->id])
        ->call('assign')->assertSet('state', 'SUCCESS');
    $pu = DB::table('provider_users')->where(['provider_profile_id' => $this->clinic->id, 'user_id' => $nurse->id])->first();
    expect($pu->provider_role)->toBe('NURSE')->and($pu->facility_scope)->toBe('ASSIGNED');
    Livewire::test(UsersPage::class)->call('revoke', $pu->id)->assertSet('state', 'SUCCESS');
    expect(DB::table('provider_users')->where('id', $pu->id)->value('status'))->not->toBe('ACTIVE');
    $stranger = pjEmployee($this, $this->other);
    Livewire::test(UsersPage::class)->assertDontSee($stranger->email)->set('user_id', $stranger->id)->call('assign')->assertSet('state', 'VALIDATION_FAILED');

    // Departments: a department, then a service unit under it.
    Livewire::test(FacilitiesPage::class)->set('department_facility', $this->main->id)->set('department_code', 'SURG')->set('department_name', 'Surgery')->call('addDepartment')->assertSet('state', 'SUCCESS');
    $dep = DB::table('provider_departments')->where('code', 'SURG')->first();
    Livewire::test(FacilitiesPage::class)->set('department_facility', $this->main->id)->set('department_code', 'SURG-A')->set('department_name', 'Surgery ward A')
        ->set('department_level', 'SERVICE_UNIT')->set('parent_department_id', $dep->id)->call('addDepartment')->assertSet('state', 'SUCCESS');
    expect(DB::table('provider_departments')->where('parent_department_id', $dep->id)->count())->toBe(1);

    // Another provider's administrator: none of the above is visible, openable, downloadable or changeable.
    $otherAdmin = pjEmployee($this, $this->other, LPE_EXTRA);
    pjActAs($this, $otherAdmin, $this->other);
    Livewire::test(ClaimsPage::class)->assertDontSee('LPE-INV-1')->call('open', $claim)->set('query_response', 'Hijack')->call('respond')->assertSet('state', 'VALIDATION_FAILED');
    Livewire::test(ReconciliationsPage::class)->assertDontSee('BANK-LPE-1')->call('open', $recId)->assertDontSee('BANK-LPE-1');
    Livewire::test(DisputesPage::class)->assertDontSee($disputeNo)->set('claim_id', $claim)->set('description', 'Dispute on a foreign claim')->call('openDispute')->assertSet('state', 'VALIDATION_FAILED');
    foreach ($docs as $doc) {
        Livewire::test(DocumentsPage::class)->call('download', $doc->id)->assertSet('state', 'VALIDATION_FAILED')->assertNoFileDownloaded();
    }
    Livewire::test(UsersPage::class)->assertDontSee($nurse->email)->call('revoke', $pu->id)->assertSet('state', 'VALIDATION_FAILED');
    Livewire::test(FacilitiesPage::class)->set('department_facility', $this->main->id)->set('department_code', 'EVIL')->set('department_name', 'Evil')->call('addDepartment')->assertSet('state', 'VALIDATION_FAILED');
    expect(DB::table('provider_departments')->where('code', 'EVIL')->exists())->toBeFalse();
});
