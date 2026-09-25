<?php

declare(strict_types=1);

namespace App\Application\Payments\Retries;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Payments\PaymentInitiationService;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntentRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * REQ-PAY-008 (WF-025 / WF-085) — a failed payment is retried as a new payment_attempt under the SAME payment
 * intent (and so the same financial obligation), never as a second intent. Guards: only a FAILED intent,
 * at most payment_intents.max_attempts attempts, no retry when another intent of the same obligation is
 * already paid or in flight or the obligation has nothing outstanding (no double charge), and an optional
 * idempotency key so a replayed request returns the first retry instead of prompting the payer twice.
 * Every outcome carries a customer-facing message.
 */
final class PaymentRetryService
{
    public const IN_FLIGHT_OR_PAID = ['PENDING_CUSTOMER', 'PROCESSING', 'SUCCEEDED'];

    public function __construct(
        private readonly PaymentInitiationService $initiation,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @return array{payment:PaymentIntentRecord,attempt:?PaymentAttempt,customer_message:string,replayed:bool} */
    public function retry(PaymentIntentRecord $intent, ?User $actor = null, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey !== null && ($prior = PaymentAttempt::where('retry_idempotency_key', $idempotencyKey)->first())) {
            if ($prior->payment_intent_id !== $intent->id) {
                throw ValidationException::withMessages(['idempotency_key' => __('batch9_payments.idempotency_key_reused')]);
            }

            return ['payment' => $intent->refresh(), 'attempt' => $prior, 'customer_message' => __('batch9_payments.retry_started'), 'replayed' => true];
        }

        $result = DB::transaction(function () use ($intent, $actor, $idempotencyKey) {
            $locked = PaymentIntentRecord::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'FAILED') {
                $this->refuse('status', $locked->status === 'SUCCEEDED' ? 'already_paid' : 'payment_not_retryable');
            }
            if ($locked->attempts()->count() >= $locked->max_attempts) {
                return null; // recorded outside the transaction so the refusal event is not rolled back
            }
            $this->guardObligation($locked);

            $previous = $locked->attempts()->orderByDesc('attempt_number')->first();
            $payment = $this->initiation->initiate($locked, array_filter([
                'retry_of_attempt_id' => $previous?->id, 'retry_idempotency_key' => $idempotencyKey, 'requested_by' => $actor?->id,
            ]));
            $attempt = $payment->attempts()->orderByDesc('attempt_number')->first();
            $this->audit->record('payment.retry.requested', 'payment_intent', $payment->id, ['attempt' => $attempt?->attempt_number, 'retry_of_attempt_id' => $previous?->id]);
            $this->outbox->record('payment.retry.requested', 'payment_intent', $payment->id, [
                'payment_intent_id' => $payment->id, 'financial_obligation_id' => $payment->financial_obligation_id,
                'attempt_number' => $attempt?->attempt_number, 'retry_of_attempt_id' => $previous?->id,
            ]);

            return ['payment' => $payment, 'attempt' => $attempt, 'customer_message' => __('batch9_payments.retry_started', ['remaining' => max(0, $payment->max_attempts - (int) $attempt?->attempt_number)]), 'replayed' => false];
        });
        if ($result === null) {
            $used = $intent->attempts()->count();
            $this->outbox->record('payment.retry.exhausted', 'payment_intent', $intent->id, ['payment_intent_id' => $intent->id, 'attempts' => $used, 'max_attempts' => $intent->max_attempts]);
            $this->audit->record('payment.retry.exhausted', 'payment_intent', $intent->id, ['attempts' => $used]);
            $this->refuse('attempts', 'retry_exhausted', ['max' => $intent->max_attempts]);
        }

        return $result;
    }

    /** @return array{attempts_used:int,max_attempts:int,retryable:bool,customer_message:string} */
    public function status(PaymentIntentRecord $intent): array
    {
        $used = $intent->attempts()->count();
        $retryable = $intent->status === 'FAILED' && $used < $intent->max_attempts;
        $message = match (true) {
            $intent->status === 'SUCCEEDED' => __('batch9_payments.already_paid'),
            $retryable => __('batch9_payments.retry_available', ['remaining' => $intent->max_attempts - $used]),
            $intent->status === 'FAILED' => __('batch9_payments.retry_exhausted', ['max' => $intent->max_attempts]),
            default => __('batch9_payments.payment_in_progress'),
        };

        return ['attempts_used' => $used, 'max_attempts' => (int) $intent->max_attempts, 'retryable' => $retryable, 'customer_message' => $message];
    }

    /** No double charge: another intent for the same obligation is paid / in flight, or nothing is outstanding. */
    private function guardObligation(PaymentIntentRecord $intent): void
    {
        if ($intent->financial_obligation_id === null) {
            return;
        }
        $sibling = PaymentIntentRecord::where('financial_obligation_id', $intent->financial_obligation_id)->whereKeyNot($intent->id)
            ->whereIn('status', self::IN_FLIGHT_OR_PAID)->exists();
        if ($sibling) {
            $this->refuse('financial_obligation_id', 'obligation_already_covered');
        }
        $service = 'App\\Application\\Finance\\Obligations\\ObligationService';
        if (class_exists($service) && method_exists($service, 'outstanding')) {
            try {
                $outstanding = app($service)->outstanding($intent->financial_obligation_id);
            } catch (Throwable) {
                return;
            }
            if (is_numeric($outstanding) && (int) $outstanding <= 0) {
                $this->refuse('financial_obligation_id', 'obligation_already_covered');
            }
        }
    }

    private function refuse(string $field, string $key, array $params = []): never
    {
        throw ValidationException::withMessages([$field => __('batch9_payments.'.$key, $params)]);
    }
}
