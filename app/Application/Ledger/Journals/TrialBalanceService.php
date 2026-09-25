<?php

declare(strict_types=1);

namespace App\Application\Ledger\Journals;

use Illuminate\Support\Facades\DB;

/** Batch 10-7 REQ-ACC-002: trial balance over POSTED and REVERSED journals (reversals are their own mirror journals). */
final class TrialBalanceService
{
    /** @return array{currency:string,as_of:?string,accounts:list<array<string,mixed>>,total_debit_minor:int,total_credit_minor:int,balanced:bool} */
    public function compute(string $tenantId, string $currency, ?string $asOf = null): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.tenant_id', $tenantId)->where('j.currency', $currency)
            ->whereIn('j.status', ['POSTED', 'REVERSED'])
            ->when($asOf, fn ($q) => $q->whereRaw('j.posted_at < (?::date + 1)', [$asOf]))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.id as account_id, a.code, a.name, a.type, SUM(l.debit_minor) as debit_minor, SUM(l.credit_minor) as credit_minor')
            ->get()
            ->map(fn ($r) => [
                'account_id' => $r->account_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                'debit_minor' => (int) $r->debit_minor, 'credit_minor' => (int) $r->credit_minor,
                'balance_minor' => (int) $r->debit_minor - (int) $r->credit_minor,
            ])->all();
        $d = array_sum(array_column($rows, 'debit_minor'));
        $c = array_sum(array_column($rows, 'credit_minor'));

        return ['currency' => $currency, 'as_of' => $asOf, 'accounts' => $rows, 'total_debit_minor' => $d, 'total_credit_minor' => $c, 'balanced' => $d === $c];
    }
}
