<?php

declare(strict_types=1);

namespace App\Application\Finance\Statements;

use App\Models\Carrier;
use App\Models\Partner;
use App\Models\PartnerStatement;
use App\Models\Party;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Batch 9-8 — REQ-PAY-015 / ESR FIN-009..012: customer, broker, agent and
 * carrier account statements DERIVED from the transaction tables (nothing
 * is stored — a statement is a pure function of the ledger-bearing rows).
 *
 * Every line carries a signed `amount_minor` = its effect on the balance:
 *   CUSTOMER  balance = amount the customer owes (premiums due +, payments −, refunds +).
 *   BROKER / AGENT (partners) balance = amount owed TO the partner (commission +, clawback −, payouts −).
 *   CARRIER   balance = amount payable TO the carrier (premium +, commission retained −, refunds −, settlements −).
 * opening = Σ lines before period_start; closing = opening + Σ lines in period.
 *
 * Sources: payment_intents, refunds, policies, commission_accruals,
 * partner_payout_requests, settlement_batches — and, when agent 9-1's
 * `financial_obligations` table exists, receivable/payable obligations
 * (a policy that has obligations is not double counted from policies.premium_minor).
 *
 * Persisted partner statements (partner_statements + items, Wave 6) are the
 * approved snapshot for partners; fromPartnerStatement() renders one in the
 * same shape, and a derived broker/agent statement links the matching one.
 */
final class AccountStatementService
{
    public const SUBJECTS = ['customer', 'broker', 'agent', 'carrier'];

    private const PAYMENT_OK = ['SUCCEEDED'];
    private const REFUND_DONE = ['COMPLETED', 'PROCESSED', 'SUCCEEDED'];
    private const PAYOUT_DONE = ['PAID', 'SUCCEEDED'];
    private const OBLIGATION_LIVE = ['OPEN', 'PARTIALLY_SETTLED', 'SETTLED'];

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $subjectType, string $subjectId, string $from, string $to, string $currency): array
    {
        $subjectType = strtolower($subjectType);
        if (! in_array($subjectType, self::SUBJECTS, true)) {
            throw ValidationException::withMessages(['subject_type' => 'Unknown statement subject.']);
        }
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['to' => 'Period end must not precede period start.']);
        }
        $currency = strtoupper($currency);

        [$name, $lines] = match ($subjectType) {
            'customer' => [$this->party($subjectId)->display_name, $this->customerLines($tenantId, $subjectId, $currency, $end)],
            'carrier' => [$this->carrier($subjectId), $this->carrierLines($tenantId, $subjectId, $currency, $end)],
            default => [$this->partner($subjectId, $subjectType, $tenantId), $this->partnerLines($tenantId, $subjectId, $currency, $end)],
        };

        $lines = $lines->sortBy([['occurred_at', 'asc'], ['reference_id', 'asc']])->values();
        $opening = (int) $lines->filter(fn ($l) => $l['occurred_at'] < $start->utc()->toIso8601String())->sum('amount_minor');
        $period = $lines->filter(fn ($l) => $l['occurred_at'] >= $start->utc()->toIso8601String());
        $running = $opening;
        $period = $period->map(function ($l) use (&$running) {
            $running += $l['amount_minor'];

            return $l + ['balance_minor' => $running];
        })->values();

        $totals = [];
        foreach ($period as $l) {
            $totals[$l['line_type']] = ($totals[$l['line_type']] ?? 0) + $l['amount_minor'];
        }

        return [
            'statement_number' => 'AST-'.strtoupper(substr($subjectType, 0, 3)).'-'.$start->format('Ymd').'-'.$end->format('Ymd').'-'.strtoupper(substr(hash('sha256', $tenantId.$subjectId.$currency), 0, 8)),
            'subject' => ['type' => strtoupper($subjectType), 'id' => $subjectId, 'name' => $name],
            'balance_meaning' => $subjectType === 'customer' ? 'OWED_BY_SUBJECT' : 'OWED_TO_SUBJECT',
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'currency' => $currency,
            'opening_balance_minor' => $opening,
            'lines' => $period->all(),
            'totals_by_type' => $totals,
            'closing_balance_minor' => $running,
            'source' => 'DERIVED',
            'persisted_statement_id' => in_array($subjectType, ['broker', 'agent'], true)
                ? PartnerStatement::where('tenant_id', $tenantId)->where('partner_id', $subjectId)->where('currency', $currency)
                    ->whereDate('period_start', $start->toDateString())->whereDate('period_end', $end->toDateString())->value('id')
                : null,
            'content_hash' => hash('sha256', json_encode([$opening, $period->map(fn ($l) => [$l['reference_type'], $l['reference_id'], $l['line_type'], $l['amount_minor']])->all()], JSON_THROW_ON_ERROR)),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** The Wave 6 persisted partner statement, rendered in the account-statement shape. */
    public function fromPartnerStatement(PartnerStatement $s): array
    {
        $s->loadMissing('items');
        $partner = Partner::with('party')->find($s->partner_id);
        $running = (int) $s->opening_balance_minor;
        $lines = $s->items->sortBy('occurred_at')->values()->map(function ($i) use (&$running) {
            $running += (int) $i->amount_minor;

            return ['occurred_at' => $i->occurred_at?->toIso8601String(), 'line_type' => $i->entry_type === 'COMMISSION' ? 'COMMISSION' : $i->entry_type,
                'description' => $i->entry_type, 'reference_type' => $i->reference_type, 'reference_id' => $i->reference_id,
                'amount_minor' => (int) $i->amount_minor, 'balance_minor' => $running];
        });

        return [
            'statement_number' => $s->statement_number,
            'subject' => ['type' => strtoupper((string) ($partner?->type ?? 'PARTNER')), 'id' => $s->partner_id, 'name' => $partner?->party?->display_name],
            'balance_meaning' => 'OWED_TO_SUBJECT',
            'period_start' => $s->period_start->toDateString(), 'period_end' => $s->period_end->toDateString(), 'currency' => $s->currency,
            'opening_balance_minor' => (int) $s->opening_balance_minor, 'lines' => $lines->all(),
            'totals_by_type' => ['COMMISSION' => (int) $s->earned_minor, 'ADJUSTMENT' => -(int) $s->clawed_back_minor, 'PAYMENT' => -(int) $s->paid_minor],
            'closing_balance_minor' => (int) $s->closing_balance_minor,
            'source' => 'PARTNER_STATEMENT', 'status' => $s->status, 'persisted_statement_id' => $s->id,
            'content_hash' => $s->content_hash, 'generated_at' => now()->toIso8601String(),
        ];
    }

    // --- sources -----------------------------------------------------------

    private function customerLines(string $tenantId, string $partyId, string $cur, CarbonImmutable $end): Collection
    {
        $lines = collect();
        $obligationPolicies = [];
        if ($this->obligations()) {
            $rows = DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('currency', $cur)->where('kind', 'RECEIVABLE')
                ->where('debtor_type', 'party')->where('debtor_id', $partyId)->whereIn('status', self::OBLIGATION_LIVE)
                ->whereNotIn('type', ['REFUND', 'COMMISSION', 'CLAIM'])->where('due_at', '<=', $end)->get();
            foreach ($rows as $o) {
                $lines->push($this->line($o->due_at, $o->type === 'FEE' || $o->type === 'TAX' ? 'ADJUSTMENT' : 'PREMIUM_DUE', $o->description ?? $o->type, 'financial_obligation', $o->id, (int) $o->amount_minor));
                if ($o->policy_id) {
                    $obligationPolicies[$o->policy_id] = true;
                }
            }
        }
        DB::table('policies')->where('tenant_id', $tenantId)->where('party_id', $partyId)->where('currency', $cur)->where('premium_minor', '>', 0)
            ->whereRaw('COALESCE(issued_at, created_at) <= ?', [$end])->get()
            ->reject(fn ($p) => isset($obligationPolicies[$p->id]))
            ->each(fn ($p) => $lines->push($this->line($p->issued_at ?? $p->created_at, 'PREMIUM_DUE', 'Premium '.($p->policy_number ?? ''), 'policy', $p->id, (int) $p->premium_minor)));

        $payments = DB::table('payment_intents as pi')->join('proposals as pr', 'pr.id', '=', 'pi.proposal_id')
            ->where('pi.tenant_id', $tenantId)->where('pr.party_id', $partyId)->where('pi.currency', $cur);
        (clone $payments)->whereIn('pi.status', self::PAYMENT_OK)->whereRaw('COALESCE(pi.reconciled_at, pi.updated_at) <= ?', [$end])
            ->get(['pi.id', 'pi.amount_minor', 'pi.reconciled_at', 'pi.updated_at', 'pi.provider'])
            ->each(fn ($p) => $lines->push($this->line($p->reconciled_at ?? $p->updated_at, 'PAYMENT', 'Payment ('.$p->provider.')', 'payment_intent', $p->id, -(int) $p->amount_minor)));
        DB::table('refunds as r')->join('payment_intents as pi', 'pi.id', '=', 'r.payment_intent_id')->join('proposals as pr', 'pr.id', '=', 'pi.proposal_id')
            ->where('r.tenant_id', $tenantId)->where('pr.party_id', $partyId)->where('r.currency', $cur)->whereIn('r.status', self::REFUND_DONE)
            ->whereRaw('COALESCE(r.completed_at, r.updated_at) <= ?', [$end])->get(['r.id', 'r.refund_number', 'r.amount_minor', 'r.completed_at', 'r.updated_at'])
            ->each(fn ($r) => $lines->push($this->line($r->completed_at ?? $r->updated_at, 'REFUND', 'Refund '.$r->refund_number, 'refund', $r->id, (int) $r->amount_minor)));

        return $lines;
    }

    private function partnerLines(string $tenantId, string $partnerId, string $cur, CarbonImmutable $end): Collection
    {
        $lines = collect();
        DB::table('commission_accruals')->where('tenant_id', $tenantId)->where('partner_id', $partnerId)->where('currency', $cur)->where('created_at', '<=', $end)->get()
            ->each(function ($a) use ($lines) {
                if ((int) $a->vested_minor !== 0) {
                    $lines->push($this->line($a->created_at, 'COMMISSION', 'Commission earned', 'commission_accrual', $a->id, (int) $a->vested_minor));
                }
                if ((int) $a->clawed_back_minor !== 0) {
                    $lines->push($this->line($a->updated_at, 'ADJUSTMENT', 'Commission clawback', 'commission_accrual', $a->id, -(int) $a->clawed_back_minor));
                }
            });
        DB::table('partner_payout_requests')->where('tenant_id', $tenantId)->where('partner_id', $partnerId)->where('currency', $cur)->whereIn('status', self::PAYOUT_DONE)
            ->whereRaw('COALESCE(paid_at, updated_at) <= ?', [$end])->get()
            ->each(fn ($p) => $lines->push($this->line($p->paid_at ?? $p->updated_at, 'PAYMENT', 'Payout '.$p->payout_number, 'partner_payout_request', $p->id, -(int) $p->amount_minor)));
        if ($this->obligations()) {
            DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('currency', $cur)->where('kind', 'PAYABLE')
                ->where('creditor_type', 'partner')->where('creditor_id', $partnerId)->whereIn('status', self::OBLIGATION_LIVE)
                ->where('source_type', '!=', 'commission_accrual')->where('due_at', '<=', $end)->get()
                ->each(fn ($o) => $lines->push($this->line($o->due_at, 'ADJUSTMENT', $o->description ?? $o->type, 'financial_obligation', $o->id, (int) $o->amount_minor)));
        }

        return $lines;
    }

    private function carrierLines(string $tenantId, string $carrierId, string $cur, CarbonImmutable $end): Collection
    {
        $lines = collect();
        $obligationPolicies = [];
        if ($this->obligations()) {
            $rows = DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('currency', $cur)
                ->where('creditor_type', 'carrier')->where('creditor_id', $carrierId)->whereIn('status', self::OBLIGATION_LIVE)
                ->whereNotIn('type', ['REFUND', 'COMMISSION', 'CLAIM'])->where('due_at', '<=', $end)->get();
            foreach ($rows as $o) {
                $lines->push($this->line($o->due_at, 'PREMIUM_DUE', $o->description ?? $o->type, 'financial_obligation', $o->id, (int) $o->amount_minor));
                if ($o->policy_id) {
                    $obligationPolicies[$o->policy_id] = true;
                }
            }
        }
        $policies = DB::table('policies')->where('tenant_id', $tenantId)->where('carrier_id', $carrierId)->where('currency', $cur)
            ->whereRaw('COALESCE(issued_at, created_at) <= ?', [$end])->get();
        $policies->where('premium_minor', '>', 0)->reject(fn ($p) => isset($obligationPolicies[$p->id]))
            ->each(fn ($p) => $lines->push($this->line($p->issued_at ?? $p->created_at, 'PREMIUM_DUE', 'Premium '.($p->policy_number ?? ''), 'policy', $p->id, (int) $p->premium_minor)));
        $policyIds = DB::table('policies')->where('tenant_id', $tenantId)->where('carrier_id', $carrierId)->pluck('id');
        DB::table('commission_accruals')->where('tenant_id', $tenantId)->whereIn('policy_id', $policyIds)->where('currency', $cur)->where('created_at', '<=', $end)->get()
            ->each(fn ($a) => (int) $a->vested_minor - (int) $a->clawed_back_minor === 0 ? null
                : $lines->push($this->line($a->created_at, 'COMMISSION', 'Commission retained', 'commission_accrual', $a->id, -((int) $a->vested_minor - (int) $a->clawed_back_minor))));
        DB::table('refunds as r')->join('policies as p', 'p.payment_intent_id', '=', 'r.payment_intent_id')
            ->where('r.tenant_id', $tenantId)->where('p.carrier_id', $carrierId)->where('r.currency', $cur)->whereIn('r.status', self::REFUND_DONE)
            ->whereRaw('COALESCE(r.completed_at, r.updated_at) <= ?', [$end])->get(['r.id', 'r.refund_number', 'r.amount_minor', 'r.completed_at', 'r.updated_at'])
            ->each(fn ($r) => $lines->push($this->line($r->completed_at ?? $r->updated_at, 'REFUND', 'Refund '.$r->refund_number, 'refund', $r->id, -(int) $r->amount_minor)));
        DB::table('settlement_batches')->where('tenant_id', $tenantId)->where('carrier_id', $carrierId)->where('currency', $cur)->where('status', 'PAID')
            ->whereRaw('COALESCE(paid_at, updated_at) <= ?', [$end])->get()
            ->each(fn ($b) => $lines->push($this->line($b->paid_at ?? $b->updated_at, 'PAYMENT', 'Settlement '.($b->settlement_number ?? ''), 'settlement_batch', $b->id, -(int) $b->net_amount_minor)));

        return $lines;
    }

    // --- helpers -----------------------------------------------------------

    private function line(mixed $at, string $type, string $description, string $refType, string $refId, int $amount): array
    {
        return ['occurred_at' => CarbonImmutable::parse($at)->utc()->toIso8601String(), 'line_type' => $type, 'description' => trim($description),
            'reference_type' => $refType, 'reference_id' => $refId, 'amount_minor' => $amount];
    }

    private function obligations(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('financial_obligations');
    }

    private function party(string $id): Party
    {
        return Party::findOrFail($id);
    }

    private function carrier(string $id): ?string
    {
        return Carrier::with('party')->findOrFail($id)->party?->display_name;
    }

    private function partner(string $id, string $type, string $tenantId): ?string
    {
        $p = Partner::with('party')->where('id', $id)->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->first();
        if (! $p || strtolower((string) $p->type) !== $type) {
            throw new ModelNotFoundException;
        }

        return $p->party?->display_name;
    }
}
