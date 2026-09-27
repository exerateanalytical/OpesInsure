<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Finance\Allocations\AllocationService;
use App\Application\Finance\Refunds\RefundEngine;
use App\Application\Payments\FinancialCaseService;
use App\Application\Payments\Retries\PaymentRetryService;
use App\Domain\Tenancy\TenantContext;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment detail-page actions (PaymentRequestResource view). Same permission / service as the API:
 *   allocate           POST payments/{p}/allocations                 payments.allocations.manage   AllocationService::allocate
 *   reverseAllocation  POST payment-allocation-runs/{run}/reverse    payments.allocations.reverse  AllocationService::reverse
 *   openChargeback     POST payments/{p}/chargebacks                 chargeback.manage             FinancialCaseService::openChargeback
 *   refundCandidate    POST payments/{p}/refund-candidates           refund.request                RefundEngine::candidate
 *   requestRefund      POST payments/{p}/refunds                     refund.request                FinancialCaseService::requestRefund
 *   retry              POST payments/{p}/retry                       (no route permission)         PaymentRetryService::retry
 * Idempotent API calls get a key generated once per opened modal, so a double submit replays instead of duplicating.
 * The record is always resolved inside the current tenant (as the controllers do).
 */
final class PaymentActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::allocate(), self::reverseAllocation(), self::refundCandidate(), self::requestRefund(), self::openChargeback(), self::retry()])
            ->label(__('workflow_actions.payment_group'))->icon('lucide-zap')->button();
    }

    public static function allocate(): Action
    {
        $p = 'payments.allocations.manage';

        return WorkflowAction::make('paymentAllocate', $p)->icon('lucide-split')
            ->visible(fn (PaymentIntentRecord $record) => in_array($record->status, AllocationService::ALLOCATABLE_PAYMENT_STATUSES, true))
            ->schema([
                Select::make('policy_id')->label(__('workflow_actions.fields.policy'))->searchable()
                    ->options(fn (PaymentIntentRecord $record) => Policy::where('tenant_id', $record->tenant_id)->when($record->proposal_id, fn ($q, $v) => $q->where('proposal_id', $v))
                        ->limit(100)->pluck('policy_number', 'id')->filter()),
                TextInput::make('amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->minValue(1),
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
            ])
            ->action(fn (Action $action, PaymentIntentRecord $record, array $data) => WorkflowAction::run($action, $p, fn () => app(AllocationService::class)->allocate(
                self::tenant(), self::payment($record)->id, filled($data['policy_id'] ?? null) ? $data['policy_id'] : null, [],
                filled($data['amount_minor'] ?? null) ? (int) $data['amount_minor'] : null, (string) ($data['idempotency_key'] ?? Str::uuid()), auth()->user())));
    }

    public static function reverseAllocation(): Action
    {
        $p = 'payments.allocations.reverse';
        $runs = fn (PaymentIntentRecord $r) => DB::table('payment_allocation_runs')->where('tenant_id', $r->tenant_id)->where('payment_intent_id', $r->id)->where('status', '!=', 'REVERSED');

        return WorkflowAction::make('paymentReverseAllocation', $p)->icon('lucide-undo-2')->color('danger')->requiresConfirmation()
            ->visible(fn (PaymentIntentRecord $record) => $runs($record)->exists())
            ->schema([
                Select::make('run_id')->label(__('workflow_actions.fields.allocation_run'))->required()
                    ->options(fn (PaymentIntentRecord $record) => $runs($record)->orderBy('created_at')->get()->mapWithKeys(fn ($x) => [$x->id => substr((string) $x->created_at, 0, 16).' · '.number_format((int) $x->allocated_minor).' '.$x->currency])),
                Select::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->options(WorkflowAction::options(AllocationService::REVERSAL_REASONS, 'allocation_reversal'))->required(),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->maxLength(255),
            ])
            ->action(function (Action $action, PaymentIntentRecord $record, array $data) use ($p, $runs) {
                abort_unless($runs(self::payment($record))->where('id', $data['run_id'])->exists(), 404);

                return WorkflowAction::run($action, $p, fn () => app(AllocationService::class)->reverse(self::tenant(), $data['run_id'], $data['reason_code'], $data['reason'], auth()->user()));
            });
    }

    public static function openChargeback(): Action
    {
        $p = 'chargeback.manage';

        return WorkflowAction::make('paymentOpenChargeback', $p)->icon('lucide-shield-alert')->requiresConfirmation()
            ->schema([
                TextInput::make('provider_case_reference')->label(__('workflow_actions.fields.provider_case_reference'))->required()->maxLength(160),
                TextInput::make('amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->minValue(1)->required(),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                DateTimePicker::make('response_due_at')->label(__('workflow_actions.fields.response_due_at')),
            ])
            ->action(fn (Action $action, PaymentIntentRecord $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FinancialCaseService::class)->openChargeback(self::payment($record), array_filter($data, fn ($v) => filled($v)))));
    }

    public static function refundCandidate(): Action
    {
        $p = 'refund.request';

        return WorkflowAction::make('paymentRefundCandidate', $p)->icon('lucide-receipt')
            ->visible(fn (PaymentIntentRecord $record) => $record->status === 'SUCCEEDED')
            ->schema([
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                TextInput::make('amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->minValue(1),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, PaymentIntentRecord $record, array $data) => WorkflowAction::run($action, $p, fn () => app(RefundEngine::class)->candidate(
                self::payment($record), 'manual', null, $data['reason_code'], auth()->user(),
                filled($data['amount_minor'] ?? null) ? (int) $data['amount_minor'] : null, filled($data['notes'] ?? null) ? $data['notes'] : null)));
    }

    public static function requestRefund(): Action
    {
        $p = 'refund.request';

        return WorkflowAction::make('paymentRequestRefund', $p)->icon('lucide-rotate-ccw')->requiresConfirmation()
            ->visible(fn (PaymentIntentRecord $record) => $record->status === 'SUCCEEDED')
            ->schema([
                TextInput::make('amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->minValue(1)->required(),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
                Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
            ])
            ->action(function (Action $action, PaymentIntentRecord $record, array $data) use ($p) {
                $d = ['amount_minor' => (int) $data['amount_minor'], 'reason_code' => $data['reason_code'], 'idempotency_key' => (string) ($data['idempotency_key'] ?? Str::uuid())]
                    + (filled($data['notes'] ?? null) ? ['notes' => $data['notes']] : []);

                return WorkflowAction::run($action, $p, fn () => app(FinancialCaseService::class)->requestRefund(self::payment($record), $d, auth()->user()));
            });
    }

    public static function retry(): Action
    {
        return WorkflowAction::make('paymentRetry', null)->icon('lucide-refresh-cw')->requiresConfirmation()
            ->visible(fn (PaymentIntentRecord $record) => $record->status === 'FAILED')
            ->action(fn (Action $action, PaymentIntentRecord $record) => WorkflowAction::run($action, null,
                fn () => app(PaymentRetryService::class)->retry(self::payment($record), auth()->user(), (string) Str::uuid())));
    }

    private static function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }

    private static function payment(PaymentIntentRecord $record): PaymentIntentRecord
    {
        return PaymentIntentRecord::where('tenant_id', self::tenant())->findOrFail($record->id);
    }
}
