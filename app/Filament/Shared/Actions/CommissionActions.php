<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Commissions\Machine\CommissionLifecycleService;
use App\Application\FinancialDistribution\CommissionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommissionAccrual;
use App\Models\CommissionRuleVersion;
use App\Models\InsuranceProduct;
use App\Models\Policy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

/**
 * Commission rules and accruals (UI coverage batch 9). Same service, same permission as the API route; the services keep
 * their maker-checker rules (rule approver ≠ rule creator, adjusted accrual re-approved by someone else).
 *   ruleCreate            POST financial-distribution/commission-rules                commission.manage    CommissionService::createRule
 *   ruleApprove           POST financial-distribution/commission-rules/{r}/approve    commission.approve   CommissionService::approveRule
 *   accrualAccrue         POST financial-distribution/commissions/accrue              commission.accrue    CommissionService::accrue
 *   accrualVest           POST financial-distribution/commissions/{a}/vest            commission.vest      CommissionService::vest
 *   accrualClawback       POST financial-distribution/commissions/{a}/clawback        commission.clawback  CommissionService::clawback
 *   accrualEarn           POST commissions/accruals/{a}/earn                          commission.vest      CommissionLifecycleService::earn
 *   accrualApprove        POST commissions/accruals/{a}/approve                       commission.approve   CommissionLifecycleService::approve
 *   accrualMakePayable    POST commissions/accruals/{a}/make-payable                  commission.vest      CommissionLifecycleService::makePayable
 *   accrualAdjust         POST commissions/accruals/{a}/adjust                        commission.manage    CommissionLifecycleService::adjust
 *   accrualDispute        POST commissions/accruals/{a}/dispute                       commission.manage    CommissionLifecycleService::dispute
 *   accrualResolveDispute POST commissions/accruals/{a}/resolve-dispute               commission.approve   CommissionLifecycleService::resolveDispute
 *   accrualReverse        POST commissions/accruals/{a}/reverse                       commission.clawback  CommissionLifecycleService::reverse
 */
final class CommissionActions
{
    private const L = 'finance_actions';

    // ---- rules -------------------------------------------------------------------------------------------------

    public static function ruleCreate(): Action
    {
        $p = 'commission.manage';

        return WorkflowAction::make('ruleCreate', $p, self::L)->icon('lucide-plus')
            ->schema([
                Select::make('carrier_id')->label(__('finance_actions.fields.carrier'))->options(fn () => FinanceOptions::carriers())->searchable()->required(),
                Select::make('product_id')->label(__('finance_actions.fields.product'))->options(fn (Get $get) => $get('carrier_id') ? InsuranceProduct::where('carrier_id', $get('carrier_id'))->orderBy('code')->limit(500)->get()
                    ->mapWithKeys(fn (InsuranceProduct $x) => [$x->id => $x->code.' v'.$x->version])->all() : [])->searchable(),
                Select::make('partner_id')->label(__('finance_actions.fields.partner'))->options(fn () => FinanceOptions::partners())->searchable(),
                TextInput::make('basis_points')->label(__('finance_actions.fields.basis_points'))->integer()->minValue(0)->maxValue(10000)->required(),
                TextInput::make('holdback_basis_points')->label(__('finance_actions.fields.holdback_basis_points'))->integer()->minValue(0)->maxValue(10000)->default(0)->required(),
                TextInput::make('vesting_days')->label(__('finance_actions.fields.vesting_days'))->integer()->minValue(0)->maxValue(3650)->default(0)->required(),
                DatePicker::make('effective_from')->label(__('finance_actions.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__('finance_actions.fields.effective_until'))->afterOrEqual('effective_from'),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = array_filter([
                    'carrier_id' => $data['carrier_id'], 'product_id' => $data['product_id'] ?? null, 'partner_id' => $data['partner_id'] ?? null,
                    'basis_points' => (int) $data['basis_points'], 'holdback_basis_points' => (int) $data['holdback_basis_points'], 'vesting_days' => (int) $data['vesting_days'],
                    'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');

                return WorkflowAction::run($action, $p, fn () => app(CommissionService::class)->createRule([...$d, 'tenant_id' => self::tenant()], auth()->user()),
                    __('finance_actions.ruleCreate.done'));
            });
    }

    public static function ruleApprove(): Action
    {
        $p = 'commission.approve';

        return WorkflowAction::make('ruleApprove', $p, self::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (CommissionRuleVersion $record) => $record->status === 'DRAFT')
            ->action(fn (Action $action, CommissionRuleVersion $record) => WorkflowAction::run($action, $p,
                fn () => app(CommissionService::class)->approveRule(self::rule($record), auth()->user()), __('finance_actions.ruleApprove.done')));
    }

    // ---- accruals ----------------------------------------------------------------------------------------------

    public static function accrualAccrue(): Action
    {
        $p = 'commission.accrue';

        return WorkflowAction::make('accrualAccrue', $p, self::L)->icon('lucide-plus')
            ->schema([
                Select::make('policy_id')->label(__('finance_actions.fields.policy'))->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search) => Policy::where('tenant_id', self::tenant())->where('policy_number', 'ilike', "%{$search}%")->limit(50)->pluck('policy_number', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Policy::where('tenant_id', self::tenant())->whereKey($value)->value('policy_number')),
                Select::make('rule_id')->label(__('finance_actions.fields.rule'))->required()
                    ->options(fn () => CommissionRuleVersion::where('tenant_id', self::tenant())->where('status', 'APPROVED')->orderByDesc('effective_from')->limit(200)->get()
                        ->mapWithKeys(fn (CommissionRuleVersion $r) => [$r->id => 'v'.$r->version.' · '.$r->basis_points.' bps · '.optional($r->effective_from)->toDateString()])->all()),
                Select::make('partner_id')->label(__('finance_actions.fields.partner'))->options(fn () => FinanceOptions::partners())->searchable()->required(),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($data) {
                    $policy = Policy::where('tenant_id', self::tenant())->findOrFail($data['policy_id']);
                    $rule = CommissionRuleVersion::where('tenant_id', self::tenant())->findOrFail($data['rule_id']);

                    return app(CommissionService::class)->accrue($policy, $rule, $data['partner_id'], 'web-'.Str::uuid());
                }, __('finance_actions.accrualAccrue.done'));
            });
    }

    public static function accrualVest(): Action
    {
        $p = 'commission.vest';

        return WorkflowAction::make('accrualVest', $p, self::L)->icon('lucide-lock-open')->requiresConfirmation()
            ->visible(fn (CommissionAccrual $record) => $record->status === 'PENDING' && $record->vests_at !== null && ! $record->vests_at->isFuture())
            ->action(fn (Action $action, CommissionAccrual $record) => WorkflowAction::run($action, $p,
                fn () => app(CommissionService::class)->vest(self::accrual($record)), __('finance_actions.accrualVest.done')));
    }

    public static function accrualClawback(): Action
    {
        $p = 'commission.clawback';

        return WorkflowAction::make('accrualClawback', $p, self::L)->icon('lucide-undo-2')->color('danger')
            ->visible(fn (CommissionAccrual $record) => in_array($record->status, ['PENDING', 'EARNED', 'APPROVED', 'VESTED', 'AVAILABLE', 'ADJUSTED', 'DISPUTED'], true)
                && (int) $record->amount_minor - (int) $record->clawed_back_minor - (int) $record->paid_minor > 0)
            ->schema([
                TextInput::make('amount_minor')->label(__('finance_actions.fields.amount_minor'))->integer()->minValue(1)->required(),
                TextInput::make('reason_code')->label(__('finance_actions.fields.reason_code'))->required()->maxLength(80),
            ])
            ->action(fn (Action $action, CommissionAccrual $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionService::class)->clawback(self::accrual($record), (int) $data['amount_minor'], $data['reason_code'], auth()->user()), __('finance_actions.accrualClawback.done')));
    }

    public static function accrualEarn(): Action
    {
        $p = 'commission.vest';

        return WorkflowAction::make('accrualEarn', $p, self::L)->icon('lucide-circle-check')->requiresConfirmation()
            ->visible(fn (CommissionAccrual $record) => $record->status === 'PENDING')
            ->action(fn (Action $action, CommissionAccrual $record) => WorkflowAction::run($action, $p,
                fn () => app(CommissionLifecycleService::class)->earn(self::accrual($record), auth()->user()), __('finance_actions.accrualEarn.done')));
    }

    public static function accrualApprove(): Action
    {
        $p = 'commission.approve';

        return WorkflowAction::make('accrualApprove', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (CommissionAccrual $record) => in_array($record->status, ['EARNED', 'ADJUSTED'], true))
            ->schema([Textarea::make('note')->label(__('finance_actions.fields.note'))->maxLength(1000)])
            ->action(fn (Action $action, CommissionAccrual $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionLifecycleService::class)->approve(self::accrual($record), auth()->user(), filled($data['note'] ?? null) ? $data['note'] : null), __('finance_actions.accrualApprove.done')));
    }

    public static function accrualMakePayable(): Action
    {
        $p = 'commission.vest';

        return WorkflowAction::make('accrualMakePayable', $p, self::L)->icon('lucide-wallet')->requiresConfirmation()
            ->visible(fn (CommissionAccrual $record) => $record->status === 'APPROVED')
            ->action(fn (Action $action, CommissionAccrual $record) => WorkflowAction::run($action, $p,
                fn () => app(CommissionLifecycleService::class)->makePayable(self::accrual($record), auth()->user()), __('finance_actions.accrualMakePayable.done')));
    }

    public static function accrualAdjust(): Action
    {
        $p = 'commission.manage';

        return WorkflowAction::make('accrualAdjust', $p, self::L)->icon('lucide-pencil')
            ->visible(fn (CommissionAccrual $record) => in_array($record->status, ['PENDING', 'EARNED', 'APPROVED'], true))
            ->schema([
                TextInput::make('amount_minor')->label(__('finance_actions.fields.new_amount_minor'))->integer()->minValue(0)->required(),
                Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->maxLength(1000),
            ])
            ->action(fn (Action $action, CommissionAccrual $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionLifecycleService::class)->adjust(self::accrual($record), (int) $data['amount_minor'], $data['reason'], auth()->user()), __('finance_actions.accrualAdjust.done')));
    }

    public static function accrualDispute(): Action
    {
        $p = 'commission.manage';

        return WorkflowAction::make('accrualDispute', $p, self::L)->icon('lucide-flag')->color('warning')
            ->visible(fn (CommissionAccrual $record) => in_array($record->status, ['PENDING', 'EARNED', 'APPROVED', 'VESTED', 'ADJUSTED'], true))
            ->schema([Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->maxLength(1000)])
            ->action(fn (Action $action, CommissionAccrual $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionLifecycleService::class)->dispute(self::accrual($record), $data['reason'], auth()->user()), __('finance_actions.accrualDispute.done')));
    }

    public static function accrualResolveDispute(): Action
    {
        $p = 'commission.approve';

        return WorkflowAction::make('accrualResolveDispute', $p, self::L)->icon('lucide-gavel')
            ->visible(fn (CommissionAccrual $record) => $record->status === 'DISPUTED')
            ->schema([
                TextInput::make('amount_minor')->label(__('finance_actions.fields.corrected_amount_minor'))->integer()->minValue(0),
                Textarea::make('note')->label(__('finance_actions.fields.note'))->required()->maxLength(1000),
            ])
            ->action(fn (Action $action, CommissionAccrual $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionLifecycleService::class)->resolveDispute(self::accrual($record), filled($data['amount_minor'] ?? null) ? (int) $data['amount_minor'] : null, $data['note'], auth()->user()),
                __('finance_actions.accrualResolveDispute.done')));
    }

    public static function accrualReverse(): Action
    {
        $p = 'commission.clawback';

        return WorkflowAction::make('accrualReverse', $p, self::L)->icon('lucide-circle-x')->color('danger')->requiresConfirmation()
            ->visible(fn (CommissionAccrual $record) => in_array($record->status, ['CALCULATED', 'PENDING', 'EARNED', 'APPROVED', 'ADJUSTED', 'DISPUTED'], true))
            ->schema([TextInput::make('reason')->label(__('finance_actions.fields.reason_code'))->required()->maxLength(60)])
            ->action(fn (Action $action, CommissionAccrual $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionLifecycleService::class)->reverse(self::accrual($record), $data['reason'], auth()->user()), __('finance_actions.accrualReverse.done')));
    }

    /** @return list<Action> */
    public static function accrualActions(): array
    {
        return [self::accrualEarn(), self::accrualVest(), self::accrualApprove(), self::accrualMakePayable(), self::accrualAdjust(), self::accrualDispute(),
            self::accrualResolveDispute(), self::accrualClawback(), self::accrualReverse()];
    }

    private static function rule(CommissionRuleVersion $r): CommissionRuleVersion
    {
        return CommissionRuleVersion::where('tenant_id', self::tenant())->findOrFail($r->id);
    }

    private static function accrual(CommissionAccrual $a): CommissionAccrual
    {
        return CommissionAccrual::where('tenant_id', self::tenant())->findOrFail($a->id);
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
