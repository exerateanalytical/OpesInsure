<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Finance\Clearing\ClearingBatch;
use App\Application\Finance\Clearing\ClearingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\PaymentIntentRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Mobile-money clearing actions (ClearingBatchResource), REQ-PAY-011. Same permission / service as the API:
 *   open       POST clearing/batches                  clearing.manage     ClearingService::open      (list header)
 *   attach     POST clearing/batches/{b}/items        clearing.manage     ClearingService::attach
 *   settle     POST clearing/batches/{b}/settle       clearing.manage     ClearingService::settle
 *   reconcile  POST clearing/batches/{b}/reconcile    clearing.reconcile  ClearingService::reconcile (settler ≠ reconciler, enforced by the service)
 */
final class ClearingActions
{
    public static function open(): Action
    {
        $p = 'clearing.manage';

        return WorkflowAction::make('clearingOpen', $p)->icon('lucide-plus')
            ->schema([
                TextInput::make('provider')->label(__('workflow_actions.fields.provider'))->required()->maxLength(32),
                TextInput::make('settlement_reference')->label(__('workflow_actions.fields.settlement_reference'))->required()->maxLength(128),
                DatePicker::make('settlement_date')->label(__('workflow_actions.fields.settlement_date'))->required(),
                TextInput::make('currency')->label(__('workflow_actions.fields.currency'))->required()->length(3)->default('XAF'),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ClearingService::class)->open(self::tenant(), array_filter($data, fn ($v) => filled($v)), auth()->user())));
    }

    public static function attach(): Action
    {
        $p = 'clearing.manage';

        return WorkflowAction::make('clearingAttach', $p)->icon('lucide-link')
            ->visible(fn (ClearingBatch $record) => $record->status === 'OPEN')
            ->schema([
                Select::make('payment_ids')->label(__('workflow_actions.fields.payments'))->multiple()->required()->searchable()
                    ->options(fn (ClearingBatch $record) => PaymentIntentRecord::where(['tenant_id' => $record->tenant_id, 'provider' => $record->provider, 'currency' => $record->currency, 'status' => 'SUCCEEDED'])
                        ->whereNotIn('id', DB::table('mobile_money_clearing_items')->select('payment_intent_id'))->latest()->limit(500)->get()
                        ->mapWithKeys(fn ($x) => [$x->id => ($x->provider_reference ?: substr($x->id, 0, 8)).' · '.number_format((int) $x->amount_minor).' '.$x->currency])),
            ])
            ->action(fn (Action $action, ClearingBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ClearingService::class)->attach(self::batch($record), array_values($data['payment_ids']), auth()->user())));
    }

    public static function settle(): Action
    {
        $p = 'clearing.manage';

        return WorkflowAction::make('clearingSettle', $p)->icon('lucide-landmark')->requiresConfirmation()
            ->visible(fn (ClearingBatch $record) => $record->status === 'OPEN')
            ->schema([
                TextInput::make('settled_minor')->label(__('workflow_actions.fields.settled_minor'))->integer()->minValue(0)->required(),
                TextInput::make('fee_minor')->label(__('workflow_actions.fields.fee_minor'))->integer()->minValue(0),
                TextInput::make('bank_reference')->label(__('workflow_actions.fields.bank_reference'))->required()->maxLength(128),
            ])
            ->action(fn (Action $action, ClearingBatch $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ClearingService::class)->settle(self::batch($record),
                ['settled_minor' => (int) $data['settled_minor'], 'fee_minor' => (int) ($data['fee_minor'] ?? 0), 'bank_reference' => $data['bank_reference']], auth()->user())));
    }

    public static function reconcile(): Action
    {
        $p = 'clearing.reconcile';

        return WorkflowAction::make('clearingReconcile', $p)->icon('lucide-scale')->requiresConfirmation()
            ->visible(fn (ClearingBatch $record) => in_array($record->status, ['SETTLED', 'VARIANCE'], true))
            ->schema([Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000)])
            ->action(fn (Action $action, ClearingBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ClearingService::class)->reconcile(self::batch($record), auth()->user(), filled($data['notes'] ?? null) ? $data['notes'] : null)));
    }

    private static function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }

    private static function batch(ClearingBatch $record): ClearingBatch
    {
        return ClearingBatch::where('tenant_id', self::tenant())->findOrFail($record->id);
    }
}
