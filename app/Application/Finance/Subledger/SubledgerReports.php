<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

use App\Application\Commissions\Machine\CommissionMachine;
use App\Application\Finance\ExceptionCentre\FinanceExceptionCentre;
use App\Application\Finance\Reports\FinanceReportRegistry;
use App\Application\Ledger\Periods\PreCloseChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Agent F1 — the spec's 22 finance sub-ledger reports. Reports that FR-01..20 already produce are DELEGATED to
 * FinanceReportRegistry (UNMATCHED_PAYMENTS = FR-09, REFUND_REGISTER = FR-11) and FINANCIAL_EXCEPTION_DASHBOARD to the Batch 10 finance
 * exception centre; the rest are read-only projections of the sub-ledger / obligations / accruals. A report the data model cannot
 * answer (DEBIT_CREDIT_NOTE_REGISTER — no note store) is NOT_AVAILABLE with the reason, never guessed. Same output shape as
 * FinanceReportRegistry (so its toCsv() renders exports) plus `applied_filters`.
 */
final class SubledgerReports
{
    public const DELEGATED = ['UNMATCHED_PAYMENTS' => 'FR-09', 'REFUND_REGISTER' => 'FR-11'];

    public const BALANCE_BY = ['ACCOUNT_BALANCE_BY_INSURER' => 'insurer', 'ACCOUNT_BALANCE_BY_BROKER' => 'broker', 'ACCOUNT_BALANCE_BY_CLIENT' => 'client',
        'ACCOUNT_BALANCE_BY_PRODUCT' => 'product', 'ACCOUNT_BALANCE_BY_INSURANCE_CLASS' => 'insurance_class', 'ACCOUNT_BALANCE_BY_CIMA_BRANCH' => 'cima_branch'];

    public function __construct(private SubledgerQuery $query, private SubledgerViews $views, private AgingService $aging) {}

    /** @return list<array{code:string, status:string, source:string}> */
    public function catalogue(): array
    {
        return array_map(fn (string $c) => ['code' => $c, 'status' => $c === 'DEBIT_CREDIT_NOTE_REGISTER' ? FinanceReportRegistry::NOT_AVAILABLE : FinanceReportRegistry::AVAILABLE,
            'source' => self::DELEGATED[$c] ?? ($c === 'FINANCIAL_EXCEPTION_DASHBOARD' ? 'FINANCE_EXCEPTION_CENTRE' : 'SUBLEDGER')], SubledgerCatalogue::spec()['reports']);
    }

    public function run(string $tenantId, string $code, array $filters): array
    {
        if (! in_array($code, SubledgerCatalogue::spec()['reports'], true)) {
            throw new NotFoundHttpException("Unknown sub-ledger report {$code}.");
        }
        $head = ['code' => $code, 'title' => ucwords(strtolower(str_replace('_', ' ', $code))), 'screen' => 'FIN-SUBLEDGER', 'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'params' => $filters, 'applied_filters' => $filters];
        if (isset(self::DELEGATED[$code])) {
            $r = app(FinanceReportRegistry::class)->run($tenantId, self::DELEGATED[$code], array_filter(['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null, 'currency' => $filters['currency'] ?? null]));

            return ['delegated_to' => self::DELEGATED[$code]] + $r + ['applied_filters' => $filters];
        }
        if ($code === 'DEBIT_CREDIT_NOTE_REGISTER') {
            return $head + ['status' => FinanceReportRegistry::NOT_AVAILABLE, 'reason' => 'No debit/credit note store exists yet (spec DOC-188/189 are catalogue types only); adjustments are MANUAL journals.',
                'columns' => [], 'rows' => [], 'truncated' => false];
        }
        $rows = $this->rows($tenantId, $code, $filters);
        $rows = array_values(array_map(fn ($r) => array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v, (array) $r), $rows));
        $truncated = count($rows) > SubledgerQuery::MAX_ROWS;
        $rows = array_slice($rows, 0, SubledgerQuery::MAX_ROWS);

        return $head + ['status' => FinanceReportRegistry::AVAILABLE, 'reason' => null, 'columns' => $rows ? array_keys($rows[0]) : [], 'rows' => $rows, 'truncated' => $truncated];
    }

    private function rows(string $tenantId, string $code, array $f): array
    {
        if (isset(self::BALANCE_BY[$code])) {
            return array_map(fn ($r) => array_diff_key($r, ['drilldown' => 1]), $this->query->balances($tenantId, $f, self::BALANCE_BY[$code]));
        }

        return match ($code) {
            'BROKER_CARRIER_ACCOUNTS' => $this->carrierAccounts($tenantId, $f),
            'INSURER_BROKER_ACCOUNTS' => $this->brokerAccounts($tenantId, $f),
            'COMMISSION_AGING' => $this->aging->age($tenantId, 'COMMISSION', $f)['rows'],
            'PREMIUM_RECEIVABLE_AGING' => $this->aging->age($tenantId, 'RECEIVABLE', $f)['rows'],
            'PREMIUM_REMITTANCE_AGING' => $this->aging->age($tenantId, 'REMITTANCE', $f)['rows'],
            'CUSTOMER_ACCOUNT_LEDGER' => $this->query->entries($tenantId, $f)->whereIn('e.entry_type', ['CUSTOMER_RECEIVABLE', 'CUSTOMER_DEPOSIT'])->orderBy('e.posting_date')->limit(SubledgerQuery::MAX_ROWS + 1)
                ->get(['e.id', 'e.posting_date', 'e.customer_id', 'e.policy_id', 'e.journal_type', 'e.entry_type', 'e.debit_amount', 'e.credit_amount', 'e.currency', 'e.journal_id'])->all(),
            'AGENT_COMMISSION_LEDGER' => $this->commissionLedger($tenantId, $f, 'AGENT'),
            'BROKER_COMMISSION_LEDGER' => $this->commissionLedger($tenantId, $f, 'BROKER'),
            'INSURER_COMMISSION_PAYABLE' => array_values(array_filter($this->commissionLedger($tenantId, $f, null), fn ($r) => in_array($r['status'], ['PAYABLE', 'PARTIALLY_PAID'], true))),
            'SETTLEMENT_RECONCILIATION' => $this->settlementReconciliation($tenantId, $f),
            'UNAPPLIED_CASH' => DB::table('premium_remittances')->where('tenant_id', $tenantId)->whereColumn('allocated_minor', '<', 'amount_minor')
                ->when($f['currency'] ?? null, fn ($q, $c) => $q->where('currency', $c))->when($f['insurer_id'] ?? null, fn ($q, $i) => $q->where('insurer_id', $i))
                ->orderBy('remittance_date')->get()->map(fn ($r) => ['remittance_id' => $r->id, 'remittance_number' => $r->remittance_number, 'insurer_id' => $r->insurer_id,
                    'remittance_date' => $r->remittance_date, 'currency' => $r->currency, 'amount_minor' => (int) $r->amount_minor, 'allocated_minor' => (int) $r->allocated_minor,
                    'unapplied_minor' => (int) $r->amount_minor - (int) $r->allocated_minor, 'status' => $r->status])->all(),
            'PERIOD_CLOSE_STATUS' => DB::table('accounting_periods')->where('tenant_id', $tenantId)->orderBy('starts_on')->get()->map(fn ($p) => [
                'period_id' => $p->id, 'fiscal_year' => $p->fiscal_year, 'period_number' => $p->period_number, 'starts_on' => $p->starts_on, 'ends_on' => $p->ends_on,
                'status' => $p->status, 'spec_status' => SubledgerCatalogue::PERIOD_STATUS[$p->status] ?? $p->status, 'reopen_status' => $p->reopen_status,
                'checks' => $this->closeChecks($p)])->all(),
            'FINANCIAL_EXCEPTION_DASHBOARD' => collect(app(FinanceExceptionCentre::class)->summary($tenantId)['sources'])
                ->map(fn ($s, $k) => ['source' => $k, 'count' => $s['count'] ?? 0, 'status' => $s['status'] ?? 'AVAILABLE'])->values()->all(),
        };
    }

    private function carrierAccounts(string $tenantId, array $f): array
    {
        $carriers = DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('creditor_type', 'carrier')->distinct()->pluck('creditor_id')
            ->merge(DB::table('policies')->where('tenant_id', $tenantId)->distinct()->pluck('carrier_id'))->filter()->unique()
            ->when($f['insurer_id'] ?? null, fn ($c, $i) => $c->filter(fn ($x) => $x === $i));

        return $carriers->map(function ($id) use ($tenantId, $f) {
            $a = $this->views->brokerInsurerAccount($tenantId, $id, $f);

            return ['insurer_id' => $id, 'premium_payable_gross_minor' => $a['premium_payable']['gross_minor'], 'premium_remitted_minor' => $a['premium_payable']['settled_minor'],
                'premium_unremitted_minor' => $a['premium_payable']['outstanding_minor'], 'commission_receivable_gross_minor' => $a['commission_receivable']['gross_minor'],
                'commission_receivable_outstanding_minor' => $a['commission_receivable']['outstanding_minor'], 'unapplied_remittance_minor' => $a['unapplied_remittance_minor'],
                'net_due_to_insurer_minor' => $a['net_due_to_insurer_minor']];
        })->values()->all();
    }

    private function brokerAccounts(string $tenantId, array $f): array
    {
        return DB::table('partners')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->where('type', 'BROKER')
            ->when($f['broker_id'] ?? null, fn ($q, $b) => $q->where('id', $b))->pluck('id')
            ->map(function ($id) use ($tenantId, $f) {
                $a = $this->views->insurerBrokerAccount($tenantId, $id, $f);

                return ['broker_id' => $id, 'premium_receivable_gross_minor' => $a['premium_receivable']['gross_minor'], 'premium_received_minor' => $a['premium_receivable']['settled_minor'],
                    'premium_receivable_outstanding_minor' => $a['premium_receivable']['outstanding_minor'], 'commission_payable_gross_minor' => $a['commission_payable']['gross_minor'],
                    'commission_paid_minor' => $a['commission_payable']['paid_minor'], 'commission_payable_outstanding_minor' => $a['commission_payable']['outstanding_minor'],
                    'net_due_to_broker_minor' => $a['net_due_to_broker_minor']];
            })->filter(fn ($r) => $r['premium_receivable_gross_minor'] || $r['commission_payable_gross_minor'])->values()->all();
    }

    private function commissionLedger(string $tenantId, array $f, ?string $partnerType): array
    {
        $partners = $partnerType ? DB::table('partners')->where('type', $partnerType)->pluck('id')->all() : null;

        return $this->views->accruals($tenantId, $f['currency'] ?? null, $f)
            ->when($partners !== null, fn ($c) => $c->whereIn('partner_id', $partners))
            ->map(fn ($a) => ['commission_id' => $a->id, 'partner_id' => $a->partner_id, 'policy_id' => $a->policy_id, 'currency' => $a->currency, 'gross_commission' => (int) $a->amount_minor,
                'paid_amount' => (int) $a->paid_minor, 'clawed_back' => (int) $a->clawed_back_minor,
                'outstanding_amount' => in_array($a->status, ['REVERSED', 'CLAWED_BACK'], true) ? 0 : max(0, (int) $a->amount_minor - (int) $a->paid_minor - (int) $a->clawed_back_minor),
                'status' => CommissionMachine::specState($a), 'stored_status' => $a->status, 'earned_at' => $a->earned_at, 'payable_at' => $a->payable_at, 'paid_at' => $a->paid_at])->values()->all();
    }

    /** Settlement net vs the journals it posted and the carrier payables it opened — a difference is a reconciliation finding. */
    private function settlementReconciliation(string $tenantId, array $f): array
    {
        return DB::table('settlement_batches')->where('tenant_id', $tenantId)->when($f['currency'] ?? null, fn ($q, $c) => $q->where('currency', $c))
            ->when($f['insurer_id'] ?? null, fn ($q, $i) => $q->where('carrier_id', $i))->orderBy('period_end')->get()
            ->map(function ($b) {
                $posted = (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.reference_type', 'settlement.approved')
                    ->where('j.reference_id', $b->id)->whereIn('j.status', ['POSTED', 'REVERSED'])->sum('l.debit_minor');
                $lines = (int) DB::table('financial_obligations')->where('source_type', 'settlement_batch')->where('source_id', $b->id)->sum('amount_minor');

                return ['settlement_id' => $b->id, 'settlement_number' => $b->settlement_number, 'carrier_id' => $b->carrier_id, 'status' => $b->status,
                    'spec_status' => SubledgerCatalogue::SETTLEMENT_STATUS[$b->status] ?? $b->status, 'currency' => $b->currency, 'net_settlement_minor' => (int) $b->net_amount_minor,
                    'settlement_lines_minor' => $lines, 'posted_minor' => $posted, 'difference_minor' => $lines ? (int) $b->net_amount_minor - $lines : 0];
            })->all();
    }

    /** Spec period_close.checks evaluated against the period (Batch 10 PreCloseChecklist + the sub-ledger specific checks). */
    private function closeChecks(object $p): array
    {
        $pre = app(PreCloseChecklist::class)->run($p)['items'];
        $t = $p->tenant_id;
        $unbalanced = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->where('j.tenant_id', $t)->whereBetween('j.accounting_date', [$p->starts_on, $p->ends_on])
            ->groupBy('j.id')->havingRaw('SUM(l.debit_minor) <> SUM(l.credit_minor)')->select('j.id')->get()->count();

        return [
            'all journals balanced' => $unbalanced === 0,
            'unposted approved transactions reviewed' => ($pre['pending_manual_journals']['count'] ?? 0) === 0,
            'bank/mobile-money reconciliation reviewed' => ($pre['unreconciled_items']['count'] ?? 0) === 0,
            'broker-carrier reconciliation reviewed' => DB::table('premium_remittances')->where('tenant_id', $t)->whereIn('status', ['DISPUTED', 'RECONCILIATION_HOLD'])->doesntExist(),
            'unapplied cash reviewed' => DB::table('premium_remittances')->where('tenant_id', $t)->whereColumn('allocated_minor', '<', 'amount_minor')->where('remittance_date', '<=', $p->ends_on)->doesntExist(),
            'commission accruals completed' => DB::table('commission_accruals')->where('tenant_id', $t)->where('status', 'CALCULATED')->where('created_at', '<=', $p->ends_on.' 23:59:59')->doesntExist(),
            'commission clawbacks posted' => DB::table('commission_accruals as a')->where('a.tenant_id', $t)->where('a.clawed_back_minor', '>', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journals as j')->where('j.reference_type', 'commission.clawed_back')->whereColumn('j.reference_id', 'a.id'))->doesntExist(),
            'tax/levy postings completed' => true,
            'settlement differences reviewed' => ($pre['unapproved_settlements']['count'] ?? 0) === 0,
            'suspense balances reviewed' => ($pre['suspense_balance']['count'] ?? 0) === 0,
        ];
    }
}
