<?php

declare(strict_types=1);

namespace App\Application\Finance\Refunds;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Payments\FinancialCaseService;
use App\Models\PaymentIntentRecord;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PAY-009 / WF-063 — refund engine on the existing `refunds` table (never a second one).
 *
 *   candidate()  → CANDIDATE   raised by a source (issuance_exception, cancellation, endorsement, manual), amount proposed
 *   calculate()  → CALCULATED  maker fixes gross − deductions = net, within the payment's refundable balance
 *   review()     → REQUESTED   reviewer ≠ calculator; REQUESTED is the state FinancialCaseService::approveRefund accepts
 *   approve()    → APPROVED    checker ≠ requester / calculator (FinancialCaseService, maker-checker)
 *   pay()        → PAID        payout recorded with its provider reference
 *   reconcile()  → RECONCILED  payout matched to the statement; reconciler ≠ payer
 *   reject()     → REJECTED    from any pre-approval state
 * Legacy callers (cancellation 8-2, endorsement 8-1, mobile) still create REQUESTED rows directly.
 */
final class RefundEngine
{
    public const STATUSES = ['CANDIDATE', 'CALCULATED', 'REQUESTED', 'APPROVED', 'PAID', 'RECONCILED', 'REJECTED', 'PROCESSING', 'COMPLETED'];

    public const PAYOUT_METHODS = ['MOBILE_MONEY', 'BANK_TRANSFER', 'CHEQUE', 'CASH', 'PROVIDER_REVERSAL'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox, private readonly FinancialCaseService $cases) {}

    /** Idempotent per source: one candidate per (source_type, source_id). */
    public function candidate(PaymentIntentRecord $payment, string $sourceType, ?string $sourceId, string $reasonCode, User $actor, ?int $proposedMinor = null, ?string $notes = null): Refund
    {
        if ($payment->status !== 'SUCCEEDED') {
            throw ValidationException::withMessages(['payment' => __('wave4.refund_requires_success')]);
        }
        $key = 'refund-candidate-'.$sourceType.'-'.($sourceId ?? Str::uuid());

        return DB::transaction(function () use ($payment, $sourceType, $sourceId, $reasonCode, $actor, $proposedMinor, $notes, $key): Refund {
            PaymentIntentRecord::whereKey($payment->id)->lockForUpdate()->first();
            if ($existing = Refund::where('tenant_id', $payment->tenant_id)->where('idempotency_key', $key)->first()) {
                return $existing;
            }
            $balance = $this->refundableBalance($payment);
            $amount = $proposedMinor ?? $balance;
            if ($amount < 1 || $amount > $balance) {
                throw ValidationException::withMessages(['amount_minor' => __('wave4.refund_exceeds_balance')]);
            }
            $r = Refund::create(['tenant_id' => $payment->tenant_id, 'payment_intent_id' => $payment->id, 'refund_number' => 'RFD-'.strtoupper(Str::random(10)),
                'amount_minor' => $amount, 'currency' => $payment->currency, 'status' => 'CANDIDATE', 'reason_code' => mb_substr($reasonCode, 0, 64), 'notes' => $notes,
                'requested_by' => $actor->id, 'idempotency_key' => $key, 'source_type' => $sourceType, 'source_id' => $sourceId]);
            $this->transition($r, null, 'CANDIDATE', $reasonCode, $actor, 'refund.candidate_created', ['source_type' => $sourceType, 'source_id' => $sourceId, 'amount_minor' => $amount]);

            return $r;
        });
    }

    /** @param array{gross_minor?:int,deductions?:list<array{code:string,amount_minor:int}>,notes?:string|null} $data */
    public function calculate(Refund $r, array $data, User $actor): Refund
    {
        $this->assertStatus($r, ['CANDIDATE', 'CALCULATED']);

        return DB::transaction(function () use ($r, $data, $actor): Refund {
            $payment = PaymentIntentRecord::whereKey($r->payment_intent_id)->lockForUpdate()->firstOrFail();
            $balance = $this->refundableBalance($payment, $r->id);
            $gross = (int) ($data['gross_minor'] ?? $balance);
            $deductions = array_values(array_map(fn ($x) => ['code' => (string) $x['code'], 'amount_minor' => (int) $x['amount_minor']], $data['deductions'] ?? []));
            $net = $gross - array_sum(array_column($deductions, 'amount_minor'));
            if ($gross > $balance || $net < 1) {
                throw ValidationException::withMessages(['amount_minor' => __('wave4.refund_exceeds_balance')]);
            }
            $from = $r->status;
            $r->update(['status' => 'CALCULATED', 'amount_minor' => $net, 'calculated_by' => $actor->id, 'calculated_at' => now(), 'notes' => $data['notes'] ?? $r->notes,
                'calculation' => ['gross_minor' => $gross, 'deductions' => $deductions, 'net_minor' => $net, 'refundable_balance_minor' => $balance, 'currency' => $r->currency]]);
            $this->transition($r, $from, 'CALCULATED', 'CALCULATED', $actor, 'refund.calculated', ['amount_minor' => $net]);

            return $r->refresh();
        });
    }

    public function review(Refund $r, User $actor, ?string $notes = null): Refund
    {
        $this->assertStatus($r, ['CALCULATED']);
        if ($r->calculated_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave4.maker_checker')]);
        }
        $r->update(['status' => 'REQUESTED', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $this->transition($r, 'CALCULATED', 'REQUESTED', 'REVIEWED', $actor, 'refund.reviewed', ['notes' => $notes]);

        return $r->refresh();
    }

    /** Checker step — FinancialCaseService owns the REQUESTED → APPROVED transition and its maker-checker rule. */
    public function approve(Refund $r, User $actor): Refund
    {
        $r = $this->cases->approveRefund($r, $actor);
        if (app()->bound(RefundObligationLink::class) && ($obligation = app(RefundObligationLink::class)->open($r))) {
            $r->update(['financial_obligation_id' => $obligation]);
        }

        return $r->refresh();
    }

    public function reject(Refund $r, User $actor, string $reason): Refund
    {
        $this->assertStatus($r, ['CANDIDATE', 'CALCULATED', 'REQUESTED']);
        $from = $r->status;
        $r->update(['status' => 'REJECTED', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
        $this->transition($r, $from, 'REJECTED', 'REJECTED', $actor, 'refund.rejected', ['reason' => $reason]);

        return $r->refresh();
    }

    /** @param array{payout_method:string,provider_reference:string} $data */
    public function pay(Refund $r, array $data, User $actor): Refund
    {
        $this->assertStatus($r, ['APPROVED']);
        $r->update(['status' => 'PAID', 'payout_method' => $data['payout_method'], 'provider_reference' => $data['provider_reference'], 'paid_by' => $actor->id, 'paid_at' => now(), 'completed_at' => now()]);
        $this->transition($r, 'APPROVED', 'PAID', 'PAID', $actor, 'refund.paid', ['amount_minor' => $r->amount_minor, 'payout_method' => $data['payout_method']]);
        if (app()->bound(RefundObligationLink::class)) {
            app(RefundObligationLink::class)->settle($r);
        }

        return $r->refresh();
    }

    public function reconcile(Refund $r, User $actor, string $bankReference): Refund
    {
        $this->assertStatus($r, ['PAID']);
        if ($r->paid_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave4.maker_checker')]);
        }
        $r->update(['status' => 'RECONCILED', 'bank_reference' => $bankReference, 'reconciled_by' => $actor->id, 'reconciled_at' => now()]);
        $this->transition($r, 'PAID', 'RECONCILED', 'RECONCILED', $actor, 'refund.reconciled', ['bank_reference' => $bankReference]);

        return $r->refresh();
    }

    /** Paid amount minus every refund still holding part of it (optionally excluding one being recalculated). */
    public function refundableBalance(PaymentIntentRecord $payment, ?string $exceptRefundId = null): int
    {
        $held = (int) Refund::where('payment_intent_id', $payment->id)->whereIn('status', Refund::ACTIVE_STATUSES)
            ->when($exceptRefundId, fn ($q) => $q->where('id', '<>', $exceptRefundId))->sum('amount_minor');

        return max(0, (int) $payment->amount_minor - $held);
    }

    /** @param list<string> $allowed */
    private function assertStatus(Refund $r, array $allowed): void
    {
        if (! in_array($r->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Refund {$r->refund_number} is {$r->status}; expected ".implode(' or ', $allowed).'.']);
        }
    }

    private function transition(Refund $r, ?string $from, string $to, string $reason, User $actor, string $event, array $meta): void
    {
        DB::table('financial_case_events')->insert(['id' => (string) Str::uuid(), 'case_type' => 'REFUND', 'case_id' => $r->id, 'from_status' => $from, 'to_status' => $to,
            'reason_code' => mb_substr($reason, 0, 64), 'actor_id' => $actor->id, 'metadata' => json_encode($meta), 'occurred_at' => now()]);
        $this->outbox->record($event, 'refund', $r->id, ['refund_id' => $r->id, 'payment_intent_id' => $r->payment_intent_id, 'status' => $to] + $meta);
        $this->audit->record($event, 'refund', $r->id, $meta, $reason);
    }
}
