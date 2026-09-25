<?php

declare(strict_types=1);

namespace App\Application\Fraud;

use App\Models\Claim;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FRD-001 — decides whether a claim transition must be blocked for fraud reasons.
 * Approval and settlement are blocked while a CLAIM_FRAUD_REVIEW is open (FRAUD_REVIEW_OPEN) and after a human
 * confirmed fraud (FRAUD_CONFIRMED). Declining, investigating and closing stay possible.
 */
final class ClaimFraudHold
{
    /** Blueprint target states that commit money to the claimant (ClaimLifecycle, agent C1). */
    public const BLOCKED_STATES = ['APPROVED', 'PARTIALLY_APPROVED', 'SETTLEMENT_PENDING', 'SETTLED'];

    /** Returns null when allowed, else a blocking reason code. */
    public function check(Claim $claim, string $event, array $context = []): ?string
    {
        if (! $this->blocksMoney($event, $context)) {
            return null;
        }
        $open = DB::table('risk_alerts')->where('subject_id', $claim->id)->where('alert_type', ClaimFraudIndicatorService::ALERT_TYPE)
            ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])->exists();
        if ($open) {
            return 'FRAUD_REVIEW_OPEN';
        }
        $flag = DB::table('claims')->where('id', $claim->id)->value('fraud_flag');

        return $flag === 'FRAUD_CONFIRMED' ? 'FRAUD_CONFIRMED' : null;
    }

    private function blocksMoney(string $event, array $context): bool
    {
        $to = $context['to'] ?? $context['to_state'] ?? $context['target'] ?? null;
        if (is_string($to)) {
            return in_array(strtoupper($to), self::BLOCKED_STATES, true);
        }
        $e = strtolower($event);

        return str_contains($e, 'approv') || str_contains($e, 'settle');
    }
}
