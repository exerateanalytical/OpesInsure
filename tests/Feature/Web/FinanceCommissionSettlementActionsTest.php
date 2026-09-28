<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Bordereaux\Pages\ListBordereaux;
use App\Filament\Admin\Resources\Bordereaux\Pages\ViewBordereau;
use App\Filament\Admin\Resources\CarrierSettlements\Pages\ListCarrierSettlements;
use App\Filament\Admin\Resources\CarrierSettlements\Pages\ViewCarrierSettlement;
use App\Filament\Admin\Resources\CommissionAccruals\Pages\ListCommissionAccruals;
use App\Filament\Admin\Resources\CommissionAccruals\Pages\ViewCommissionAccrual;
use App\Filament\Admin\Resources\CommissionRules\Pages\ListCommissionRules;
use App\Filament\Admin\Resources\CommissionRules\Pages\ViewCommissionRule;
use App\Filament\Admin\Resources\PartnerPayouts\Pages\ViewPartnerPayout;
use App\Filament\Admin\Resources\PartnerStatements\Pages\ListPartnerStatements;
use App\Filament\Admin\Resources\PartnerStatements\Pages\ViewPartnerStatement;
use App\Models\Bordereau;
use App\Models\CommissionRuleVersion;
use App\Models\Partner;
use App\Models\PartnerPayoutRequest;
use App\Models\PartnerStatement;
use App\Models\PartnerStatementItem;
use App\Models\Party;
use App\Models\Role;
use App\Models\SettlementBatch;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** Commission, statement / payout, settlement and bordereau desktop actions (UI coverage batches 9-10): same services, same permissions, maker-checker kept. */
const FCS_PERMISSIONS = [
    'commission.read', 'commission.manage', 'commission.approve', 'commission.accrue', 'commission.vest', 'commission.clawback',
    'statements.read', 'statements.prepare', 'statements.approve', 'statements.publish',
    'commission.statements.adjust', 'commission.statements.adjustments.approve', 'commission.statements.dispute', 'commission.statements.dispute.resolve',
    'payout.request', 'payout.approve', 'payout.process', 'payout.reverse',
    'settlement.read', 'settlement.prepare', 'settlement.approve', 'settlement.submit', 'settlement.confirm', 'settlement.reverse', 'settlement.reconcile',
    'bordereaux.view', 'bordereaux.prepare', 'bordereaux.approve', 'bordereaux.submit', 'bordereaux.confirm',
];

const FCS_READ_ONLY = ['commission.read', 'statements.read', 'settlement.read', 'bordereaux.view'];

function fcsUser(string $tenantId, array $permissions): User
{
    $u = User::create(['full_name' => 'FCS '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'FINANCE_MANAGER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'FCS-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function fcsAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

beforeEach(function () {
    $this->tenantModel = Tenant::create(['type' => 'BROKER', 'legal_name' => 'FCS '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $this->tenant = $this->tenantModel->id;
    $this->user = fcsUser($this->tenant, FCS_PERMISSIONS);
    $this->other = fcsUser($this->tenant, FCS_PERMISSIONS);
    $chain = makeMobileFinanceProposalChain($this->tenantModel);
    $this->carrier = $chain['carrier'];
    $this->policy = makeMobileTestPolicy($chain['proposal'], $this->tenantModel, $this->carrier->id, $chain['party']->id);
    $this->partner = Partner::create(['tenant_id' => $this->tenant, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'FCS Agent', 'status' => 'ACTIVE'])->id,
        'type' => 'AGENT', 'status' => 'ACTIVE', 'compliance' => []]);
    fcsAs($this->user, $this->tenant);
});

it('offers the finance actions with the permissions and hides them without', function () {
    Livewire::test(ListCommissionRules::class)->assertActionVisible('ruleCreate');
    Livewire::test(ListCommissionAccruals::class)->assertActionVisible('accrualAccrue');
    Livewire::test(ListPartnerStatements::class)->assertActionVisible('statementGenerate')->assertActionVisible('statementPrepare');
    Livewire::test(ListCarrierSettlements::class)->assertActionVisible('carrierSettlementPrepare')->assertActionVisible('ledgerSettlementDraft');
    Livewire::test(ListBordereaux::class)->assertActionVisible('bordereauPrepare');
    $accrual = makeMobileTestCommissionAccrual($this->tenantModel, $this->partner, $this->policy, ['status' => 'EARNED']);
    Livewire::test(ViewCommissionAccrual::class, ['record' => $accrual->id])->assertActionVisible('accrualApprove')->assertActionVisible('accrualDispute')
        ->assertActionHidden('accrualMakePayable')->assertActionHidden('accrualResolveDispute');

    $rule = CommissionRuleVersion::create(['tenant_id' => $this->tenant, 'carrier_id' => $this->carrier->id, 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'DRAFT',
        'basis_points' => 1000, 'holdback_basis_points' => 0, 'vesting_days' => 0, 'conditions' => [], 'rule_hash' => str_repeat('b', 64), 'created_by' => $this->other->id]);
    Livewire::test(ViewCommissionRule::class, ['record' => $rule->id])->assertActionVisible('ruleApprove');

    // The rules page itself needs commission.manage; without commission.approve the approval is not offered.
    fcsAs(fcsUser($this->tenant, ['commission.manage']), $this->tenant);
    Livewire::test(ViewCommissionRule::class, ['record' => $rule->id])->assertActionHidden('ruleApprove');

    fcsAs(fcsUser($this->tenant, FCS_READ_ONLY), $this->tenant);
    Livewire::test(ListCommissionAccruals::class)->assertActionHidden('accrualAccrue');
    Livewire::test(ListPartnerStatements::class)->assertActionHidden('statementGenerate')->assertActionHidden('statementPrepare');
    Livewire::test(ListCarrierSettlements::class)->assertActionHidden('carrierSettlementPrepare')->assertActionHidden('ledgerSettlementDraft');
    Livewire::test(ListBordereaux::class)->assertActionHidden('bordereauPrepare');
    Livewire::test(ViewCommissionAccrual::class, ['record' => $accrual->id])->assertActionHidden('accrualApprove')->assertActionHidden('accrualDispute');
});

it('creates a commission rule and refuses its approval by the creator', function () {
    Livewire::test(ListCommissionRules::class)->callAction('ruleCreate', [
        'carrier_id' => $this->carrier->id, 'basis_points' => 1000, 'holdback_basis_points' => 0, 'vesting_days' => 0, 'effective_from' => now()->toDateString(),
    ])->assertHasNoActionErrors()->assertNotified(__('finance_actions.ruleCreate.done'));
    $rule = CommissionRuleVersion::where('tenant_id', $this->tenant)->firstOrFail();
    expect($rule->status)->toBe('DRAFT')->and($rule->created_by)->toBe($this->user->id);

    Livewire::test(ViewCommissionRule::class, ['record' => $rule->id])->callAction('ruleApprove')->assertNotified(__('workflow_actions.failed'));
    expect($rule->refresh()->status)->toBe('DRAFT');
});

it('approves, disputes and resolves a commission, and keeps the re-approval with a different user', function () {
    $a = makeMobileTestCommissionAccrual($this->tenantModel, $this->partner, $this->policy, ['status' => 'EARNED']);

    Livewire::test(ViewCommissionAccrual::class, ['record' => $a->id])->callAction('accrualApprove', ['note' => 'Checked'])->assertNotified(__('finance_actions.accrualApprove.done'));
    expect($a->refresh()->status)->toBe('APPROVED');

    Livewire::test(ViewCommissionAccrual::class, ['record' => $a->id])->callAction('accrualDispute', ['reason' => 'Partner contests the rate'])->assertNotified(__('finance_actions.accrualDispute.done'));
    expect($a->refresh()->status)->toBe('DISPUTED');

    Livewire::test(ViewCommissionAccrual::class, ['record' => $a->id])->callAction('accrualResolveDispute', ['note' => 'Rate confirmed'])->assertNotified(__('finance_actions.accrualResolveDispute.done'));
    expect($a->refresh()->status)->toBe('ADJUSTED');

    // Maker-checker: whoever resolved (adjusted) cannot re-approve.
    Livewire::test(ViewCommissionAccrual::class, ['record' => $a->id])->callAction('accrualApprove', [])->assertNotified(__('workflow_actions.failed'));
    expect($a->refresh()->status)->toBe('ADJUSTED');
});

it('approves and publishes a statement, requests a payout and runs it through approval and processing', function () {
    $s = makeMobileTestPartnerStatement($this->tenantModel, $this->partner, $this->other);

    Livewire::test(ViewPartnerStatement::class, ['record' => $s->id])->callAction('statementApprove')->assertNotified(__('finance_actions.statementApprove.done'));
    expect($s->refresh()->status)->toBe('APPROVED');
    Livewire::test(ViewPartnerStatement::class, ['record' => $s->id])->callAction('statementPublish')->assertNotified(__('finance_actions.statementPublish.done'));
    expect($s->refresh()->status)->toBe('PUBLISHED');

    Livewire::test(ViewPartnerStatement::class, ['record' => $s->id])->callAction('payoutRequest', ['amount_minor' => 5000, 'destination_type' => 'MOBILE_MONEY', 'destination' => '+237670000001'])
        ->assertNotified(__('finance_actions.payoutRequest.done'));
    $payout = PartnerPayoutRequest::where('partner_statement_id', $s->id)->firstOrFail();
    expect($payout->status)->toBe('REQUESTED');

    // Maker-checker: the requester cannot approve the payout.
    Livewire::test(ViewPartnerPayout::class, ['record' => $payout->id])->callAction('payoutApprove')->assertNotified(__('workflow_actions.failed'));
    expect($payout->refresh()->status)->toBe('REQUESTED');

    fcsAs($this->other, $this->tenant);
    Livewire::test(ViewPartnerPayout::class, ['record' => $payout->id])->callAction('payoutApprove')->assertNotified(__('finance_actions.payoutApprove.done'));
    expect($payout->refresh()->status)->toBe('APPROVED');
    Livewire::test(ViewPartnerPayout::class, ['record' => $payout->id])->callAction('payoutProcess', ['provider' => 'MTN_MOMO'])->assertNotified(__('finance_actions.payoutProcess.done'));
    expect($payout->refresh()->status)->toBe('PROCESSING');
    Livewire::test(ViewPartnerPayout::class, ['record' => $payout->id])->callAction('payoutFail', ['failure_code' => 'TIMEOUT'])->assertNotified(__('finance_actions.payoutFail.done'));
    expect($payout->refresh()->status)->toBe('FAILED');
});

it('prepares a draft statement and proposes an adjustment decided by another user', function () {
    Livewire::test(ListPartnerStatements::class)->callAction('statementPrepare', [
        'partner_id' => $this->partner->id, 'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'XAF', 'opening_balance_minor' => 0,
    ])->assertHasNoActionErrors()->assertNotified(__('finance_actions.statementPrepare.done'));
    $s = PartnerStatement::where('tenant_id', $this->tenant)->firstOrFail();
    expect($s->status)->toBe('DRAFT');

    Livewire::test(ViewPartnerStatement::class, ['record' => $s->id])->callAction('adjustmentPropose', ['amount_minor' => 2500, 'reason' => 'Late bonus'])
        ->assertNotified(__('finance_actions.adjustmentPropose.done'));
    $item = PartnerStatementItem::where('partner_statement_id', $s->id)->where('entry_type', 'ADJUSTMENT')->firstOrFail();
    expect($item->adjustment_status)->toBe('PROPOSED');

    fcsAs($this->other, $this->tenant);
    Livewire::test(ViewPartnerStatement::class, ['record' => $s->id])->callAction('adjustmentApprove', ['item_id' => $item->id])->assertNotified(__('finance_actions.adjustmentApprove.done'));
    expect($item->refresh()->adjustment_status)->toBe('APPROVED');
});

it('prepares a policy-basis carrier settlement and runs it to paid with a separate approver', function () {
    Livewire::test(ListCarrierSettlements::class)->callAction('carrierSettlementPrepare', [
        'carrier_id' => $this->carrier->id, 'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'XAF',
    ])->assertHasNoActionErrors()->assertNotified(__('finance_actions.carrierSettlementPrepare.done'));
    $b = SettlementBatch::where('tenant_id', $this->tenant)->firstOrFail();
    expect($b->status)->toBe('DRAFT');

    Livewire::test(ViewCarrierSettlement::class, ['record' => $b->id])->callAction('carrierSettlementApprove', [])->assertNotified(__('workflow_actions.failed'));
    expect($b->refresh()->status)->toBe('DRAFT');

    fcsAs($this->other, $this->tenant);
    Livewire::test(ViewCarrierSettlement::class, ['record' => $b->id])->assertActionHidden('ledgerSettlementCalculate')
        ->callAction('carrierSettlementApprove', [])->assertNotified(__('finance_actions.carrierSettlementApprove.done'));
    expect($b->refresh()->status)->toBe('APPROVED');
    Livewire::test(ViewCarrierSettlement::class, ['record' => $b->id])->callAction('carrierSettlementSubmit')->assertNotified(__('finance_actions.carrierSettlementSubmit.done'));
    expect($b->refresh()->status)->toBe('SUBMITTED');
    Livewire::test(ViewCarrierSettlement::class, ['record' => $b->id])->callAction('carrierSettlementPaid', ['bank_reference' => 'BK-001'])->assertNotified(__('finance_actions.carrierSettlementPaid.done'));
    expect($b->refresh()->status)->toBe('PAID');
});

it('drafts an obligations-basis settlement and cancels it', function () {
    Livewire::test(ListCarrierSettlements::class)->callAction('ledgerSettlementDraft', [
        'carrier_id' => $this->carrier->id, 'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'XAF',
    ])->assertHasNoActionErrors()->assertNotified(__('finance_actions.ledgerSettlementDraft.done'));
    $b = SettlementBatch::where('tenant_id', $this->tenant)->firstOrFail();
    expect($b->status)->toBe('DRAFT')->and($b->calculation_basis)->toBe('OBLIGATIONS');

    Livewire::test(ViewCarrierSettlement::class, ['record' => $b->id])->assertActionHidden('carrierSettlementApprove')
        ->callAction('ledgerSettlementCancel', ['reason' => 'Wrong period'])->assertNotified(__('finance_actions.ledgerSettlementCancel.done'));
    expect($b->refresh()->status)->toBe('CANCELLED');
});

it('prepares a bordereau and takes it through approval, submission and acknowledgement', function () {
    Livewire::test(ListBordereaux::class)->callAction('bordereauPrepare', [
        'carrier_id' => $this->carrier->id, 'type' => 'PREMIUM', 'period_start' => now()->subMonth()->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'XAF',
    ])->assertHasNoActionErrors()->assertNotified(__('finance_actions.bordereauPrepare.done'));
    $b = Bordereau::where('tenant_id', $this->tenant)->firstOrFail();
    expect($b->status)->toBe('DRAFT');

    Livewire::test(ViewBordereau::class, ['record' => $b->id])->callAction('bordereauApprove')->assertNotified(__('workflow_actions.failed'));
    expect($b->refresh()->status)->toBe('DRAFT');

    fcsAs($this->other, $this->tenant);
    Livewire::test(ViewBordereau::class, ['record' => $b->id])->callAction('bordereauApprove')->assertNotified(__('finance_actions.bordereauApprove.done'));
    expect($b->refresh()->status)->toBe('APPROVED');
    Livewire::test(ViewBordereau::class, ['record' => $b->id])->callAction('bordereauSubmit')->assertNotified(__('finance_actions.bordereauSubmit.done'));
    expect($b->refresh()->status)->toBe('SUBMITTED');
    Livewire::test(ViewBordereau::class, ['record' => $b->id])->callAction('bordereauAcknowledge', ['carrier_reference' => 'INS-ACK-9'])->assertNotified(__('finance_actions.bordereauAcknowledge.done'));
    expect($b->refresh()->status)->toBe('ACKNOWLEDGED');
});
