<?php

declare(strict_types=1);

namespace App\Application\Ledger\Periods;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** REQ-ACC-003: read-only pre-close checks. Each check is skipped (count 0, available=false) when its tables are absent. */
final class PreCloseChecklist
{
    /** @return array{items: array<string, array{available: bool, count: int, amount_minor?: int}>, blocking: list<string>} */
    public function run(object $period): array
    {
        $t = $period->tenant_id; $from = $period->starts_on; $to = $period->ends_on.' 23:59:59.999999';
        $items = [];

        $items['unreconciled_items'] = ['available' => false, 'count' => 0];
        if (Schema::hasTable('reconciliation_items') && Schema::hasTable('reconciliation_imports') && Schema::hasColumn('reconciliation_imports', 'tenant_id')) {
            $items['unreconciled_items'] = ['available' => true, 'count' => DB::table('reconciliation_items as i')->join('reconciliation_imports as m', 'm.id', '=', 'i.reconciliation_import_id')
                ->where('m.tenant_id', $t)->where('i.status', 'UNMATCHED')->whereBetween('i.transaction_at', [$from, $to])->count()];
        }

        $items['suspense_balance'] = ['available' => false, 'count' => 0, 'amount_minor' => 0];
        if (Schema::hasTable('journal_lines') && Schema::hasTable('ledger_accounts')) {
            $rows = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('j.tenant_id', $t)->where('a.code', 'ilike', '%SUSPENSE%')->where('j.posted_at', '<=', $to)
                ->groupBy('a.id')->selectRaw('a.id, SUM(l.debit_minor - l.credit_minor) AS bal')->havingRaw('SUM(l.debit_minor - l.credit_minor) <> 0')->get();
            $items['suspense_balance'] = ['available' => true, 'count' => $rows->count(), 'amount_minor' => (int) $rows->sum(fn ($r) => abs((int) $r->bal))];
        }

        $items['pending_manual_journals'] = ['available' => false, 'count' => 0];
        if (Schema::hasTable('journals')) {
            $items['pending_manual_journals'] = ['available' => true, 'count' => DB::table('journals')->where('tenant_id', $t)
                ->whereIn('status', ['DRAFT', 'VALIDATED', 'APPROVED'])->where('created_at', '<=', $to)->count()];
        }

        $items['unapproved_settlements'] = ['available' => false, 'count' => 0];
        if (Schema::hasTable('settlement_batches') && Schema::hasColumn('settlement_batches', 'tenant_id')) {
            $items['unapproved_settlements'] = ['available' => true, 'count' => DB::table('settlement_batches')->where('tenant_id', $t)->whereNull('approved_at')
                ->whereNotIn('status', ['APPROVED', 'PAID', 'SETTLED', 'CANCELLED', 'REJECTED', 'REVERSED'])->where('created_at', '<=', $to)->count()];
        }

        return ['items' => $items, 'blocking' => array_keys(array_filter($items, fn ($i) => $i['count'] > 0))];
    }
}
