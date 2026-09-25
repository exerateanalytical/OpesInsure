<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * Owner decision item 9: branch premium allocations are never invented.
 *  - PENDING_CARRIER_ALLOCATION: no carrier split yet (legacy runs say PENDING_OQ_24; same meaning).
 *  - ESTIMATED_NON_REGULATORY: an internal estimate; kept for management views, EXCLUDED from official reporting.
 *  - ALLOCATED: the carrier's split; the only status that feeds regulatory reporting.
 * Reads rating_runs.branch_allocation / allocation_status (the rating engine's branch split).
 */
final class BranchPremiumAllocation
{
    public const PENDING = 'PENDING_CARRIER_ALLOCATION';

    public const ESTIMATED = 'ESTIMATED_NON_REGULATORY';

    public const ALLOCATED = 'ALLOCATED';

    public static function normalize(?string $status): string
    {
        return match ($status) {
            self::ALLOCATED, self::ESTIMATED => $status,
            default => self::PENDING,   // null, PENDING_OQ_24, PENDING_CARRIER_ALLOCATION
        };
    }

    public static function isRegulatory(?string $status): bool
    {
        return self::normalize($status) === self::ALLOCATED;
    }

    /**
     * Regulatory branch totals over succeeded rating runs: only ALLOCATED splits count.
     *
     * @param  list<string>  $runIds
     * @return array{branches: array<string, int>, excluded: array<string, int>}
     */
    public function regulatoryTotals(array $runIds): array
    {
        $branches = [];
        $excluded = [self::PENDING => 0, self::ESTIMATED => 0];
        foreach (DB::table('rating_runs')->whereIn('id', $runIds)->where('status', 'SUCCEEDED')->get(['id', 'branch_allocation', 'allocation_status']) as $run) {
            $status = self::normalize($run->allocation_status);
            if ($status !== self::ALLOCATED) {
                $excluded[$status]++;

                continue;
            }
            foreach ((array) json_decode((string) $run->branch_allocation, true) as $part) {
                $code = (string) ($part['branch_code'] ?? '');
                $branches[$code] = ($branches[$code] ?? 0) + (int) ($part['amount_minor'] ?? 0);
            }
        }
        ksort($branches);

        return ['branches' => $branches, 'excluded' => $excluded];
    }
}
