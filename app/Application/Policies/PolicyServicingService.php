<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Payments\FinancialCaseService;
use App\Application\Shared\CanonicalJson;
use App\Domain\Policies\PolicyStateMachine;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PolicyServicingService
{
    public function __construct(
        private PolicyStateMachine $machine,
        private CancellationCalculator $cancellation,
        private FinancialCaseService $financialCases,
        private CanonicalJson $json,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {}

    public function request(Policy $policy, array $data, User $actor): PolicyTransaction
    {
        return DB::transaction(function () use ($policy, $data, $actor): PolicyTransaction {
            $policy = Policy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $reinstatable = $data['type'] === 'REINSTATEMENT'
                && in_array($policy->status, ['SUSPENDED', 'EXPIRED'], true);

            if ($policy->status !== 'ACTIVE' && ! $reinstatable) {
                throw ValidationException::withMessages(['status' => __('wave5.policy_not_serviceable')]);
            }

            $effectiveAt = CarbonImmutable::parse($data['effective_at']);
            if ($data['type'] !== 'REINSTATEMENT'
                && ($effectiveAt->lessThan($policy->coverage_starts_at) || $effectiveAt->greaterThan($policy->coverage_ends_at))) {
                throw ValidationException::withMessages(['effective_at' => __('wave5.service_effective_date_invalid')]);
            }

            if (PolicyTransaction::where('policy_id', $policy->id)
                ->whereIn('status', ['PAYMENT_PENDING', 'PENDING_APPROVAL'])
                ->exists()) {
                throw ValidationException::withMessages(['status' => __('wave5.service_already_pending')]);
            }

            $calculation = ['refund_minor' => 0, 'rule_id' => null];
            if ($data['type'] === 'CANCELLATION') {
                $calculation = $this->cancellation->calculate($policy, $effectiveAt);
            }

            $termsAfter = array_replace_recursive($policy->terms_snapshot, $data['requested_changes'] ?? []);
            $premiumDelta = $data['type'] === 'CANCELLATION' ? 0 : (int) $data['premium_delta_minor'];
            $status = $premiumDelta > 0 ? 'PAYMENT_PENDING' : 'PENDING_APPROVAL';

            $transaction = PolicyTransaction::create([
                'tenant_id' => $policy->tenant_id,
                'policy_id' => $policy->id,
                'type' => $data['type'],
                'status' => $status,
                'transaction_number' => 'PTX-'.strtoupper(Str::random(10)),
                'effective_at' => $data['effective_at'],
                'requested_changes' => $data['requested_changes'] ?? [],
                'terms_before' => $policy->terms_snapshot,
                'terms_after' => $termsAfter,
                'premium_delta_minor' => $premiumDelta,
                'currency' => $policy->currency,
                'reason_code' => $data['reason_code'],
                'notes' => $data['notes'] ?? null,
                'requested_by' => $actor->id,
                'cancellation_rule_version_id' => $calculation['rule_id'],
                'refund_minor' => $calculation['refund_minor'],
                'terms_hash' => $this->json->hash($termsAfter),
            ]);

            $pendingStatus = match ($data['type']) {
                'ENDORSEMENT' => 'ENDORSEMENT_PENDING',
                'CANCELLATION' => 'CANCELLATION_PENDING',
                default => $policy->status,
            };

            if ($pendingStatus !== $policy->status) {
                $this->machine->assert($policy->status, $pendingStatus);
                $fromStatus = $policy->status;
                $policy->update(['status' => $pendingStatus]);
                $this->policyHistory($policy, $fromStatus, $pendingStatus, 'SERVICE_REQUESTED', $actor, $transaction);
            }

            $this->event($transaction, null, $status, 'SERVICE_REQUESTED', $actor);
            $this->audit->record('policy.service.requested', 'policy_transaction', $transaction->id, [
                'policy_id' => $policy->id,
                'type' => $transaction->type,
            ], $transaction->reason_code);

            return $transaction;
        });
    }

    public function linkPayment(PolicyTransaction $transaction, PaymentIntentRecord $payment): PolicyTransaction
    {
        if ($transaction->status !== 'PAYMENT_PENDING'
            || $payment->tenant_id !== $transaction->tenant_id
            || $transaction->payment_intent_id !== $payment->id
            || $payment->status !== 'SUCCEEDED'
            || ! $payment->reconciled_at
            || $payment->currency !== $transaction->currency
            || $payment->amount_minor !== $transaction->premium_delta_minor) {
            throw ValidationException::withMessages([
                'payment_intent_id' => __('wave5.service_payment_invalid'),
            ]);
        }

        $fromStatus = $transaction->status;
        $transaction->update(['payment_intent_id' => $payment->id, 'status' => 'PENDING_APPROVAL']);
        $this->event($transaction, $fromStatus, 'PENDING_APPROVAL', 'PAYMENT_RECONCILED', null);

        return $transaction->refresh();
    }

    public function requestPayment(PolicyTransaction $transaction, array $data, User $actor): PaymentIntentRecord
    {
        if (! array_key_exists($data['provider'], config('payments.providers', []))
            || ! preg_match('/^\+[1-9]\d{7,14}$/', $data['payer_phone_e164'])
            || strlen($data['idempotency_key']) < 16) {
            throw ValidationException::withMessages(['payment' => __('wave5.service_payment_request_invalid')]);
        }
        if ($transaction->status !== 'PAYMENT_PENDING' || $transaction->premium_delta_minor <= 0) {
            throw ValidationException::withMessages(['status' => __('wave5.service_payment_not_required')]);
        }

        if ($existing = PaymentIntentRecord::where([
            'tenant_id' => $transaction->tenant_id,
            'idempotency_key' => $data['idempotency_key'],
        ])->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($transaction, $data, $actor): PaymentIntentRecord {
            $transaction = PolicyTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($transaction->status !== 'PAYMENT_PENDING' || $transaction->payment_intent_id) {
                throw ValidationException::withMessages(['status' => __('wave5.service_payment_not_required')]);
            }

            $payment = PaymentIntentRecord::create([
                'tenant_id' => $transaction->tenant_id,
                'proposal_id' => $transaction->policy->proposal_id,
                'provider' => $data['provider'],
                'payer_phone_e164' => $data['payer_phone_e164'],
                'amount_minor' => $transaction->premium_delta_minor,
                'currency' => $transaction->currency,
                'status' => 'PENDING_CUSTOMER',
                'idempotency_key' => $data['idempotency_key'],
                'provider_snapshot' => ['policy_transaction_id' => $transaction->id],
                'requested_by' => $actor->id,
                'expires_at' => now()->addMinutes(15),
                'customer_prompted_at' => now(),
                'request_channel' => 'WEB',
            ]);
            $transaction->update(['payment_intent_id' => $payment->id]);

            $this->audit->record('policy.service.payment_requested', 'policy_transaction', $transaction->id, [
                'payment_intent_id' => $payment->id,
                'amount_minor' => $payment->amount_minor,
            ]);
            $this->outbox->record('policy.service.payment_requested', 'policy_transaction', $transaction->id, [
                'policy_transaction_id' => $transaction->id,
                'payment_intent_id' => $payment->id,
            ]);

            return $payment;
        });
    }

    public function approve(PolicyTransaction $transaction, User $actor): Policy
    {
        return DB::transaction(function () use ($transaction, $actor): Policy {
            $transaction = PolicyTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['status' => __('wave5.transaction_not_pending')]);
            }
            if ($transaction->requested_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
            }

            $policy = Policy::whereKey($transaction->policy_id)->lockForUpdate()->firstOrFail();
            if ($this->json->hash($transaction->terms_after) !== $transaction->terms_hash) {
                throw ValidationException::withMessages(['terms' => __('wave5.terms_changed')]);
            }

            $toStatus = match ($transaction->type) {
                'CANCELLATION' => 'CANCELLED',
                'REINSTATEMENT', 'ENDORSEMENT' => 'ACTIVE',
                default => throw ValidationException::withMessages(['type' => __('wave5.transaction_type_invalid')]),
            };

            if ($policy->status !== $toStatus) {
                $this->machine->assert($policy->status, $toStatus);
            }

            $fromStatus = $policy->status;
            $transaction->update([
                'status' => 'APPROVED',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $policy->update([
                'status' => $toStatus,
                'terms_snapshot' => $transaction->terms_after,
                'premium_minor' => max(0, $policy->premium_minor + $transaction->premium_delta_minor),
                'terms_hash' => $transaction->terms_hash,
                'version' => $policy->version + 1,
            ]);

            $this->policyHistory($policy, $fromStatus, $toStatus, $transaction->reason_code, $actor, $transaction);
            $this->event($transaction, 'PENDING_APPROVAL', 'APPROVED', 'APPROVED', $actor);

            if ($transaction->type === 'CANCELLATION' && $transaction->refund_minor > 0) {
                $payment = PaymentIntentRecord::findOrFail($policy->payment_intent_id);
                $this->financialCases->requestRefund($payment, [
                    'amount_minor' => $transaction->refund_minor,
                    'reason_code' => 'POLICY_CANCELLATION',
                    'notes' => 'Generated from '.$transaction->transaction_number,
                    'idempotency_key' => 'policy-cancellation-'.$transaction->id,
                ], $actor);
            }

            $this->audit->record('policy.service.approved', 'policy_transaction', $transaction->id, [
                'policy_id' => $policy->id,
                'to_status' => $toStatus,
                'refund_minor' => $transaction->refund_minor,
            ], $transaction->reason_code);
            $this->outbox->record('policy.service.approved', 'policy_transaction', $transaction->id, [
                'policy_id' => $policy->id,
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
            ]);

            // Document engine: avenant / cancellation / reinstatement pack (savepoint; never blocks servicing).
            app(\App\Application\Documents\Engine\DocumentEngine::class)->fireQuietly($transaction->type.'_ISSUED', $policy->refresh(), ['transaction' => $transaction->refresh()], $actor);

            return $policy->refresh();
        });
    }

    public function reject(PolicyTransaction $transaction, string $reason, User $actor): PolicyTransaction
    {
        return DB::transaction(function () use ($transaction, $reason, $actor): PolicyTransaction {
            $transaction = PolicyTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if (! in_array($transaction->status, ['PAYMENT_PENDING', 'PENDING_APPROVAL'], true)) {
                throw ValidationException::withMessages(['status' => __('wave5.transaction_not_pending')]);
            }
            if ($transaction->requested_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
            }

            $policy = Policy::whereKey($transaction->policy_id)->lockForUpdate()->firstOrFail();
            $fromTransactionStatus = $transaction->status;
            $transaction->update([
                'status' => 'REJECTED',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'notes' => trim(($transaction->notes ? $transaction->notes."\n" : '').'Rejection: '.$reason),
            ]);

            if (in_array($policy->status, ['ENDORSEMENT_PENDING', 'CANCELLATION_PENDING'], true)) {
                $fromPolicyStatus = $policy->status;
                $this->machine->assert($fromPolicyStatus, 'ACTIVE');
                $policy->update(['status' => 'ACTIVE']);
                $this->policyHistory($policy, $fromPolicyStatus, 'ACTIVE', 'SERVICE_REJECTED', $actor, $transaction);
            }

            $this->event($transaction, $fromTransactionStatus, 'REJECTED', 'REJECTED', $actor);
            $this->audit->record('policy.service.rejected', 'policy_transaction', $transaction->id, [
                'policy_id' => $policy->id,
            ], 'SERVICE_REJECTED');
            $this->outbox->record('policy.service.rejected', 'policy_transaction', $transaction->id, [
                'policy_id' => $policy->id,
                'transaction_id' => $transaction->id,
            ]);

            return $transaction->refresh();
        });
    }

    private function event(PolicyTransaction $transaction, ?string $from, string $to, string $reason, ?User $actor): void
    {
        DB::table('policy_transaction_events')->insert([
            'id' => (string) Str::uuid(),
            'policy_transaction_id' => $transaction->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason_code' => $reason,
            'actor_id' => $actor?->id,
            'metadata' => '{}',
            'occurred_at' => now(),
        ]);
    }

    private function policyHistory(Policy $policy, string $from, string $to, string $reason, User $actor, PolicyTransaction $transaction): void
    {
        DB::table('policy_status_history')->insert([
            'id' => (string) Str::uuid(),
            'policy_id' => $policy->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason_code' => $reason,
            'actor_id' => $actor->id,
            'metadata' => json_encode([
                'transaction_id' => $transaction->id,
                'refund_minor' => $transaction->refund_minor,
            ]),
            'occurred_at' => now(),
        ]);
    }
}
