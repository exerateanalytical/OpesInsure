<?php

declare(strict_types=1);

namespace App\Application\Bordereaux;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REQ-STL-002 / REQ-DUP-008 — the business records a carrier bordereau of a given type
 * reports for a period. Used only by App\Application\FinancialDistribution\BordereauService
 * (the one carrier bordereau service). Not the reinsurance treaty bordereau
 * (App\Application\Reinsurance\TreatyBordereauService).
 *
 *  PREMIUM      → policies issued in the period             (item NEW_BUSINESS, amount = premium)
 *  ENDORSEMENT  → endorsements approved in the period       (item ENDORSEMENT, amount = premium delta)
 *  CANCELLATION → cancellations approved in the period      (item CANCELLATION, amount = -refund)
 *  CLAIM        → claims submitted in the period            (item CLAIM, amount = approved ?? reserve ?? estimate)
 *  COMMISSION   → commission accruals raised in the period  (item COMMISSION, amount = commission)
 *
 * Every source is scoped to the tenant + carrier (through the policy) and, when given,
 * to an explicit policy selection. Non-premium sources are restricted to the bordereau currency.
 */
final class BordereauItemSource
{
    public const ITEM_TYPE = [
        'PREMIUM' => 'NEW_BUSINESS',
        'ENDORSEMENT' => 'ENDORSEMENT',
        'CANCELLATION' => 'CANCELLATION',
        'CLAIM' => 'CLAIM',
        'COMMISSION' => 'COMMISSION',
    ];

    /**
     * @param  list<string>  $policyIds  explicit selection ([] = whole period)
     * @return list<array{source_type:string,source_id:string,policy_id:string,transaction_type:string,premium_minor:int,commission_minor:int,amount_minor:int,effective_at:mixed}>
     */
    public function items(string $tenantId, string $carrierId, string $type, string $from, string $to, string $currency, array $policyIds = []): array
    {
        $end = $to.' 23:59:59';
        $policies = fn (Builder $q, string $col = 'p') => $q->where("$col.tenant_id", $tenantId)->where("$col.carrier_id", $carrierId)
            ->when($policyIds !== [], fn ($q) => $q->whereIn("$col.id", $policyIds));
        $item = self::ITEM_TYPE[$type] ?? throw new \InvalidArgumentException("Unsupported bordereau type {$type}.");

        $rows = match ($type) {
            'PREMIUM' => $policies(DB::table('policies as p'))
                ->when($policyIds === [], fn ($q) => $q->whereBetween('p.issued_at', [$from, $end]))
                ->orderBy('p.issued_at')->orderBy('p.id')->lockForUpdate()
                ->get(['p.id as source_id', 'p.id as policy_id', 'p.premium_minor as amount', 'p.issued_at as effective_at'])
                ->map(fn ($r) => ['POLICY', $r, (int) $r->amount, $this->commission($tenantId, $r->policy_id)]),
            'ENDORSEMENT' => $policies(DB::table('policy_transactions as t')->join('policies as p', 'p.id', '=', 't.policy_id'))
                ->where('t.tenant_id', $tenantId)->where('t.type', 'ENDORSEMENT')->where('t.status', 'APPROVED')
                ->whereBetween('t.approved_at', [$from, $end])->where('t.currency', $currency)
                ->orderBy('t.approved_at')->orderBy('t.id')
                ->get(['t.id as source_id', 't.policy_id', 't.premium_delta_minor as amount', 't.effective_at'])
                ->map(fn ($r) => ['POLICY_TRANSACTION', $r, (int) $r->amount, 0]),
            'CANCELLATION' => $policies(DB::table('policy_cancellations as c')->join('policies as p', 'p.id', '=', 'c.policy_id'))
                ->where('c.status', 'APPROVED')->whereBetween('c.decided_at', [$from, $end])->where('c.currency', $currency)
                ->orderBy('c.decided_at')->orderBy('c.id')
                ->get(['c.id as source_id', 'c.policy_id', 'c.refund_minor as amount', 'c.effective_at'])
                ->map(fn ($r) => ['POLICY_CANCELLATION', $r, -(int) $r->amount, 0]),
            'CLAIM' => $policies(DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id'))
                ->where('c.tenant_id', $tenantId)->where('c.status', '<>', 'DRAFT')->whereBetween('c.submitted_at', [$from, $end])
                ->where(fn ($q) => $q->whereNull('c.currency')->orWhere('c.currency', $currency))
                ->orderBy('c.submitted_at')->orderBy('c.id')
                ->get(['c.id as source_id', 'c.policy_id', DB::raw('COALESCE(c.approved_amount_minor, NULLIF(c.current_reserve_minor, 0), c.estimated_loss_minor, 0) as amount'), 'c.loss_occurred_at as effective_at'])
                ->map(fn ($r) => ['CLAIM', $r, (int) $r->amount, 0]),
            'COMMISSION' => $policies(DB::table('commission_accruals as a')->join('policies as p', 'p.id', '=', 'a.policy_id'))
                ->whereBetween('a.created_at', [$from, $end])->where('a.currency', $currency)
                ->orderBy('a.created_at')->orderBy('a.id')
                ->get(['a.id as source_id', 'a.policy_id', 'a.amount_minor as amount', 'a.created_at as effective_at'])
                ->map(fn ($r) => ['COMMISSION_ACCRUAL', $r, (int) $r->amount, (int) $r->amount]),
        };

        return $rows->map(fn (array $x) => [
            'source_type' => $x[0],
            'source_id' => $x[1]->source_id,
            'policy_id' => $x[1]->policy_id,
            'transaction_type' => $item,
            'premium_minor' => in_array($type, ['PREMIUM', 'ENDORSEMENT', 'CANCELLATION'], true) ? $x[2] : 0,
            'commission_minor' => $x[3],
            'amount_minor' => $x[2],
            'effective_at' => $x[1]->effective_at ?? now(),
        ])->values()->all();
    }

    private function commission(string $tenantId, string $policyId): int
    {
        return (int) DB::table('commission_accruals as a')->join('policies as p', 'p.id', '=', 'a.policy_id')
            ->where('p.tenant_id', $tenantId)->where('a.policy_id', $policyId)->sum('a.amount_minor');
    }
}
