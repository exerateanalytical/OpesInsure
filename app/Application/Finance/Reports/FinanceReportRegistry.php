<?php

declare(strict_types=1);

namespace App\Application\Finance\Reports;

use App\Application\Finance\Obligations\ObligationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Batch 10-10 — REQ-ACC-005 "20 finance reports" (FRP I; ESR FIN-001..024, FIN-024 Financial Reports).
 * A registry of read-only reports over the existing money-chain tables. Every report is tenant-scoped,
 * amounts are integer minor units + ISO currency. A report whose source tables are not installed (or whose
 * data is not modelled yet) is listed as NOT_AVAILABLE with the reason, never computed from guesses.
 */
final class FinanceReportRegistry
{
    public const AVAILABLE = 'AVAILABLE';

    public const NOT_AVAILABLE = 'NOT_AVAILABLE';

    public const MAX_ROWS = 5000;

    /** @return array<string, array{title:string, screen:string, tables:list<string>, date_column:?string, currency_column:?string, reason?:string}> */
    public static function definitions(): array
    {
        return [
            'FR-01' => ['title' => 'Cash & collections', 'screen' => 'FIN-002', 'tables' => ['payment_intents'], 'date_column' => 'created_at', 'currency_column' => 'currency'],
            'FR-02' => ['title' => 'Receivables aging', 'screen' => 'FIN-003', 'tables' => ['financial_obligations'], 'date_column' => null, 'currency_column' => 'currency'],
            'FR-03' => ['title' => 'Payables aging', 'screen' => 'FIN-004', 'tables' => ['financial_obligations'], 'date_column' => null, 'currency_column' => 'currency'],
            'FR-04' => ['title' => 'Premium receivables (open)', 'screen' => 'FIN-007', 'tables' => ['financial_obligations'], 'date_column' => 'due_at', 'currency_column' => 'currency'],
            'FR-05' => ['title' => 'Instalment schedule status', 'screen' => 'FIN-007', 'tables' => ['policy_premium_instalments'], 'date_column' => 'due_date', 'currency_column' => 'currency'],
            'FR-06' => ['title' => 'Premium components (gross-to-net)', 'screen' => 'FIN-008', 'tables' => ['premium_components'], 'date_column' => 'created_at', 'currency_column' => 'currency'],
            'FR-07' => ['title' => 'Payment allocations by category', 'screen' => 'FIN-008', 'tables' => ['payment_allocations'], 'date_column' => 'created_at', 'currency_column' => 'currency'],
            'FR-08' => ['title' => 'Reconciliation summary', 'screen' => 'FIN-005', 'tables' => ['reconciliation_imports'], 'date_column' => 'created_at', 'currency_column' => 'currency'],
            'FR-09' => ['title' => 'Unmatched transactions', 'screen' => 'FIN-022', 'tables' => ['reconciliation_items', 'reconciliation_imports'], 'date_column' => 'i.transaction_at', 'currency_column' => 'i.currency'],
            'FR-10' => ['title' => 'Mobile-money clearing', 'screen' => 'FIN-021', 'tables' => ['mobile_money_clearing_batches'], 'date_column' => 'settlement_date', 'currency_column' => 'currency'],
            'FR-11' => ['title' => 'Refund register', 'screen' => 'FIN-018', 'tables' => ['refunds'], 'date_column' => 'created_at', 'currency_column' => 'currency'],
            'FR-12' => ['title' => 'Cashier sessions & variances', 'screen' => 'FIN-002', 'tables' => ['cashier_sessions'], 'date_column' => 'opened_at', 'currency_column' => 'currency'],
            'FR-13' => ['title' => 'Trial balance', 'screen' => 'FIN-013', 'tables' => ['journals', 'journal_lines', 'ledger_accounts'], 'date_column' => 'j.posted_at', 'currency_column' => 'j.currency'],
            'FR-14' => ['title' => 'Journal register', 'screen' => 'FIN-014', 'tables' => ['journals', 'journal_lines'], 'date_column' => 'j.posted_at', 'currency_column' => 'j.currency'],
            'FR-15' => ['title' => 'Commission ledger', 'screen' => 'FIN-017', 'tables' => ['commission_accruals'], 'date_column' => 'created_at', 'currency_column' => 'currency'],
            'FR-16' => ['title' => 'Carrier settlement batches', 'screen' => 'FIN-019', 'tables' => ['settlement_batches'], 'date_column' => 'period_end', 'currency_column' => 'currency'],
            'FR-17' => ['title' => 'Partner (broker / agent) statements', 'screen' => 'FIN-010', 'tables' => ['partner_statements'], 'date_column' => 'period_end', 'currency_column' => 'currency'],
            'FR-18' => ['title' => 'Claims paid', 'screen' => 'FIN-004', 'tables' => ['claim_payments', 'claims'], 'date_column' => 'p.paid_at', 'currency_column' => 'p.currency'],
            'FR-19' => ['title' => 'Reinsurance ceded premium', 'screen' => 'FIN-024', 'tables' => ['reinsurance_cessions'], 'date_column' => 'created_at', 'currency_column' => 'currency'],
            'FR-20' => ['title' => 'Technical provisions (UPR / IBNR / outstanding)', 'screen' => 'FIN-024', 'tables' => ['technical_provisions'], 'date_column' => null, 'currency_column' => null,
                'reason' => 'Technical provisions are stored/imported from the insurer actuarial engine (FRP I); no provisions store exists yet and the platform must not invent actuarial methods.'],
        ];
    }

    /** @return list<array{code:string, title:string, screen:string, status:string, reason:?string}> */
    public function catalogue(): array
    {
        $out = [];
        foreach (self::definitions() as $code => $def) {
            $reason = $this->unavailableReason($def);
            $out[] = ['code' => $code, 'title' => $def['title'], 'screen' => $def['screen'], 'status' => $reason ? self::NOT_AVAILABLE : self::AVAILABLE, 'reason' => $reason];
        }

        return $out;
    }

    /**
     * @param  array{from?:?string, to?:?string, currency?:?string, as_of?:?string}  $params
     * @return array{code:string, title:string, screen:string, status:string, reason:?string, generated_at:string, params:array, columns:list<string>, rows:list<array<string, mixed>>, truncated:bool}
     */
    public function run(string $tenantId, string $code, array $params = []): array
    {
        $def = self::definitions()[$code] ?? throw new NotFoundHttpException("Unknown finance report {$code}.");
        $head = ['code' => $code, 'title' => $def['title'], 'screen' => $def['screen'], 'generated_at' => CarbonImmutable::now()->toIso8601String(), 'params' => $params];
        if ($reason = $this->unavailableReason($def)) {
            return $head + ['status' => self::NOT_AVAILABLE, 'reason' => $reason, 'columns' => [], 'rows' => [], 'truncated' => false];
        }

        $asOf = ! empty($params['as_of']) ? CarbonImmutable::parse($params['as_of']) : CarbonImmutable::now();
        if (in_array($code, ['FR-02', 'FR-03'], true)) {
            $rows = $this->aging($tenantId, $code === 'FR-02' ? 'RECEIVABLE' : 'PAYABLE', $params['currency'] ?? null, $asOf);
            $truncated = false;
        } else {
            $q = $this->query($code, $tenantId);
            if ($def['date_column'] && ! empty($params['from'])) {
                $q->where($def['date_column'], '>=', CarbonImmutable::parse($params['from'])->startOfDay());
            }
            if ($def['date_column'] && ! empty($params['to'])) {
                $q->where($def['date_column'], '<=', CarbonImmutable::parse($params['to'])->endOfDay());
            }
            if ($def['currency_column'] && ! empty($params['currency'])) {
                $q->where($def['currency_column'], strtoupper($params['currency']));
            }
            $rows = $q->limit(self::MAX_ROWS + 1)->get()->map(fn ($r) => (array) $r)->all();
            $truncated = count($rows) > self::MAX_ROWS;
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        return $head + ['status' => self::AVAILABLE, 'reason' => null, 'columns' => $rows ? array_keys($rows[0]) : [], 'rows' => array_values($rows), 'truncated' => $truncated];
    }

    /** CSV rendering of a report result (header row + data rows; NOT_AVAILABLE yields the reason). */
    public static function toCsv(array $report): string
    {
        $h = fopen('php://temp', 'r+');
        if ($report['status'] !== self::AVAILABLE) {
            fputcsv($h, ['code', 'status', 'reason']);
            fputcsv($h, [$report['code'], $report['status'], $report['reason']]);
        } else {
            fputcsv($h, $report['columns']);
            foreach ($report['rows'] as $row) {
                fputcsv($h, array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) || $v === null ? $v : json_encode($v)), array_values($row)));
            }
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);

        return $csv;
    }

    private function unavailableReason(array $def): ?string
    {
        if (isset($def['reason'])) {
            return $def['reason'];
        }
        foreach ($def['tables'] as $table) {
            if (! Schema::hasTable($table)) {
                return "Source table {$table} is not installed.";
            }
        }

        return null;
    }

    private function query(string $code, string $t): Builder
    {
        return match ($code) {
            'FR-01' => DB::table('payment_intents')->where('tenant_id', $t)->where('status', 'SUCCEEDED')
                ->selectRaw('cast(created_at as date) as day, provider, currency, count(*) as payments, sum(amount_minor) as collected_minor')
                ->groupByRaw('cast(created_at as date), provider, currency')->orderBy('day')->orderBy('provider'),
            'FR-04' => DB::table('financial_obligations')->where('tenant_id', $t)->where('kind', 'RECEIVABLE')->whereIn('type', ['PREMIUM', 'INSTALMENT'])
                ->whereIn('status', ObligationService::OPEN_STATUSES)
                ->select(['id', 'type', 'status', 'policy_id', 'source_reference', 'debtor_type', 'debtor_id', 'currency', 'amount_minor', 'outstanding_minor', 'due_at'])->orderBy('due_at'),
            'FR-05' => DB::table('policy_premium_instalments')->where('tenant_id', $t)
                ->selectRaw('status, currency, count(*) as instalments, sum(amount_minor) as amount_minor, sum(paid_minor) as paid_minor, sum(amount_minor - paid_minor) as outstanding_minor')
                ->groupBy('status', 'currency')->orderBy('status'),
            'FR-06' => DB::table('premium_components')->where('tenant_id', $t)->whereNull('closure')
                ->selectRaw('component, currency, count(*) as lines, sum(amount_minor) as amount_minor')->groupBy('component', 'currency')->orderBy('component'),
            'FR-07' => DB::table('payment_allocations')->where('tenant_id', $t)
                ->selectRaw('target_category, kind, currency, count(*) as allocations, sum(amount_minor) as amount_minor')->groupBy('target_category', 'kind', 'currency')->orderBy('target_category'),
            'FR-08' => DB::table('reconciliation_imports')->where('tenant_id', $t)
                ->select(['id', 'source_type', 'provider', 'statement_reference', 'period_start', 'period_end', 'currency', 'status', 'total_rows', 'matched_rows', 'exception_rows', 'completed_at', 'approved_at'])->orderByDesc('created_at'),
            'FR-09' => DB::table('reconciliation_items as i')->join('reconciliation_imports as m', 'm.id', '=', 'i.reconciliation_import_id')
                ->where('m.tenant_id', $t)->where('i.status', 'EXCEPTION')->whereNull('i.resolved_at')
                ->select(['i.id', 'm.provider', 'i.external_reference', 'i.transaction_at', 'i.currency', 'i.gross_minor', 'i.fee_minor', 'i.net_minor', 'i.expected_minor', 'i.variance_minor', 'i.outcome', 'i.exception_code', 'i.case_id'])->orderBy('i.transaction_at'),
            'FR-10' => DB::table('mobile_money_clearing_batches')->where('tenant_id', $t)
                ->select(['id', 'provider', 'settlement_reference', 'settlement_date', 'currency', 'status', 'expected_minor', 'fee_minor', 'settled_minor', 'variance_minor', 'bank_reference', 'reconciled_at'])->orderByDesc('settlement_date'),
            'FR-11' => DB::table('refunds')->where('tenant_id', $t)
                ->select(['id', 'refund_number', 'status', 'reason_code', 'currency', 'amount_minor', 'payout_method', 'source_type', 'created_at', 'reviewed_at', 'paid_at', 'reconciled_at', 'rejected_at'])->orderByDesc('created_at'),
            'FR-12' => DB::table('cashier_sessions')->where('tenant_id', $t)
                ->select(['id', 'branch_id', 'cashier_user_id', 'status', 'currency', 'opening_float_minor', 'expected_cash_minor', 'counted_cash_minor', 'variance_minor', 'cheque_count', 'cheque_total_minor', 'opened_at', 'closed_at', 'decided_at'])->orderByDesc('opened_at'),
            'FR-13' => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.tenant_id', $t)->where('j.status', 'POSTED')
                ->selectRaw('a.code as account_code, a.name as account_name, a.type as account_type, j.currency, sum(l.debit_minor) as debit_minor, sum(l.credit_minor) as credit_minor, sum(l.debit_minor) - sum(l.credit_minor) as balance_minor')
                ->groupBy('a.code', 'a.name', 'a.type', 'j.currency')->orderBy('a.code'),
            'FR-14' => DB::table('journals as j')->leftJoin('journal_lines as l', 'l.journal_id', '=', 'j.id')->where('j.tenant_id', $t)
                ->selectRaw('j.id, j.reference_type, j.reference_id, j.status, j.currency, j.posted_at, j.reverses_journal_id, coalesce(sum(l.debit_minor), 0) as debit_minor, coalesce(sum(l.credit_minor), 0) as credit_minor')
                ->groupBy('j.id', 'j.reference_type', 'j.reference_id', 'j.status', 'j.currency', 'j.posted_at', 'j.reverses_journal_id')->orderByDesc('j.posted_at'),
            'FR-15' => DB::table('commission_accruals')->where('tenant_id', $t)
                ->selectRaw('partner_id, status, currency, count(*) as accruals, sum(amount_minor) as accrued_minor, sum(vested_minor) as vested_minor, sum(paid_minor) as paid_minor, sum(clawed_back_minor) as clawed_back_minor')
                ->groupBy('partner_id', 'status', 'currency')->orderBy('partner_id'),
            'FR-16' => DB::table('settlement_batches')->where('tenant_id', $t)
                ->select(['id', 'settlement_number', 'carrier_id', 'period_start', 'period_end', 'currency', 'net_amount_minor', 'status', 'approved_at', 'submitted_at', 'paid_at', 'bank_reference'])->orderByDesc('period_end'),
            'FR-17' => DB::table('partner_statements')->where('tenant_id', $t)
                ->select(['id', 'statement_number', 'partner_id', 'period_start', 'period_end', 'currency', 'status', 'opening_balance_minor', 'earned_minor', 'clawed_back_minor', 'paid_minor', 'closing_balance_minor', 'published_at'])->orderByDesc('period_end'),
            'FR-18' => DB::table('claim_payments as p')->join('claims as c', 'c.id', '=', 'p.claim_id')->where('c.tenant_id', $t)->whereNotNull('p.paid_at')
                ->select(['p.id', 'c.claim_number', 'c.policy_id', 'p.payee_party_id', 'p.currency', 'p.amount_minor', 'p.status', 'p.paid_at', 'p.reversed_at', 'p.external_reference'])->orderByDesc('p.paid_at'),
            'FR-19' => DB::table('reinsurance_cessions')->where('tenant_id', $t)
                ->selectRaw('treaty_id, treaty_type, currency, count(*) as cessions, sum(gross_premium_minor) as gross_premium_minor, sum(ceded_premium_minor) as ceded_premium_minor, sum(commission_minor) as commission_minor, sum(net_ceded_premium_minor) as net_ceded_premium_minor')
                ->groupBy('treaty_id', 'treaty_type', 'currency')->orderBy('treaty_type'),
        };
    }

    /** @return list<array<string, mixed>> one row per currency with the CURRENT/1_30/31_60/61_90/90_PLUS buckets */
    private function aging(string $tenantId, string $kind, ?string $currency, CarbonImmutable $asOf): array
    {
        $rows = [];
        foreach (app(ObligationService::class)->aging($tenantId, $kind, $asOf) as $ccy => $buckets) {
            if ($currency && strtoupper($currency) !== $ccy) {
                continue;
            }
            $row = ['currency' => $ccy];
            foreach ($buckets as $b => $minor) {
                $row['bucket_'.strtolower($b).'_minor'] = $minor;
            }
            $row['total_minor'] = array_sum($buckets);
            $rows[] = $row;
        }

        return $rows;
    }
}
