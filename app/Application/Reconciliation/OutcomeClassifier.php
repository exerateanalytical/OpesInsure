<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Domain\Reconciliation\MatchEngine;
use App\Models\PaymentIntentRecord;
use App\Models\ReconciliationItem;

/**
 * REQ-PAY-007 statement-line outcome. Builds on MatchEngine (reference/currency/amount equality) and adds the
 * history-aware outcomes:
 *  - UNMATCHED  no payment for the reference, or currency mismatch;
 *  - DUPLICATE  the same statement reference was already applied, or the payment is already fully covered by
 *               earlier lines (WF-026/027/086: flagged as refund candidate DUPLICATE_PAYMENT);
 *  - MATCHED    the line settles exactly what is still expected (a split settlement's last line included);
 *  - OVER       the line exceeds what is still expected;
 *  - PARTIAL    short, and the payment is not yet confirmed SUCCEEDED (instalment / remainder still to come);
 *  - UNDER      short, although the provider confirmed the payment SUCCEEDED (short remittance).
 */
final class OutcomeClassifier
{
    public const OUTCOMES = ['MATCHED', 'PARTIAL', 'UNMATCHED', 'DUPLICATE', 'OVER', 'UNDER'];

    /** Outcomes whose amount counts as applied to the payment. */
    public const APPLIED = ['MATCHED', 'PARTIAL'];

    public function __construct(private readonly MatchEngine $engine) {}

    /** @return array{outcome: string, status: string, exception_code: ?string, expected_minor: ?int, variance_minor: ?int} */
    public function classify(string $externalReference, int $amountMinor, string $currency, ?PaymentIntentRecord $payment): array
    {
        if (! $payment || $payment->currency !== $currency) {
            $d = $this->engine->decide($amountMinor, (int) ($payment?->amount_minor ?? -1), $currency, $payment?->currency ?? $currency, (bool) $payment);

            return $this->result('UNMATCHED', $d->exceptionCode, $payment ? (int) $payment->amount_minor : null, null);
        }
        $expected = (int) $payment->amount_minor;
        $applied = ReconciliationItem::where('matched_type', 'PAYMENT_INTENT')->where('matched_id', $payment->id)->whereIn('outcome', self::APPLIED);
        $appliedSum = (int) (clone $applied)->sum('gross_minor');
        $sameReference = (clone $applied)->where('external_reference', $externalReference)->where('outcome', 'MATCHED')->exists();
        if ($sameReference || $appliedSum >= $expected) {
            return $this->result('DUPLICATE', 'DUPLICATE_PAYMENT', $expected, $amountMinor);
        }
        $remaining = $expected - $appliedSum;
        $d = $this->engine->decide($amountMinor, $remaining, $currency, $payment->currency, true);
        if ($d->status === 'MATCHED') {
            return $this->result('MATCHED', null, $remaining, 0);
        }
        if ($amountMinor > $remaining) {
            return $this->result('OVER', 'AMOUNT_OVER', $remaining, $amountMinor - $remaining);
        }

        return $payment->status === 'SUCCEEDED'
            ? $this->result('UNDER', 'AMOUNT_UNDER', $remaining, $amountMinor - $remaining)
            : $this->result('PARTIAL', 'PARTIAL_PAYMENT', $remaining, $amountMinor - $remaining);
    }

    /** @return array{outcome: string, status: string, exception_code: ?string, expected_minor: ?int, variance_minor: ?int} */
    private function result(string $outcome, ?string $code, ?int $expected, ?int $variance): array
    {
        return ['outcome' => $outcome, 'status' => $outcome === 'MATCHED' ? 'MATCHED' : 'EXCEPTION', 'exception_code' => $code, 'expected_minor' => $expected, 'variance_minor' => $variance];
    }
}
