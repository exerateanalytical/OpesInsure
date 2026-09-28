<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Commissions\Statements\CommissionStatementService;
use App\Application\FinancialDistribution\PartnerStatementService;
use App\Application\FinancialDistribution\PayoutService;
use App\Application\WebExperiences\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\PartnerPayoutRequest;
use App\Models\PartnerStatement;
use App\Models\PartnerStatementItem;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Partner (commission) statements and partner payouts (UI coverage batches 9-10). Maker-checker stays in the services:
 * statement approver ≠ preparer, adjustment checker ≠ maker, payout approver ≠ requester, payout reverser ≠ requester.
 *   statementGenerate          POST commission-statements/generate                   statements.prepare                          CommissionStatementService::generate / generatePeriod
 *   statementPrepare           POST partner-statements                               statements.prepare                          PartnerStatementService::prepare
 *   statementApprove           POST partner-statements/{s}/approve                   statements.approve                          PartnerStatementService::approve
 *   statementPublish           POST partner-statements/{s}/publish                   statements.publish                          PartnerStatementService::publish
 *   statementDispute           POST partner-statements/{s}/dispute                   commission.statements.dispute               CommissionStatementService::dispute
 *   statementResolveDispute    POST partner-statements/{s}/resolve-dispute           commission.statements.dispute.resolve       CommissionStatementService::resolveDispute
 *   adjustmentPropose          POST partner-statements/{s}/adjustments               commission.statements.adjust                CommissionStatementService::proposeAdjustment
 *   adjustmentApprove          POST partner-statement-adjustments/{i}/approve        commission.statements.adjustments.approve   CommissionStatementService::approveAdjustment
 *   adjustmentReject           POST partner-statement-adjustments/{i}/reject         commission.statements.adjustments.approve   CommissionStatementService::rejectAdjustment
 *   payoutRequest              POST partner-statements/{s}/payouts                   payout.request                              PayoutService::request
 *   payoutApprove              POST partner-payouts/{p}/approve                      payout.approve                              PayoutService::approve
 *   payoutProcess              POST partner-payouts/{p}/process                      payout.process                              PayoutService::markProcessing
 *   payoutComplete             POST partner-payouts/{p}/complete                     payout.process                              PayoutService::complete
 *   payoutFail                 POST partner-payouts/{p}/fail                         payout.process                              PayoutService::fail
 *   payoutReverse              POST partner-payouts/{p}/reverse                      payout.reverse                              PayoutService::reverse
 */
final class StatementPayoutActions
{
    private const L = 'finance_actions';

    // ---- statements --------------------------------------------------------------------------------------------

    public static function statementGenerate(): Action
    {
        $p = 'statements.prepare';

        return WorkflowAction::make('statementGenerate', $p, self::L)->icon('lucide-files')
            ->schema([
                Select::make('partner_id')->label(__('finance_actions.fields.partner_optional'))->options(fn () => FinanceOptions::partners())->searchable(),
                ...self::periodFields(),
                TextInput::make('opening_balance_minor')->label(__('finance_actions.fields.opening_balance_minor'))->integer()->default(0),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($data) {
                    $tenant = self::tenant();
                    $svc = app(CommissionStatementService::class);
                    if (filled($data['partner_id'] ?? null)) {
                        abort_unless(Partner::where('id', $data['partner_id'])->where('tenant_id', $tenant)->exists(), 404);

                        return $svc->generate($tenant, $data['partner_id'], $data['period_start'], $data['period_end'], $data['currency'], auth()->user(), (int) ($data['opening_balance_minor'] ?? 0));
                    }

                    return $svc->generatePeriod($tenant, $data['period_start'], $data['period_end'], $data['currency'], auth()->user());
                }, __('finance_actions.statementGenerate.done'));
            });
    }

    public static function statementPrepare(): Action
    {
        $p = 'statements.prepare';

        return WorkflowAction::make('statementPrepare', $p, self::L)->icon('lucide-plus')
            ->schema([
                Select::make('partner_id')->label(__('finance_actions.fields.partner'))->options(fn () => FinanceOptions::partners())->searchable()->required(),
                ...self::periodFields(),
                TextInput::make('opening_balance_minor')->label(__('finance_actions.fields.opening_balance_minor'))->integer()->default(0),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(PartnerStatementService::class)->prepare([
                'partner_id' => $data['partner_id'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'currency' => $data['currency'],
                'opening_balance_minor' => (int) ($data['opening_balance_minor'] ?? 0), 'idempotency_key' => 'web-'.Str::uuid(), 'tenant_id' => self::tenant(),
            ], auth()->user()), __('finance_actions.statementPrepare.done')));
    }

    public static function statementApprove(): Action
    {
        $p = 'statements.approve';

        return WorkflowAction::make('statementApprove', $p, self::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (PartnerStatement $record) => $record->status === 'DRAFT')
            ->action(fn (Action $action, PartnerStatement $record) => WorkflowAction::run($action, $p,
                fn () => app(PartnerStatementService::class)->approve(self::statement($record), auth()->user()), __('finance_actions.statementApprove.done')));
    }

    public static function statementPublish(): Action
    {
        $p = 'statements.publish';

        return WorkflowAction::make('statementPublish', $p, self::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (PartnerStatement $record) => $record->status === 'APPROVED')
            ->action(fn (Action $action, PartnerStatement $record) => WorkflowAction::run($action, $p,
                fn () => app(PartnerStatementService::class)->publish(self::statement($record), auth()->user()), __('finance_actions.statementPublish.done')));
    }

    public static function statementDispute(): Action
    {
        $p = 'commission.statements.dispute';

        return WorkflowAction::make('statementDispute', $p, self::L)->icon('lucide-flag')->color('warning')
            ->visible(fn (PartnerStatement $record) => in_array($record->status, CommissionStatementService::DISPUTABLE, true))
            ->schema([Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, PartnerStatement $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionStatementService::class)->dispute(self::statement($record), $data['reason'], auth()->user()), __('finance_actions.statementDispute.done')));
    }

    public static function statementResolveDispute(): Action
    {
        $p = 'commission.statements.dispute.resolve';

        return WorkflowAction::make('statementResolveDispute', $p, self::L)->icon('lucide-gavel')
            ->visible(fn (PartnerStatement $record) => $record->status === 'DISPUTED')
            ->schema([Textarea::make('resolution')->label(__('finance_actions.fields.resolution'))->required()->maxLength(2000)])
            ->action(fn (Action $action, PartnerStatement $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionStatementService::class)->resolveDispute(self::statement($record), $data['resolution'], auth()->user()), __('finance_actions.statementResolveDispute.done')));
    }

    public static function adjustmentPropose(): Action
    {
        $p = 'commission.statements.adjust';

        return WorkflowAction::make('adjustmentPropose', $p, self::L)->icon('lucide-diff')
            ->visible(fn (PartnerStatement $record) => in_array($record->status, CommissionStatementService::ADJUSTABLE, true))
            ->schema([
                TextInput::make('amount_minor')->label(__('finance_actions.fields.signed_amount_minor'))->integer()->required()->notIn(['0']),
                Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->maxLength(1000),
            ])
            ->action(fn (Action $action, PartnerStatement $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionStatementService::class)->proposeAdjustment(self::statement($record), (int) $data['amount_minor'], $data['reason'], auth()->user()),
                __('finance_actions.adjustmentPropose.done')));
    }

    public static function adjustmentApprove(): Action
    {
        $p = 'commission.statements.adjustments.approve';

        return WorkflowAction::make('adjustmentApprove', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (PartnerStatement $record) => self::proposed($record) !== [])
            ->schema(fn (PartnerStatement $record) => [Select::make('item_id')->label(__('finance_actions.fields.adjustment'))->options(self::proposed($record))->required()])
            ->action(fn (Action $action, PartnerStatement $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionStatementService::class)->approveAdjustment(self::item($record, $data['item_id']), auth()->user()), __('finance_actions.adjustmentApprove.done')));
    }

    public static function adjustmentReject(): Action
    {
        $p = 'commission.statements.adjustments.approve';

        return WorkflowAction::make('adjustmentReject', $p, self::L)->icon('lucide-circle-x')->color('danger')
            ->visible(fn (PartnerStatement $record) => self::proposed($record) !== [])
            ->schema(fn (PartnerStatement $record) => [
                Select::make('item_id')->label(__('finance_actions.fields.adjustment'))->options(self::proposed($record))->required(),
                Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->maxLength(1000),
            ])
            ->action(fn (Action $action, PartnerStatement $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CommissionStatementService::class)->rejectAdjustment(self::item($record, $data['item_id']), auth()->user(), $data['reason']), __('finance_actions.adjustmentReject.done')));
    }

    public static function payoutRequest(): Action
    {
        $p = 'payout.request';

        return WorkflowAction::make('payoutRequest', $p, self::L)->icon('lucide-banknote')
            ->visible(fn (PartnerStatement $record) => $record->status === 'PUBLISHED')
            ->schema([
                TextInput::make('amount_minor')->label(__('finance_actions.fields.amount_minor'))->integer()->minValue(1)->required(),
                Select::make('destination_type')->label(__('finance_actions.fields.destination_type'))
                    ->options(['MOBILE_MONEY' => __('finance_actions.codes.MOBILE_MONEY'), 'BANK' => __('finance_actions.codes.BANK')])->required(),
                TextInput::make('destination')->label(__('finance_actions.fields.destination'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, PartnerStatement $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PayoutService::class)->request(self::statement($record), [
                    'amount_minor' => (int) $data['amount_minor'], 'destination_type' => $data['destination_type'], 'destination' => $data['destination'], 'idempotency_key' => 'web-'.Str::uuid(),
                ], auth()->user()), __('finance_actions.payoutRequest.done')));
    }

    /** @return list<Action> */
    public static function statementActions(): array
    {
        return [self::statementApprove(), self::statementPublish(), self::payoutRequest(), self::adjustmentPropose(), self::adjustmentApprove(), self::adjustmentReject(),
            self::statementDispute(), self::statementResolveDispute()];
    }

    // ---- payouts -----------------------------------------------------------------------------------------------

    public static function payoutApprove(): Action
    {
        $p = 'payout.approve';

        return WorkflowAction::make('payoutApprove', $p, self::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (PartnerPayoutRequest $record) => $record->status === 'REQUESTED')
            ->action(fn (Action $action, PartnerPayoutRequest $record) => WorkflowAction::run($action, $p,
                fn () => app(PayoutService::class)->approve(self::payout($record), auth()->user()), __('finance_actions.payoutApprove.done')));
    }

    public static function payoutProcess(): Action
    {
        $p = 'payout.process';

        return WorkflowAction::make('payoutProcess', $p, self::L)->icon('lucide-loader')
            ->visible(fn (PartnerPayoutRequest $record) => $record->status === 'APPROVED')
            ->schema([TextInput::make('provider')->label(__('finance_actions.fields.provider'))->required()->maxLength(48)])
            ->action(fn (Action $action, PartnerPayoutRequest $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PayoutService::class)->markProcessing(self::payout($record), ['provider' => $data['provider']], auth()->user()), __('finance_actions.payoutProcess.done')));
    }

    public static function payoutComplete(): Action
    {
        $p = 'payout.process';

        return WorkflowAction::make('payoutComplete', $p, self::L)->icon('lucide-circle-check')->color('success')
            ->visible(fn (PartnerPayoutRequest $record) => $record->status === 'PROCESSING')
            ->schema([TextInput::make('provider_reference')->label(__('finance_actions.fields.provider_reference'))->required()->maxLength(120)])
            ->action(fn (Action $action, PartnerPayoutRequest $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PayoutService::class)->complete(self::payout($record), ['provider_reference' => $data['provider_reference']], auth()->user()), __('finance_actions.payoutComplete.done')));
    }

    public static function payoutFail(): Action
    {
        $p = 'payout.process';

        return WorkflowAction::make('payoutFail', $p, self::L)->icon('lucide-triangle-alert')->color('danger')
            ->visible(fn (PartnerPayoutRequest $record) => $record->status === 'PROCESSING')
            ->schema([
                TextInput::make('failure_code')->label(__('finance_actions.fields.failure_code'))->required()->maxLength(80),
                Textarea::make('failure_message')->label(__('finance_actions.fields.failure_message'))->maxLength(1000),
            ])
            ->action(fn (Action $action, PartnerPayoutRequest $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PayoutService::class)->fail(self::payout($record), ['failure_code' => $data['failure_code'], 'failure_message' => filled($data['failure_message'] ?? null) ? $data['failure_message'] : null], auth()->user()),
                __('finance_actions.payoutFail.done')));
    }

    public static function payoutReverse(): Action
    {
        $p = 'payout.reverse';

        return WorkflowAction::make('payoutReverse', $p, self::L)->icon('lucide-undo-2')->color('danger')->requiresConfirmation()
            ->visible(fn (PartnerPayoutRequest $record) => $record->status === 'PAID')
            ->schema([TextInput::make('reason_code')->label(__('finance_actions.fields.reason_code'))->required()->maxLength(80)])
            ->action(fn (Action $action, PartnerPayoutRequest $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PayoutService::class)->reverse(self::payout($record), $data['reason_code'], auth()->user()), __('finance_actions.payoutReverse.done')));
    }

    /** @return list<Action> */
    public static function payoutActions(): array
    {
        return [self::payoutApprove(), self::payoutProcess(), self::payoutComplete(), self::payoutFail(), self::payoutReverse()];
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    /** @return list<Field> */
    public static function periodFields(): array
    {
        return [
            DatePicker::make('period_start')->label(__('finance_actions.fields.period_start'))->required(),
            DatePicker::make('period_end')->label(__('finance_actions.fields.period_end'))->required()->afterOrEqual('period_start'),
            Select::make('currency')->label(__('finance_actions.fields.currency'))->options(FinanceOptions::currencies())->default('XAF')->required(),
        ];
    }

    /** @return array<string, string> the statement's adjustments awaiting a decision */
    private static function proposed(PartnerStatement $s): array
    {
        if (! in_array($s->status, CommissionStatementService::ADJUSTABLE, true)) {
            return [];
        }

        return PartnerStatementItem::where('partner_statement_id', $s->id)->where('entry_type', 'ADJUSTMENT')->where('adjustment_status', 'PROPOSED')->get()
            ->mapWithKeys(fn (PartnerStatementItem $i) => [$i->id => Money::display((int) $i->amount_minor, $i->currency).' · '.Str::limit((string) $i->reason, 60)])->all();
    }

    private static function item(PartnerStatement $s, string $id): PartnerStatementItem
    {
        $s = self::statement($s);

        return PartnerStatementItem::where('partner_statement_id', $s->id)->where('entry_type', 'ADJUSTMENT')->findOrFail($id);
    }

    private static function statement(PartnerStatement $s): PartnerStatement
    {
        return PartnerStatement::where('tenant_id', self::tenant())->findOrFail($s->id);
    }

    private static function payout(PartnerPayoutRequest $p): PartnerPayoutRequest
    {
        return PartnerPayoutRequest::where('tenant_id', self::tenant())->findOrFail($p->id);
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
