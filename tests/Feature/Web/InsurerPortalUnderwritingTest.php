<?php

declare(strict_types=1);

use App\Application\Shared\CanonicalJson;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\InsuranceProducts\InsuranceProductResource;
use App\Filament\Admin\Resources\InsuranceProducts\Pages\ListInsuranceProducts;
use App\Filament\Admin\Resources\Policies\Pages\ViewPolicy;
use App\Filament\Admin\Resources\PolicyIssuances\Pages\ViewPolicyIssuance;
use App\Filament\Admin\Resources\UnderwritingCases\Pages\ViewUnderwritingCase;
use App\Filament\Admin\Resources\UnderwritingCases\UnderwritingCaseResource;
use App\Filament\Shared\Actions\IssuanceActions;
use App\Filament\Shared\Actions\PolicyActions;
use App\Filament\Shared\Actions\UnderwritingCaseActions;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\UnderwritingCase;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

/**
 * Owner decision 2026-09-29 (D4 lifted) + owner rule "insurer staff see exactly what their RBAC permissions allow":
 * an insurer underwriter decides a case, issues a policy and processes an endorsement in /insurer on its own carrier's
 * records, with the shared actions (UnderwritingCaseActions, IssuanceActions, PolicyActions). Another insurer's records
 * are refused; four-eyes (maker-checker) stays enforced by the services; read-only permissions see but cannot act.
 * No role-name shortcut: each user holds a custom role carrying exactly the listed permissions.
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function ipuUser(string $tenantId, array $permissions, ?string $carrierId, string $roleCode = 'UNDERWRITER'): User
{
    $u = User::create(['full_name' => 'IPU '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'IPU-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function ipuAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
}

function ipuHarness(array $actions, object $record)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, ['model' => $record::class, 'recordId' => $record->getKey()]);
}

function ipuPolicy(string $tenantId, array $chain): Policy
{
    return Policy::create([
        'tenant_id' => $tenantId, 'proposal_id' => $chain['proposal']->id, 'carrier_id' => $chain['carrier']->id, 'party_id' => $chain['party']->id,
        'policy_number' => 'POL-IPU-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subMonth(),
    ]);
}

const IPU_UNDERWRITER = ['carrier.referrals.read', 'underwriting.decide', 'policies.read'];
const IPU_READER = ['carrier.referrals.read', 'carrier.issuance.read', 'policies.read', 'catalogue.view'];

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    $this->carrier = $this->f['carrier']->id;
    $this->other = makeMobileFinanceProposalChain($this->f['tenant']); // same tenant, another insurer
});

it('lets an insurer underwriter decide its own carrier case in /insurer, refuses another insurer case and read-only users', function () {
    $this->f['proposal']->update(['status' => 'UNDER_REVIEW']);
    $this->other['proposal']->update(['status' => 'UNDER_REVIEW']);
    $mine = UnderwritingCase::create(['tenant_id' => $this->tenant, 'proposal_id' => $this->f['proposal']->id, 'carrier_id' => $this->carrier, 'status' => 'IN_REVIEW']);
    $foreign = UnderwritingCase::create(['tenant_id' => $this->tenant, 'proposal_id' => $this->other['proposal']->id, 'carrier_id' => $this->other['carrier']->id, 'status' => 'IN_REVIEW']);

    $uw = ipuUser($this->tenant, IPU_UNDERWRITER, $this->carrier);
    $reader = ipuUser($this->tenant, IPU_READER, $this->carrier, 'CARRIER_STAFF');

    // Pages: own case opens, another insurer's case is not found.
    $this->actingAs($uw)->get(UnderwritingCaseResource::getUrl('view', ['record' => $mine->id], panel: 'insurer'))->assertOk();
    $this->actingAs($uw)->get(UnderwritingCaseResource::getUrl('view', ['record' => $foreign->id], panel: 'insurer'))->assertNotFound();

    // Read-only (carrier.referrals.read, no underwriting.decide): sees the case, cannot decide.
    ipuAs($reader, $this->tenant);
    Livewire::test(ViewUnderwritingCase::class, ['record' => $mine->id])->assertOk()->assertActionHidden('uwDecide');

    // Another insurer's case: every action refused (permission held, record not own).
    ipuAs($uw, $this->tenant);
    ipuHarness([fn () => UnderwritingCaseActions::decide()], $foreign)->assertActionHidden('uwDecide');

    // Own case: decided in /insurer through UnderwritingService.
    Livewire::test(ViewUnderwritingCase::class, ['record' => $mine->id])->assertActionVisible('uwDecide')
        ->callAction('uwDecide', ['decision' => 'APPROVED', 'reason_code' => 'STANDARD_RISK', 'notes' => 'Clean driving record, standard terms apply.'])
        ->assertNotified(__('insurer_portal_uw.uwDecide.done'));
    expect($mine->refresh()->status)->toBe('DECIDED')->and($mine->outcome)->toBe('APPROVED')
        ->and($foreign->refresh()->status)->toBe('IN_REVIEW');
});

it('issues a policy in /insurer with maker-checker on its own carrier request only', function () {
    $proposal = $this->f['proposal'];
    $proposal->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['currency' => 'XAF', 'total_minor' => 100000]]);
    $payment = makeMobileTestPayment($proposal, $this->f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now(), 'amount_minor' => 100000]);
    $perms = ['carrier.issuance.read', 'carrier.referrals.decide'];
    $maker = ipuUser($this->tenant, $perms, $this->carrier, 'CARRIER_STAFF');
    $checker = ipuUser($this->tenant, $perms, $this->carrier, 'CARRIER_STAFF');
    $request = PolicyIssuanceRequest::create([
        'tenant_id' => $this->tenant, 'proposal_id' => $proposal->id, 'payment_intent_id' => $payment->id, 'carrier_id' => $this->carrier,
        'status' => 'CARRIER_REVIEW', 'authority_snapshot' => ['mode' => 'CARRIER_REVIEW_REQUIRED'], 'terms_hash' => app(CanonicalJson::class)->hash($proposal->refresh()->terms_snapshot),
        'coverage_starts_at' => now(), 'coverage_ends_at' => now()->addYear(), 'requested_by' => $maker->id,
    ]);

    // Four-eyes: the requester cannot approve its own request.
    ipuAs($maker, $this->tenant);
    Livewire::test(ViewPolicyIssuance::class, ['record' => $request->id])->assertOk()->assertActionHidden('issuanceApprove');

    // Read-only: sees the request, no decision.
    ipuAs(ipuUser($this->tenant, IPU_READER, $this->carrier, 'CARRIER_STAFF'), $this->tenant);
    Livewire::test(ViewPolicyIssuance::class, ['record' => $request->id])->assertOk()->assertActionHidden('issuanceApprove');

    // Another insurer's decider: refused.
    ipuAs(ipuUser($this->tenant, $perms, $this->other['carrier']->id, 'CARRIER_STAFF'), $this->tenant);
    ipuHarness([fn () => IssuanceActions::approve()], $request)->assertActionHidden('issuanceApprove');

    // A second person of the same insurer approves: the policy is issued.
    ipuAs($checker, $this->tenant);
    Livewire::test(ViewPolicyIssuance::class, ['record' => $request->id])->callAction('issuanceApprove', ['carrier_reference' => 'INS-IPU-1'])
        ->assertNotified(__('issuance_maker_checker.actions.issuanceApprove.done'));
    expect($request->refresh()->status)->toBe('APPROVED')->and(Policy::where('issuance_request_id', $request->id)->exists())->toBeTrue();
});

it('processes an endorsement in /insurer with maker-checker on its own carrier policy only', function () {
    $policy = ipuPolicy($this->tenant, $this->f);
    $foreign = ipuPolicy($this->tenant, $this->other);
    $maker = ipuUser($this->tenant, ['policies.read', 'policies.service.approve'], $this->carrier, 'CARRIER_STAFF');
    $checker = ipuUser($this->tenant, ['policies.read', 'policies.service.approve'], $this->carrier, 'CARRIER_ADMIN');

    ipuAs($maker, $this->tenant);
    ipuHarness([fn () => PolicyActions::endorse()], $foreign)->assertActionHidden('policyEndorse');
    Livewire::test(ViewPolicy::class, ['record' => $policy->id])->callAction('policyEndorse', [
        'effective_at' => now()->addDay()->toDateTimeString(), 'requested_changes' => ['address' => 'Bonapriso'], 'premium_delta_minor' => 0, 'reason_code' => 'ADDRESS_CHANGE',
    ]);
    $t = DB::table('policy_transactions')->where(['policy_id' => $policy->id, 'type' => 'ENDORSEMENT'])->sole();
    expect($t->status)->toBe('PENDING_APPROVAL');

    // The maker cannot approve its own endorsement (service maker-checker).
    Livewire::test(ViewPolicy::class, ['record' => $policy->id])->callAction('policyDecideService', ['transaction_id' => $t->id, 'outcome' => 'APPROVE'])
        ->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('policy_transactions')->where('id', $t->id)->value('status'))->toBe('PENDING_APPROVAL');

    // A read-only user sees the policy but not the decision.
    ipuAs(ipuUser($this->tenant, IPU_READER, $this->carrier, 'CARRIER_STAFF'), $this->tenant);
    Livewire::test(ViewPolicy::class, ['record' => $policy->id])->assertOk()->assertActionHidden('policyDecideService');

    ipuAs($checker, $this->tenant);
    Livewire::test(ViewPolicy::class, ['record' => $policy->id])->callAction('policyDecideService', ['transaction_id' => $t->id, 'outcome' => 'APPROVE']);
    expect(DB::table('policy_transactions')->where('id', $t->id)->value('status'))->toBe('APPROVED');
});

it('shows the insurer only its own products, gated by the catalogue permission', function () {
    $reader = ipuUser($this->tenant, IPU_READER, $this->carrier, 'CARRIER_STAFF');
    $noCatalogue = ipuUser($this->tenant, ['policies.read'], $this->carrier, 'CARRIER_STAFF');
    $foreignProduct = App\Models\InsuranceProduct::where('carrier_id', $this->other['carrier']->id)->firstOrFail();

    $this->actingAs($reader)->get(InsuranceProductResource::getUrl('index', panel: 'insurer'))->assertOk()
        ->assertSee($this->f['product']->name);
    $this->actingAs($reader)->get(InsuranceProductResource::getUrl('view', ['record' => $this->f['product']->id], panel: 'insurer'))->assertOk();
    $this->actingAs($reader)->get(InsuranceProductResource::getUrl('view', ['record' => $foreignProduct->id], panel: 'insurer'))->assertNotFound();
    ipuAs($noCatalogue, $this->tenant);
    expect(InsuranceProductResource::canViewAny())->toBeFalse();

    // Read-only: no generic create / edit forms and no builder actions in the portal.
    ipuAs($reader, $this->tenant);
    Livewire::test(ListInsuranceProducts::class)->assertOk()->assertCanSeeTableRecords([$this->f['product']])->assertCanNotSeeTableRecords([$foreignProduct])
        ->assertActionHidden('catCreateProduct');
    Livewire::test(App\Filament\Admin\Resources\InsuranceProducts\Pages\ViewInsuranceProduct::class, ['record' => $this->f['product']->id])->assertOk()->assertActionHidden('runTests');

    // catalogue.test (held by insurer admins): the sandbox actions show on the own carrier's product.
    ipuAs(ipuUser($this->tenant, ['catalogue.view', 'catalogue.test'], $this->carrier, 'CARRIER_ADMIN'), $this->tenant);
    Livewire::test(App\Filament\Admin\Resources\InsuranceProducts\Pages\ViewInsuranceProduct::class, ['record' => $this->f['product']->id])->assertActionVisible('runTests');
});
