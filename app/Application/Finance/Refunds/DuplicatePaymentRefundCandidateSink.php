<?php

declare(strict_types=1);

namespace App\Application\Finance\Refunds;

use App\Application\Audit\AuditWriter;
use App\Application\Payments\FinancialCaseService;
use App\Application\Reconciliation\RefundCandidateSink;
use App\Models\PaymentIntentRecord;
use App\Models\ReconciliationItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Batch 9-4 (for Batch 9-5) — turns a reconciliation item flagged as a refund candidate (DUPLICATE_PAYMENT)
 * into a REQUESTED refund on the matched payment intent, through the existing FinancialCaseService (so the
 * over-refund cap and maker-checker approval still apply). The statement uploader is the requester; the
 * reconciliation item id makes the request idempotent. When no refund can be requested (no matched paid
 * intent, balance exhausted) the candidate is left for manual review and audited.
 * Only bound when App\Application\Reconciliation\RefundCandidateSink exists (RefundCandidateServiceProvider).
 */
final class DuplicatePaymentRefundCandidateSink implements RefundCandidateSink
{
    public function __construct(private readonly FinancialCaseService $cases, private readonly AuditWriter $audit) {}

    public function refundCandidate(ReconciliationItem $item, string $reason, int $amountMinor, string $currency): void
    {
        $payment = $item->matched_type === 'PAYMENT_INTENT' && $item->matched_id ? PaymentIntentRecord::find($item->matched_id) : null;
        $actor = User::find($item->import?->uploaded_by);
        if ($payment === null || $actor === null || $payment->currency !== $currency || $amountMinor <= 0) {
            $this->pending($item, $reason, 'NO_REFUNDABLE_PAYMENT');

            return;
        }
        try {
            $refund = $this->cases->requestRefund($payment, [
                'amount_minor' => $amountMinor, 'reason_code' => $reason, 'idempotency_key' => 'recon-refund-'.$item->id,
                'notes' => 'Reconciliation refund candidate '.$item->external_reference,
            ], $actor);
        } catch (ValidationException $e) {
            $this->pending($item, $reason, array_key_first($e->errors()) ?? 'REFUSED');

            return;
        }
        $this->audit->record('refund.candidate.requested', 'reconciliation_item', $item->id, ['refund_id' => $refund->id, 'payment_intent_id' => $payment->id, 'amount_minor' => $amountMinor], $reason);
    }

    private function pending(ReconciliationItem $item, string $reason, string $why): void
    {
        $this->audit->record('refund.candidate.pending_review', 'reconciliation_item', $item->id, ['why' => $why], $reason);
    }
}
