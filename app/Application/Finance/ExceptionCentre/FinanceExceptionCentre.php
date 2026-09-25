<?php

declare(strict_types=1);

namespace App\Application\Finance\ExceptionCentre;

use App\Application\Finance\Obligations\ObligationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 10-10 — REQ-ACC-005 finance exception centre (ESR FIN-006). One read-only view over every open
 * finance exception already recorded by the Batch 9 money chain. Each source is guarded with Schema::hasTable
 * so a missing module reports available=false instead of failing the whole centre.
 *
 * Sources: issuance exceptions (paid-not-issued etc.), reconciliation exceptions (unmatched / variance items),
 * refunds awaiting action, mobile-money clearing variances, overdue obligations by aging bucket,
 * closed cashier sessions awaiting supervisor approval.
 */
final class FinanceExceptionCentre
{
    public const SOURCES = ['issuance_exceptions', 'reconciliation_exceptions', 'refunds_awaiting_action', 'clearing_variances', 'overdue_obligations', 'cashier_sessions_awaiting_approval'];

    /** Refund states that still need somebody to act (review, pay or reconcile). */
    public const REFUND_ACTION_STATUSES = ['CANDIDATE', 'CALCULATED', 'REQUESTED', 'APPROVED', 'PROCESSING', 'PAID'];

    private const ITEM_LIMIT = 50;

    /** @return array{as_of:string, total_open:int, sources:array<string, array<string, mixed>>} */
    public function summary(string $tenantId, ?CarbonImmutable $asOf = null, ?array $only = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $sources = [];
        foreach (self::SOURCES as $source) {
            if ($only && ! in_array($source, $only, true)) {
                continue;
            }
            $sources[$source] = $this->{lcfirst(str_replace('_', '', ucwords($source, '_')))}($tenantId, $asOf);
        }

        return ['as_of' => $asOf->toIso8601String(), 'total_open' => array_sum(array_column($sources, 'count')), 'sources' => $sources];
    }

    private function issuanceExceptions(string $tenantId, CarbonImmutable $asOf): array
    {
        return $this->guarded(['issuance_exceptions'], fn () => $this->section(
            DB::table('issuance_exceptions')->where('tenant_id', $tenantId)->whereIn('status', ['OPEN', 'ESCALATED']),
            ['id', 'kind', 'status', 'reason_code', 'proposal_id', 'payment_intent_id', 'attempts', 'escalated_at', 'created_at'], 'created_at',
            null, fn (Builder $q) => $q->selectRaw('kind as k, count(*) as n')->groupBy('kind'),
        ));
    }

    private function reconciliationExceptions(string $tenantId, CarbonImmutable $asOf): array
    {
        return $this->guarded(['reconciliation_items', 'reconciliation_imports'], fn () => $this->section(
            DB::table('reconciliation_items as i')->join('reconciliation_imports as m', 'm.id', '=', 'i.reconciliation_import_id')
                ->where('m.tenant_id', $tenantId)->where('i.status', 'EXCEPTION')->whereNull('i.resolved_at'),
            ['i.id', 'i.external_reference', 'i.exception_code', 'i.outcome', 'i.gross_minor', 'i.variance_minor', 'i.currency', 'i.case_id', 'i.refund_candidate', 'i.transaction_at', 'm.provider'], 'i.transaction_at',
            ['i.currency', 'i.gross_minor'], fn (Builder $q) => $q->selectRaw("coalesce(i.outcome, i.exception_code, 'UNKNOWN') as k, count(*) as n")->groupByRaw("coalesce(i.outcome, i.exception_code, 'UNKNOWN')"),
        ));
    }

    private function refundsAwaitingAction(string $tenantId, CarbonImmutable $asOf): array
    {
        return $this->guarded(['refunds'], fn () => $this->section(
            DB::table('refunds')->where('tenant_id', $tenantId)->whereIn('status', self::REFUND_ACTION_STATUSES)->whereNull('reconciled_at'),
            ['id', 'refund_number', 'status', 'amount_minor', 'currency', 'reason_code', 'payout_method', 'created_at'], 'created_at',
            ['currency', 'amount_minor'], fn (Builder $q) => $q->selectRaw('status as k, count(*) as n')->groupBy('status'),
        ));
    }

    private function clearingVariances(string $tenantId, CarbonImmutable $asOf): array
    {
        return $this->guarded(['mobile_money_clearing_batches'], fn () => $this->section(
            DB::table('mobile_money_clearing_batches')->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->where('status', 'VARIANCE')->orWhere(fn ($w) => $w->where('status', 'SETTLED')->whereNotNull('variance_minor')->where('variance_minor', '!=', 0))),
            ['id', 'provider', 'settlement_reference', 'settlement_date', 'status', 'currency', 'expected_minor', 'fee_minor', 'settled_minor', 'variance_minor'], 'settlement_date',
            ['currency', 'variance_minor'], fn (Builder $q) => $q->selectRaw('provider as k, count(*) as n')->groupBy('provider'),
        ));
    }

    private function overdueObligations(string $tenantId, CarbonImmutable $asOf): array
    {
        return $this->guarded(['financial_obligations'], function () use ($tenantId, $asOf): array {
            $base = DB::table('financial_obligations')->where('tenant_id', $tenantId)->whereIn('status', ObligationService::OPEN_STATUSES)
                ->where('outstanding_minor', '>', 0)->where('due_at', '<', $asOf->startOfDay());
            $buckets = [];
            $count = 0;
            (clone $base)->orderBy('id')->select(['kind', 'currency', 'due_at', 'outstanding_minor'])->chunk(500, function ($rows) use (&$buckets, &$count, $asOf): void {
                foreach ($rows as $r) {
                    $bucket = ObligationService::bucket($r->due_at, $asOf);
                    $buckets[$r->kind][$r->currency] ??= array_fill_keys(array_slice(ObligationService::BUCKETS, 1), ['count' => 0, 'outstanding_minor' => 0]);
                    $buckets[$r->kind][$r->currency][$bucket]['count']++;
                    $buckets[$r->kind][$r->currency][$bucket]['outstanding_minor'] += (int) $r->outstanding_minor;
                    $count++;
                }
            });
            $items = (clone $base)->orderBy('due_at')->limit(self::ITEM_LIMIT)
                ->get(['id', 'kind', 'type', 'status', 'source_type', 'source_reference', 'policy_id', 'currency', 'outstanding_minor', 'due_at'])
                ->map(function ($r) use ($asOf) {
                    $r->aging_bucket = ObligationService::bucket($r->due_at, $asOf);

                    return $r;
                });

            return ['available' => true, 'count' => $count, 'amounts' => $this->amounts(clone $base, 'currency', 'outstanding_minor'), 'breakdown' => $buckets, 'items' => $items->all()];
        });
    }

    private function cashierSessionsAwaitingApproval(string $tenantId, CarbonImmutable $asOf): array
    {
        return $this->guarded(['cashier_sessions'], fn () => $this->section(
            DB::table('cashier_sessions')->where('tenant_id', $tenantId)->where('status', 'CLOSED'),
            ['id', 'branch_id', 'cashier_user_id', 'currency', 'expected_cash_minor', 'counted_cash_minor', 'variance_minor', 'closed_at'], 'closed_at',
            ['currency', 'variance_minor'], fn (Builder $q) => $q->selectRaw("case when coalesce(variance_minor, 0) = 0 then 'BALANCED' else 'VARIANCE' end as k, count(*) as n")
                ->groupByRaw("case when coalesce(variance_minor, 0) = 0 then 'BALANCED' else 'VARIANCE' end"),
        ));
    }

    /** @param array{0:string,1:string}|null $sum [currencyColumn, amountColumn] */
    private function section(Builder $base, array $columns, string $orderBy, ?array $sum, callable $breakdown): array
    {
        return [
            'available' => true,
            'count' => (clone $base)->count(),
            'amounts' => $sum ? $this->amounts(clone $base, $sum[0], $sum[1]) : [],
            'breakdown' => $breakdown(clone $base)->pluck('n', 'k')->map(fn ($n) => (int) $n)->all(),
            'items' => (clone $base)->orderBy($orderBy)->limit(self::ITEM_LIMIT)->get($columns)->all(),
        ];
    }

    /** @return array<string, int> currency => summed minor units */
    private function amounts(Builder $q, string $currency, string $amount): array
    {
        return $q->selectRaw("{$currency} as c, coalesce(sum({$amount}), 0) as s")->groupBy($currency)->pluck('s', 'c')->map(fn ($s) => (int) $s)->all();
    }

    private function guarded(array $tables, callable $read): array
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return ['available' => false, 'reason' => "Table {$table} is not installed.", 'count' => 0, 'amounts' => [], 'breakdown' => [], 'items' => []];
            }
        }

        return $read();
    }
}
