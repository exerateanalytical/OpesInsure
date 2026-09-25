<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Temporal\ReferenceDateResolver;
use App\Application\Temporal\TemporalResolutionException;
use App\Application\Temporal\VersionResolver;
use App\Models\CancellationRuleVersion;
use App\Models\Policy;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Cancellation refund calculation. The applicable cancellation_rule_versions
 * row is resolved by the Temporal engine (REQ-TMP-001, ICE E1 §1.5): the CANCEL
 * reference-date rule anchors on the cancellation effective_at, validity is
 * closed-closed on the business date. Zero or overlapping approved rules block
 * (INV-1.3) instead of silently falling back to the latest version.
 */
final class CancellationCalculator
{
    public function __construct(
        private readonly VersionResolver $versions,
        private readonly ReferenceDateResolver $referenceDates,
    ) {}

    /**
     * REQ-CAN-001: an insurer-initiated cancellation is always refunded pro-rata without the
     * admin fee (the insured did not choose to leave); the rule's SHORT_RATE basis and fee
     * apply to insured / intermediary-initiated cancellations only.
     */
    public function calculate(Policy $p, CarbonInterface $effective, ?string $initiatedBy = null): array
    {
        if ($effective->lessThan($p->coverage_starts_at) || $effective->greaterThan($p->coverage_ends_at)) {
            throw ValidationException::withMessages(['effective_at' => __('wave5.cancellation_date_invalid')]);
        }
        $line = $p->proposal->offer->quote->line_code;
        $r = $this->rule($line, $effective);

        $total = max(1, $p->coverage_starts_at->diffInDays($p->coverage_ends_at));
        $unused = max(0, $effective->diffInDays($p->coverage_ends_at));
        $proRata = (int) round($p->premium_minor * $unused / $total);
        $insurer = $initiatedBy === 'INSURER';
        $basis = $insurer ? 'PRO_RATA' : $r->basis;
        $refund = $basis === 'SHORT_RATE' ? (int) round($proRata * $r->short_rate_basis_points / 10000) : $proRata;
        $refund = max(0, min($p->premium_minor, $refund - ($insurer ? 0 : $r->admin_fee_minor)));

        return ['refund_minor' => $refund, 'rule_id' => $r->id, 'basis' => $basis, 'unused_days' => $unused, 'total_days' => $total];
    }

    private function rule(string $line, CarbonInterface $effective): CancellationRuleVersion
    {
        try {
            $at = $this->referenceDates->for('CANCEL', ['effective_at' => $effective], 'cancellation_rule', 'TERMS');
            $resolved = $this->versions->resolve('cancellation_rule', ['line_code' => $line], $at);
        } catch (TemporalResolutionException) {
            throw ValidationException::withMessages(['rule' => __('wave5.cancellation_rule_missing')]);
        }

        // Only an APPROVED version may price a cancellation (registry filter, re-asserted here).
        return CancellationRuleVersion::where(['id' => $resolved->id, 'status'=>'APPROVED'])->firstOrFail();
    }
}
