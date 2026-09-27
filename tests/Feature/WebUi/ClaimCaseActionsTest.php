<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\RepairNetworkRegister;
use App\Filament\Shared\Actions\ClaimCaseActions;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\ClaimInvolvedParty;
use App\Models\ClaimPayment;
use App\Models\ClaimRecovery;
use App\Models\Policy;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

/*
 * UI coverage batches 1 and 2 (claims): every ClaimCaseActions / RepairNetworkActions action is hidden without the
 * API route's permission, and works through the same service with it (similar actions grouped per test).
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function ccUser(string $tenantId, array $permissions): User
{
    $u = User::create(['full_name' => 'CC '.Str::random(5), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'CLAIMS_OFFICER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'CC-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function ccAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function ccHarness(array $actions, ?object $record = null)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

function ccClaim(array $f, Policy $policy, string $status = 'ASSESSMENT'): Claim
{
    return Claim::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-CC-'.Str::random(6),
        'status' => $status, 'loss_occurred_at' => now()->subDays(2), 'loss_details' => [], 'currency' => 'XAF']);
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    $this->policy = Policy::create([
        'tenant_id' => $this->tenant, 'proposal_id' => $this->f['proposal']->id, 'carrier_id' => $this->f['carrier']->id, 'party_id' => $this->f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subMonth(),
    ]);
    app(TenantContext::class)->set($this->tenant);
});

/** [factory, action name, permission] for every claim-record action. */
function ccRecordActions(): array
{
    return [
        ['coverageCheck', 'claims.coverage.check'], ['coverageResolve', 'claims.coverage.resolve'], ['lateReportRecommend', 'claims.late_report.recommend'],
        ['lateReportDecide', 'claims.late_report.approve'], ['appeal', 'claims.decision.appeal'], ['largeLossCheck', 'claims.view'], ['transition', 'claims.transition'],
        ['investigationOpen', 'claims.investigation.manage'], ['investigationFindings', 'claims.investigation.manage'], ['investigationIndicators', 'claims.investigation.manage'],
        ['investigationConclude', 'claims.investigation.conclude'], ['partyAdd', 'claims.parties.manage'], ['partyUpdate', 'claims.parties.manage'], ['partyRemove', 'claims.parties.manage'],
        ['expertAssign', 'claims.experts.assign'], ['expertCancel', 'claims.experts.assign'], ['expertReview', 'claims.experts.review'],
        ['evidenceAttach', 'claims.evidence.manage'], ['evidenceVerify', 'claims.evidence.verify'], ['evidenceReview', 'claims.evidence.verify'],
        ['carrierQueue', 'claims.carrier.exchange'], ['carrierCallback', 'claims.carrier.callback'], ['carrierManualEntry', 'claims.carrier.manual_entry'],
        ['carrierReviewEntry', 'claims.carrier.manual_approve'], ['executionSubmit', 'claims.carrier.exchange'],
        ['settlementDischarge', 'claims.settlement.discharge'], ['settlementConfirmDischarge', 'claims.settlement.discharge'], ['settlementPayment', 'claims.settlement.pay'],
        ['paymentExecution', 'claims.payment.execute'], ['recoveryTransfer', 'claims.close'],
    ];
}

it('hides every claim case action from a user without the API permission', function () {
    ccAs(ccUser($this->tenant, ['claims.view.none']), $this->tenant);
    $claim = ccClaim($this->f, $this->policy);
    foreach (ccRecordActions() as [$name]) {
        ccHarness([fn () => ClaimCaseActions::$name()], $claim)->assertActionHidden($name);
    }
    foreach (['policyCoverageCheck', 'agentRegister', 'brokerRegister'] as $name) {
        ccHarness([fn () => ClaimCaseActions::$name()])->assertActionHidden($name);
    }
});

it('shows an action once its permission is held (state permitting)', function () {
    $claim = ccClaim($this->f, $this->policy);
    foreach (['coverageCheck', 'largeLossCheck', 'transition', 'investigationOpen', 'partyAdd', 'expertAssign', 'carrierQueue', 'carrierManualEntry', 'executionSubmit'] as $name) {
        $perm = collect(ccRecordActions())->firstWhere(0, $name)[1];
        ccAs(ccUser($this->tenant, [$perm]), $this->tenant);
        ccHarness([fn () => ClaimCaseActions::$name()], $claim)->assertActionVisible($name);
    }
    foreach (['policyCoverageCheck' => 'claims.coverage.check', 'agentRegister' => 'agent.clients.manage', 'brokerRegister' => 'broker.claims.file'] as $name => $perm) {
        ccAs(ccUser($this->tenant, [$perm]), $this->tenant);
        ccHarness([fn () => ClaimCaseActions::$name()])->assertActionVisible($name);
    }
});

it('adds, updates and removes an involved party through ClaimPartyService', function () {
    ccAs(ccUser($this->tenant, ['claims.parties.manage']), $this->tenant);
    $claim = ccClaim($this->f, $this->policy);
    ccHarness([fn () => ClaimCaseActions::partyAdd()], $claim)->callAction('partyAdd', ['role' => 'WITNESS', 'display_name' => 'Ada Witness', 'party_type' => 'INDIVIDUAL', 'contact_phone' => '+237670000001', 'consent_basis' => 'LEGITIMATE_INTEREST'])
        ->assertNotified(__('claim_actions.partyAdd.done'));
    $row = ClaimInvolvedParty::where(['claim_id' => $claim->id, 'role' => 'WITNESS'])->firstOrFail();
    ccHarness([fn () => ClaimCaseActions::partyUpdate()], $claim)->callAction('partyUpdate', ['party_row_id' => $row->id, 'display_name' => 'Ada B. Witness', 'reason' => 'Name corrected'])
        ->assertNotified(__('claim_actions.partyUpdate.done'));
    expect($row->refresh()->display_name)->toBe('Ada B. Witness');
    ccHarness([fn () => ClaimCaseActions::partyRemove()], $claim)->callAction('partyRemove', ['party_row_id' => $row->id, 'reason' => 'Added by mistake'])
        ->assertNotified(__('claim_actions.partyRemove.done'));
    expect($row->refresh()->removed_at)->not->toBeNull();
});

it('opens an investigation, records findings and indicators, and concludes it through ClaimInvestigationService', function () {
    ccAs(ccUser($this->tenant, ['claims.investigation.manage', 'claims.investigation.conclude']), $this->tenant);
    $claim = ccClaim($this->f, $this->policy);
    ccHarness([fn () => ClaimCaseActions::investigationOpen()], $claim)->callAction('investigationOpen', ['reason_code' => 'RED_FLAG', 'reason' => 'Loss reported days after cover started'])
        ->assertNotified(__('claim_actions.investigationOpen.done'));
    $inv = DB::table('claim_investigations')->where('claim_id', $claim->id)->first();
    expect($inv->status)->toBe('OPEN');
    ccHarness([fn () => ClaimCaseActions::investigationFindings()], $claim)->callAction('investigationFindings', ['investigation_id' => $inv->id, 'findings' => 'Witness statements are consistent.'])
        ->assertNotified(__('claim_actions.investigationFindings.done'));
    $ind = (string) Str::uuid();
    DB::table('fraud_indicators')->insert(['id' => $ind, 'code' => 'FI-CC-'.Str::random(4), 'category' => 'TIMING', 'severity' => 'MEDIUM', 'outcome' => 'REVIEW', 'is_determination' => false, 'data_status' => 'VERIFIED', 'pack_status' => 'ACTIVE', 'source' => 'TEST']);
    ccHarness([fn () => ClaimCaseActions::investigationIndicators()], $claim)->callAction('investigationIndicators', ['investigation_id' => $inv->id, 'indicator_ids' => [$ind]])
        ->assertNotified(__('claim_actions.investigationIndicators.done'));
    expect(DB::table('claim_investigation_indicators')->where('investigation_id', $inv->id)->count())->toBe(1);
    ccHarness([fn () => ClaimCaseActions::investigationConclude()], $claim)->callAction('investigationConclude', ['investigation_id' => $inv->id, 'outcome' => 'NO_FRAUD_FOUND', 'summary' => 'Nothing suspicious found.'])
        ->assertNotified(__('claim_actions.investigationConclude.done'));
    expect(DB::table('claim_investigations')->where('id', $inv->id)->value('status'))->not->toBe('OPEN');
});

it('queues a carrier message and records its acknowledgement through ClaimCarrierExchangeService', function () {
    ccAs(ccUser($this->tenant, ['claims.carrier.exchange', 'claims.carrier.callback']), $this->tenant);
    $claim = ccClaim($this->f, $this->policy);
    ccHarness([fn () => ClaimCaseActions::carrierQueue()], $claim)->callAction('carrierQueue', ['message_type' => 'CLAIM_SUBMISSION', 'payload' => ['note' => 'first notice']])
        ->assertNotified(__('claim_actions.carrierQueue.done'));
    $msg = DB::table('carrier_exchange_messages')->where('claim_id', $claim->id)->first();
    expect($msg->status)->toBe('QUEUED');
    ccHarness([fn () => ClaimCaseActions::carrierCallback()], $claim)->callAction('carrierCallback', ['message_id' => $msg->id, 'outcome' => 'ACKNOWLEDGED', 'external_reference' => 'CAR-123'])
        ->assertNotified(__('claim_actions.carrierCallback.done'));
    expect(DB::table('carrier_exchange_messages')->where('id', $msg->id)->value('status'))->toBe('ACKNOWLEDGED')
        ->and($claim->refresh()->carrier_reference)->toBe('CAR-123');
});

it('runs the large loss check through LargeLossNotifier', function () {
    ccAs(ccUser($this->tenant, ['claims.view', 'claims.coverage.check', 'claims.coverage.resolve']), $this->tenant);
    $claim = ccClaim($this->f, $this->policy);
    ccHarness([fn () => ClaimCaseActions::largeLossCheck()], $claim)->callAction('largeLossCheck')->assertNotified(__('claim_actions.largeLossCheck.done'));
});

it('runs the claim coverage check and resolves a check needing review', function () {
    ccAs(ccUser($this->tenant, ['claims.coverage.check', 'claims.coverage.resolve']), $this->tenant);
    $claim = ccClaim($this->f, $this->policy);
    ccHarness([fn () => ClaimCaseActions::coverageCheck()], $claim)->callAction('coverageCheck', ['coverage_code' => null, 'facts' => []]);
    expect(DB::table('claim_coverage_checks')->where('claim_id', $claim->id)->count())->toBe(1);
    DB::table('claim_coverage_checks')->where('claim_id', $claim->id)->update(['outcome' => 'REVIEW_REQUIRED', 'resolution' => null]);
    $check = DB::table('claim_coverage_checks')->where('claim_id', $claim->id)->first();
    ccAs(ccUser($this->tenant, ['claims.coverage.resolve']), $this->tenant); // the resolver must differ from the user who ran the check
    ccHarness([fn () => ClaimCaseActions::coverageResolve()], $claim)->callAction('coverageResolve', ['check_id' => $check->id, 'resolution' => 'COVERED', 'note' => 'Cover confirmed on file.'])
        ->assertNotified(__('claim_actions.coverageResolve.done'));
    expect(DB::table('claim_coverage_checks')->where('id', $check->id)->value('resolution'))->toBe('COVERED');
});

it('moves an approved payment to processing then failed, and transfers an open recovery', function () {
    ccAs(ccUser($this->tenant, ['claims.payment.execute', 'claims.close']), $this->tenant);
    $claim = ccClaim($this->f, $this->policy, 'APPROVED');
    $maker = ccUser($this->tenant, []);
    $decision = ClaimDecision::create(['claim_id' => $claim->id, 'decision' => 'APPROVE', 'approved_amount_minor' => 50000, 'currency' => 'XAF', 'reason_code' => 'COVERED_IN_FULL',
        'rationale' => 'Covered in full after assessment.', 'status' => 'APPROVED', 'proposed_by' => $maker->id, 'approved_by' => ccUser($this->tenant, [])->id, 'approved_at' => now()]);
    $payment = ClaimPayment::forceCreate(['id' => (string) Str::uuid(), 'claim_id' => $claim->id, 'claim_decision_id' => $decision->id, 'payee_party_id' => $this->f['party']->id, 'amount_minor' => 50000, 'currency' => 'XAF',
        'status' => 'APPROVED', 'idempotency_key' => (string) Str::uuid(), 'attempt_count' => 0, 'requested_by' => $maker->id]);
    ccHarness([fn () => ClaimCaseActions::paymentExecution()], $claim)->callAction('paymentExecution', ['payment_id' => $payment->id, 'operation' => 'PROCESSING'])
        ->assertNotified(__('claim_actions.paymentExecution.done'));
    expect($payment->refresh()->status)->toBe('PROCESSING');
    ccHarness([fn () => ClaimCaseActions::paymentExecution()], $claim)->callAction('paymentExecution', ['payment_id' => $payment->id, 'operation' => 'FAILED', 'reason' => 'Bank rejected the transfer'])
        ->assertNotified(__('claim_actions.paymentExecution.done'));
    expect($payment->refresh()->status)->not->toBe('PROCESSING');

    $rec = ClaimRecovery::create(['claim_id' => $claim->id, 'type' => 'SUBROGATION', 'status' => 'OPEN', 'counterparty_name' => 'Third party insurer', 'target_amount_minor' => 10000,
        'currency' => 'XAF', 'reference' => 'RCV-'.Str::upper(Str::random(8)), 'opened_by' => auth()->id()]);
    ccHarness([fn () => ClaimCaseActions::recoveryTransfer()], $claim)->callAction('recoveryTransfer', ['recovery_id' => $rec->id, 'transferee' => 'Collections agency'])
        ->assertNotified(__('claim_actions.recoveryTransfer.done'));
    expect($rec->refresh()->status)->toBe('TRANSFERRED');
});

it('recommends and then decides a late report with two different users (maker-checker)', function () {
    $claim = ccClaim($this->f, $this->policy);
    DB::table('claim_reporting_checks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'claim_id' => $claim->id, 'loss_occurred_at' => now()->subDays(40),
        'reported_at' => now(), 'deadline_days' => 5, 'deadline_at' => now()->subDays(35), 'days_late' => 35, 'late' => true, 'approval_status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
    ccAs(ccUser($this->tenant, ['claims.late_report.recommend']), $this->tenant);
    ccHarness([fn () => ClaimCaseActions::lateReportRecommend()], $claim)->callAction('lateReportRecommend', ['recommendation' => 'ACCEPT', 'rationale' => 'Claimant was hospitalised.'])
        ->assertNotified(__('claim_actions.lateReportRecommend.done'));
    ccAs(ccUser($this->tenant, ['claims.late_report.approve']), $this->tenant);
    ccHarness([fn () => ClaimCaseActions::lateReportDecide()], $claim)->callAction('lateReportDecide', ['decision' => 'APPROVE', 'rationale' => 'Accepted on medical evidence.'])
        ->assertNotified(__('claim_actions.lateReportDecide.done'));
    expect(DB::table('claim_reporting_checks')->where('claim_id', $claim->id)->value('approval_status'))->not->toBe('RECOMMENDED');
});

it('refuses a stage-invalid transition with a visible notification instead of failing silently', function () {
    ccAs(ccUser($this->tenant, ['claims.transition']), $this->tenant);
    $claim = ccClaim($this->f, $this->policy, 'CLOSED');
    ccHarness([fn () => ClaimCaseActions::transition()], $claim)->callAction('transition', ['to_status' => 'PAYMENT_PENDING', 'reason_code' => 'TEST'])
        ->assertNotified(__('workflow_actions.failed'));
    expect($claim->refresh()->status)->toBe('CLOSED');
});

it('hides the repair network register actions without permission and verifies a pending provider with it', function () {
    $maker = ccUser($this->tenant, []);
    $id = (string) Str::uuid();
    $partner = (string) Str::uuid();
    $garage = \App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Garage CC', 'status' => 'ACTIVE']);
    DB::table('partners')->insert(['id' => $partner, 'tenant_id' => null, 'party_id' => $garage->id, 'type' => 'GARAGE', 'status' => 'ACTIVE', 'compliance' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('provider_profiles')->insert(['id' => $id, 'partner_id' => $partner, 'category' => 'GARAGE', 'provider_type_code' => 'GARAGE', 'party_id' => $garage->id, 'credentialing_status' => 'ACTIVE', 'data_status' => 'PENDING_VERIFICATION',
        'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
    ccAs(ccUser($this->tenant, ['providers.manage']), $this->tenant);
    Livewire::test(RepairNetworkRegister::class)->assertTableActionHidden('repairVerifySource', $id)->assertTableActionVisible('repairCapabilities', $id);
    ccAs(ccUser($this->tenant, ['providers.credential']), $this->tenant);
    Livewire::test(RepairNetworkRegister::class)->assertTableActionHidden('repairCapabilities', $id)
        ->callTableAction('repairVerifySource', $id, ['source_reference' => 'Official register 2026'])->assertNotified(__('claim_actions.repairVerifySource.done'));
    expect(DB::table('provider_profiles')->where('id', $id)->value('data_status'))->not->toBe('PENDING_VERIFICATION');
});

it('renders the claim case read tabs (investigations, experts, parties, coverage, evidence, settlements, carrier, litigation) on the claim page', function () {
    $claim = ccClaim($this->f, $this->policy);
    $handler = ccUser($this->tenant, ['claims.investigation.manage']);
    app(\App\Application\Claims\Assessment\ClaimInvestigationService::class)->open($claim, 'RED_FLAG', 'Suspicious repair invoice pattern', $handler);
    ccAs(ccUser($this->tenant, ['claims.view']), $this->tenant);
    $page = $this->get(\App\Filament\Admin\Resources\Claims\ClaimResource::getUrl('view', ['record' => $claim]))->assertOk();
    foreach (['investigations', 'experts', 'parties', 'coverage', 'evidence', 'settlements', 'carrier', 'litigation'] as $tab) {
        $page->assertSee(__('claim_actions.tabs.'.$tab));
    }
    $page->assertSee('Suspicious repair invoice pattern');
});
