<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

use App\Application\Commissions\Machine\CommissionMachine;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Finance\Statements\AccountStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agent F1 — spec broker_dashboard / insurer_dashboard / customer_accounting / agent_accounting.
 *
 * Mirrored views: the broker's view of one insurer and the insurer's view of one broker read the SAME rows (financial_obligations,
 * premium_remittance_allocations, commission_accruals, settlement_batches) — only the labels flip. Premium and commission are always
 * shown as separate gross balances; the `net_*` figures are informational and never posted (settlements.netting_rule).
 * Ledger KPIs (premium billed / collected / due to insurers / remitted / unapplied / refunds) are Σ finance_ledger_entries by sub-ledger
 * and carry a drill-down filter set for GET finance/subledger/entries; commission KPIs are Σ commission_accruals by spec state
 * (CommissionMachine::specState) and drill down by commission_id. Nothing here is stored or editable
 * ("No dashboard balance is an editable source of truth").
 */
final class SubledgerViews
{
    public function __construct(private SubledgerQuery $query, private AgingService $aging, private AccountStatementService $statements) {}

    /** Spec premium status of a customer premium RECEIVABLE obligation. */
    public static function premiumStatus(object $o): string
    {
        if ($o->status === 'WRITTEN_OFF') {
            return 'WRITTEN_OFF';
        }
        if ($o->status === 'CANCELLED') {
            return 'REVERSED';
        }
        if ($o->policy_id) {
            $refunds = DB::table('financial_obligations')->where('policy_id', $o->policy_id)->where('type', 'REFUND')->whereNotIn('status', ['CANCELLED'])->get();
            if ($refunds->isNotEmpty()) {
                return (int) $refunds->sum('amount_minor') >= (int) $o->amount_minor ? 'REFUNDED' : 'PARTIALLY_REFUNDED';
            }
        }
        $outstanding = (int) $o->outstanding_minor;
        if ($outstanding === (int) $o->amount_minor) {
            return 'BILLED';
        }
        if ($outstanding > 0) {
            return 'PARTIALLY_COLLECTED';
        }
        $payables = $o->policy_id ? DB::table('financial_obligations')->where('policy_id', $o->policy_id)->where('kind', 'PAYABLE')->where('creditor_type', 'carrier')->get() : collect();
        if ($payables->isEmpty()) {
            return 'COLLECTED';
        }
        $left = (int) $payables->sum('outstanding_minor');

        return $left === 0 ? 'REMITTED' : ($left < (int) $payables->sum('amount_minor') ? 'PARTIALLY_REMITTED' : 'COLLECTED');
    }

    // --- mirrored counterparty accounts --------------------------------------------------------------------------------

    /** Broker opens one insurer: premium payable and commission receivable, separately (spec broker_dashboard.insurer_detail_tabs). */
    public function brokerInsurerAccount(string $tenantId, string $insurerId, array $filters, string $tab = 'OVERVIEW'): array
    {
        $cur = $filters['currency'] ?? null;
        $payables = $this->insurerPayables($tenantId, $insurerId, $cur);
        // Commission the insurer owes the broker = accruals of BROKER partners on this insurer's policies (agent commission is the broker's own payable).
        $brokers = DB::table('partners')->where('type', 'BROKER')->pluck('id')->all();
        $commission = $this->accruals($tenantId, $cur)->whereIn('policy_id', DB::table('policies')->where('tenant_id', $tenantId)->where('carrier_id', $insurerId)->pluck('id')->all())
            ->whereIn('partner_id', $brokers)->values();
        $remittances = DB::table('premium_remittances')->where('tenant_id', $tenantId)->where('insurer_id', $insurerId)->when($cur, fn ($q, $c) => $q->where('currency', $c))->get();
        $premium = $this->sumObligations($payables);
        $comm = $this->sumCommission($commission);
        $unapplied = (int) $remittances->sum(fn ($r) => (int) $r->amount_minor - (int) $r->allocated_minor);

        return [
            'perspective' => 'BROKER', 'counterparty' => ['type' => 'INSURER', 'id' => $insurerId], 'currency' => $cur, 'tab' => $tab,
            'premium_payable' => $premium + ['remittance_statuses' => $payables->countBy(fn ($o) => PremiumRemittanceService::remittanceStatus($o))->all()],
            'commission_receivable' => $comm,
            'unapplied_remittance_minor' => $unapplied,
            'net_due_to_insurer_minor' => $premium['outstanding_minor'] - $comm['outstanding_minor'], 'net_is_informational' => true,
            'tab_rows' => $this->tab($tenantId, $tab, ['insurer_id' => $insurerId] + $filters, $payables, $commission, $remittances),
        ];
    }

    /** Insurer opens one broker: premium receivable and commission payable, separately (spec insurer_dashboard.broker_detail_tabs). */
    public function insurerBrokerAccount(string $tenantId, string $brokerId, array $filters, string $tab = 'OVERVIEW'): array
    {
        $cur = $filters['currency'] ?? null;
        $receivables = $this->brokerReceivables($tenantId, $brokerId, $cur);
        $commission = $this->accruals($tenantId, $cur)->where('partner_id', $brokerId);
        $premium = $this->sumObligations($receivables);
        $comm = $this->sumCommission($commission);

        return [
            'perspective' => 'INSURER', 'counterparty' => ['type' => 'BROKER', 'id' => $brokerId], 'currency' => $cur, 'tab' => $tab,
            'premium_receivable' => $premium,
            'commission_payable' => $comm,
            'net_due_to_broker_minor' => $comm['outstanding_minor'] - $premium['outstanding_minor'], 'net_is_informational' => true,
            'tab_rows' => $this->tab($tenantId, $tab, ['broker_id' => $brokerId] + $filters, $receivables, $commission, collect()),
        ];
    }

    // --- dashboards -------------------------------------------------------------------------------------------------------

    public function brokerDashboard(string $tenantId, array $filters, ?string $groupBy = null): array
    {
        $cur = $filters['currency'] ?? null;
        $sl = fn (string $type) => $this->ledger($tenantId, $filters, $type);
        $billed = $sl('CUSTOMER_RECEIVABLE');
        $payable = $sl('INSURER_PREMIUM_PAYABLE');
        $unapplied = $sl('CUSTOMER_DEPOSIT');
        $refunds = $sl('REFUND_CLEARING');
        $comm = $this->sumCommission($this->accruals($tenantId, $cur, $filters));
        $kpis = [
            'premium_written' => $this->kpi($billed['debit_minor'], $filters, ['entry_type' => 'CUSTOMER_RECEIVABLE', 'side' => 'DEBIT']),
            'premium_collected' => $this->kpi($billed['credit_minor'], $filters, ['entry_type' => 'CUSTOMER_RECEIVABLE', 'side' => 'CREDIT']),
            'premium_uncollected' => $this->kpi($billed['debit_minor'] - $billed['credit_minor'], $filters, ['entry_type' => 'CUSTOMER_RECEIVABLE']),
            'premium_due_to_insurers' => $this->kpi($payable['credit_minor'], $filters, ['entry_type' => 'INSURER_PREMIUM_PAYABLE', 'side' => 'CREDIT']),
            'premium_remitted' => $this->kpi($payable['debit_minor'], $filters, ['entry_type' => 'INSURER_PREMIUM_PAYABLE', 'side' => 'DEBIT']),
            'premium_unremitted' => $this->kpi($payable['credit_minor'] - $payable['debit_minor'], $filters, ['entry_type' => 'INSURER_PREMIUM_PAYABLE']),
            'commission_expected' => $this->kpi($comm['by_state']['EXPECTED'] ?? 0, $filters, ['commission_status' => 'EXPECTED']),
            'commission_accrued' => $this->kpi($comm['by_state']['ACCRUED'] ?? 0, $filters, ['commission_status' => 'ACCRUED']),
            'commission_payable' => $this->kpi(($comm['by_state']['PAYABLE'] ?? 0) + ($comm['by_state']['PARTIALLY_PAID'] ?? 0), $filters, ['commission_status' => 'PAYABLE']),
            'commission_paid' => $this->kpi($comm['paid_minor'], $filters, ['journal_type' => 'COMMISSION_PAYMENT']),
            'commission_outstanding' => $this->kpi($comm['outstanding_minor'], $filters, ['journal_type' => 'COMMISSION_ACCRUAL']),
            'refunds' => $this->kpi($refunds['credit_minor'], $filters, ['entry_type' => 'REFUND_CLEARING', 'side' => 'CREDIT']),
            'clawbacks' => $this->kpi($comm['clawed_back_minor'], $filters, ['journal_type' => 'COMMISSION_CLAWBACK']),
            'unapplied_cash' => $this->kpi(abs($unapplied['debit_minor'] - $unapplied['credit_minor']), $filters, ['entry_type' => 'CUSTOMER_DEPOSIT']),
            'reconciliation_difference' => $this->kpi($this->reconciliationDifference($tenantId, $cur), $filters, ['report' => 'SETTLEMENT_RECONCILIATION']),
        ];
        $kpis['net_due_to_insurer'] = $this->kpi($kpis['premium_unremitted']['value_minor'] - $comm['outstanding_minor'], $filters, ['entry_type' => 'INSURER_PREMIUM_PAYABLE'], true);
        $kpis['net_due_to_broker'] = $this->kpi($comm['outstanding_minor'], $filters, ['journal_type' => 'COMMISSION_ACCRUAL'], true);

        return ['perspective' => 'BROKER', 'applied_filters' => $filters, 'kpis' => $kpis, 'groups' => $groupBy ? $this->query->balances($tenantId, $filters, $groupBy) : null];
    }

    public function insurerDashboard(string $tenantId, array $filters, ?string $groupBy = null): array
    {
        $cur = $filters['currency'] ?? null;
        $partners = DB::table('partners')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->where('type', 'BROKER')->pluck('id')->all();
        $receivables = collect($partners)->flatMap(fn ($b) => $this->brokerReceivables($tenantId, $b, $cur))->unique('id');
        $rec = $this->sumObligations($receivables);
        $today = CarbonImmutable::now()->startOfDay();
        $overdue = $receivables->filter(fn ($o) => (int) $o->outstanding_minor > 0 && CarbonImmutable::parse($o->due_at)->lt($today));
        $comm = $this->sumCommission($this->accruals($tenantId, $cur, $filters)->whereIn('partner_id', $partners));
        $refundsDue = (int) DB::table('refunds')->where('tenant_id', $tenantId)->whereIn('status', ['APPROVED', 'PROCESSING'])->when($cur, fn ($q, $c) => $q->where('currency', $c))->sum('amount_minor');
        $settlementNet = (int) DB::table('settlement_batches')->where('tenant_id', $tenantId)->whereNotIn('status', ['CANCELLED', 'REVERSED', 'FAILED'])->when($cur, fn ($q, $c) => $q->where('currency', $c))->sum('net_amount_minor');
        $unmatched = DB::table('reconciliation_items as i')->join('reconciliation_imports as m', 'm.id', '=', 'i.reconciliation_import_id')->where('m.tenant_id', $tenantId)
            ->where('i.status', 'EXCEPTION')->whereNull('i.resolved_at')->when($cur, fn ($q, $c) => $q->where('i.currency', $c));
        $kpis = [
            'premium_receivable_from_brokers' => $this->kpi($rec['outstanding_minor'], $filters, ['entry_type' => 'CUSTOMER_RECEIVABLE']),
            'premium_received_from_brokers' => $this->kpi($rec['settled_minor'], $filters, ['entry_type' => 'CUSTOMER_RECEIVABLE', 'side' => 'CREDIT']),
            'overdue_broker_remittances' => $this->kpi((int) $overdue->sum('outstanding_minor'), $filters, ['aging_bucket' => 'DAYS_1_30'], false, $overdue->count()),
            'commission_expected' => $this->kpi($comm['by_state']['EXPECTED'] ?? 0, $filters, ['commission_status' => 'EXPECTED']),
            'commission_accrued' => $this->kpi($comm['by_state']['ACCRUED'] ?? 0, $filters, ['commission_status' => 'ACCRUED']),
            'commission_payable' => $this->kpi(($comm['by_state']['PAYABLE'] ?? 0) + ($comm['by_state']['PARTIALLY_PAID'] ?? 0), $filters, ['commission_status' => 'PAYABLE']),
            'commission_paid' => $this->kpi($comm['paid_minor'], $filters, ['journal_type' => 'COMMISSION_PAYMENT']),
            'commission_outstanding' => $this->kpi($comm['outstanding_minor'], $filters, ['journal_type' => 'COMMISSION_ACCRUAL']),
            'refunds_due' => $this->kpi($refundsDue, $filters, ['entry_type' => 'REFUND_CLEARING']),
            'settlement_net' => $this->kpi($settlementNet, $filters, ['journal_type' => 'PREMIUM_REMITTANCE']),
            'unmatched_receipts' => $this->kpi((int) (clone $unmatched)->sum('i.gross_minor'), $filters, ['report' => 'UNMATCHED_PAYMENTS'], false, (clone $unmatched)->count()),
            'reconciliation_difference' => $this->kpi($this->reconciliationDifference($tenantId, $cur), $filters, ['report' => 'SETTLEMENT_RECONCILIATION']),
        ];

        return ['perspective' => 'INSURER', 'applied_filters' => $filters, 'kpis' => $kpis, 'groups' => $groupBy ? $this->query->balances($tenantId, $filters, $groupBy) : null];
    }

    // --- customer & agent ledgers -----------------------------------------------------------------------------------------

    public function customerLedger(string $tenantId, string $partyId, string $view, array $filters): array
    {
        $cur = $filters['currency'] ?? 'XAF';
        $obligations = DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('kind', 'RECEIVABLE')->where('debtor_type', 'party')->where('debtor_id', $partyId)
            ->where('currency', $cur)->when($filters['policy_id'] ?? null, fn ($q, $p) => $q->where('policy_id', $p))->orderBy('due_at')->get();
        $rows = match ($view) {
            'PREMIUM_BILLED' => $obligations->whereNotIn('type', ['REFUND'])->map(fn ($o) => ['obligation_id' => $o->id, 'policy_id' => $o->policy_id, 'type' => $o->type, 'amount_minor' => (int) $o->amount_minor, 'due_at' => $o->due_at, 'premium_status' => self::premiumStatus($o)]),
            'UNPAID_PREMIUMS' => $obligations->whereIn('status', ObligationService::OPEN_STATUSES)->map(fn ($o) => ['obligation_id' => $o->id, 'policy_id' => $o->policy_id, 'outstanding_minor' => (int) $o->outstanding_minor, 'due_at' => $o->due_at, 'aging_bucket' => AgingService::bucket((string) $o->due_at, CarbonImmutable::now())]),
            'PAYMENTS' => DB::table('payment_intents as pi')->join('proposals as pr', 'pr.id', '=', 'pi.proposal_id')->where('pi.tenant_id', $tenantId)->where('pr.party_id', $partyId)
                ->where('pi.currency', $cur)->where('pi.status', 'SUCCEEDED')->get(['pi.id', 'pi.amount_minor', 'pi.provider', 'pi.reconciled_at']),
            'POLICY_ALLOCATIONS' => DB::table('payment_allocations')->where('tenant_id', $tenantId)->whereIn('financial_obligation_id', $obligations->pluck('id')->all())->orderBy('created_at')
                ->get(['id', 'payment_intent_id', 'financial_obligation_id', 'target_category', 'kind', 'amount_minor', 'reverses_allocation_id']),
            'CREDITS' => $this->query->entries($tenantId, ['customer_id' => $partyId, 'currency' => $cur])->where('e.entry_type', 'CUSTOMER_DEPOSIT')->get(),
            'REFUNDS' => DB::table('refunds as r')->join('payment_intents as pi', 'pi.id', '=', 'r.payment_intent_id')->join('proposals as pr', 'pr.id', '=', 'pi.proposal_id')
                ->where('r.tenant_id', $tenantId)->where('pr.party_id', $partyId)->where('r.currency', $cur)->get(['r.id', 'r.refund_number', 'r.amount_minor', 'r.status', 'r.paid_at']),
            'ACCOUNT_STATEMENT' => null,
            default => throw ValidationException::withMessages(['view' => 'Unknown customer accounting view.']),
        };
        if ($view === 'ACCOUNT_STATEMENT') {
            return ['view' => $view, 'statement' => $this->customerStatement($tenantId, $partyId, $filters)];
        }

        return ['view' => $view, 'customer_id' => $partyId, 'currency' => $cur, 'rows' => collect($rows)->map(fn ($r) => (array) $r)->values()->all()];
    }

    /** Spec customer_accounting.statement_fields over AccountStatementService (opening + debits − credits = closing, asserted). */
    public function customerStatement(string $tenantId, string $partyId, array $filters): array
    {
        $s = $this->statements->build($tenantId, 'customer', $partyId, $filters['date_from'] ?? now()->startOfYear()->toDateString(), $filters['date_to'] ?? now()->toDateString(), $filters['currency'] ?? 'XAF');
        $lines = collect($s['lines']);
        $sum = fn (string $t) => (int) $lines->where('line_type', $t)->sum('amount_minor');
        $debits = (int) $lines->where('amount_minor', '>', 0)->sum('amount_minor');
        $credits = -(int) $lines->where('amount_minor', '<', 0)->sum('amount_minor');

        return [
            'statement_number' => $s['statement_number'], 'period_start' => $s['period_start'], 'period_end' => $s['period_end'], 'currency' => $s['currency'],
            'opening_balance' => $s['opening_balance_minor'], 'debits' => $debits, 'credits' => $credits, 'payments' => -$sum('PAYMENT'), 'refunds' => $sum('REFUND'),
            'adjustments' => $sum('ADJUSTMENT'), 'closing_balance' => $s['closing_balance_minor'],
            'reconciles' => $s['opening_balance_minor'] + $debits - $credits === $s['closing_balance_minor'],
            'lines' => $s['lines'], 'content_hash' => $s['content_hash'],
        ];
    }

    public function agentLedger(string $tenantId, string $agentId, string $view, array $filters): array
    {
        $accruals = $this->accruals($tenantId, $filters['currency'] ?? null, $filters)->where('partner_id', $agentId);
        $row = fn ($a) => ['commission_id' => $a->id, 'policy_id' => $a->policy_id, 'amount_minor' => (int) $a->amount_minor, 'paid_minor' => (int) $a->paid_minor,
            'clawed_back_minor' => (int) $a->clawed_back_minor, 'outstanding_minor' => max(0, (int) $a->amount_minor - (int) $a->paid_minor - (int) $a->clawed_back_minor),
            'status' => CommissionMachine::specState($a), 'stored_status' => $a->status, 'currency' => $a->currency, 'created_at' => $a->created_at];
        $state = fn (array $states) => $accruals->filter(fn ($a) => in_array(CommissionMachine::specState($a), $states, true))->map($row);
        $rows = match ($view) {
            'EXPECTED_COMMISSION' => $state(['EXPECTED']),
            'ACCRUED_COMMISSION' => $state(['ACCRUED']),
            'PAYABLE_COMMISSION' => $state(['PAYABLE', 'PARTIALLY_PAID']),
            'PAID_COMMISSION' => $accruals->filter(fn ($a) => (int) $a->paid_minor > 0)->map($row),
            'CLAWBACKS' => $accruals->filter(fn ($a) => (int) $a->clawed_back_minor > 0)->map($row),
            'ADVANCES' => $this->query->entries($tenantId, ['agent_id' => $agentId])->where('e.entry_type', 'AGENT_COMMISSION_PAYABLE')
                ->whereIn('e.account_id', DB::table('ledger_accounts')->where('code', '409100')->pluck('id')->all())->get()->map(fn ($e) => (array) $e),
            'SETTLEMENT_HISTORY' => DB::table('partner_payout_requests')->where('tenant_id', $tenantId)->where('partner_id', $agentId)->orderBy('created_at')
                ->get(['id', 'payout_number', 'amount_minor', 'currency', 'status', 'paid_at'])->map(fn ($p) => (array) $p),
            default => throw ValidationException::withMessages(['view' => 'Unknown agent accounting view.']),
        };

        return ['view' => $view, 'agent_id' => $agentId, 'applied_filters' => $filters, 'rows' => $rows->values()->all()];
    }

    // --- helpers ---------------------------------------------------------------------------------------------------------------

    /** Commission accruals with the spec agent_accounting / broker filters that apply to them. */
    public function accruals(string $tenantId, ?string $currency, array $f = []): Collection
    {
        $policyFilter = array_filter(['party_id' => $f['customer_id'] ?? $f['client'] ?? null, 'carrier_id' => $f['insurer_id'] ?? $f['insurer'] ?? null]);
        $productPolicies = ($p = $f['product_id'] ?? $f['product'] ?? null) ? DB::table('policies as po')->join('proposals as pr', 'pr.id', '=', 'po.proposal_id')
            ->join('quote_offers as o', 'o.id', '=', 'pr.quote_offer_id')->where('po.tenant_id', $tenantId)->where('o.product_id', $p)->pluck('po.id')->all() : null;
        $classPolicies = ($c = $f['insurance_class_id'] ?? null) ? DB::table('policies as po')->join('proposals as pr', 'pr.id', '=', 'po.proposal_id')
            ->join('quote_offers as o', 'o.id', '=', 'pr.quote_offer_id')->join('insurance_products as ip', 'ip.id', '=', 'o.product_id')
            ->where('po.tenant_id', $tenantId)->where('ip.line_code', $c)->pluck('po.id')->all() : null;

        return DB::table('commission_accruals')->where('tenant_id', $tenantId)
            ->when($currency, fn ($q, $c) => $q->where('currency', $c))
            ->when($f['policy_id'] ?? $f['policy'] ?? null, fn ($q, $p) => $q->where('policy_id', $p))
            ->when($f['agent_id'] ?? $f['broker_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->when($policyFilter, fn ($q) => $q->whereIn('policy_id', DB::table('policies')->where('tenant_id', $tenantId)->where($policyFilter)->select('id')))
            ->when($productPolicies !== null, fn ($q) => $q->whereIn('policy_id', $productPolicies ?: ['00000000-0000-0000-0000-000000000000']))
            ->when($classPolicies !== null, fn ($q) => $q->whereIn('policy_id', $classPolicies ?: ['00000000-0000-0000-0000-000000000000']))
            ->when($f['date_from'] ?? $f['date'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', CarbonImmutable::parse($d)->startOfDay()))
            ->when($f['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', CarbonImmutable::parse($d)->endOfDay()))
            ->orderBy('created_at')->get()
            ->when($f['commission_status'] ?? $f['status'] ?? null, fn ($c, $s) => $c->filter(fn ($a) => CommissionMachine::specState($a) === $s))
            ->values();
    }

    private function insurerPayables(string $tenantId, string $insurerId, ?string $cur): Collection
    {
        return DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('kind', 'PAYABLE')->where('creditor_type', 'carrier')->where('creditor_id', $insurerId)
            ->whereNotIn('status', ['CANCELLED'])->when($cur, fn ($q, $c) => $q->where('currency', $c))->orderBy('due_at')->get();
    }

    private function brokerReceivables(string $tenantId, string $brokerId, ?string $cur): Collection
    {
        $policies = DB::table('policies')->where('tenant_id', $tenantId)->where('servicing_partner_id', $brokerId)->pluck('id')->all();

        return DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('kind', 'RECEIVABLE')->whereNotIn('type', ['REFUND', 'COMMISSION', 'CLAIM'])
            ->whereNotIn('status', ['CANCELLED'])->where(fn ($q) => $q->where(fn ($q) => $q->where('debtor_type', 'partner')->where('debtor_id', $brokerId))->orWhereIn('policy_id', $policies))
            ->when($cur, fn ($q, $c) => $q->where('currency', $c))->orderBy('due_at')->get();
    }

    private function sumObligations(Collection $rows): array
    {
        $amount = (int) $rows->sum('amount_minor');
        $out = (int) $rows->sum('outstanding_minor');

        return ['gross_minor' => $amount, 'settled_minor' => $amount - $out, 'outstanding_minor' => $out, 'count' => $rows->count(), 'obligation_ids' => $rows->pluck('id')->values()->all()];
    }

    private function sumCommission(Collection $rows): array
    {
        $by = [];
        foreach ($rows as $a) {
            $s = CommissionMachine::specState($a);
            $by[$s] = ($by[$s] ?? 0) + max(0, (int) $a->amount_minor - (int) $a->paid_minor - (int) $a->clawed_back_minor);
        }
        $live = $rows->filter(fn ($a) => ! in_array($a->status, ['REVERSED', 'CLAWED_BACK'], true));

        return ['gross_minor' => (int) $rows->sum('amount_minor'), 'paid_minor' => (int) $rows->sum('paid_minor'), 'clawed_back_minor' => (int) $rows->sum('clawed_back_minor'),
            'outstanding_minor' => (int) $live->sum(fn ($a) => max(0, (int) $a->amount_minor - (int) $a->paid_minor - (int) $a->clawed_back_minor)),
            'by_state' => $by, 'commission_ids' => $rows->pluck('id')->values()->all()];
    }

    /** @return array{debit_minor:int, credit_minor:int} */
    private function ledger(string $tenantId, array $filters, string $entryType): array
    {
        $r = $this->query->entries($tenantId, $filters)->where('e.entry_type', $entryType)->selectRaw('COALESCE(SUM(e.debit_amount),0) d, COALESCE(SUM(e.credit_amount),0) c')->first();

        return ['debit_minor' => (int) $r->d, 'credit_minor' => (int) $r->c];
    }

    private function reconciliationDifference(string $tenantId, ?string $cur): int
    {
        return (int) DB::table('reconciliation_items as i')->join('reconciliation_imports as m', 'm.id', '=', 'i.reconciliation_import_id')->where('m.tenant_id', $tenantId)
            ->whereNull('i.resolved_at')->when($cur, fn ($q, $c) => $q->where('i.currency', $c))->sum(DB::raw('ABS(COALESCE(i.variance_minor, 0))'));
    }

    private function kpi(int $value, array $filters, array $drill, bool $informational = false, ?int $count = null): array
    {
        $report = $drill['report'] ?? null;
        unset($drill['report']);

        return array_filter(['value_minor' => $value, 'count' => $count, 'informational' => $informational ?: null,
            'drilldown' => ['endpoint' => $report ? 'finance/subledger/reports/'.$report : 'finance/subledger/entries', 'filters' => $filters + $drill]], fn ($v) => $v !== null);
    }

    private function tab(string $tenantId, string $tab, array $filters, Collection $premium, Collection $commission, Collection $remittances): array
    {
        $tabs = array_unique(array_merge(SubledgerCatalogue::spec()['broker_dashboard']['insurer_detail_tabs'], SubledgerCatalogue::spec()['insurer_dashboard']['broker_detail_tabs']));
        if (! in_array($tab, $tabs, true)) {
            throw ValidationException::withMessages(['tab' => 'Unknown detail tab.']);
        }
        $rows = fn (Collection $c) => $c->map(fn ($r) => (array) $r)->values()->all();

        return match ($tab) {
            'OVERVIEW' => [],
            'PREMIUMS', 'PREMIUM_RECEIVABLE', 'PREMIUM_RECEIVED' => $rows($premium),
            'COMMISSIONS', 'COMMISSIONS_PAYABLE' => $rows($commission->map(fn ($a) => (object) ((array) $a + ['spec_status' => CommissionMachine::specState($a)]))),
            'COMMISSION_PAYMENTS' => $rows(DB::table('partner_payout_requests')->where('tenant_id', $tenantId)->where('partner_id', $filters['broker_id'] ?? null)->get()),
            'REMITTANCES' => $rows($remittances),
            'COLLECTIONS' => $rows($this->query->entries($tenantId, array_intersect_key($filters, array_flip(['insurer_id', 'broker_id', 'currency'])))->where('e.journal_type', 'PREMIUM_COLLECTION')->get()),
            'REFUNDS' => $rows($this->query->entries($tenantId, array_intersect_key($filters, array_flip(['insurer_id', 'broker_id', 'currency'])))->where('e.journal_type', 'REFUND')->get()),
            'SETTLEMENTS' => $rows(DB::table('settlement_batches')->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->when($filters['insurer_id'] ?? null, fn ($q, $i) => $q->where('carrier_id', $i))->when($filters['broker_id'] ?? null, fn ($q, $b) => $q->where('partner_id', $b)))->get()),
            'RECONCILIATION' => $rows(collect(app(SubledgerReports::class)->run($tenantId, 'SETTLEMENT_RECONCILIATION', $filters)['rows'])->map(fn ($r) => (object) $r)),
            'AGING' => $this->aging->age($tenantId, isset($filters['insurer_id']) ? 'REMITTANCE' : 'RECEIVABLE', $filters)['buckets'],
            'DOCUMENTS' => $rows(DB::table('documents')->where('tenant_id', $tenantId)->where('generation_trigger', 'FINANCE_SUBLEDGER_STATEMENT')
                ->where('subject_key', $filters['insurer_id'] ?? $filters['broker_id'] ?? '-')->get(['id', 'document_type_code', 'document_number', 'title', 'issued_at'])),
            'AUDIT' => $rows(DB::table('audit_events')->where('tenant_id', $tenantId)->whereIn('subject_id', array_merge($premium->pluck('id')->all(), $remittances->pluck('id')->all()))
                ->orderBy('created_at')->limit(500)->get()),
            'DEBIT_CREDIT_NOTES' => ['status' => 'NOT_AVAILABLE', 'reason' => 'No debit/credit note store exists yet; adjustments are manual journals (MANUAL_ADJUSTMENT).'],
        };
    }
}
