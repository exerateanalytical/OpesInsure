<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent F1 — spec ledger_entry: the sub-ledger is a PROJECTION of posted journal lines (journals/journal_lines stay the only
 * posting layer; LedgerService + the balanced-journal domain rule are untouched). Every POSTED (or later REVERSED) journal line
 * becomes exactly one immutable finance_ledger_entries row (unique journal_line_id + idempotency key "jl:{line}"), enriched with the
 * spec dimension set resolved from the journal's source entity (journals.reference_type / reference_id). A reversal journal's lines
 * become new entries that reference the entry they mirror (reverses_entry_id) — the original is never touched (DB trigger).
 *
 * catchUp() is idempotent and cheap when nothing is pending; every sub-ledger read calls it first, so a KPI can never be stale
 * against the journals it drills down to.
 */
final class SubledgerProjector
{
    /** accounting event (journals.reference_type) => source table of reference_id. */
    private const SOURCE_TABLE = [
        'finance.obligation.created' => 'financial_obligations', 'payment.succeeded' => 'payment_intents', 'payment.reconciled' => 'payment_intents',
        'commission.accrued' => 'commission_accruals', 'commission.earned' => 'commission_accruals', 'commission.clawed_back' => 'commission_accruals',
        'commission.paid' => 'partner_payout_requests', 'refund.approved' => 'refunds', 'refund.paid' => 'refunds',
        'settlement.approved' => 'settlement_batches', 'settlement.settled' => 'settlement_batches',
        'premium.remittance.recorded' => 'premium_remittances', 'premium.remittance.allocated' => 'premium_remittance_allocations',
        'claim.settlement.approved' => 'claim_settlements', 'claim.settlement.paid' => 'claim_settlements',
        'health.provider_claim.approved' => 'health_provider_claims', 'health.provider_claim.paid' => 'health_provider_claims',
        'reinsurance.recovery.billed' => 'reinsurance_recoveries',
    ];

    /** Tables probed (by id) for an event without a fixed source table. */
    private const PROBE = ['financial_obligations', 'commission_accruals', 'payment_intents', 'refunds', 'settlement_batches', 'premium_remittances',
        'premium_remittance_allocations', 'partner_payout_requests', 'claim_settlements', 'health_provider_claims', 'policies', 'claims'];

    /** source column => dimension. */
    private const HARVEST = ['policy_id' => 'policy_id', 'claim_id' => 'claim_id', 'carrier_id' => 'insurer_id', 'insurer_id' => 'insurer_id', 'broker_id' => 'broker_id',
        'payment_intent_id' => 'payment_id', 'proposal_id' => 'proposal_id', 'quote_id' => 'quote_id', 'product_id' => 'product_id', 'branch_id' => 'branch_id'];

    /** Source table => the dimension its own id fills. */
    private const SELF_DIMENSION = ['commission_accruals' => 'commission_id', 'payment_intents' => 'payment_id', 'settlement_batches' => 'settlement_id',
        'policies' => 'policy_id', 'claims' => 'claim_id', 'premium_remittances' => 'batch_id'];

    /** @var array<string, bool> */
    private array $tables = [];

    public function catchUp(?string $tenantId = null, int $limit = 5000): int
    {
        $lines = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
            ->whereIn('j.status', ['POSTED', 'REVERSED'])
            ->when($tenantId !== null, fn ($q) => $q->where('j.tenant_id', $tenantId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('finance_ledger_entries as e')->whereColumn('e.journal_line_id', 'l.id'))
            ->orderBy('j.posted_at')->orderBy('j.created_at')->limit($limit)
            ->get(['l.id as line_id', 'l.account_id', 'l.debit_minor', 'l.credit_minor', 'j.id as journal_id', 'j.tenant_id', 'j.reference_type', 'j.reference_id', 'j.currency',
                'j.journal_type', 'j.correlation_id', 'j.posted_at', 'j.created_at', 'j.journal_date', 'j.accounting_date', 'j.reverses_journal_id']);
        $cache = [];
        $n = 0;
        foreach ($lines as $l) {
            $key = $l->journal_id;
            $cache[$key] ??= $this->resolve($l);
            [$dims, $sourceTable, $financials] = $cache[$key];
            $code = DB::table('ledger_accounts')->where('id', $l->account_id)->value('code');
            $entryType = $this->entryType((string) $code, $dims);
            $posted = CarbonImmutable::parse($l->posted_at ?? $l->created_at);
            $row = [
                'id' => (string) Str::uuid(), 'tenant_id' => $l->tenant_id, 'journal_id' => $l->journal_id, 'journal_line_id' => $l->line_id, 'account_id' => $l->account_id,
                'counterparty_account_id' => $this->counterpartyAccount($l->tenant_id, $l->account_id, $dims), 'entry_type' => $entryType,
                'journal_type' => SubledgerCatalogue::journalType((string) $l->reference_type, $l->journal_type),
                'debit_amount' => (int) $l->debit_minor, 'credit_amount' => (int) $l->credit_minor, 'currency' => $l->currency,
                'transaction_date' => $l->journal_date ?? $posted->toDateString(), 'value_date' => $l->journal_date ?? $posted->toDateString(),
                'posting_date' => $l->accounting_date ?? $posted->toDateString(), 'status' => 'POSTED',
                'reverses_entry_id' => $this->reversedEntry($l), 'source_event_id' => $l->correlation_id ? mb_substr((string) $l->correlation_id, 0, 120) : null,
                'source_entity_type' => $sourceTable ?? ($l->reference_type === 'JOURNAL_REVERSAL' ? 'journals' : null), 'source_entity_id' => mb_substr((string) $l->reference_id, 0, 120),
                'idempotency_key' => 'jl:'.$l->line_id, 'financials' => $financials ? json_encode($financials) : null, 'created_at' => now(),
            ];
            foreach (SubledgerCatalogue::DIMENSIONS as $dim) {
                $row[$dim] = isset($dims[$dim]) ? mb_substr((string) $dims[$dim], 0, 64) : null;
            }
            $n += DB::table('finance_ledger_entries')->insertOrIgnore($row);
        }

        return $n;
    }

    /** @return array{0: array<string, string>, 1: ?string, 2: ?array<string, int>} */
    private function resolve(object $j): array
    {
        if ($j->reference_type === 'JOURNAL_REVERSAL') {
            // The mirror journal carries the dimensions of the journal it reverses.
            $orig = DB::table('finance_ledger_entries')->where('journal_id', $j->reference_id)->first();
            $dims = [];
            foreach (SubledgerCatalogue::DIMENSIONS as $dim) {
                if ($orig && $orig->{$dim} !== null) {
                    $dims[$dim] = $orig->{$dim};
                }
            }

            return [$dims, $orig?->source_entity_type, ['reversal_amount' => (int) DB::table('journal_lines')->where('journal_id', $j->journal_id)->sum('debit_minor')]];
        }
        [$table, $row] = $this->source((string) $j->reference_type, (string) $j->reference_id);
        if (! $row) {
            return [[], null, null];
        }
        $dims = [];
        foreach (self::HARVEST as $col => $dim) {
            if (! empty($row->{$col})) {
                $dims[$dim] = (string) $row->{$col};
            }
        }
        if (isset(self::SELF_DIMENSION[$table])) {
            $dims[self::SELF_DIMENSION[$table]] = $row->id;
        }
        if (! empty($row->partner_id)) {
            $dims += $this->partner((string) $row->partner_id);
            $dims['_beneficiary'] = isset($this->partner((string) $row->partner_id)['broker_id']) ? 'BROKER' : 'AGENT';
        }
        $financials = null;
        switch ($table) {
            case 'financial_obligations':
                $dims += $this->party($row->debtor_type, $row->debtor_id) + $this->party($row->creditor_type, $row->creditor_id);
                $financials = [match ($row->type) { 'TAX' => 'tax_amount', 'FEE' => 'fee_amount', 'REFUND' => 'refund_amount', 'COMMISSION' => 'commission_gross', default => 'gross_premium' } => (int) $row->amount_minor];
                break;
            case 'commission_accruals':
                $financials = ['commission_gross' => (int) $row->amount_minor, 'commission_net' => (int) $row->amount_minor - (int) $row->clawed_back_minor];
                break;
            case 'refunds':
                $financials = ['refund_amount' => (int) $row->amount_minor];
                if ($row->payment_intent_id && ($pid = DB::table('policies')->where('payment_intent_id', $row->payment_intent_id)->value('id'))) {
                    $dims['policy_id'] ??= $pid;
                }
                break;
            case 'settlement_batches':
                $financials = ['settlement_amount' => (int) $row->net_amount_minor];
                break;
            case 'premium_remittance_allocations':
                $r = DB::table('premium_remittances')->find($row->premium_remittance_id);
                $dims += array_filter(['insurer_id' => $r?->insurer_id, 'broker_id' => $r?->broker_id, 'batch_id' => $r?->id]);
                $financials = ['settlement_amount' => (int) $row->amount_minor];
                break;
            case 'payment_intents':
                $dims['policy_id'] ??= DB::table('policies')->where('payment_intent_id', $row->id)->value('id') ?? DB::table('policies')->where('proposal_id', $row->proposal_id)->value('id');
                $dims['customer_id'] ??= DB::table('proposals')->where('id', $row->proposal_id)->value('party_id');
                break;
        }
        if (! empty($row->claim_id) && empty($dims['policy_id'])) {
            $dims['policy_id'] = DB::table('claims')->where('id', $row->claim_id)->value('policy_id');
        }
        if (! empty($dims['policy_id'])) {
            $dims += $this->policy((string) $dims['policy_id']);
        }

        if ($table === 'financial_obligations' && $row->creditor_type === 'partner') {
            $dims['_beneficiary'] = isset($this->partner((string) $row->creditor_id)['broker_id']) ? 'BROKER' : 'AGENT';
        }

        return [array_filter($dims, fn ($v) => $v !== null && $v !== ''), $table, $financials];
    }

    /** @return array{0: ?string, 1: ?object} */
    private function source(string $event, string $referenceId): array
    {
        if (! Str::isUuid($referenceId)) {
            return [null, null];
        }
        $tables = isset(self::SOURCE_TABLE[$event]) ? [self::SOURCE_TABLE[$event]] : self::PROBE;
        foreach ($tables as $t) {
            if ($this->hasTable($t) && ($row = DB::table($t)->where('id', $referenceId)->first())) {
                return [$t, $row];
            }
        }

        return [null, null];
    }

    /** @return array<string, string> */
    private function policy(string $policyId): array
    {
        $p = DB::table('policies')->find($policyId);
        if (! $p) {
            return [];
        }
        $dims = array_filter(['customer_id' => $p->party_id, 'insurer_id' => $p->carrier_id, 'proposal_id' => $p->proposal_id]);
        $offer = DB::table('proposals as pr')->join('quote_offers as o', 'o.id', '=', 'pr.quote_offer_id')->where('pr.id', $p->proposal_id)->first(['o.product_id', 'o.quote_id']);
        if ($offer) {
            $dims['product_id'] = $offer->product_id;
            $dims['quote_id'] = $offer->quote_id;
            $dims['insurance_class_id'] = DB::table('insurance_products')->where('id', $offer->product_id)->value('line_code');
        }
        if ($p->servicing_partner_id) {
            $dims += $this->partner((string) $p->servicing_partner_id);
        }

        return array_filter($dims);
    }

    /** @return array<string, string> */
    private function partner(string $partnerId): array
    {
        $type = strtoupper((string) DB::table('partners')->where('id', $partnerId)->value('type'));

        return [$type === 'BROKER' ? 'broker_id' : 'agent_id' => $partnerId];
    }

    /** @return array<string, string> */
    private function party(?string $type, ?string $id): array
    {
        if (! $type || ! $id) {
            return [];
        }

        return match (strtolower($type)) {
            'party', 'customer' => ['customer_id' => $id],
            'carrier', 'insurer' => ['insurer_id' => $id],
            'partner' => $this->partner($id),
            'reinsurer' => ['reinsurer_id' => $id],
            'provider' => ['provider_id' => $id],
            default => [],
        };
    }

    private function entryType(string $code, array $dims): string
    {
        if ($code === '421000') {
            $beneficiary = $dims['_beneficiary'] ?? (isset($dims['agent_id']) ? 'AGENT' : (isset($dims['broker_id']) ? 'BROKER' : 'AGENT'));

            return $beneficiary.'_COMMISSION_PAYABLE';
        }

        return SubledgerCatalogue::SUBLEDGER_BY_CODE[$code] ?? 'GENERAL_LEDGER';
    }

    private function counterpartyAccount(?string $tenantId, string $accountId, array $dims): ?string
    {
        if (! $tenantId) {
            return null;
        }
        foreach (DB::table('finance_counterparty_accounts')->where('tenant_id', $tenantId)->where('gl_control_account_id', $accountId)->whereIn('status', ['ACTIVE', 'SUSPENDED'])->get() as $a) {
            $dim = SubledgerCatalogue::COUNTERPARTY_DIMENSION[$a->counterparty_type] ?? null;
            if ($dim && ($dims[$dim] ?? null) === $a->counterparty_id) {
                return $a->id;
            }
        }

        return null;
    }

    private function reversedEntry(object $l): ?string
    {
        if ($l->reference_type !== 'JOURNAL_REVERSAL') {
            return null;
        }

        return DB::table('finance_ledger_entries')->where('journal_id', $l->reference_id)->where('account_id', $l->account_id)
            ->where('debit_amount', (int) $l->credit_minor)->where('credit_amount', (int) $l->debit_minor)->value('id');
    }

    private function hasTable(string $t): bool
    {
        return $this->tables[$t] ??= Schema::hasTable($t);
    }
}
