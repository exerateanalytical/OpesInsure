<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Finance\Allocations\AllocationRuleService;
use App\Application\Finance\Cashier\CashierSessionService;
use App\Application\Finance\Fx\FxRateService;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Finance\ReferenceMasters\FinanceReferenceCatalogue;
use App\Application\Finance\ReferenceMasters\FinanceReferenceService;
use App\Application\Finance\Subledger\AgingService;
use App\Application\Finance\Subledger\CounterpartyAccountService;
use App\Application\Finance\Subledger\StatementDocumentService;
use App\Application\Finance\Subledger\SubledgerCatalogue;
use App\Application\Finance\Subledger\SubledgerQuery;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Finance operations (UI coverage batch 14). Each action calls the SAME service method as the API route, with the SAME
 * permission and the controller's validation; the record is picked from the caller's tenant (same scoping as the API).
 * Maker-checker stays in the services (cashier ≠ supervisor, proposer ≠ approver, opener ≠ approver, maker ≠ checker).
 *   allocationRulePublish     POST finance/allocation-rule                               finance.allocation_rules.manage     AllocationRuleService::publish
 *   cashierSessionOpen        POST finance/cashier-sessions                              cashier.sessions.operate            CashierSessionService::open
 *   cashierSessionCollect     POST finance/cashier-sessions/{s}/collections              cashier.sessions.operate            CashierSessionService::collect
 *   cashierSessionClose       POST finance/cashier-sessions/{s}/close                    cashier.sessions.operate            CashierSessionService::close
 *   cashierSessionDecide      POST finance/cashier-sessions/{s}/decide                   cashier.sessions.approve            CashierSessionService::decide
 *   fxRateRecord              POST finance/fx-rates                                      fx.rates.manage                     FxRateService::record
 *   obligationWriteOff        POST finance/obligations/{o}/write-off                     finance.obligations.manage          ObligationService::writeOff
 *   obligationCancel          POST finance/obligations/{o}/cancel                        finance.obligations.manage          ObligationService::cancel
 *   controlAccountPropose     POST finance/reference/control-accounts                    finance.gl.configure                FinanceReferenceService::proposeControlAccount
 *   controlAccountApprove     POST finance/reference/control-accounts/{m}/approve        finance.gl.approve                  FinanceReferenceService::approveControlAccount
 *   costCentreCreate          POST finance/reference/cost-centres                        finance.gl.configure                FinanceReferenceService::createCostCentre
 *   costCentreStatus          POST finance/reference/cost-centres/{c}/status             finance.gl.configure                FinanceReferenceService::setCostCentreStatus
 *   institutionUpdate         PATCH finance/reference/institutions/{i}                   finance.institutions.manage         FinanceReferenceService::updateInstitution
 *   paymentProviderCreate     POST finance/reference/payment-providers                   finance.payment_providers.configure FinanceReferenceService::saveProfile
 *   paymentProviderUpdate     PATCH finance/reference/payment-providers/{p}              finance.payment_providers.configure FinanceReferenceService::saveProfile
 *   paymentProviderSubmit     POST finance/reference/payment-providers/{p}/submit        finance.payment_providers.configure FinanceReferenceService::submitProfile
 *   paymentProviderDecide     POST finance/reference/payment-providers/{p}/decision      finance.payment_providers.approve   FinanceReferenceService::decideProfile
 *   counterpartyAccountOpen   POST finance/subledger/accounts                            finance.adjustments.create          CounterpartyAccountService::open
 *   counterpartyAccountApprove POST finance/subledger/accounts/{a}/approve               finance.adjustments.approve         CounterpartyAccountService::approve
 *   counterpartyAccountStatus POST finance/subledger/accounts/{a}/status                 finance.adjustments.approve         CounterpartyAccountService::changeStatus
 *   agingConfigure            POST finance/subledger/aging-settings                      finance.commissions.configure       AgingService::configure
 *   subledgerDocumentGenerate POST finance/subledger/documents/{doc}                     finance.accounts.export             SubledgerQuery::normalise + StatementDocumentService::generate
 */
final class FinanceOperationsActions
{
    public const L = 'finance_ops_actions';

    /** @return list<string> every permission of the batch (the page is shown to a holder of any of them) */
    public const PERMISSIONS = [
        'finance.allocation_rules.manage', 'cashier.sessions.operate', 'cashier.sessions.approve', 'fx.rates.manage', 'finance.obligations.manage',
        'finance.gl.configure', 'finance.gl.approve', 'finance.institutions.manage', 'finance.payment_providers.configure', 'finance.payment_providers.approve',
        'finance.adjustments.create', 'finance.adjustments.approve', 'finance.commissions.configure', 'finance.accounts.export',
    ];

    // ---- allocation rule --------------------------------------------------------------------------------------

    public static function allocationRulePublish(): Action
    {
        $p = 'finance.allocation_rules.manage';

        return WorkflowAction::make('allocationRulePublish', $p, self::L)->icon('lucide-list-ordered')
            ->schema([
                Select::make('strategy')->label(self::f('strategy'))->options(self::opts(AllocationRuleService::STRATEGIES))->required(),
                Select::make('priority')->label(self::f('priority'))->helperText(self::f('priority_help'))->multiple()->options(self::opts(AllocationRuleService::CATEGORIES))->required()->minItems(1)->maxItems(6),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(255),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(AllocationRuleService::class)->publish(
                self::tenant(), $data['strategy'], array_values($data['priority']), $data['reason'], auth()->user()), self::done('allocationRulePublish')));
    }

    // ---- cashier sessions -------------------------------------------------------------------------------------

    public static function cashierSessionOpen(): Action
    {
        $p = 'cashier.sessions.operate';

        return WorkflowAction::make('cashierSessionOpen', $p, self::L)->icon('lucide-lock-open')
            ->schema([
                Select::make('branch_id')->label(self::f('branch'))->options(fn () => self::branches())->searchable()->required(),
                TextInput::make('opening_float_minor')->label(self::f('opening_float_minor'))->integer()->minValue(0)->required(),
                Select::make('currency')->label(self::f('currency'))->options(FinanceOptions::currencies())->default('XAF'),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(CashierSessionService::class)->open(
                self::tenant(), $data['branch_id'], (int) $data['opening_float_minor'], $data['currency'] ?? 'XAF', auth()->user()), self::done('cashierSessionOpen')));
    }

    public static function cashierSessionCollect(): Action
    {
        $p = 'cashier.sessions.operate';

        return WorkflowAction::make('cashierSessionCollect', $p, self::L)->icon('lucide-hand-coins')
            ->schema([
                self::pick('session_id', 'cashier_session', fn () => self::sessions('OPEN')),
                Select::make('method')->label(self::f('method'))->options(self::opts(CashierSessionService::METHODS))->required()->live(),
                TextInput::make('amount_minor')->label(self::f('amount_minor'))->integer()->minValue(1)->required(),
                Select::make('currency')->label(self::f('currency_optional'))->options(FinanceOptions::currencies()),
                TextInput::make('payer_name')->label(self::f('payer_name'))->maxLength(255)->required(),
                TextInput::make('cheque_number')->label(self::f('cheque_number'))->maxLength(64)->visible(fn ($get) => $get('method') === 'CHEQUE')->required(fn ($get) => $get('method') === 'CHEQUE'),
                TextInput::make('cheque_bank')->label(self::f('cheque_bank'))->maxLength(128)->visible(fn ($get) => $get('method') === 'CHEQUE'),
                Select::make('financial_obligation_id')->label(self::f('obligation_optional'))->options(fn () => self::obligations())->searchable(),
                TextInput::make('reference')->label(self::f('reference'))->maxLength(128),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(CashierSessionService::class)->collect(
                self::tenant(), $data['session_id'], array_filter([
                    'method' => $data['method'], 'amount_minor' => (int) $data['amount_minor'], 'currency' => $data['currency'] ?? null, 'payer_name' => $data['payer_name'] ?? null,
                    'cheque_number' => $data['cheque_number'] ?? null, 'cheque_bank' => $data['cheque_bank'] ?? null,
                    'financial_obligation_id' => $data['financial_obligation_id'] ?? null, 'reference' => $data['reference'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''), auth()->user()), self::done('cashierSessionCollect')));
    }

    public static function cashierSessionClose(): Action
    {
        $p = 'cashier.sessions.operate';

        return WorkflowAction::make('cashierSessionClose', $p, self::L)->icon('lucide-lock')
            ->schema([
                self::pick('session_id', 'cashier_session', fn () => self::sessions('OPEN')),
                TextInput::make('counted_cash_minor')->label(self::f('counted_cash_minor'))->integer()->minValue(0)->required(),
                Textarea::make('notes')->label(self::f('closing_notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(CashierSessionService::class)->close(
                self::tenant(), $data['session_id'], (int) $data['counted_cash_minor'], filled($data['notes'] ?? null) ? $data['notes'] : null, auth()->user()), self::done('cashierSessionClose')));
    }

    public static function cashierSessionDecide(): Action
    {
        $p = 'cashier.sessions.approve';

        return WorkflowAction::make('cashierSessionDecide', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->schema([
                self::pick('session_id', 'cashier_session', fn () => self::sessions('CLOSED')),
                Select::make('decision')->label(self::f('decision'))->options(self::codes(['APPROVE', 'REJECT'], 'decision'))->required(),
                Textarea::make('notes')->label(self::f('decision_notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(CashierSessionService::class)->decide(
                self::tenant(), $data['session_id'], $data['decision'] === 'APPROVE', filled($data['notes'] ?? null) ? $data['notes'] : null, auth()->user()), self::done('cashierSessionDecide')));
    }

    // ---- FX ---------------------------------------------------------------------------------------------------

    public static function fxRateRecord(): Action
    {
        $p = 'fx.rates.manage';

        return WorkflowAction::make('fxRateRecord', $p, self::L)->icon('lucide-arrow-left-right')
            ->schema([
                TextInput::make('base_currency')->label(self::f('base_currency'))->length(3)->required(),
                TextInput::make('quote_currency')->label(self::f('quote_currency'))->length(3)->required(),
                TextInput::make('rate')->label(self::f('rate'))->numeric()->rule('gt:0')->required(),
                Select::make('source')->label(self::f('source'))->options(self::opts(FxRateService::SOURCES))->required(),
                TextInput::make('source_reference')->label(self::f('source_reference'))->maxLength(128),
                DateTimePicker::make('effective_at')->label(self::f('effective_at'))->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FxRateService::class)->record(self::tenant(), array_filter([
                'base_currency' => strtoupper($data['base_currency']), 'quote_currency' => strtoupper($data['quote_currency']), 'rate' => $data['rate'],
                'source' => $data['source'], 'source_reference' => $data['source_reference'] ?? null, 'effective_at' => $data['effective_at'],
            ], fn ($v) => $v !== null && $v !== ''), auth()->user()), self::done('fxRateRecord')));
    }

    // ---- obligations ------------------------------------------------------------------------------------------

    public static function obligationWriteOff(): Action
    {
        return self::obligationAction('obligationWriteOff', 'lucide-file-x', fn (string $id, string $reason) => app(ObligationService::class)->writeOff($id, $reason, auth()->id()));
    }

    public static function obligationCancel(): Action
    {
        return self::obligationAction('obligationCancel', 'lucide-ban', fn (string $id, string $reason) => app(ObligationService::class)->cancel($id, $reason, auth()->id()));
    }

    private static function obligationAction(string $name, string $icon, callable $call): Action
    {
        $p = 'finance.obligations.manage';

        return WorkflowAction::make($name, $p, self::L)->icon($icon)->color('danger')
            ->schema([
                self::pick('obligation_id', 'obligation', fn () => self::obligations()),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(1000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data, $call) {
                // Same tenant ownership check as ObligationController::owned (404 outside the tenant).
                DB::table('financial_obligations')->where('tenant_id', self::tenant())->where('id', $data['obligation_id'])->exists() || abort(404);

                return $call($data['obligation_id'], $data['reason']);
            }, self::done($name)));
    }

    // ---- GL control accounts & cost centres ------------------------------------------------------------------

    public static function controlAccountPropose(): Action
    {
        $p = 'finance.gl.configure';

        return WorkflowAction::make('controlAccountPropose', $p, self::L)->icon('lucide-git-pull-request')
            ->schema([
                Select::make('control_code')->label(self::f('control_code'))->options(self::opts(array_keys(FinanceReferenceCatalogue::CONTROL_ACCOUNTS)))->searchable()->required(),
                TextInput::make('ledger_account_code')->label(self::f('ledger_account_code'))->required()->maxLength(64),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FinanceReferenceService::class)->proposeControlAccount(
                self::tenant(), $data['control_code'], $data['ledger_account_code'], (string) auth()->id(), $data['reason']), self::done('controlAccountPropose')));
    }

    public static function controlAccountApprove(): Action
    {
        $p = 'finance.gl.approve';

        return WorkflowAction::make('controlAccountApprove', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->schema([self::pick('mapping_id', 'control_mapping', fn () => DB::table('gl_control_account_mappings')->where('tenant_id', self::tenant())->where('status', 'PENDING_APPROVAL')
                ->orderBy('control_code')->get()->mapWithKeys(fn ($m) => [$m->id => "{$m->control_code} → {$m->ledger_account_code} (v{$m->version})"])->all())])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FinanceReferenceService::class)->approveControlAccount(
                self::tenant(), $data['mapping_id'], (string) auth()->id()), self::done('controlAccountApprove')));
    }

    public static function costCentreCreate(): Action
    {
        $p = 'finance.gl.configure';

        return WorkflowAction::make('costCentreCreate', $p, self::L)->icon('lucide-plus')
            ->schema([
                TextInput::make('code')->label(self::f('code'))->required()->maxLength(40)->regex('/^[A-Za-z0-9_-]+$/'),
                TextInput::make('name')->label(self::f('name'))->required()->maxLength(255),
                Select::make('parent_id')->label(self::f('parent_cost_centre'))->options(fn () => self::costCentres())->searchable(),
                Select::make('branch_id')->label(self::f('branch_optional'))->options(fn () => self::branches())->searchable(),
                DatePicker::make('effective_from')->label(self::f('effective_from')),
                DatePicker::make('effective_until')->label(self::f('effective_until'))->afterOrEqual('effective_from'),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FinanceReferenceService::class)->createCostCentre(
                self::tenant(), self::filled($data, ['code', 'name', 'parent_id', 'branch_id', 'effective_from', 'effective_until']), (string) auth()->id()), self::done('costCentreCreate')));
    }

    public static function costCentreStatus(): Action
    {
        $p = 'finance.gl.configure';

        return WorkflowAction::make('costCentreStatus', $p, self::L)->icon('lucide-toggle-right')
            ->schema([
                self::pick('cost_centre_id', 'cost_centre', fn () => self::costCentres()),
                Select::make('status')->label(self::f('status'))->options(self::codes(['ACTIVE', 'INACTIVE'], 'status'))->required(),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FinanceReferenceService::class)->setCostCentreStatus(
                self::tenant(), $data['cost_centre_id'], $data['status'], $data['reason']), self::done('costCentreStatus')));
    }

    // ---- institutions & payment provider profiles -------------------------------------------------------------

    public static function institutionUpdate(): Action
    {
        $p = 'finance.institutions.manage';

        return WorkflowAction::make('institutionUpdate', $p, self::L)->icon('lucide-landmark')
            ->schema([
                self::pick('institution_id', 'institution', fn () => DB::table('financial_institutions')->orderBy('legal_name')->limit(500)->pluck('legal_name', 'id')->all()),
                TextInput::make('legal_name')->label(self::f('legal_name'))->maxLength(255),
                TextInput::make('trade_name')->label(self::f('trade_name'))->maxLength(255),
                TextInput::make('bank_code')->label(self::f('bank_code'))->maxLength(20),
                TextInput::make('bic_swift')->label(self::f('bic_swift'))->maxLength(11),
                TextInput::make('operating_status')->label(self::f('operating_status'))->maxLength(30),
                TextInput::make('head_office_city')->label(self::f('head_office_city'))->maxLength(120),
                TextInput::make('website')->label(self::f('website'))->url(),
                TextInput::make('source_url')->label(self::f('source_url'))->url(),
                Select::make('verification_status')->label(self::f('verification_status'))->options(self::opts(FinanceReferenceCatalogue::GAP_STATUSES)),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = self::filled($data, ['legal_name', 'trade_name', 'bank_code', 'bic_swift', 'operating_status', 'head_office_city', 'website', 'source_url', 'verification_status']);

                return app(FinanceReferenceService::class)->updateInstitution($data['institution_id'], $d + ['reason' => $data['reason']], (string) auth()->id(), $data['reason']);
            }, self::done('institutionUpdate')));
    }

    public static function paymentProviderCreate(): Action
    {
        $p = 'finance.payment_providers.configure';

        return WorkflowAction::make('paymentProviderCreate', $p, self::L)->icon('lucide-plus')
            ->schema(self::profileFields(true))
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FinanceReferenceService::class)->saveProfile(
                self::tenant(), self::profileData($data), (string) auth()->id()), self::done('paymentProviderCreate')));
    }

    public static function paymentProviderUpdate(): Action
    {
        $p = 'finance.payment_providers.configure';

        return WorkflowAction::make('paymentProviderUpdate', $p, self::L)->icon('lucide-pencil')
            ->schema([self::pick('profile_id', 'payment_profile', fn () => self::profiles(['CONFIG_REQUIRED', 'PENDING_APPROVAL', 'SUSPENDED'])), ...self::profileFields(false)])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FinanceReferenceService::class)->saveProfile(
                self::tenant(), self::profileData($data), (string) auth()->id(), $data['profile_id']), self::done('paymentProviderUpdate')));
    }

    public static function paymentProviderSubmit(): Action
    {
        $p = 'finance.payment_providers.configure';

        return WorkflowAction::make('paymentProviderSubmit', $p, self::L)->icon('lucide-send')
            ->schema([self::pick('profile_id', 'payment_profile', fn () => self::profiles(['CONFIG_REQUIRED']))])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FinanceReferenceService::class)->submitProfile(
                self::tenant(), $data['profile_id']), self::done('paymentProviderSubmit')));
    }

    public static function paymentProviderDecide(): Action
    {
        $p = 'finance.payment_providers.approve';

        return WorkflowAction::make('paymentProviderDecide', $p, self::L)->icon('lucide-gavel')
            ->schema([
                self::pick('profile_id', 'payment_profile', fn () => self::profiles(['PENDING_APPROVAL', 'ACTIVE'])),
                Select::make('decision')->label(self::f('decision'))->options(self::codes(['APPROVE', 'REJECT', 'SUSPEND'], 'decision'))->required(),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(FinanceReferenceService::class)->decideProfile(
                self::tenant(), $data['profile_id'], $data['decision'], (string) auth()->id(), $data['reason']), self::done('paymentProviderDecide')));
    }

    /** @return list<mixed> the controller's saveProfile fields (secrets never entered here: callback/fees JSON stay API-only) */
    private static function profileFields(bool $create): array
    {
        $adapters = array_keys((array) config('payments.providers', [])) ?: ['fake', 'maviance', 'campay', 'mtn_momo', 'orange_money'];

        return [
            Select::make('provider_type')->label(self::f('provider_type'))->options(self::opts(FinanceReferenceCatalogue::PROVIDER_TYPES))->required($create),
            Select::make('adapter_provider')->label(self::f('adapter_provider'))->options(array_combine($adapters, $adapters)),
            Select::make('financial_institution_id')->label(self::f('institution_optional'))->options(fn () => DB::table('financial_institutions')->orderBy('legal_name')->limit(500)->pluck('legal_name', 'id')->all())->searchable(),
            Select::make('environment')->label(self::f('environment'))->options(self::opts(['SANDBOX', 'PRODUCTION'])),
            TextInput::make('api_base_url')->label(self::f('api_base_url'))->url()->maxLength(255),
            TextInput::make('merchant_identifier')->label(self::f('merchant_identifier'))->maxLength(120),
            TextInput::make('collection_account')->label(self::f('collection_account'))->maxLength(80),
            TextInput::make('settlement_account')->label(self::f('settlement_account'))->maxLength(80),
            Select::make('settlement_cycle')->label(self::f('settlement_cycle'))->options(self::opts(FinanceReferenceCatalogue::SETTLEMENT_CYCLES)),
            DatePicker::make('effective_from')->label(self::f('effective_from')),
            DatePicker::make('effective_until')->label(self::f('effective_until'))->afterOrEqual('effective_from'),
        ];
    }

    private static function profileData(array $data): array
    {
        return self::filled($data, ['provider_type', 'adapter_provider', 'financial_institution_id', 'environment', 'api_base_url', 'merchant_identifier',
            'collection_account', 'settlement_account', 'settlement_cycle', 'effective_from', 'effective_until']);
    }

    // ---- counterparty sub-ledger accounts, aging, statement documents ----------------------------------------

    public static function counterpartyAccountOpen(): Action
    {
        $p = 'finance.adjustments.create';

        return WorkflowAction::make('counterpartyAccountOpen', $p, self::L)->icon('lucide-book-plus')
            ->schema([
                Select::make('relationship_type')->label(self::f('relationship_type'))->options(self::opts(array_keys(SubledgerCatalogue::RELATIONSHIPS)))->required()->live(),
                Select::make('account_type')->label(self::f('account_type'))->options(fn ($get) => self::opts(SubledgerCatalogue::RELATIONSHIPS[$get('relationship_type')] ?? []))->required(),
                TextInput::make('counterparty_id')->label(self::f('counterparty_id'))->helperText(self::f('counterparty_help'))->uuid()->required(),
                Select::make('currency')->label(self::f('currency'))->options(FinanceOptions::currencies())->default('XAF')->required(),
                Select::make('branch_id')->label(self::f('branch_optional'))->options(fn () => self::branches())->searchable(),
                TextInput::make('gl_account_code')->label(self::f('gl_account_code'))->maxLength(20),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(CounterpartyAccountService::class)->open(
                self::tenant(), self::filled($data, ['relationship_type', 'account_type', 'counterparty_id', 'currency', 'branch_id', 'gl_account_code']), (string) auth()->id()),
                self::done('counterpartyAccountOpen')));
    }

    public static function counterpartyAccountApprove(): Action
    {
        $p = 'finance.adjustments.approve';

        return WorkflowAction::make('counterpartyAccountApprove', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->schema([self::pick('account_id', 'counterparty_account', fn () => self::accounts(['PENDING_APPROVAL']))])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(CounterpartyAccountService::class)->approve(
                self::tenant(), $data['account_id'], (string) auth()->id()), self::done('counterpartyAccountApprove')));
    }

    public static function counterpartyAccountStatus(): Action
    {
        $p = 'finance.adjustments.approve';

        return WorkflowAction::make('counterpartyAccountStatus', $p, self::L)->icon('lucide-toggle-right')
            ->schema([
                self::pick('account_id', 'counterparty_account', fn () => self::accounts(['ACTIVE', 'SUSPENDED'])),
                Select::make('status')->label(self::f('status'))->options(self::codes(['ACTIVE', 'SUSPENDED', 'CLOSED'], 'status'))->required(),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(CounterpartyAccountService::class)->changeStatus(
                self::tenant(), $data['account_id'], $data['status'], $data['reason'], (string) auth()->id()), self::done('counterpartyAccountStatus')));
    }

    public static function agingConfigure(): Action
    {
        $p = 'finance.commissions.configure';

        return WorkflowAction::make('agingConfigure', $p, self::L)->icon('lucide-hourglass')
            ->schema([
                Select::make('scope')->label(self::f('aging_scope'))->options(self::opts(SubledgerCatalogue::AGING_SCOPES))->required(),
                Select::make('basis')->label(self::f('aging_basis'))->options(self::opts(SubledgerCatalogue::AGING_BASIS))->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(AgingService::class)->configure(
                self::tenant(), $data['scope'], $data['basis'], (string) auth()->id()), self::done('agingConfigure')));
    }

    public static function subledgerDocumentGenerate(): Action
    {
        $p = 'finance.accounts.export';

        return WorkflowAction::make('subledgerDocumentGenerate', $p, self::L)->icon('lucide-file-text')
            ->schema([
                Select::make('doc')->label(self::f('statement_document'))->options(collect(SubledgerCatalogue::STATEMENT_DOCUMENTS)->mapWithKeys(fn ($v, $k) => [$k => "{$k} — {$v['spec']}"])->all())->required(),
                TextInput::make('subject_id')->label(self::f('subject_id'))->helperText(self::f('subject_help'))->uuid()->required(),
                DatePicker::make('from')->label(self::f('period_from')),
                DatePicker::make('to')->label(self::f('period_to')),
                Select::make('currency')->label(self::f('currency_optional'))->options(FinanceOptions::currencies()),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                // Same filter normalisation as SubledgerController::generateDocument.
                $filters = app(SubledgerQuery::class)->normalise(self::filled($data, ['from', 'to', 'currency']));

                return app(StatementDocumentService::class)->generate(self::tenant(), strtoupper($data['doc']), $data['subject_id'], $filters, (string) auth()->id());
            }, self::done('subledgerDocumentGenerate')));
    }

    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::allocationRulePublish(), self::cashierSessionOpen(), self::cashierSessionCollect(), self::cashierSessionClose(), self::cashierSessionDecide(),
            self::fxRateRecord(), self::obligationWriteOff(), self::obligationCancel(), self::controlAccountPropose(), self::controlAccountApprove(),
            self::costCentreCreate(), self::costCentreStatus(), self::institutionUpdate(), self::paymentProviderCreate(), self::paymentProviderUpdate(),
            self::paymentProviderSubmit(), self::paymentProviderDecide(), self::counterpartyAccountOpen(), self::counterpartyAccountApprove(),
            self::counterpartyAccountStatus(), self::agingConfigure(), self::subledgerDocumentGenerate(),
        ];
    }

    // ---- helpers (shared with LedgerOperationsActions) --------------------------------------------------------

    /** A required, searchable record picker labelled from finance_ops_actions.fields.<label>. */
    public static function pick(string $name, string $label, \Closure $options): Select
    {
        return Select::make($name)->label(self::f($label))->options($options)->searchable()->required();
    }

    public static function f(string $key): string
    {
        return __(self::L.'.fields.'.$key);
    }

    public static function done(string $name): string
    {
        return __(self::L.'.'.$name.'.done');
    }

    /** @param list<string> $values @return array<string, string> */
    public static function opts(array $values): array
    {
        return array_combine($values, $values) ?: [];
    }

    /** @param list<string> $values @return array<string, string> translated codes (finance_ops_actions.codes.<group>.<value>) */
    public static function codes(array $values, string $group): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => WorkflowAction::optional(self::L.".codes.{$group}.{$v}") ?? $v])->all();
    }

    /** @param list<string> $keys @return array<string, mixed> only the keys the user filled (the API's "sometimes"/"nullable") */
    public static function filled(array $data, array $keys): array
    {
        return array_filter(array_intersect_key($data, array_flip($keys)), fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    /** @return array<string, string> */
    public static function branches(): array
    {
        return DB::table('tenant_branches')->where('tenant_id', self::tenant())->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    private static function sessions(string $status): array
    {
        return DB::table('cashier_sessions as s')->leftJoin('tenant_branches as b', 'b.id', '=', 's.branch_id')->leftJoin('users as u', 'u.id', '=', 's.cashier_user_id')
            ->where('s.tenant_id', self::tenant())->where('s.status', $status)->orderByDesc('s.opened_at')->limit(200)
            ->get(['s.id', 's.opened_at', 's.currency', 'b.name as branch', 'u.full_name as cashier'])
            ->mapWithKeys(fn ($s) => [$s->id => trim(($s->branch ?? '').' · '.($s->cashier ?? '').' · '.$s->currency.' · '.$s->opened_at)])->all();
    }

    /** @return array<string, string> open obligations of the tenant */
    public static function obligations(?callable $scope = null): array
    {
        $q = DB::table('financial_obligations')->where('tenant_id', self::tenant())->whereIn('status', ObligationService::OPEN_STATUSES);
        if ($scope) {
            $scope($q);
        }

        return $q->orderBy('due_at')->limit(300)->get()
            ->mapWithKeys(fn ($o) => [$o->id => "{$o->kind} {$o->type} · ".number_format((int) $o->outstanding_minor, 0, ',', ' ')." {$o->currency} · ".substr((string) $o->due_at, 0, 10)])->all();
    }

    /** @return array<string, string> */
    private static function costCentres(): array
    {
        return DB::table('cost_centres')->where('tenant_id', self::tenant())->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} — {$c->name} ({$c->status})"])->all();
    }

    /** @param list<string> $statuses @return array<string, string> */
    private static function profiles(array $statuses): array
    {
        return DB::table('payment_provider_profiles')->where('tenant_id', self::tenant())->whereIn('status', $statuses)->orderBy('provider_type')->get()
            ->mapWithKeys(fn ($p) => [$p->id => "{$p->provider_type} · ".($p->environment ?? '').' · '.$p->status])->all();
    }

    /** @param list<string> $statuses @return array<string, string> */
    private static function accounts(array $statuses): array
    {
        return DB::table('finance_counterparty_accounts')->where('tenant_id', self::tenant())->whereIn('status', $statuses)->orderBy('account_code')->limit(500)->get()
            ->mapWithKeys(fn ($a) => [$a->id => "{$a->account_code} · {$a->currency} · {$a->status}"])->all();
    }
}
