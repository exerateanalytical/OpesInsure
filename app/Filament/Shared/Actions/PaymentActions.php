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

    /**
     * Premium collection from the proposal page (owner decision 2026-09-29, /broker writable):
     *   POST payments (no route permission) + POST payments/{p}/initiate — PaymentRequestService::create +
     *   PaymentInitiationService::initiate, with the controller's checks (partner book, fake provider only where allowed).
     * The customer then authorises on their phone; the provider webhook reconciles it and opens the carrier issuance
     * request automatically (PaymentIssuanceTrigger).
     */
    public static function requestPremium(): Action
    {
        return WorkflowAction::make('brokerRequestPremium', null, 'broker_portal_sales')->icon('lucide-smartphone')
            ->visible(fn (\App\Models\Proposal $record) => $record->status === 'PAYMENT_PENDING'
                && ! PaymentIntentRecord::where(['proposal_id' => $record->id, 'status' => 'SUCCEEDED'])->exists())
            ->fillForm(fn (\App\Models\Proposal $record) => ['payer_phone_e164' => DB::table('party_contacts')->where(['party_id' => $record->party_id, 'type' => 'PHONE'])->value('normalized_value')])
            ->schema([
                Select::make('provider')->label(__('broker_portal_sales.fields.provider'))->required()->options(fn () => self::providerOptions()),
                TextInput::make('payer_phone_e164')->label(__('broker_portal_sales.fields.payer_phone'))->required()->regex('/^\+[1-9]\d{7,14}$/'),
            ])
            ->action(fn (Action $action, \App\Models\Proposal $record, array $data) => WorkflowAction::run($action, null, function () use ($action, $record, $data) {
                app(\App\Application\Partners\PartnerBook::class)->assertInBook(auth()->user(), $record->party_id);
                \App\Application\Demo\DemoPersonas::assertProviderAllowed($data['provider'], auth()->user());
                // Owner rule 2026-09-30: the premium is requested only once the CUSTOMER accepted the contract terms
                // themselves. Until then the customer gets the web acceptance link by SMS (to their own phone on file,
                // never the payer phone typed here) and no payment is created.
                $links = app(\App\Application\Underwriting\Proposal\ProposalAcceptanceLinks::class);
                if (! $links->customerAccepted($record->refresh())) {
                    $s = $links->send($record, null, auth()->user());
                    $key = match (true) {
                        $s['status'] === 'NO_PHONE' => 'link_no_phone_broker', $s['status'] === 'SMS_FAILED' => 'link_failed_broker',
                        $s['sent_now'] => 'link_sent_broker', default => 'link_recent_broker',
                    };
                    \Filament\Notifications\Notification::make()->warning()->persistent()->title(__("acceptance.{$key}", ['phone' => $s['phone_masked'] ?? '']))->send();
                    $action->halt();
                }
                $intent = app(\App\Application\Payments\PaymentRequestService::class)->create(\App\Models\Tenant::findOrFail(self::tenant()), $record->refresh(),
                    ['provider' => $data['provider'], 'payer_phone_e164' => $data['payer_phone_e164'], 'idempotency_key' => (string) Str::uuid()], auth()->user());

                return in_array($intent->status, ['CREATED', 'PENDING_CUSTOMER', 'FAILED'], true) && $intent->provider_reference === null
                    ? app(\App\Application\Payments\PaymentInitiationService::class)->initiate($intent) : $intent;
            }, __('broker_portal_sales.brokerRequestPremium.done')));
    }

    /** Receipt of the proposal's confirmed premium payment: the signed PDF link GET mobile/payments/{p}/receipt returns. */
    public static function premiumReceipt(): Action
    {
        $paid = fn (\App\Models\Proposal $p) => PaymentIntentRecord::where(['tenant_id' => $p->tenant_id, 'proposal_id' => $p->id, 'status' => 'SUCCEEDED'])->latest('updated_at')->first();

        return WorkflowAction::make('brokerPremiumReceipt', null, 'broker_portal_sales')->icon('lucide-receipt')
            ->visible(fn (\App\Models\Proposal $record) => $paid($record) !== null)
            ->url(fn (\App\Models\Proposal $record) => ($i = $paid($record)) ? \Illuminate\Support\Facades\URL::temporarySignedRoute('mobile.payments.receipt.pdf',
                now()->addMinutes((int) config('lifecycle.download_ttl_minutes', 30)), ['payment' => $i->id]) : null, shouldOpenInNewTab: true);
    }

    /** Providers with an ACTIVE connection for this tenant (as PaymentInitiationService requires), plus the test provider where allowed. */
    private static function providerOptions(): array
    {
        $live = \App\Models\PaymentProviderConnection::where('status', 'ACTIVE')->where('environment', 'PRODUCTION')->where(fn ($q) => $q->where('tenant_id', self::tenant())->orWhereNull('tenant_id'))
            ->distinct()->pluck('provider')->all();
        if (\App\Application\Demo\DemoPersonas::fakeProviderAllowed(auth()->user())) {
            $live[] = 'fake';
        }

        return collect($live)->unique()->mapWithKeys(fn ($v) => [$v => \Illuminate\Support\Facades\Lang::has("broker_portal_sales.providers.{$v}") ? __("broker_portal_sales.providers.{$v}") : $v])->all();
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
