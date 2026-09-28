<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Settlements\SettlementService;
use App\Domain\Tenancy\TenantContext;
use App\Models\SettlementBatch;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Carrier settlements (UI coverage batch 10). One settlement_batches table, two lifecycles, both in SettlementService:
 *  - POLICY basis (carrier-settlements/*, DRAFT → APPROVED → SUBMITTED → PAID | FAILED, PAID → REVERSED);
 *  - OBLIGATIONS basis (broker-settlements/*, DRAFT → CALCULATED → REVIEW → APPROVED → PROCESSING → SETTLED → RECONCILED).
 * Each action is offered only on its own basis and status. Maker-checker (approver ≠ preparer) stays in the service.
 *   carrierSettlementPrepare  POST carrier-settlements                    settlement.prepare    SettlementService::prepare
 *   carrierSettlementApprove  POST carrier-settlements/{s}/approve        settlement.approve    SettlementService::approveAny
 *   carrierSettlementSubmit   POST carrier-settlements/{s}/submit         settlement.submit     SettlementService::submitAny
 *   carrierSettlementPaid     POST carrier-settlements/{s}/paid           settlement.confirm    SettlementService::paidAny
 *   carrierSettlementFail     POST carrier-settlements/{s}/fail           settlement.confirm    SettlementService::failAny
 *   carrierSettlementReverse  POST carrier-settlements/{s}/reverse        settlement.reverse    SettlementService::reverseAny
 *   ledgerSettlementDraft     POST broker-settlements                     settlement.prepare    SettlementService::draft
 *   ledgerSettlementCalculate POST broker-settlements/{b}/calculate       settlement.prepare    SettlementService::calculate
 *   ledgerSettlementReview    POST broker-settlements/{b}/review          settlement.prepare    SettlementService::submitForReview
 *   ledgerSettlementCancel    POST broker-settlements/{b}/cancel          settlement.prepare    SettlementService::cancel
 *   ledgerSettlementApprove   POST broker-settlements/{b}/approve         settlement.approve    SettlementService::approve
 *   ledgerSettlementReject    POST broker-settlements/{b}/reject          settlement.approve    SettlementService::reject
 *   ledgerSettlementProcess   POST broker-settlements/{b}/process         settlement.submit     SettlementService::process
 *   ledgerSettlementFail      POST broker-settlements/{b}/fail            settlement.confirm    SettlementService::failProcessing
 *   ledgerSettlementSettle    POST broker-settlements/{b}/settle          settlement.confirm    SettlementService::settle
 *   ledgerSettlementReconcile POST broker-settlements/{b}/reconcile       settlement.reconcile  SettlementService::reconcile
 */
final class SettlementActions
{
    private const L = 'finance_actions';

    // ---- POLICY basis (carrier-settlements) --------------------------------------------------------------------

    public static function carrierSettlementPrepare(): Action
    {
        $p = 'settlement.prepare';

        return WorkflowAction::make('carrierSettlementPrepare', $p, self::L)->icon('lucide-plus')
            ->schema([
                Select::make('carrier_id')->label(__('finance_actions.fields.carrier'))->options(fn () => FinanceOptions::carriers())->searchable()->required(),
                ...StatementPayoutActions::periodFields(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(SettlementService::class)->prepare([
                'carrier_id' => $data['carrier_id'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'currency' => $data['currency'],
                'idempotency_key' => 'web-'.Str::uuid(), 'tenant_id' => self::tenant(),
            ], auth()->user()), __('finance_actions.carrierSettlementPrepare.done')));
    }

    public static function carrierSettlementApprove(): Action
    {
        $p = 'settlement.approve';

        return WorkflowAction::make('carrierSettlementApprove', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (SettlementBatch $record) => self::policyBasis($record) && $record->status === 'DRAFT')
            ->schema([Textarea::make('notes')->label(__('finance_actions.fields.approval_notes'))->minLength(20)->maxLength(2000)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->approveAny(self::batch($record), auth()->user(), filled($data['notes'] ?? null) ? $data['notes'] : null), __('finance_actions.carrierSettlementApprove.done')));
    }

    public static function carrierSettlementSubmit(): Action
    {
        $p = 'settlement.submit';

        return WorkflowAction::make('carrierSettlementSubmit', $p, self::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (SettlementBatch $record) => self::policyBasis($record) && $record->status === 'APPROVED')
            ->action(fn (Action $action, SettlementBatch $record) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->submitAny(self::batch($record), auth()->user()), __('finance_actions.carrierSettlementSubmit.done')));
    }

    public static function carrierSettlementPaid(): Action
    {
        $p = 'settlement.confirm';

        return WorkflowAction::make('carrierSettlementPaid', $p, self::L)->icon('lucide-circle-check')->color('success')
            ->visible(fn (SettlementBatch $record) => self::policyBasis($record) && $record->status === 'SUBMITTED')
            ->schema([TextInput::make('bank_reference')->label(__('finance_actions.fields.bank_reference'))->required()->maxLength(120)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->paidAny(self::batch($record), $data['bank_reference'], auth()->user()), __('finance_actions.carrierSettlementPaid.done')));
    }

    public static function carrierSettlementFail(): Action
    {
        $p = 'settlement.confirm';

        return WorkflowAction::make('carrierSettlementFail', $p, self::L)->icon('lucide-triangle-alert')->color('danger')
            ->visible(fn (SettlementBatch $record) => self::policyBasis($record) && $record->status === 'SUBMITTED')
            ->schema([Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->maxLength(1000)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->failAny(self::batch($record), $data['reason'], auth()->user()), __('finance_actions.carrierSettlementFail.done')));
    }

    public static function carrierSettlementReverse(): Action
    {
        $p = 'settlement.reverse';

        return WorkflowAction::make('carrierSettlementReverse', $p, self::L)->icon('lucide-undo-2')->color('danger')->requiresConfirmation()
            ->visible(fn (SettlementBatch $record) => self::policyBasis($record) && $record->status === 'PAID')
            ->schema([TextInput::make('reason_code')->label(__('finance_actions.fields.reason_code'))->required()->maxLength(80)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->reverseAny(self::batch($record), $data['reason_code'], auth()->user()), __('finance_actions.carrierSettlementReverse.done')));
    }

    // ---- OBLIGATIONS basis (broker-settlements) ----------------------------------------------------------------

    public static function ledgerSettlementDraft(): Action
    {
        $p = 'settlement.prepare';

        return WorkflowAction::make('ledgerSettlementDraft', $p, self::L)->icon('lucide-file-plus')
            ->schema([
                Select::make('carrier_id')->label(__('finance_actions.fields.carrier'))->options(fn () => FinanceOptions::carriers())->searchable()->required(),
                Select::make('partner_id')->label(__('finance_actions.fields.partner_optional'))->options(fn () => FinanceOptions::partners())->searchable(),
                ...StatementPayoutActions::periodFields(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(SettlementService::class)->draft([
                'carrier_id' => $data['carrier_id'], 'partner_id' => filled($data['partner_id'] ?? null) ? $data['partner_id'] : null,
                'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'currency' => strtoupper($data['currency']),
                'idempotency_key' => 'web-'.Str::uuid(), 'tenant_id' => self::tenant(),
            ], auth()->user()), __('finance_actions.ledgerSettlementDraft.done')));
    }

    public static function ledgerSettlementCalculate(): Action
    {
        $p = 'settlement.prepare';

        return WorkflowAction::make('ledgerSettlementCalculate', $p, self::L)->icon('lucide-calculator')->requiresConfirmation()
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && in_array($record->status, ['DRAFT', 'CALCULATED'], true))
            ->action(fn (Action $action, SettlementBatch $record) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->calculate(self::batch($record), auth()->user()), __('finance_actions.ledgerSettlementCalculate.done')));
    }

    public static function ledgerSettlementReview(): Action
    {
        $p = 'settlement.prepare';

        return WorkflowAction::make('ledgerSettlementReview', $p, self::L)->icon('lucide-eye')->requiresConfirmation()
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && $record->status === 'CALCULATED')
            ->action(fn (Action $action, SettlementBatch $record) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->submitForReview(self::batch($record), auth()->user()), __('finance_actions.ledgerSettlementReview.done')));
    }

    public static function ledgerSettlementCancel(): Action
    {
        $p = 'settlement.prepare';

        return WorkflowAction::make('ledgerSettlementCancel', $p, self::L)->icon('lucide-ban')->color('danger')
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && in_array($record->status, ['DRAFT', 'CALCULATED', 'REVIEW'], true))
            ->schema([Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->minLength(5)->maxLength(1000)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->cancel(self::batch($record), auth()->user(), $data['reason']), __('finance_actions.ledgerSettlementCancel.done')));
    }

    public static function ledgerSettlementApprove(): Action
    {
        $p = 'settlement.approve';

        return WorkflowAction::make('ledgerSettlementApprove', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && $record->status === 'REVIEW')
            ->schema([Textarea::make('notes')->label(__('finance_actions.fields.approval_notes'))->minLength(20)->maxLength(2000)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->approve(self::batch($record), auth()->user(), filled($data['notes'] ?? null) ? $data['notes'] : null), __('finance_actions.ledgerSettlementApprove.done')));
    }

    public static function ledgerSettlementReject(): Action
    {
        $p = 'settlement.approve';

        return WorkflowAction::make('ledgerSettlementReject', $p, self::L)->icon('lucide-circle-x')->color('danger')
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && $record->status === 'REVIEW')
            ->schema([Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->minLength(5)->maxLength(1000)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->reject(self::batch($record), auth()->user(), $data['reason']), __('finance_actions.ledgerSettlementReject.done')));
    }

    public static function ledgerSettlementProcess(): Action
    {
        $p = 'settlement.submit';

        return WorkflowAction::make('ledgerSettlementProcess', $p, self::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && $record->status === 'APPROVED')
            ->action(fn (Action $action, SettlementBatch $record) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->process(self::batch($record), auth()->user()), __('finance_actions.ledgerSettlementProcess.done')));
    }

    public static function ledgerSettlementFail(): Action
    {
        $p = 'settlement.confirm';

        return WorkflowAction::make('ledgerSettlementFail', $p, self::L)->icon('lucide-triangle-alert')->color('danger')
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && $record->status === 'PROCESSING')
            ->schema([Textarea::make('reason')->label(__('finance_actions.fields.reason'))->required()->maxLength(1000)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->failProcessing(self::batch($record), $data['reason'], auth()->user()), __('finance_actions.ledgerSettlementFail.done')));
    }

    public static function ledgerSettlementSettle(): Action
    {
        $p = 'settlement.confirm';

        return WorkflowAction::make('ledgerSettlementSettle', $p, self::L)->icon('lucide-circle-check')->color('success')
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && $record->status === 'PROCESSING')
            ->schema([TextInput::make('bank_reference')->label(__('finance_actions.fields.bank_reference'))->required()->maxLength(120)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->settle(self::batch($record), $data['bank_reference'], auth()->user()), __('finance_actions.ledgerSettlementSettle.done')));
    }

    public static function ledgerSettlementReconcile(): Action
    {
        $p = 'settlement.reconcile';

        return WorkflowAction::make('ledgerSettlementReconcile', $p, self::L)->icon('lucide-scale')
            ->visible(fn (SettlementBatch $record) => self::ledger($record) && $record->status === 'SETTLED')
            ->schema([TextInput::make('reference')->label(__('finance_actions.fields.reconciliation_reference'))->required()->maxLength(120)])
            ->action(fn (Action $action, SettlementBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(SettlementService::class)->reconcile(self::batch($record), $data['reference'], auth()->user()), __('finance_actions.ledgerSettlementReconcile.done')));
    }

    /** @return list<Action> */
    public static function recordActions(): array
    {
        return [
            self::carrierSettlementApprove(), self::carrierSettlementSubmit(), self::carrierSettlementPaid(), self::carrierSettlementFail(), self::carrierSettlementReverse(),
            self::ledgerSettlementCalculate(), self::ledgerSettlementReview(), self::ledgerSettlementApprove(), self::ledgerSettlementReject(), self::ledgerSettlementProcess(),
            self::ledgerSettlementSettle(), self::ledgerSettlementFail(), self::ledgerSettlementReconcile(), self::ledgerSettlementCancel(),
        ];
    }

    private static function policyBasis(SettlementBatch $b): bool
    {
        return ($b->calculation_basis ?? SettlementService::BASIS_POLICY) === SettlementService::BASIS_POLICY;
    }

    private static function ledger(SettlementBatch $b): bool
    {
        return ($b->calculation_basis ?? SettlementService::BASIS_POLICY) === SettlementService::BASIS_OBLIGATIONS;
    }

    /** Same tenant-scoped read as the API (SettlementService::find). */
    private static function batch(SettlementBatch $b): SettlementBatch
    {
        return app(SettlementService::class)->find(self::tenant(), $b->id);
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
