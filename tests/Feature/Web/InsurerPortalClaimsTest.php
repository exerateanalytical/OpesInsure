<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Claims\Pages\ListClaims;
use App\Filament\Admin\Resources\Claims\Pages\ViewClaim;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\ClaimPayment;
use App\Models\Policy;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Owner decision 2026-09-29 (D4 lifted): an insurer claims handler works its own carrier's claims in /insurer with the
 * shared claim actions (ClaimActions / ClaimCaseActions). Every page and action is gated by the permission the matching
 * API route uses, and every record is narrowed to the caller's carrier (PortalScope). No role-name shortcut: the users
 * below hold a governed role carrying exactly the listed permissions.
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function ipcUser(string $tenantId, array $permissions, ?string $carrierId, string $roleCode = 'CARRIER_STAFF'): User
{
    $u = User::create(['full_name' => 'IPC '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => $roleCode.'-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function ipcPolicy(string $tenantId, array $chain): Policy
{
    return Policy::create([
        'tenant_id' => $tenantId, 'proposal_id' => $chain['proposal']->id, 'carrier_id' => $chain['carrier']->id, 'party_id' => $chain['party']->id,
        'policy_number' => 'POL-IPC-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subMonth(),
    ]);
}

function ipcClaim(string $tenantId, Policy $policy, string $status = 'ASSESSMENT'): Claim
{
    return Claim::create(['tenant_id' => $tenantId, 'policy_id' => $policy->id, 'claimant_party_id' => $policy->party_id, 'claim_number' => 'CLM-IPC-'.Str::upper(Str::random(6)),
        'status' => $status, 'loss_occurred_at' => now()->subDays(2), 'loss_details' => [], 'currency' => 'XAF']);
}

function ipcAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
}

function ipcAuthority(string $carrierId, User $u, int $limit): void
{
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $carrierId, 'holder_type' => 'USER', 'holder_id' => $u->id,
        'authority_type' => 'CLAIM_SETTLE', 'max_amount_minor' => $limit, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
}

const IPC_HANDLER = ['claims.view', 'claims.create', 'claims.assessment.record', 'claims.reserve.request', 'claims.decision.propose', 'claims.decision.approve', 'claims.payment.request', 'claims.payment.approve'];
const IPC_CHECKER = ['claims.view', 'claims.assessment.review', 'claims.reserve.approve', 'claims.decision.approve', 'claims.payment.approve'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    $this->carrier = $this->f['carrier']->id;
    $this->policy = ipcPolicy($this->tenant, $this->f);
    $this->otherChain = makeMobileFinanceProposalChain($this->f['tenant']);
    $this->otherPolicy = ipcPolicy($this->tenant, $this->otherChain);
});

it('lets an insurer claims handler complete a claim journey in /insurer on its own carrier claim, with maker-checker', function () {
    $maker = ipcUser($this->tenant, IPC_HANDLER, $this->carrier);
    $checker = ipcUser($this->tenant, IPC_CHECKER, $this->carrier, 'CLAIMS_MANAGER');
    ipcAuthority($this->carrier, $maker, 500000);
    ipcAuthority($this->carrier, $checker, 500000);

    // Register (FNOL) from the claims list: only the caller's carrier policies are offered and accepted.
    ipcAs($maker, $this->tenant);
    $list = Livewire::test(ListClaims::class)->assertActionVisible('claimRegister');
    $list->callAction('claimRegister', [
        'policy_id' => $this->otherPolicy->id, 'claimant_party_id' => $this->otherPolicy->party_id, 'loss_occurred_at' => now()->subDay()->toDateTimeString(),
        'description' => 'Foreign carrier policy', 'priority' => 'NORMAL', 'channel' => 'BACK_OFFICE',
    ]);
    expect(Claim::where('policy_id', $this->otherPolicy->id)->count())->toBe(0);
    Livewire::test(ListClaims::class)->callAction('claimRegister', [
        'policy_id' => $this->policy->id, 'claimant_party_id' => $this->policy->party_id, 'loss_occurred_at' => now()->subDay()->toDateTimeString(),
        'description' => 'Rear-end collision at a junction', 'estimated_loss_minor' => 300000, 'priority' => 'NORMAL', 'channel' => 'BACK_OFFICE',
    ])->assertNotified(__('workflow_actions.claimRegister.done'));
    $claim = Claim::where('policy_id', $this->policy->id)->sole();

    // Assess, then reserve (maker), approved by the checker.
    $claim->update(['status' => 'ASSESSMENT']);
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimAssess', [
        'heads' => [['head_code' => 'REPAIR', 'recommended_minor' => 250000, 'claimed_minor' => 300000]], 'rationale' => 'Garage estimate reviewed on site.',
    ])->assertNotified(__('workflow_actions.claimAssess.done'));
    expect(DB::table('claim_assessments')->where(['claim_id' => $claim->id, 'status' => 'SUBMITTED'])->count())->toBe(1);

    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimReserve', ['amount_minor' => 250000, 'reason_code' => 'INITIAL_ESTIMATE']);
    $reserve = DB::table('claim_reserve_changes')->where('claim_id', $claim->id)->first();
    expect($reserve)->not->toBeNull();
    if ($reserve->status === 'PENDING_APPROVAL') {
        ipcAs($checker, $this->tenant);
        Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimApproveReserve', ['reserve_id' => $reserve->id]);
        expect(DB::table('claim_reserve_changes')->where('id', $reserve->id)->value('status'))->toBeIn(['APPROVED', 'REFERRED']);
    }

    // Decide (maker proposes; the maker may not approve its own proposal; the checker approves).
    $claim->refresh()->update(['status' => 'CARRIER_REVIEW']);
    ipcAs($maker, $this->tenant);
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimDecide', [
        'decision' => 'PARTIAL', 'reason_codes' => ['DEDUCTIBLE_APPLIED'], 'heads' => [['head' => 'REPAIR', 'amount_minor' => 200000]],
        'rationale' => 'Assessed against the adjuster report and policy wording.',
    ])->assertNotified(__('workflow_actions.claimDecide.done'));
    $decision = ClaimDecision::where('claim_id', $claim->id)->sole();
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimApproveDecision', ['decision_id' => $decision->id, 'outcome' => 'APPROVE'])
        ->assertNotified(__('workflow_actions.failed'));
    expect($decision->refresh()->status)->toBe('PENDING_APPROVAL');

    ipcAs($checker, $this->tenant);
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimApproveDecision', ['decision_id' => $decision->id, 'outcome' => 'APPROVE'])
        ->assertNotified(__('workflow_actions.claimApproveDecision.done'));
    expect($decision->refresh()->status)->toBe('APPROVED');

    // Payment request (maker), which the maker cannot approve itself; the checker approves.
    ipcAs($maker, $this->tenant);
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimRequestPayment', ['decision_id' => $decision->id, 'amount_minor' => 200000])
        ->assertNotified(__('workflow_actions.claimRequestPayment.done'));
    $payment = ClaimPayment::where('claim_id', $claim->id)->sole();
    expect($payment->status)->toBe('PENDING_APPROVAL');
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimApprovePayment', ['payment_id' => $payment->id])->assertNotified(__('workflow_actions.failed'));
    expect($payment->refresh()->status)->toBe('PENDING_APPROVAL');

    ipcAs($checker, $this->tenant);
    Livewire::test(ViewClaim::class, ['record' => $claim->id])->callAction('claimApprovePayment', ['payment_id' => $payment->id])
        ->assertNotified(__('workflow_actions.claimApprovePayment.done'));
    expect($payment->refresh()->status)->toBe('APPROVED');
});

it('refuses another insurer claim: absent from the list, 404 on the detail page', function () {
    $mine = ipcClaim($this->tenant, $this->policy);
    $foreign = ipcClaim($this->tenant, $this->otherPolicy);
    $handler = ipcUser($this->tenant, IPC_HANDLER, $this->carrier);

    $this->actingAs($handler)->get('/insurer/claims')->assertOk()->assertSee($mine->claim_number)->assertDontSee($foreign->claim_number);
    $this->actingAs($handler)->get('/insurer/claims/'.$mine->id)->assertOk();
    $this->actingAs($handler)->get('/insurer/claims/'.$foreign->id)->assertNotFound();

    ipcAs($handler, $this->tenant);
    expect(fn () => Livewire::test(ViewClaim::class, ['record' => $foreign->id]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('lets a carrier-linked CLAIMS_OFFICER enter /insurer and see only its carrier claims', function () {
    $mine = ipcClaim($this->tenant, $this->policy);
    $foreign = ipcClaim($this->tenant, $this->otherPolicy);
    $officer = ipcUser($this->tenant, ['claims.view', 'claims.assessment.record'], $this->carrier, 'CLAIMS_OFFICER');

    $this->actingAs($officer)->get('/insurer/claims')->assertOk()->assertSee($mine->claim_number)->assertDontSee($foreign->claim_number);
});

it('shows no claims to an insurer user without claims permissions: no nav item, 403 on the pages', function () {
    $claim = ipcClaim($this->tenant, $this->policy);
    $user = ipcUser($this->tenant, ['carrier.dashboard.read', 'policies.read'], $this->carrier);

    $this->actingAs($user)->get('/insurer')->assertOk()->assertDontSee('/insurer/claims', false);
    $this->actingAs($user)->get('/insurer/claims')->assertForbidden();
    $this->actingAs($user)->get('/insurer/claims/'.$claim->id)->assertForbidden();
});

it('gives a read-only claims user the records but no write action', function () {
    $claim = ipcClaim($this->tenant, $this->policy, 'CARRIER_REVIEW');
    $reader = ipcUser($this->tenant, ['carrier.claims.read'], $this->carrier);

    $this->actingAs($reader)->get('/insurer/claims')->assertOk()->assertSee($claim->claim_number);
    $this->actingAs($reader)->get('/insurer/claims/'.$claim->id)->assertOk();

    ipcAs($reader, $this->tenant);
    Livewire::test(ListClaims::class)->assertActionHidden('claimRegister');
    $page = Livewire::test(ViewClaim::class, ['record' => $claim->id]);
    foreach (['claimAssign', 'claimAssess', 'claimReserve', 'claimDecide', 'claimClose', 'claimOpenRecovery'] as $name) {
        $page->assertActionHidden($name);
    }
    expect(\App\Filament\Shared\Actions\WorkflowAction::allowed('claims.decision.propose', $claim))->toBeFalse();
});
