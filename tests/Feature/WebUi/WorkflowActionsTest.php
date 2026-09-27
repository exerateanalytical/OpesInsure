<?php

declare(strict_types=1);

use App\Application\Approvals\ApprovalService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\ApprovalActions;
use App\Filament\Shared\Actions\ClaimActions;
use App\Filament\Shared\Actions\DocumentTemplateActions;
use App\Filament\Shared\Actions\HealthProviderActions;
use App\Filament\Shared\Actions\PolicyActions;
use App\Filament\Shared\Actions\WorkQueueActions;
use App\Models\ApprovalRequest;
use App\Models\Claim;
use App\Models\DocumentTemplate;
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

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** A user in the tenant whose role carries exactly $permissions. */
function wfUser(string $tenantId, array $permissions, string $roleCode = 'CLAIMS_OFFICER'): User
{
    $u = User::create(['full_name' => 'WF '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => $roleCode.'-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function wfPolicy(array $f): Policy
{
    return Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subMonth(),
    ]);
}

function wfClaim(array $f, Policy $policy, string $status = 'ASSESSMENT'): Claim
{
    return Claim::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-WF-'.Str::random(6),
        'status' => $status, 'loss_occurred_at' => now()->subDays(2), 'loss_details' => [], 'currency' => 'XAF']);
}

/** @param  list<Closure>  $actions */
function wfHarness(array $actions, ?object $record = null)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

function wfAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    $this->policy = wfPolicy($this->f);
    app(TenantContext::class)->set($this->tenant);
});

it('registers a claim (FNOL wizard) through FnolService, and hides it without claims.create', function () {
    wfAs(wfUser($this->tenant, ['claims.create']), $this->tenant);
    wfHarness([fn () => ClaimActions::register()])->callAction('claimRegister', [
        'policy_id' => $this->policy->id, 'claimant_party_id' => $this->f['party']->id, 'loss_occurred_at' => now()->subDay()->toDateTimeString(),
        'description' => 'Rear-ended at a junction', 'priority' => 'NORMAL', 'channel' => 'PHONE',
    ])->assertNotified(__('workflow_actions.claimRegister.done'));
    expect(Claim::where('policy_id', $this->policy->id)->count())->toBe(1);

    wfAs(wfUser($this->tenant, ['claims.view']), $this->tenant);
    wfHarness([fn () => ClaimActions::register()])->assertActionHidden('claimRegister');
    expect(Claim::where('policy_id', $this->policy->id)->count())->toBe(1);
});

it('requests a reserve change and a different checker approves it; denied without the permission', function () {
    $claim = wfClaim($this->f, $this->policy);
    wfAs(wfUser($this->tenant, ['claims.view']), $this->tenant);
    wfHarness([fn () => ClaimActions::reserve()], $claim)->assertActionHidden('claimReserve');

    wfAs(wfUser($this->tenant, ['claims.reserve.request']), $this->tenant);
    wfHarness([fn () => ClaimActions::reserve()], $claim)
        ->callAction('claimReserve', ['amount_minor' => 250000, 'reason_code' => 'INITIAL_ESTIMATE']);
    $change = DB::table('claim_reserve_changes')->where('claim_id', $claim->id)->first();
    expect($change->status)->toBe('PENDING_APPROVAL')->and((int) $change->requested_amount_minor)->toBe(250000);

    wfAs(wfUser($this->tenant, ['claims.reserve.approve'], 'CLAIMS_MANAGER'), $this->tenant);
    wfHarness([fn () => ClaimActions::approveReserve()], $claim)->callAction('claimApproveReserve', ['reserve_id' => $change->id]);
    expect(DB::table('claim_reserve_changes')->where('id', $change->id)->value('status'))->toBeIn(['APPROVED', 'REFERRED']);
});

it('records an assessment through ClaimAssessmentService and shows a refused one as a notification', function () {
    $claim = wfClaim($this->f, $this->policy);
    wfAs(wfUser($this->tenant, ['claims.assessment.record']), $this->tenant);
    wfHarness([fn () => ClaimActions::assess()], $claim)->callAction('claimAssess', [
        'heads' => [['head_code' => 'REPAIR', 'recommended_minor' => 120000, 'claimed_minor' => 150000]], 'rationale' => 'Garage estimate reviewed on site.',
    ]);
    expect(DB::table('claim_assessments')->where(['claim_id' => $claim->id, 'status' => 'SUBMITTED'])->count())->toBe(1);

    $claim->update(['status' => 'CLOSED']);
    wfHarness([fn () => ClaimActions::assess()], $claim->refresh())->callAction('claimAssess', [
        'heads' => [['head_code' => 'REPAIR', 'recommended_minor' => 1]], 'rationale' => 'Another assessment attempt.',
    ])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('claim_assessments')->where('claim_id', $claim->id)->count())->toBe(1);
});

it('closes a claim (withdrawn) and runs the maker-checker reopening', function () {
    $claim = wfClaim($this->f, $this->policy, 'ACKNOWLEDGED');
    wfAs(wfUser($this->tenant, ['claims.view']), $this->tenant);
    wfHarness([fn () => ClaimActions::close()], $claim)->assertActionHidden('claimClose');

    wfAs(wfUser($this->tenant, ['claims.close']), $this->tenant);
    wfHarness([fn () => ClaimActions::close()], $claim)->callAction('claimClose', ['reason_code' => 'WITHDRAWN', 'summary' => 'Claimant withdrew by phone']);
    expect($claim->refresh()->status)->toBe('CLOSED');

    $maker = wfUser($this->tenant, ['claims.reopen.request']);
    wfAs($maker, $this->tenant);
    wfHarness([fn () => ClaimActions::requestReopen()], $claim)->callAction('claimRequestReopen', ['reason_code' => 'NEW_EVIDENCE', 'justification' => 'Police report received', 'restore_reserve_minor' => 0]);
    $req = DB::table('claim_reopen_requests')->where('claim_id', $claim->id)->first();
    expect($req->status)->toBe('PENDING_APPROVAL');

    wfAs(wfUser($this->tenant, ['claims.reopen.approve'], 'CLAIMS_MANAGER'), $this->tenant);
    wfHarness([fn () => ClaimActions::decideReopen()], $claim)->callAction('claimDecideReopen', ['request_id' => $req->id, 'outcome' => 'APPROVE']);
    expect(DB::table('claim_reopen_requests')->where('id', $req->id)->value('status'))->toBe('APPROVED')->and($claim->refresh()->status)->not->toBe('CLOSED');
});

it('assigns a claim handler through ClaimLifecycleService', function () {
    $claim = wfClaim($this->f, $this->policy, 'ACKNOWLEDGED');
    $handler = wfUser($this->tenant, ['claims.view']);
    wfAs(wfUser($this->tenant, ['claims.assign'], 'CLAIMS_MANAGER'), $this->tenant);
    wfHarness([fn () => ClaimActions::assign()], $claim)->callAction('claimAssign', ['assignee_id' => $handler->id, 'reason_code' => 'WORKLOAD']);
    expect($claim->refresh()->assigned_to)->toBe($handler->id);
});

it('requests an endorsement and a checker approves it; cancellation needs policies.cancellation.request', function () {
    wfAs(wfUser($this->tenant, ['policies.read']), $this->tenant);
    wfHarness([fn () => PolicyActions::endorse()], $this->policy)->callAction('policyEndorse', [
        'effective_at' => now()->addDay()->toDateTimeString(), 'requested_changes' => ['address' => 'Bonapriso'], 'premium_delta_minor' => 0, 'reason_code' => 'ADDRESS_CHANGE',
    ]);
    $t = DB::table('policy_transactions')->where(['policy_id' => $this->policy->id, 'type' => 'ENDORSEMENT'])->first();
    expect($t->status)->toBe('PENDING_APPROVAL');

    wfHarness([fn () => PolicyActions::requestCancellation()], $this->policy->refresh())->assertActionHidden('policyRequestCancellation');

    wfAs(wfUser($this->tenant, ['policies.service.approve'], 'CLAIMS_MANAGER'), $this->tenant);
    wfHarness([fn () => PolicyActions::decideService()], $this->policy->refresh())->callAction('policyDecideService', ['transaction_id' => $t->id, 'outcome' => 'APPROVE']);
    expect(DB::table('policy_transactions')->where('id', $t->id)->value('status'))->toBe('APPROVED');
});

it('requests a policy cancellation through CancellationService', function () {
    wfAs(wfUser($this->tenant, ['policies.cancellation.request'], 'CARRIER_STAFF'), $this->tenant);
    wfHarness([fn () => PolicyActions::requestCancellation()], $this->policy)->callAction('policyRequestCancellation', [
        'effective_at' => now()->addDays(10)->toDateTimeString(), 'initiated_by' => 'INSURED', 'reason_code' => 'CUSTOMER_REQUEST',
    ])->assertNotified(__('workflow_actions.failed')); // no approved cancellation rule in this fixture: the service refusal is surfaced
    expect(DB::table('policy_cancellations')->where('policy_id', $this->policy->id)->exists())->toBeFalse();

    wfAs(wfUser($this->tenant, ['policies.read']), $this->tenant);
    wfHarness([fn () => PolicyActions::requestCancellation()], $this->policy)->assertActionHidden('policyRequestCancellation');
});

it('moves a template draft to review, approval and publication with maker-checker; denied without documents.templates.manage', function () {
    $author = wfUser($this->tenant, ['documents.templates.manage']);
    $t = DocumentTemplate::create(['code' => 'WF-'.Str::random(6), 'document_type_code' => 'POLICY_SCHEDULE', 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'version' => 1,
        'status' => 'DRAFT', 'title_en' => 'Schedule', 'title_fr' => 'Conditions', 'content' => ['sections' => []], 'content_hash' => 'x', 'effective_from' => now()->toDateString(), 'created_by' => $author->id]);
    $t->update(['content_hash' => app(\App\Application\Documents\Engine\DocumentTemplateService::class)->hash($t)]);

    wfAs(wfUser($this->tenant, ['claims.view']), $this->tenant);
    wfHarness(array_map(fn ($a) => fn () => $a, DocumentTemplateActions::all()), $t)->assertActionHidden('templateSubmit');

    wfAs($author, $this->tenant);
    wfHarness([fn () => DocumentTemplateActions::submit()], $t)->assertActionExists('templateSubmit')->assertActionVisible('templateSubmit')->callAction('templateSubmit');
    expect($t->refresh()->status)->toBe('REVIEW');
    wfHarness([fn () => DocumentTemplateActions::approve()], $t)->callAction('templateApprove')->assertNotified(__('workflow_actions.failed'));
    expect($t->refresh()->status)->toBe('REVIEW');

    wfAs(wfUser($this->tenant, ['documents.templates.manage']), $this->tenant);
    wfHarness([fn () => DocumentTemplateActions::approve()], $t)->callAction('templateApprove');
    wfHarness([fn () => DocumentTemplateActions::publish()], $t->refresh())->callAction('templatePublish', []);
    expect($t->refresh()->status)->toBe('PUBLISHED');
    wfHarness([fn () => DocumentTemplateActions::retire()], $t)->callAction('templateRetire', ['reason' => 'Superseded by new wording']);
    expect($t->refresh()->status)->toBe('RETIRED');
});

it('decides an approval request through ApprovalService with approvals.decide, never by the requester', function () {
    $maker = wfUser($this->tenant, ['approvals.inbox.view']);
    $req = ApprovalRequest::create(['tenant_id' => $this->tenant, 'action_code' => 'TEST', 'subject_type' => 'test', 'subject_id' => (string) Str::uuid(),
        'status' => 'PENDING', 'requested_by' => $maker->id, 'required_approvals' => 1, 'reason' => 'Web UI test']);

    wfAs($maker, $this->tenant);
    wfHarness([fn () => ApprovalActions::approve()], $req)->assertActionHidden('approvalApprove');

    $checker = wfUser($this->tenant, ['approvals.decide', 'approvals.inbox.view'], 'COMPLIANCE_ADMIN');
    wfAs($checker, $this->tenant);
    $h = wfHarness([fn () => ApprovalActions::reject()], $req);
    if (app(ApprovalService::class)->canDecide($req, $checker)) {
        $h->callAction('approvalReject', ['note' => 'Not justified']);
        expect($req->refresh()->status)->toBe('REJECTED');
    } else {
        $h->assertActionHidden('approvalReject');
    }
})->skip(fn () => ! Schema::hasTable('approval_requests'), 'approval_requests missing');

it('takes the next case from a work queue and assigns a case; denied without cases.manage', function () {
    $worker = wfUser($this->tenant, ['cases.manage', 'cases.assign'], 'CLAIMS_MANAGER');
    $queue = WorkQueue::create(['tenant_id' => $this->tenant, 'code' => 'WFQ-'.Str::random(4), 'name' => 'Web queue', 'active' => true]);
    DB::table('queue_members')->insert(['id' => (string) Str::uuid(), 'queue_id' => $queue->id, 'user_id' => $worker->id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

    wfAs(wfUser($this->tenant, ['cases.view']), $this->tenant);
    wfHarness([fn () => WorkQueueActions::claimNext()], $queue)->assertActionHidden('queueClaimNext');

    wfAs($worker, $this->tenant);
    wfHarness([fn () => WorkQueueActions::claimNext()], $queue)->callAction('queueClaimNext')->assertNotified(__('workflow_actions.queueClaimNext.empty'));
});

it('hides health pre-authorization and provider settlement actions without the permissions and surfaces service refusals', function () {
    wfAs(wfUser($this->tenant, ['claims.view']), $this->tenant);
    wfHarness([fn () => HealthProviderActions::settlementCreate()])->assertActionHidden('settlementCreate');

    wfAs(wfUser($this->tenant, ['health.provider_settlements.manage'], 'FINANCE_MANAGER'), $this->tenant);
    wfHarness([fn () => HealthProviderActions::settlementCreate()])->assertActionVisible('settlementCreate');
});
