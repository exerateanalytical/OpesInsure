<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

use App\Application\Commissions\Machine\CommissionMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agent F1 — spec global filters + drill-down over the sub-ledger. Every read catches the projector up first, is tenant-scoped
 * (tenant_isolation_required) and applies EXACTLY the filters it echoes back in `applied_filters`, so an export of the same request
 * matches the screen ("Export exactly matches active filters"). A filter the data model cannot answer (group_id / fleet_id: no group
 * or fleet store exists) is refused with 422 rather than silently ignored.
 */
final class SubledgerQuery
{
    public const MAX_ROWS = 5000;

    private const NOT_MODELLED = ['group_id' => 'No customer group store exists yet.', 'fleet_id' => 'No fleet store exists yet.'];

    /** Dimension filters applied directly to finance_ledger_entries columns. */
    private const DIMENSION_FILTERS = ['insurer_id', 'broker_id', 'agent_id', 'customer_id', 'provider_id', 'reinsurer_id', 'branch_id', 'region_id', 'sales_channel_id',
        'product_id', 'product_version_id', 'insurance_class_id', 'cima_branch_id', 'policy_id', 'claim_id', 'invoice_id', 'payment_id'];

    /** spec group_by => entry dimension. */
    public const GROUP_BY = ['insurer' => 'insurer_id', 'broker' => 'broker_id', 'agent' => 'agent_id', 'client' => 'customer_id', 'policy' => 'policy_id', 'product' => 'product_id',
        'insurance_class' => 'insurance_class_id', 'cima_branch' => 'cima_branch_id', 'branch' => 'branch_id', 'period' => 'period', 'account' => 'entry_type', 'provider' => 'provider_id'];

    /** Drill-down keys (not spec filters) that dashboards pass to narrow entries to one KPI's source. */
    public const DRILL_KEYS = ['entry_type', 'journal_type', 'side', 'commission_id', 'settlement_id', 'batch_id', 'counterparty_account_id', 'journal_id'];

    public function __construct(private SubledgerProjector $projector) {}

    /** @return array<string, mixed> the filters that were applied (validated, normalised) */
    public function normalise(array $input): array
    {
        foreach (self::NOT_MODELLED as $k => $reason) {
            if (! empty($input[$k])) {
                throw ValidationException::withMessages([$k => "Filter {$k} is not available: {$reason}"]);
            }
        }
        $f = array_intersect_key($input, array_flip(array_merge(SubledgerCatalogue::spec()['filters'], self::DRILL_KEYS)));
        if (isset($f['side']) && ! in_array($f['side'], ['DEBIT', 'CREDIT'], true)) {
            throw ValidationException::withMessages(['side' => 'side must be DEBIT or CREDIT.']);
        }
        $f = array_filter($f, fn ($v) => $v !== null && $v !== '');
        if (isset($f['currency'])) {
            $f['currency'] = strtoupper((string) $f['currency']);
        }
        if (isset($f['aging_bucket']) && ! isset(SubledgerCatalogue::AGING_BUCKETS[$f['aging_bucket']])) {
            throw ValidationException::withMessages(['aging_bucket' => 'Unknown aging bucket.']);
        }
        ksort($f);

        return $f;
    }

    public function entries(string $tenantId, array $filters): Builder
    {
        $this->projector->catchUp($tenantId);
        $q = DB::table('finance_ledger_entries as e')->where('e.tenant_id', $tenantId);
        foreach (self::DIMENSION_FILTERS as $dim) {
            if (! empty($filters[$dim])) {
                $q->where('e.'.$dim, $filters[$dim]);
            }
        }
        foreach (['entry_type', 'journal_type', 'commission_id', 'settlement_id', 'batch_id', 'counterparty_account_id', 'journal_id'] as $k) {
            if (! empty($filters[$k])) {
                $q->where('e.'.$k, $filters[$k]);
            }
        }
        $f = $filters;
        $q->when($f['side'] ?? null, fn ($q, $v) => $q->where($v === 'DEBIT' ? 'e.debit_amount' : 'e.credit_amount', '>', 0))
            ->when($f['currency'] ?? null, fn ($q, $v) => $q->where('e.currency', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('e.posting_date', '>=', CarbonImmutable::parse($v)->toDateString()))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('e.posting_date', '<=', CarbonImmutable::parse($v)->toDateString()))
            ->when($f['transaction_date'] ?? null, fn ($q, $v) => $q->where('e.transaction_date', CarbonImmutable::parse($v)->toDateString()))
            ->when($f['value_date'] ?? null, fn ($q, $v) => $q->where('e.value_date', CarbonImmutable::parse($v)->toDateString()))
            ->when($f['amount_min'] ?? null, fn ($q, $v) => $q->whereRaw('(e.debit_amount + e.credit_amount) >= ?', [(int) $v]))
            ->when($f['amount_max'] ?? null, fn ($q, $v) => $q->whereRaw('(e.debit_amount + e.credit_amount) <= ?', [(int) $v]))
            ->when($f['accounting_period'] ?? null, function ($q, $v) use ($tenantId) {
                $p = DB::table('accounting_periods')->where('tenant_id', $tenantId)->where(fn ($w) => $w->where('id', $v)->orWhereRaw("fiscal_year || '-' || lpad(period_number::text, 2, '0') = ?", [$v]))->first();
                $p ? $q->whereBetween('e.posting_date', [$p->starts_on, $p->ends_on]) : $q->whereRaw('1 = 0');
            })
            ->when($f['aging_bucket'] ?? null, function ($q, $v) {
                [$from, $to] = SubledgerCatalogue::AGING_BUCKETS[$v];
                $today = CarbonImmutable::now()->startOfDay();
                $v === 'CURRENT' ? $q->where('e.posting_date', '>=', $today->toDateString())
                    : $q->where('e.posting_date', '<=', $today->subDays($from)->toDateString())->when($to !== null, fn ($q) => $q->where('e.posting_date', '>=', $today->subDays($to)->toDateString()));
            });
        $this->sourceDateFilters($q, $tenantId, $f);
        $this->statusFilters($q, $tenantId, $f);

        return $q;
    }

    /** @return array{applied_filters:array, rows:list<array>, totals:array, truncated:bool} */
    public function list(string $tenantId, array $filters): array
    {
        $rows = $this->entries($tenantId, $filters)->orderBy('e.posting_date')->orderBy('e.created_at')->orderBy('e.id')->limit(self::MAX_ROWS + 1)->get()->map(fn ($r) => (array) $r)->all();
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        return ['applied_filters' => $filters, 'rows' => $rows, 'totals' => $this->totals($tenantId, $filters), 'truncated' => $truncated];
    }

    /** @return array<string, array{debit_minor:int, credit_minor:int, net_minor:int}> currency => totals */
    public function totals(string $tenantId, array $filters): array
    {
        return $this->entries($tenantId, $filters)->groupBy('e.currency')->selectRaw('e.currency, SUM(e.debit_amount) d, SUM(e.credit_amount) c')->get()
            ->mapWithKeys(fn ($r) => [$r->currency => ['debit_minor' => (int) $r->d, 'credit_minor' => (int) $r->c, 'net_minor' => (int) $r->d - (int) $r->c]])->all();
    }

    /** Balances grouped by one spec dimension (and sub-ledger), for dashboards and ACCOUNT_BALANCE_BY_* reports. */
    public function balances(string $tenantId, array $filters, string $groupBy, ?array $entryTypes = null): array
    {
        $col = self::GROUP_BY[$groupBy] ?? throw ValidationException::withMessages(['group_by' => "Unknown group_by {$groupBy}."]);
        $expr = $col === 'period' ? "to_char(e.posting_date, 'YYYY-MM')" : 'e.'.$col;

        return $this->entries($tenantId, $filters)->when($entryTypes, fn ($q, $t) => $q->whereIn('e.entry_type', $t))
            ->groupByRaw("{$expr}, e.entry_type, e.currency")->selectRaw("{$expr} as group_key, e.entry_type, e.currency, SUM(e.debit_amount) debit_minor, SUM(e.credit_amount) credit_minor, COUNT(*) entries")
            ->orderByRaw('1')->get()->map(fn ($r) => ['group_by' => $groupBy, 'group_key' => $r->group_key, 'entry_type' => $r->entry_type, 'currency' => $r->currency,
                'debit_minor' => (int) $r->debit_minor, 'credit_minor' => (int) $r->credit_minor, 'balance_minor' => (int) $r->debit_minor - (int) $r->credit_minor, 'entries' => (int) $r->entries,
                'drilldown' => ['filters' => $col === 'period' || $r->group_key === null ? $filters : $filters + [$col => $r->group_key]]])->all();
    }

    /**
     * Drill-down of one sub-ledger entry: entry → journal (+ all its lines, balanced) → source entity → policy → supporting documents.
     * Covers both spec drilldown_paths (commission and unremitted premium).
     */
    public function drilldown(string $tenantId, string $entryId): array
    {
        $this->projector->catchUp($tenantId);
        $e = DB::table('finance_ledger_entries')->where('tenant_id', $tenantId)->where('id', $entryId)->first();
        abort_unless($e, 404);
        $journal = DB::table('journals')->where('tenant_id', $tenantId)->where('id', $e->journal_id)->first();
        $lines = DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $e->journal_id)
            ->get(['l.id', 'a.code', 'a.name', 'l.debit_minor', 'l.credit_minor'])->map(fn ($l) => (array) $l)->all();
        $source = $e->source_entity_type && $e->source_entity_id ? DB::table($e->source_entity_type)->where('id', $e->source_entity_id)->first() : null;
        $policy = $e->policy_id ? DB::table('policies')->where('tenant_id', $tenantId)->where('id', $e->policy_id)->first(['id', 'policy_number', 'party_id', 'carrier_id', 'status', 'coverage_starts_at', 'coverage_ends_at']) : null;
        $documents = DB::table('documents')->where('tenant_id', $tenantId)->where(function ($q) use ($e) {
            $q->when($e->policy_id, fn ($q, $p) => $q->orWhere('policy_id', $p))->when($e->claim_id, fn ($q, $c) => $q->orWhere('claim_id', $c))
                ->orWhere(fn ($q) => $q->where('subject_key', $e->source_entity_id))->orWhere(fn ($q) => $q->where('subject_key', $e->journal_id));
        })->orderBy('created_at')->get(['id', 'document_type_code', 'document_number', 'title', 'status', 'issued_at'])->map(fn ($d) => (array) $d)->all();

        return [
            'entry' => (array) $e,
            'journal' => $journal ? ['id' => $journal->id, 'reference_type' => $journal->reference_type, 'reference_id' => $journal->reference_id, 'status' => $journal->status,
                'spec_status' => SubledgerCatalogue::JOURNAL_STATUS[$journal->status] ?? $journal->status, 'journal_type' => $e->journal_type, 'posted_at' => $journal->posted_at,
                'reverses_journal_id' => $journal->reverses_journal_id, 'lines' => $lines,
                'balanced' => array_sum(array_column($lines, 'debit_minor')) === array_sum(array_column($lines, 'credit_minor'))] : null,
            'source' => $source ? ['type' => $e->source_entity_type, 'id' => $e->source_entity_id, 'record' => (array) $source] : null,
            'policy' => $policy ? (array) $policy : null,
            'documents' => $documents,
            'path' => array_values(array_filter(['ENTRY', $journal ? 'JOURNAL_ENTRY' : null, $source ? 'SOURCE_TRANSACTION' : null, $policy ? 'POLICY' : null, $documents ? 'SUPPORTING_DOCUMENT' : null])),
        ];
    }

    private function sourceDateFilters(Builder $q, string $tenantId, array $f): void
    {
        $day = fn ($v) => CarbonImmutable::parse($v)->toDateString();
        $in = function (string $col, string $table, mixed $idCol, string $dateCol, string $v) use ($q, $tenantId) {
            $q->whereIn('e.'.$col, DB::table($table)->where('tenant_id', $tenantId)->whereRaw("DATE({$dateCol}) = ?", [$v])->select($idCol));
        };
        if ($v = $f['due_date'] ?? null) {
            $q->whereIn('e.policy_id', DB::table('financial_obligations')->where('tenant_id', $tenantId)->whereRaw('DATE(due_at) = ?', [$day($v)])->whereNotNull('policy_id')->selectRaw('policy_id::text'));
        }
        if ($v = $f['collection_date'] ?? null) {
            $q->whereIn('e.payment_id', DB::table('payment_intents')->where('tenant_id', $tenantId)->where('status', 'SUCCEEDED')->whereRaw('DATE(COALESCE(reconciled_at, updated_at)) = ?', [$day($v)])->selectRaw('id::text'));
        }
        if ($v = $f['remittance_date'] ?? null) {
            $q->whereIn('e.batch_id', DB::table('premium_remittances')->where('tenant_id', $tenantId)->whereDate('remittance_date', $day($v))->selectRaw('id::text'));
        }
        if ($v = $f['commission_earned_date'] ?? null) {
            $in('commission_id', 'commission_accruals', DB::raw('id::text'), 'earned_at', $day($v));
        }
        if ($v = $f['commission_paid_date'] ?? null) {
            $in('commission_id', 'commission_accruals', DB::raw('id::text'), 'paid_at', $day($v));
        }
        if ($v = $f['policy_inception_date'] ?? null) {
            $in('policy_id', 'policies', DB::raw('id::text'), 'coverage_starts_at', $day($v));
        }
        if ($v = $f['policy_expiry_date'] ?? null) {
            $in('policy_id', 'policies', DB::raw('id::text'), 'coverage_ends_at', $day($v));
        }
    }

    private function statusFilters(Builder $q, string $tenantId, array $f): void
    {
        if ($v = $f['commission_status'] ?? null) {
            $ids = DB::table('commission_accruals')->where('tenant_id', $tenantId)->get()->filter(fn ($a) => CommissionMachine::specState($a) === $v)->pluck('id')->all();
            $q->whereIn('e.commission_id', $ids ?: ['-']);
        }
        if ($v = $f['premium_status'] ?? null) {
            $ids = DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('kind', 'RECEIVABLE')->whereNotNull('policy_id')->get()
                ->filter(fn ($o) => SubledgerViews::premiumStatus($o) === $v)->pluck('policy_id')->unique()->all();
            $q->whereIn('e.policy_id', $ids ?: ['-']);
        }
        if ($v = $f['settlement_status'] ?? null) {
            $stored = array_keys(array_filter(SubledgerCatalogue::SETTLEMENT_STATUS, fn ($s) => $s === $v));
            $q->whereIn('e.settlement_id', DB::table('settlement_batches')->where('tenant_id', $tenantId)->whereIn('status', $stored ?: ['-'])->selectRaw('id::text'));
        }
        if ($v = $f['reconciliation_status'] ?? null) {
            $stored = array_keys(array_filter(SubledgerCatalogue::RECONCILIATION_STATUS, fn ($s) => $s === $v));
            $q->whereIn('e.payment_id', DB::table('reconciliation_items as i')->join('reconciliation_imports as m', 'm.id', '=', 'i.reconciliation_import_id')
                ->where('m.tenant_id', $tenantId)->whereIn('i.outcome', $stored ?: ['-'])->whereNotNull('i.matched_id')->selectRaw('i.matched_id::text'));
        }
    }
}
