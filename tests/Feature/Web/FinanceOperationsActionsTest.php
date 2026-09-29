<?php

declare(strict_types=1);

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Finance\ReferenceMasters\FinanceReferenceService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\FinanceOperations;
use App\Filament\Shared\Actions\FinanceOperationsActions;
use App\Filament\Shared\Actions\LedgerOperationsActions;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Finance operations desk (UI coverage batches 14-15): same services, same permissions, maker-checker kept. */
const FOA_ACTIONS = [
    'allocationRulePublish', 'cashierSessionOpen', 'cashierSessionCollect', 'cashierSessionClose', 'cashierSessionDecide', 'fxRateRecord', 'obligationWriteOff',
    'obligationCancel', 'controlAccountPropose', 'controlAccountApprove', 'costCentreCreate', 'costCentreStatus', 'institutionUpdate', 'paymentProviderCreate',
    'paymentProviderUpdate', 'paymentProviderSubmit', 'paymentProviderDecide', 'counterpartyAccountOpen', 'counterpartyAccountApprove', 'counterpartyAccountStatus',
    'agingConfigure', 'subledgerDocumentGenerate', 'remittanceRecord', 'remittanceAllocate', 'remittanceHold', 'actuarialImport', 'actuarialApprove', 'actuarialReject',
    'uprPost', 'journalDraft', 'journalValidate', 'journalApprove', 'journalReject', 'journalPost', 'journalReverse', 'ledgerJournalReverse',
];

function foaUser(string $tenantId, array $permissions): User
{
    $u = User::create(['full_name' => 'FOA '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'FINANCE_MANAGER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'FOA-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function foaAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function foaPage(): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(FinanceOperations::class);
}

beforeEach(function () {
    $this->tenant = Tenant::create(['type' => 'BROKER', 'legal_name' => 'FOA '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []])->id;
    $this->perms = [...FinanceOperationsActions::PERMISSIONS, ...LedgerOperationsActions::PERMISSIONS];
    $this->user = foaUser($this->tenant, $this->perms);
    $this->other = foaUser($this->tenant, $this->perms);
    foaAs($this->user, $this->tenant);
});

it('offers every action with the permissions and hides them without', function () {
    $page = foaPage();
    foreach (FOA_ACTIONS as $a) {
        $page->assertActionVisible($a);
    }

    foaAs(foaUser($this->tenant, ['ledger.adjust']), $this->tenant);
    expect(FinanceOperations::canAccess())->toBeTrue();
    foaPage()->assertActionVisible('journalDraft')->assertActionVisible('journalValidate')
        ->assertActionHidden('journalApprove')->assertActionHidden('journalPost')->assertActionHidden('cashierSessionOpen')->assertActionHidden('paymentProviderDecide');

    foaAs(foaUser($this->tenant, ['finance.accounts.view']), $this->tenant);
    expect(FinanceOperations::canAccess())->toBeFalse();
});

it('runs a cashier session and refuses its review by the cashier', function () {
    $branch = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $branch, 'tenant_id' => $this->tenant, 'code' => 'BR-'.Str::random(5), 'name' => 'Douala', 'created_at' => now(), 'updated_at' => now()]);

    foaPage()->callAction('cashierSessionOpen', ['branch_id' => $branch, 'opening_float_minor' => 10000, 'currency' => 'XAF'])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.cashierSessionOpen.done'));
    $s = DB::table('cashier_sessions')->where('tenant_id', $this->tenant)->first();
    expect($s->status)->toBe('OPEN');

    foaPage()->callAction('cashierSessionCollect', ['session_id' => $s->id, 'method' => 'CASH', 'amount_minor' => 5000, 'payer_name' => 'Jean Mbarga'])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.cashierSessionCollect.done'));
    expect(DB::table('cashier_collections')->where('cashier_session_id', $s->id)->sum('amount_minor'))->toEqual(5000);

    foaPage()->callAction('cashierSessionClose', ['session_id' => $s->id, 'counted_cash_minor' => 15000])->assertNotified(__('finance_ops_actions.cashierSessionClose.done'));
    expect(DB::table('cashier_sessions')->find($s->id)->status)->toBe('CLOSED');

    // Maker-checker: the cashier cannot approve their own session.
    foaPage()->callAction('cashierSessionDecide', ['session_id' => $s->id, 'decision' => 'APPROVE'])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('cashier_sessions')->find($s->id)->status)->toBe('CLOSED');

    foaAs($this->other, $this->tenant);
    foaPage()->callAction('cashierSessionDecide', ['session_id' => $s->id, 'decision' => 'APPROVE'])->assertNotified(__('finance_ops_actions.cashierSessionDecide.done'));
    expect(DB::table('cashier_sessions')->find($s->id)->status)->toBe('APPROVED');
});

it('publishes the allocation rule, records an FX rate, sets aging and writes off and cancels obligations', function () {
    foaPage()->callAction('allocationRulePublish', ['strategy' => 'PRIORITY_FIRST', 'priority' => ['TAX', 'PREMIUM'], 'reason' => 'Taxes first per board decision'])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.allocationRulePublish.done'));
    expect(DB::table('allocation_rule_versions')->where('tenant_id', $this->tenant)->count())->toBe(1);

    foaPage()->callAction('fxRateRecord', ['base_currency' => 'USD', 'quote_currency' => 'XAF', 'rate' => '605.5', 'source' => 'BANK', 'effective_at' => now()->subHour()->toDateTimeString()])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.fxRateRecord.done'));
    expect(DB::table('fx_rates')->where('tenant_id', $this->tenant)->where('base_currency', 'USD')->exists())->toBeTrue();

    foaPage()->callAction('agingConfigure', ['scope' => 'RECEIVABLE', 'basis' => 'TRANSACTION_DATE'])->assertNotified(__('finance_ops_actions.agingConfigure.done'));
    expect(DB::table('finance_aging_settings')->where('tenant_id', $this->tenant)->where('scope', 'RECEIVABLE')->value('basis'))->toBe('TRANSACTION_DATE');

    $mk = fn () => app(ObligationService::class)->create(['tenant_id' => $this->tenant, 'kind' => 'RECEIVABLE', 'type' => 'PREMIUM', 'source_type' => 'test', 'source_id' => (string) Str::uuid(),
        'currency' => 'XAF', 'amount_minor' => 25000, 'due_at' => now()->addDays(10)]);
    $a = $mk();
    $b = $mk();
    foaPage()->callAction('obligationWriteOff', ['obligation_id' => $a->id, 'reason' => 'Debtor insolvent'])->assertNotified(__('finance_ops_actions.obligationWriteOff.done'));
    foaPage()->callAction('obligationCancel', ['obligation_id' => $b->id, 'reason' => 'Issued in error'])->assertNotified(__('finance_ops_actions.obligationCancel.done'));
    expect(DB::table('financial_obligations')->find($a->id)->status)->toBe('WRITTEN_OFF')
        ->and(DB::table('financial_obligations')->find($b->id)->status)->toBe('CANCELLED');
});

it('maintains GL reference masters with four-eyes approval', function () {
    foaPage()->callAction('costCentreCreate', ['code' => 'dla-ops', 'name' => 'Douala operations'])->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.costCentreCreate.done'));
    $cc = DB::table('cost_centres')->where('tenant_id', $this->tenant)->first();
    expect($cc->code)->toBe('DLA-OPS');
    foaPage()->callAction('costCentreStatus', ['cost_centre_id' => $cc->id, 'status' => 'INACTIVE', 'reason' => 'Branch merged'])->assertNotified(__('finance_ops_actions.costCentreStatus.done'));
    expect(DB::table('cost_centres')->find($cc->id)->status)->toBe('INACTIVE');

    foaPage()->callAction('controlAccountPropose', ['control_code' => 'CUSTOMER_RECEIVABLES', 'ledger_account_code' => '411100', 'reason' => 'Broker book uses the intermediary account'])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.controlAccountPropose.done'));
    $m = DB::table('gl_control_account_mappings')->where('tenant_id', $this->tenant)->first();
    foaPage()->callAction('controlAccountApprove', ['mapping_id' => $m->id])->assertNotified(__('workflow_actions.failed'));
    foaAs($this->other, $this->tenant);
    foaPage()->callAction('controlAccountApprove', ['mapping_id' => $m->id])->assertNotified(__('finance_ops_actions.controlAccountApprove.done'));
    expect(DB::table('gl_control_account_mappings')->find($m->id)->status)->toBe('APPROVED');

    $fi = app(FinanceReferenceService::class)->importInstitution(['legal_name' => 'Banque Test Cameroun', 'institution_type' => 'BANK']);
    foaPage()->callAction('institutionUpdate', ['institution_id' => $fi, 'trade_name' => 'BTC', 'head_office_city' => 'Yaoundé', 'reason' => 'Annual register refresh'])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.institutionUpdate.done'));
    expect(DB::table('financial_institutions')->find($fi))->trade_name->toBe('BTC')->head_office_city->toBe('Yaoundé')->legal_name->toBe('Banque Test Cameroun');
});

it('configures a payment provider profile through submit and a second-user approval', function () {
    foaPage()->callAction('paymentProviderCreate', ['provider_type' => 'CASH', 'environment' => 'SANDBOX'])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.paymentProviderCreate.done'));
    $p = DB::table('payment_provider_profiles')->where('tenant_id', $this->tenant)->first();
    // Incomplete: submission is refused by the service.
    foaPage()->callAction('paymentProviderSubmit', ['profile_id' => $p->id])->assertNotified(__('workflow_actions.failed'));

    foaPage()->callAction('paymentProviderUpdate', ['profile_id' => $p->id, 'collection_account' => 'CAISSE-01', 'effective_from' => now()->toDateString()])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.paymentProviderUpdate.done'));
    foaPage()->callAction('paymentProviderSubmit', ['profile_id' => $p->id])->assertNotified(__('finance_ops_actions.paymentProviderSubmit.done'));
    expect(DB::table('payment_provider_profiles')->find($p->id)->status)->toBe('PENDING_APPROVAL');

    foaPage()->callAction('paymentProviderDecide', ['profile_id' => $p->id, 'decision' => 'APPROVE', 'reason' => 'Checked'])->assertNotified(__('workflow_actions.failed'));
    foaAs($this->other, $this->tenant);
    foaPage()->callAction('paymentProviderDecide', ['profile_id' => $p->id, 'decision' => 'APPROVE', 'reason' => 'Checked'])->assertNotified(__('finance_ops_actions.paymentProviderDecide.done'));
    expect(DB::table('payment_provider_profiles')->find($p->id)->status)->toBe('ACTIVE');
});

it('opens, approves and suspends a counterparty account with maker-checker', function () {
    foaPage()->callAction('counterpartyAccountOpen', ['relationship_type' => 'CUSTOMER', 'account_type' => 'PREMIUM_RECEIVABLE', 'counterparty_id' => (string) Str::uuid(), 'currency' => 'XAF'])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.counterpartyAccountOpen.done'));
    $a = DB::table('finance_counterparty_accounts')->where('tenant_id', $this->tenant)->first();
    expect($a->status)->toBe('PENDING_APPROVAL');
    foaPage()->callAction('counterpartyAccountApprove', ['account_id' => $a->id])->assertNotified(__('workflow_actions.failed'));

    foaAs($this->other, $this->tenant);
    foaPage()->callAction('counterpartyAccountApprove', ['account_id' => $a->id])->assertNotified(__('finance_ops_actions.counterpartyAccountApprove.done'));
    foaPage()->callAction('counterpartyAccountStatus', ['account_id' => $a->id, 'status' => 'SUSPENDED', 'reason' => 'Customer dispute'])->assertNotified(__('finance_ops_actions.counterpartyAccountStatus.done'));
    expect(DB::table('finance_counterparty_accounts')->find($a->id)->status)->toBe('SUSPENDED');
});

it('imports actuarial values and keeps approval with another user', function () {
    $values = [['metric' => 'IBNR_TOTAL', 'amount_minor' => 1500000, 'currency' => 'XAF']];
    foaPage()->callAction('actuarialImport', ['kind' => 'IBNR', 'period_end' => '2026-06-30', 'source' => 'Actuary Q2 report', 'values' => $values])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.actuarialImport.done'));
    foaPage()->callAction('actuarialImport', ['kind' => 'IBNR', 'period_end' => '2026-06-30', 'source' => 'Actuary Q2 report v2', 'values' => $values])
        ->assertNotified(__('finance_ops_actions.actuarialImport.done'));
    [$first, $second] = DB::table('technical_actuarial_imports')->where('tenant_id', $this->tenant)->orderBy('version')->get()->all();

    foaPage()->callAction('actuarialApprove', ['import_id' => $first->id])->assertNotified(__('workflow_actions.failed'));
    foaAs($this->other, $this->tenant);
    foaPage()->callAction('actuarialReject', ['import_id' => $first->id, 'reason' => 'Superseded by v2'])->assertNotified(__('finance_ops_actions.actuarialReject.done'));
    foaPage()->callAction('actuarialApprove', ['import_id' => $second->id])->assertNotified(__('finance_ops_actions.actuarialApprove.done'));
    expect(DB::table('technical_actuarial_imports')->find($first->id)->status)->toBe('REJECTED')
        ->and(DB::table('technical_actuarial_imports')->find($second->id)->status)->toBe('APPROVED');
});

it('walks a manual journal through draft, validation, four-eyes approval, posting and reversal', function () {
    $acc = function (string $code) {
        $id = (string) Str::uuid();
        DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'code' => $code, 'name' => 'Acc '.$code, 'type' => 'ASSET', 'currency' => 'XAF', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $dr = $acc('1000');
    $cr = $acc('2000');

    foaPage()->callAction('journalDraft', ['reference_type' => 'MANUAL_ADJUSTMENT', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'reason_code' => 'CORRECTION',
        'lines' => [['account_id' => $dr, 'debit_minor' => 5000, 'credit_minor' => 0], ['account_id' => $cr, 'debit_minor' => 0, 'credit_minor' => 5000]]])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.journalDraft.done'));
    $j = DB::table('journals')->where('tenant_id', $this->tenant)->where('journal_type', 'MANUAL')->first();
    expect($j->status)->toBe('DRAFT');

    foaPage()->callAction('journalValidate', ['journal_id' => $j->id])->assertNotified(__('finance_ops_actions.journalValidate.done'));
    foaPage()->callAction('journalApprove', ['journal_id' => $j->id])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('journals')->find($j->id)->status)->toBe('VALIDATED');

    foaAs($this->other, $this->tenant);
    foaPage()->callAction('journalReject', ['journal_id' => $j->id, 'reason_code' => 'WRONG_ACCOUNT'])->assertNotified(__('finance_ops_actions.journalReject.done'));
    expect(DB::table('journals')->find($j->id)->status)->toBe('DRAFT');

    foaAs($this->user, $this->tenant);
    foaPage()->callAction('journalValidate', ['journal_id' => $j->id])->assertNotified(__('finance_ops_actions.journalValidate.done'));
    foaAs($this->other, $this->tenant);
    foaPage()->callAction('journalApprove', ['journal_id' => $j->id])->assertNotified(__('finance_ops_actions.journalApprove.done'));
    foaPage()->callAction('journalPost', ['journal_id' => $j->id])->assertNotified(__('finance_ops_actions.journalPost.done'));
    expect(DB::table('journals')->find($j->id)->status)->toBe('POSTED');

    foaPage()->callAction('journalReverse', ['journal_id' => $j->id, 'reason_code' => 'ERROR', 'notes' => 'Posted to the wrong account by mistake.'])
        ->assertHasNoActionErrors()->assertNotified(__('finance_ops_actions.journalReverse.done'));
    expect(DB::table('journals')->find($j->id)->status)->toBe('REVERSED')
        ->and(DB::table('journals')->where('reverses_journal_id', $j->id)->exists())->toBeTrue();
});
