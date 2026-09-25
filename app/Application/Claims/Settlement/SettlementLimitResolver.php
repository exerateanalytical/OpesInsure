<?php

declare(strict_types=1);

namespace App\Application\Claims\Settlement;

use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Models\Claim;
use Illuminate\Support\Facades\DB;

/**
 * Remaining limit and default deductible for a claim at its loss date.
 *
 *  1. App\Application\Claims\Limits\LimitLedger (C4) when present and it exposes remainingForClaim(Claim, ?string coverageCode): ?int.
 *  2. Otherwise policy_limits of the policy version in force at the loss date (PolicyChronologyWriter::asOf):
 *     PER_CLAIM → amount − payments already made on this claim; AGGREGATE → amount − consumed; the smallest applies.
 *  No limit rows → null (uncapped).
 */
final class SettlementLimitResolver
{
    public function __construct(private PolicyChronologyWriter $chronology) {}

    /** @return array{remaining_limit_minor:?int, source:string, deductible_minor:?int, policy_version_id:?string, limits:list<array<string,mixed>>} */
    public function resolve(Claim $claim, ?string $coverageCode, int $priorPaid): array
    {
        $version = $this->chronology->asOf($claim->policy_id, $claim->loss_occurred_at ?? now());
        $coverage = null;
        if ($version && $coverageCode) {
            $coverage = DB::table('policy_coverages')->where(['policy_version_id' => $version->id, 'coverage_code' => $coverageCode])->first();
        }
        $limits = $version ? DB::table('policy_limits')->where('policy_version_id', $version->id)
            ->when($coverage, fn ($q) => $q->where(fn ($w) => $w->where('policy_coverage_id', $coverage->id)->orWhereNull('policy_coverage_id')))
            ->get() : collect();

        $deductible = $coverage?->deductible_minor !== null ? (int) $coverage->deductible_minor : null;
        $ded = $limits->firstWhere('limit_type', 'DEDUCTIBLE');
        if ($deductible === null && $ded) {
            $deductible = (int) $ded->amount_minor;
        }

        $ledgerClass = 'App\\Application\\Claims\\Limits\\LimitLedger';
        if (class_exists($ledgerClass) && method_exists($ledgerClass, 'remainingForClaim')) {
            $remaining = app($ledgerClass)->remainingForClaim($claim, $coverageCode);

            return ['remaining_limit_minor' => $remaining === null ? null : (int) $remaining, 'source' => 'LIMIT_LEDGER', 'deductible_minor' => $deductible,
                'policy_version_id' => $version?->id, 'limits' => []];
        }

        $remaining = null;
        $explained = [];
        foreach ($limits as $l) {
            $left = match ($l->limit_type) {
                'PER_CLAIM' => (int) $l->amount_minor - $priorPaid,
                'AGGREGATE' => (int) $l->amount_minor - (int) $l->consumed_minor,
                default => null,
            };
            if ($left === null) {
                continue;
            }
            $explained[] = ['limit_id' => $l->id, 'limit_type' => $l->limit_type, 'amount_minor' => (int) $l->amount_minor, 'consumed_minor' => (int) $l->consumed_minor, 'remaining_minor' => $left];
            $remaining = $remaining === null ? $left : min($remaining, $left);
        }
        if ($remaining === null && $coverage?->limit_minor !== null) {
            $remaining = (int) $coverage->limit_minor - $priorPaid;
            $explained[] = ['limit_type' => 'COVERAGE_LIMIT', 'amount_minor' => (int) $coverage->limit_minor, 'remaining_minor' => $remaining];
        }

        return ['remaining_limit_minor' => $remaining, 'source' => $remaining === null ? 'NONE' : 'POLICY_LIMITS', 'deductible_minor' => $deductible,
            'policy_version_id' => $version?->id, 'limits' => $explained];
    }
}
