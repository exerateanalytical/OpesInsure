<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Finance\Refunds\RefundEngine;
use App\Domain\Tenancy\TenantContext;
use App\Models\Refund;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Refund detail-page actions (RefundResource view), WF-063 maker-checker enforced by RefundEngine:
 *   calculate  POST refunds/{r}/calculate  refund.request    RefundEngine::calculate
 *   review     POST refunds/{r}/review     refund.review     RefundEngine::review
 *   approve    POST refunds/{r}/approve    refund.approve    RefundEngine::approve
 *   reject     POST refunds/{r}/reject     refund.approve    RefundEngine::reject
 *   pay        POST refunds/{r}/pay        refund.pay        RefundEngine::pay
 *   reconcile  POST refunds/{r}/reconcile  refund.reconcile  RefundEngine::reconcile
 */
final class RefundActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::calculate(), self::review(), self::approve(), self::reject(), self::pay(), self::reconcile()])
            ->label(__('workflow_actions.refund_group'))->icon('lucide-zap')->button();
    }

    public static function calculate(): Action
    {
        $p = 'refund.request';

        return WorkflowAction::make('refundCalculate', $p)->icon('lucide-calculator')
            ->visible(fn (Refund $record) => in_array($record->status, ['CANDIDATE', 'CALCULATED'], true))
            ->schema([
                TextInput::make('gross_minor')->label(__('workflow_actions.fields.gross_minor'))->integer()->minValue(1),
                Repeater::make('deductions')->label(__('workflow_actions.fields.deductions'))->defaultItems(0)->maxItems(20)->columns(2)->schema([
                    TextInput::make('code')->label(__('workflow_actions.fields.code'))->required()->maxLength(40),
                    TextInput::make('amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->minValue(0)->required(),
                ]),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(function (Action $action, Refund $record, array $data) use ($p) {
                $d = array_filter(['gross_minor' => filled($data['gross_minor'] ?? null) ? (int) $data['gross_minor'] : null,
                    'deductions' => array_values(array_map(fn ($x) => ['code' => $x['code'], 'amount_minor' => (int) $x['amount_minor']], $data['deductions'] ?? [])) ?: null,
                    'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null], fn ($v) => $v !== null);

                return WorkflowAction::run($action, $p, fn () => app(RefundEngine::class)->calculate(self::refund($record), $d, auth()->user()));
            });
    }

    public static function review(): Action
    {
        $p = 'refund.review';

        return WorkflowAction::make('refundReview', $p)->icon('lucide-eye')->requiresConfirmation()
            ->visible(fn (Refund $record) => $record->status === 'CALCULATED')
            ->schema([Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000)])
            ->action(fn (Action $action, Refund $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(RefundEngine::class)->review(self::refund($record), auth()->user(), filled($data['notes'] ?? null) ? $data['notes'] : null)));
    }

    public static function approve(): Action
    {
        $p = 'refund.approve';

        return WorkflowAction::make('refundApprove', $p)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (Refund $record) => $record->status === 'REQUESTED')
            ->action(fn (Action $action, Refund $record) => WorkflowAction::run($action, $p, fn () => app(RefundEngine::class)->approve(self::refund($record), auth()->user())));
    }

    public static function reject(): Action
    {
        $p = 'refund.approve';

        return WorkflowAction::make('refundReject', $p)->icon('lucide-circle-x')->color('danger')->requiresConfirmation()
            ->visible(fn (Refund $record) => in_array($record->status, ['CANDIDATE', 'CALCULATED', 'REQUESTED'], true))
            ->schema([Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, Refund $record, array $data) => WorkflowAction::run($action, $p, fn () => app(RefundEngine::class)->reject(self::refund($record), auth()->user(), $data['reason'])));
    }

    public static function pay(): Action
    {
        $p = 'refund.pay';

        return WorkflowAction::make('refundPay', $p)->icon('lucide-banknote')->requiresConfirmation()
            ->visible(fn (Refund $record) => $record->status === 'APPROVED')
            ->schema([
                Select::make('payout_method')->label(__('workflow_actions.fields.payout_method'))->options(WorkflowAction::options(RefundEngine::PAYOUT_METHODS, 'payout_method'))->required(),
                TextInput::make('provider_reference')->label(__('workflow_actions.fields.provider_reference'))->required()->maxLength(255),
            ])
            ->action(fn (Action $action, Refund $record, array $data) => WorkflowAction::run($action, $p, fn () => app(RefundEngine::class)->pay(self::refund($record), $data, auth()->user())));
    }

    public static function reconcile(): Action
    {
        $p = 'refund.reconcile';

        return WorkflowAction::make('refundReconcile', $p)->icon('lucide-scale')->requiresConfirmation()
            ->visible(fn (Refund $record) => $record->status === 'PAID')
            ->schema([TextInput::make('bank_reference')->label(__('workflow_actions.fields.bank_reference'))->required()->maxLength(128)])
            ->action(fn (Action $action, Refund $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(RefundEngine::class)->reconcile(self::refund($record), auth()->user(), $data['bank_reference'])));
    }

    private static function refund(Refund $record): Refund
    {
        return Refund::where('tenant_id', app(TenantContext::class)->id())->findOrFail($record->id);
    }
}
