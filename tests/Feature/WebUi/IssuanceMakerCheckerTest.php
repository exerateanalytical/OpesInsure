<?php

declare(strict_types=1);

use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Application\Policies\PaymentIssuanceTrigger;
use App\Application\Shared\CanonicalJson;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\IssuanceActions;
use App\Filament\Shared\Actions\ProposalActions;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

/*
 * UI coverage batch 5 + mobile audit E2: issuance maker-checker (verify / request correction / approve / second approve /
 * reject) through the web actions and the /mobile/carrier/issuance endpoints, POLICY_ISSUE authority (409
 * AUTHORITY_EXCEEDED / SECOND_APPROVAL_REQUIRED), the issuance exception queue actions and the proposal completion actions.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function imcUser(string $tenantId, array $permissions, ?string $carrierId = null, string $role = 'CARRIER_STAFF'): User
{
    $u = User::create(['full_name' => 'IMC '.Str::random(5), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'IMC-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function imcLimit(string $carrierId, User $u, int $max): void
{
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $carrierId, 'holder_type' => 'USER', 'holder_id' => $u->id,
        'authority_type' => 'POLICY_ISSUE', 'max_amount_minor' => $max, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
}

function imcAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function imcHarness(array $actions, ?object $record = null)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

function imcHeaders(string $tenantId): array
{
    return ['X-Tenant-Id' => $tenantId, 'Idempotency-Key' => (string) Str::uuid()];
}

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    $this->carrier = $this->f['carrier']->id;
    $proposal = $this->f['proposal'];
    $proposal->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['currency' => 'XAF', 'total_minor' => 100000]]);
    $payment = makeMobileTestPayment($proposal, $this->f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now(), 'amount_minor' => 100000]);
    $this->requester = imcUser($this->tenant, ['policies.issue.request']);
    $this->request = PolicyIssuanceRequest::create([
        'tenant_id' => $this->tenant, 'proposal_id' => $proposal->id, 'payment_intent_id' => $payment->id, 'carrier_id' => $this->carrier,
        'status' => 'CARRIER_REVIEW', 'authority_snapshot' => ['mode' => 'CARRIER_REVIEW_REQUIRED'], 'terms_hash' => app(CanonicalJson::class)->hash($proposal->refresh()->terms_snapshot),
        'coverage_starts_at' => now(), 'coverage_ends_at' => now()->addYear(), 'requested_by' => $this->requester->id,
    ]);
    app(TenantContext::class)->set($this->tenant);
});

// ------------------------------------------------------------------ mobile endpoints (E2)

it('runs verify, request-correction and approve on /mobile/carrier/issuance with maker-checker', function () {
    $h = imcHeaders($this->tenant);
    $checker = imcUser($this->tenant, ['carrier.issuance.read', 'carrier.referrals.decide'], $this->carrier);
    $id = $this->request->id;

    // The requester can never check their own request.
    Passport::actingAs(imcUser($this->tenant, ['carrier.issuance.read'], $this->carrier));
    $this->postJson("/api/v1/mobile/carrier/issuance/{$id}/verify", [], $h)->assertForbidden();
    $this->request->update(['requested_by' => $checker->id]);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/mobile/carrier/issuance/{$id}/verify", [], imcHeaders($this->tenant))->assertStatus(422);
    $this->request->update(['requested_by' => $this->requester->id]);

    $row = collect($this->getJson('/api/v1/mobile/carrier/issuance', $h)->assertOk()->json('data'))->firstWhere('id', $id);
    expect($row['stage'])->toBe('AWAITING_VERIFICATION')->and($row['capabilities'])->toContain('verify', 'approve', 'request_correction', 'reject')
        ->and($row['approvals'][0]['step'])->toBe('REQUESTED');

    $this->postJson("/api/v1/mobile/carrier/issuance/{$id}/request-correction", ['reason' => 'Chassis number missing'], imcHeaders($this->tenant))
        ->assertOk()->assertJsonPath('data.stage', 'CORRECTION_REQUESTED')->assertJsonPath('data.correction_reason', 'Chassis number missing');
    // A pending correction blocks approval.
    $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$id}/approve", ['carrier_reference' => 'INS-1'], imcHeaders($this->tenant))->assertStatus(422);

    $other = imcUser($this->tenant, ['carrier.issuance.read', 'carrier.referrals.decide'], $this->carrier);
    Passport::actingAs($other);
    $this->postJson("/api/v1/mobile/carrier/issuance/{$id}/verify", ['notes' => 'Chassis added'], imcHeaders($this->tenant))
        ->assertOk()->assertJsonPath('data.stage', 'VERIFIED')->assertJsonPath('data.correction_reason', null);
    $detail = $this->getJson("/api/v1/mobile/carrier/issuance/{$id}", $h)->assertOk()->json('data');
    expect($detail['payment']['verified'])->toBeTrue()->and(collect($detail['approvals'])->pluck('step')->all())->toBe(['REQUESTED', 'VERIFIED'])
        ->and($detail['capabilities'])->toContain('approve')->not->toContain('verify');

    Passport::actingAs($checker);
    $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$id}/approve", ['carrier_reference' => 'INS-1'], imcHeaders($this->tenant))->assertOk()->assertJsonPath('data.status', 'APPROVED');
    expect(Policy::where('issuance_request_id', $id)->exists())->toBeTrue()
        ->and($this->getJson("/api/v1/mobile/carrier/issuance/{$id}", $h)->json('data.stage'))->toBe('ISSUED');
    expect(DB::table('policy_issuance_events')->where('policy_issuance_request_id', $id)->pluck('reason_code')->all())->toContain('CORRECTION_REQUESTED', 'VERIFIED', 'CARRIER_AUTHORIZED');
});

it('answers 409 AUTHORITY_EXCEEDED / SECOND_APPROVAL_REQUIRED under POLICY_ISSUE limits and completes with a different second approver', function () {
    $perms = ['carrier.issuance.read', 'carrier.referrals.decide'];
    $junior = imcUser($this->tenant, $perms, $this->carrier);
    $senior = imcUser($this->tenant, $perms, $this->carrier);
    $nobody = imcUser($this->tenant, $perms, $this->carrier);
    imcLimit($this->carrier, $junior, 50000);
    imcLimit($this->carrier, $senior, 500000);
    $id = $this->request->id;

    Passport::actingAs($nobody);
    $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$id}/approve", [], imcHeaders($this->tenant))->assertStatus(409)->assertJsonPath('code', 'AUTHORITY_EXCEEDED');
    expect($this->request->refresh()->first_approved_by)->toBeNull();

    Passport::actingAs($junior);
    $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$id}/approve", ['carrier_reference' => 'INS-2'], imcHeaders($this->tenant))->assertStatus(409)->assertJsonPath('code', 'SECOND_APPROVAL_REQUIRED');
    expect($this->request->refresh()->first_approved_by)->toBe($junior->id)->and($this->request->status)->toBe('CARRIER_REVIEW');
    // The first approver cannot also give the second approval.
    $this->postJson("/api/v1/mobile/carrier/issuance/{$id}/second-approve", [], imcHeaders($this->tenant))->assertStatus(422);

    Passport::actingAs($nobody);
    expect($this->getJson("/api/v1/mobile/carrier/issuance/{$id}", imcHeaders($this->tenant))->json('data.capabilities'))->toContain('second_approve');
    $this->postJson("/api/v1/mobile/carrier/issuance/{$id}/second-approve", [], imcHeaders($this->tenant))->assertStatus(409)->assertJsonPath('code', 'AUTHORITY_EXCEEDED');

    Passport::actingAs($senior);
    $this->postJson("/api/v1/mobile/carrier/issuance/{$id}/second-approve", [], imcHeaders($this->tenant))->assertOk()
        ->assertJsonPath('data.stage', 'ISSUED')->assertJsonPath('data.approvals.2.step', 'SECOND_APPROVAL');
    $r = $this->request->refresh();
    expect($r->status)->toBe('APPROVED')->and($r->approved_by)->toBe($senior->id)->and(Policy::where('issuance_request_id', $id)->value('issuance_reference'))->toBe('INS-2');
});

it('404s another carrier\'s issuance request on the mobile maker-checker endpoints', function () {
    $foreign = imcUser($this->tenant, ['carrier.issuance.read', 'carrier.referrals.decide'], makeMobileFinanceProposalChain($this->f['tenant'])['carrier']->id);
    Passport::actingAs($foreign);
    $this->getJson("/api/v1/mobile/carrier/issuance/{$this->request->id}", imcHeaders($this->tenant))->assertNotFound();
    $this->postJson("/api/v1/mobile/carrier/issuance/{$this->request->id}/verify", [], imcHeaders($this->tenant))->assertNotFound();
});

// ------------------------------------------------------------------ web actions

it('hides the issuance actions without the deciding permission and from the requester', function () {
    imcAs(imcUser($this->tenant, ['policies.view']), $this->tenant);
    foreach (['verify' => 'issuanceVerify', 'requestCorrection' => 'issuanceRequestCorrection', 'approve' => 'issuanceApprove', 'reject' => 'issuanceReject'] as $m => $name) {
        imcHarness([fn () => IssuanceActions::$m()], $this->request)->assertActionHidden($name);
    }
    $requester = imcUser($this->tenant, ['policies.issue.approve']);
    $this->request->update(['requested_by' => $requester->id]);
    imcAs($requester, $this->tenant);
    imcHarness([fn () => IssuanceActions::approve()], $this->request)->assertActionHidden('issuanceApprove');
});

it('verifies, sends back, re-verifies and issues from the web; second approval through the web too', function () {
    $a = imcUser($this->tenant, ['policies.issue.approve']);
    $b = imcUser($this->tenant, ['policies.issue.approve']);
    imcAs($a, $this->tenant);
    imcHarness([fn () => IssuanceActions::verify()], $this->request)->callAction('issuanceVerify', ['notes' => 'ok'])->assertNotified(__('issuance_maker_checker.actions.issuanceVerify.done'));
    imcHarness([fn () => IssuanceActions::requestCorrection()], $this->request)->callAction('issuanceRequestCorrection', ['reason' => 'Wrong start date'])
        ->assertNotified(__('issuance_maker_checker.actions.issuanceRequestCorrection.done'));
    expect($this->request->refresh()->verified_at)->toBeNull();
    imcHarness([fn () => IssuanceActions::approve()], $this->request)->assertActionHidden('issuanceApprove');

    imcAs($b, $this->tenant);
    imcHarness([fn () => IssuanceActions::verify()], $this->request)->callAction('issuanceVerify', [])->assertNotified();
    imcLimit($this->carrier, $b, 1000);
    imcLimit($this->carrier, $a, 1000000);
    imcHarness([fn () => IssuanceActions::approve()], $this->request)->callAction('issuanceApprove', ['carrier_reference' => 'INS-WEB'])
        ->assertNotified(__('workflow_actions.failed'));
    expect($this->request->refresh()->first_approved_by)->toBe($b->id);

    imcAs($a, $this->tenant);
    imcHarness([fn () => IssuanceActions::secondApprove()], $this->request)->callAction('issuanceSecondApprove', [])
        ->assertNotified(__('issuance_maker_checker.actions.issuanceSecondApprove.done'));
    expect($this->request->refresh()->status)->toBe('APPROVED')->and(Policy::where('issuance_request_id', $this->request->id)->value('issuance_reference'))->toBe('INS-WEB');
});

it('rejects from the web through the carrier workspace for an insurer user', function () {
    imcAs(imcUser($this->tenant, ['carrier.referrals.decide'], $this->carrier), $this->tenant);
    imcHarness([fn () => IssuanceActions::reject()], $this->request)->callAction('issuanceReject', ['reason' => 'Vehicle inspection failed'])
        ->assertNotified(__('issuance_maker_checker.actions.issuanceReject.done'));
    expect($this->request->refresh()->status)->toBe('REJECTED');
});

it('scans, escalates, retries and resolves issuance exceptions from the web', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'EUR']]);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now(), 'requested_by' => $f['user']->id]);
    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($payment);
    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    $t = $f['tenant']->id;

    imcAs(imcUser($t, ['policies.issuance_queue.view']), $t);
    imcHarness([fn () => IssuanceActions::exceptionScan()])->assertActionHidden('exceptionScan');
    imcHarness([fn () => IssuanceActions::exceptionResolve()], $ex)->assertActionHidden('exceptionResolve');

    $ops = imcUser($t, ['policies.issuance_queue.view', 'policies.issuance_queue.manage', 'policies.issuance_queue.resolve'], null, 'OPERATIONS_MANAGER');
    imcAs($ops, $t);
    imcHarness([fn () => IssuanceActions::exceptionScan()])->callAction('exceptionScan', ['grace_minutes' => 0, 'review_hours' => 48])->assertNotified(__('issuance_maker_checker.actions.exceptionScan.done'));
    imcHarness([fn () => IssuanceActions::exceptionEscalate()], $ex)->callAction('exceptionEscalate', ['reason' => 'Carrier desk to check', 'escalated_to' => $ops->id])->assertNotified();
    expect($ex->refresh()->status)->toBe('ESCALATED');
    imcHarness([fn () => IssuanceActions::exceptionRetry()], $ex)->callAction('exceptionRetry')->assertNotified(__('issuance_maker_checker.actions.exceptionRetry.done'));
    expect($ex->refresh()->attempts)->toBe(2);
    imcHarness([fn () => IssuanceActions::exceptionResolve()], $ex)->callAction('exceptionResolve', ['resolution' => 'REFUND_REQUESTED', 'notes' => 'Carrier declined; refund'])
        ->assertNotified(__('issuance_maker_checker.actions.exceptionResolve.done'));
    expect($ex->refresh()->status)->toBe('RESOLVED');
});

it('renders the issuance and exception screens in the admin panel', function () {
    $u = imcUser($this->tenant, ['policies.issuance_queue.view', 'policies.issue.approve', 'carrier.issuance.read'], null, 'POLICY_MANAGER');
    imcAs($u, $this->tenant);
    Livewire::test(App\Filament\Admin\Resources\PolicyIssuances\Pages\ViewPolicyIssuance::class, ['record' => $this->request->id])
        ->assertOk()->assertSee(__('issuance_maker_checker.stages.AWAITING_VERIFICATION'));
    Livewire::test(App\Filament\Admin\Resources\IssuanceExceptions\Pages\ListIssuanceExceptions::class)->assertOk();
});

// ------------------------------------------------------------------ proposal completion actions

it('completes and withdraws a proposal through the assisted-channel actions', function () {
    $proposal = $this->f['proposal'];
    $proposal->update(['status' => 'DRAFT']);
    $agent = imcUser($this->tenant, ['documents.review'], null, 'TENANT_ADMIN');
    imcAs($agent, $this->tenant);

    imcHarness([fn () => ProposalActions::declare()], $proposal)->assertActionVisible('proposalDeclare');
    imcHarness([fn () => ProposalActions::coverTerms()], $proposal)->assertActionVisible('proposalCoverTerms');
    imcHarness([fn () => ProposalActions::disclosureAnswers()], $proposal)->assertActionVisible('proposalDisclosureAnswers');
    imcHarness([fn () => ProposalActions::attachDocument()], $proposal)->assertActionVisible('proposalAttachDocument');
    imcHarness([fn () => ProposalActions::reviewDocument()], $proposal)->assertActionHidden('proposalReviewDocument'); // nothing attached yet

    imcHarness([fn () => ProposalActions::withdraw()], $proposal)->callAction('proposalWithdraw', ['reason' => 'Customer changed their mind'])
        ->assertNotified(__('issuance_maker_checker.actions.proposalWithdraw.done'));
    expect(Proposal::find($proposal->id)->status)->toBe('WITHDRAWN');
    imcHarness([fn () => ProposalActions::withdraw()], Proposal::find($proposal->id))->assertActionHidden('proposalWithdraw');
});

it('maps KeyValue answers back to typed values and cover-term fields to the API shape', function () {
    expect(ProposalActions::typed(['a' => '12', 'b' => 'true', 'c' => 'text', 'd' => '["x"]']))->toBe(['a' => 12, 'b' => true, 'c' => 'text', 'd' => ['x']])
        ->and(ProposalActions::coverTermsInput(['effective_rule' => 'NEXT_DAY', 'duration_unit' => 'MONTH', 'duration_value' => '12', 'instalment_plan' => null]))
        ->toBe(['effective_rule' => 'NEXT_DAY', 'duration' => ['unit' => 'MONTH', 'value' => 12]]);
});
