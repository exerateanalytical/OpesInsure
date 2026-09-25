<?php

declare(strict_types=1);

namespace App\Application\Reinsurance;

use Illuminate\Support\Facades\DB;

/**
 * REQ-REI-002 (reinsurance, NOT the carrier bordereau in App\Application\FinancialDistribution\BordereauService): premium / risk bordereau for one treaty over a period, built from current (CALCULATED)
 * cessions, one line per policy and reinsurer share, with per-reinsurer totals.
 */
final class TreatyBordereauService
{
    public function __construct(private readonly TreatyService $treaties) {}

    public function build(string $tenantId, string $treatyId, string $type, string $from, string $to): array
    {
        $treaty = $this->treaties->treaty($tenantId, $treatyId);
        $rows = DB::table('reinsurance_cession_shares as s')
            ->join('reinsurance_cessions as c', 'c.id', '=', 's.cession_id')
            ->join('policies as p', 'p.id', '=', 'c.policy_id')
            ->join('reinsurers as r', 'r.id', '=', 's.reinsurer_id')
            ->where('c.tenant_id', $tenantId)->where('c.treaty_id', $treatyId)->where('c.status', 'CALCULATED')
            ->whereDate('p.coverage_starts_at', '>=', $from)->whereDate('p.coverage_starts_at', '<=', $to)
            ->orderBy('p.policy_number')->orderBy('r.code')
            ->select('p.policy_number', 'p.coverage_starts_at', 'p.coverage_ends_at', 'c.policy_id', 'c.currency', 'c.sum_insured_minor', 'c.gross_premium_minor',
                'c.ceded_percent', 'r.code as reinsurer_code', 'r.name as reinsurer_name', 's.reinsurer_id', 's.share_percent', 's.ceded_sum_minor',
                's.ceded_premium_minor', 's.commission_minor', 's.brokerage_minor', 's.tax_minor', 's.net_premium_minor')
            ->get()->map(fn ($r) => (array) $r)->all();

        $totals = [];
        foreach ($rows as $r) {
            $t = &$totals[$r['reinsurer_id']];
            $t ??= ['reinsurer_id' => $r['reinsurer_id'], 'reinsurer_code' => $r['reinsurer_code'], 'lines' => 0, 'ceded_sum_minor' => 0, 'ceded_premium_minor' => 0,
                'commission_minor' => 0, 'brokerage_minor' => 0, 'tax_minor' => 0, 'net_premium_minor' => 0];
            $t['lines']++;
            foreach (['ceded_sum_minor', 'ceded_premium_minor', 'commission_minor', 'brokerage_minor', 'tax_minor', 'net_premium_minor'] as $k) {
                $t[$k] += (int) $r[$k];
            }
            unset($t);
        }

        return ['treaty_id' => $treaty->id, 'treaty_code' => $treaty->code, 'type' => $type, 'currency' => $treaty->currency,
            'period' => ['from' => $from, 'to' => $to], 'lines' => $rows, 'totals' => array_values($totals)];
    }
}
