<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Profitability;

use App\Application\Ledger\Technical\TechnicalAccountingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Agent B1 — REQ-RPT-006 per-policy profitability (read-only).
 *
 * Earned premium and incurred claims come from TechnicalAccountingService::perPolicy (same bases as the technical
 * accounts); commission cost is the net accrued commission (amount − clawed back) of the policy in the period.
 * result = earned − incurred − commission. Ratios are in basis points (null when nothing is earned).
 */
final class PolicyProfitabilityReport
{
    public function __construct(private readonly TechnicalAccountingService $technical) {}

    /** @return list<array<string,mixed>> */
    public function build(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?string $policyId = null): array
    {
        $rows = $this->technical->perPolicy($tenantId, $from, $to, $policyId);
        $commission = DB::table('commission_accruals')->where('tenant_id', $tenantId)
            ->when($policyId, fn ($q, $v) => $q->where('policy_id', $v))
            ->where('created_at', '>=', $from->startOfDay())->where('created_at', '<', $to->startOfDay()->addDay())
            ->whereNotNull('policy_id')
            ->groupBy('policy_id')->selectRaw('policy_id, SUM(amount_minor - COALESCE(clawed_back_minor, 0)) as net')->pluck('net', 'policy_id');
        $out = [];
        foreach ($rows as $id => $r) {
            $out[] = $this->finish($r, (int) ($commission[$id] ?? 0));
        }
        foreach ($commission as $id => $net) {
            if (! isset($rows[$id])) {
                $out[] = $this->finish(['policy_id' => $id, 'written_minor' => 0, 'earned_minor' => 0, 'claims' => 0, 'claims_paid_minor' => 0, 'claims_incurred_minor' => 0], (int) $net);
            }
        }

        return $out;
    }

    private function finish(array $r, int $commission): array
    {
        $earned = (int) $r['earned_minor'];
        $incurred = (int) $r['claims_incurred_minor'];

        return $r + [
            'commission_minor' => $commission,
            'result_minor' => $earned - $incurred - $commission,
            'loss_ratio_bp' => $earned > 0 ? intdiv($incurred * 10000, $earned) : null,
            'combined_ratio_bp' => $earned > 0 ? intdiv(($incurred + $commission) * 10000, $earned) : null,
        ];
    }
}
