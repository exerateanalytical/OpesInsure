<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\FinancialPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent F1 — spec premium_remittance: a broker's premium remittance to an insurer.
 *   record()   books the cash as UNAPPLIED (Dr unapplied cash 471100 / Cr bank) — it stays visible until allocated
 *              (premium_remittance.unapplied_payment_account_required).
 *   allocate() applies it to one or more insurer PREMIUM PAYABLE obligations (multi-policy, partial, instalments are just obligations),
 *              settling them through ObligationService and posting Dr premium payable 401100 / Cr unapplied cash per allocation.
 *              Σ allocations can never exceed the remittance (DB check + locked validation).
 * Remittance status per payable (NOT_DUE / DUE / PARTIALLY_REMITTED / REMITTED / OVERDUE / DISPUTED / RECONCILIATION_HOLD) is derived
 * by remittanceStatus(); nothing is netted against commission (settlements.netting_rule).
 */
final class PremiumRemittanceService
{
    public function __construct(private FinancialPostingService $posting, private ObligationService $obligations, private AuditWriter $audit, private OutboxWriter $outbox) {}

    /** @param array{insurer_id:string, broker_id?:?string, currency:string, amount_minor:int, remittance_date?:?string, payment_reference?:?string, idempotency_key:string} $d */
    public function record(string $tenantId, array $d, ?string $actorId): object
    {
        if ((int) $d['amount_minor'] <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Remittance amount must be positive.']);
        }
        if ($existing = DB::table('premium_remittances')->where('tenant_id', $tenantId)->where('idempotency_key', $d['idempotency_key'])->first()) {
            return $existing;
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($tenantId, $d, $actorId, $id) {
            $currency = strtoupper($d['currency']);
            DB::table('premium_remittances')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'remittance_number' => 'PRM-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)), 'insurer_id' => $d['insurer_id'],
                'broker_id' => $d['broker_id'] ?? null, 'currency' => $currency, 'amount_minor' => (int) $d['amount_minor'], 'allocated_minor' => 0, 'status' => 'UNAPPLIED',
                'remittance_date' => $d['remittance_date'] ?? now()->toDateString(), 'payment_reference' => $d['payment_reference'] ?? null,
                'idempotency_key' => $d['idempotency_key'], 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $journal = $this->posting->post($tenantId, 'premium.remittance.recorded', $id, (int) $d['amount_minor'], $currency, 'remittance:'.$id);
            DB::table('premium_remittances')->where('id', $id)->update(['journal_id' => $journal]);
            $this->audit->record('premium.remittance.recorded', 'premium_remittance', $id, ['amount_minor' => (int) $d['amount_minor'], 'insurer_id' => $d['insurer_id']]);
            $this->outbox->record('premium.remittance.recorded', 'premium_remittance', $id, ['remittance_id' => $id, 'amount_minor' => (int) $d['amount_minor'], 'currency' => $currency]);
        });

        return DB::table('premium_remittances')->find($id);
    }

    /** @param list<array{financial_obligation_id:string, amount_minor:int}> $allocations */
    public function allocate(string $tenantId, string $remittanceId, array $allocations, ?string $actorId): object
    {
        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => 'At least one allocation is required.']);
        }

        return DB::transaction(function () use ($tenantId, $remittanceId, $allocations, $actorId) {
            $r = DB::table('premium_remittances')->where('tenant_id', $tenantId)->where('id', $remittanceId)->lockForUpdate()->first();
            abort_unless($r, 404);
            if (in_array($r->status, ['DISPUTED', 'RECONCILIATION_HOLD'], true)) {
                throw ValidationException::withMessages(['status' => "Remittance is {$r->status}; resolve it before allocating."]);
            }
            $total = array_sum(array_map(fn ($a) => (int) $a['amount_minor'], $allocations));
            if ($total > (int) $r->amount_minor - (int) $r->allocated_minor) {
                throw ValidationException::withMessages(['allocations' => 'Remittance allocations cannot exceed the unapplied remittance amount.']);
            }
            foreach ($allocations as $a) {
                $amount = (int) $a['amount_minor'];
                $o = DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('id', $a['financial_obligation_id'])->lockForUpdate()->first();
                if (! $o || $o->kind !== 'PAYABLE' || $o->creditor_type !== 'carrier' || $o->creditor_id !== $r->insurer_id) {
                    throw ValidationException::withMessages(['financial_obligation_id' => 'Only a premium payable to this remittance\'s insurer can be allocated.']);
                }
                if ($o->currency !== $r->currency) {
                    throw ValidationException::withMessages(['currency' => 'Currency must match the account currency (no FX posting).']);
                }
                if ($amount <= 0 || $amount > (int) $o->outstanding_minor) {
                    throw ValidationException::withMessages(['amount_minor' => 'Allocation must be positive and not exceed the payable outstanding.']);
                }
                $allocId = (string) Str::uuid();
                DB::table('premium_remittance_allocations')->insert(['id' => $allocId, 'tenant_id' => $tenantId, 'premium_remittance_id' => $r->id, 'financial_obligation_id' => $o->id,
                    'policy_id' => $o->policy_id, 'amount_minor' => $amount, 'currency' => $r->currency, 'created_by' => $actorId, 'created_at' => now()]);
                $this->obligations->settle($o->id, $amount, 'remittance-allocation:'.$allocId, $actorId);
                $journal = $this->posting->post($tenantId, 'premium.remittance.allocated', $allocId, $amount, $r->currency, 'remittance:'.$r->id);
                DB::table('premium_remittance_allocations')->where('id', $allocId)->update(['journal_id' => $journal]);
            }
            $allocated = (int) $r->allocated_minor + $total;
            $status = $allocated === (int) $r->amount_minor ? 'APPLIED' : 'PARTIALLY_APPLIED';
            DB::table('premium_remittances')->where('id', $r->id)->update(['allocated_minor' => $allocated, 'status' => $status, 'updated_at' => now()]);
            $this->audit->record('premium.remittance.allocated', 'premium_remittance', $r->id, ['allocated_minor' => $total, 'lines' => count($allocations)]);
            $this->outbox->record('premium.remittance.allocated', 'premium_remittance', $r->id, ['remittance_id' => $r->id, 'allocated_minor' => $total, 'unapplied_minor' => (int) $r->amount_minor - $allocated]);

            return DB::table('premium_remittances')->find($r->id);
        });
    }

    /** DISPUTED / RECONCILIATION_HOLD freeze allocation; releasing returns to the allocation-derived status. */
    public function hold(string $tenantId, string $remittanceId, string $status, string $reason, string $actorId): object
    {
        if (! in_array($status, ['DISPUTED', 'RECONCILIATION_HOLD', 'RELEASE'], true)) {
            throw ValidationException::withMessages(['status' => 'Unknown hold status.']);
        }
        $r = DB::table('premium_remittances')->where('tenant_id', $tenantId)->where('id', $remittanceId)->first();
        abort_unless($r, 404);
        $next = $status !== 'RELEASE' ? $status : ((int) $r->allocated_minor === 0 ? 'UNAPPLIED' : ((int) $r->allocated_minor === (int) $r->amount_minor ? 'APPLIED' : 'PARTIALLY_APPLIED'));
        DB::table('premium_remittances')->where('id', $r->id)->update(['status' => $next, 'updated_at' => now()]);
        $this->audit->record('premium.remittance.status_changed', 'premium_remittance', $r->id, ['from' => $r->status, 'to' => $next], $reason);

        return DB::table('premium_remittances')->find($r->id);
    }

    /** Spec remittance status of an insurer premium payable (financial_obligations PAYABLE, creditor carrier). */
    public static function remittanceStatus(object $o, ?CarbonImmutable $asOf = null): string
    {
        $asOf ??= CarbonImmutable::now();
        $held = DB::table('premium_remittance_allocations as a')->join('premium_remittances as r', 'r.id', '=', 'a.premium_remittance_id')
            ->where('a.financial_obligation_id', $o->id)->whereIn('r.status', ['DISPUTED', 'RECONCILIATION_HOLD'])->value('r.status');
        if ($held) {
            return $held;
        }
        $outstanding = (int) $o->outstanding_minor;
        if ($outstanding === 0 || $o->status === 'SETTLED') {
            return 'REMITTED';
        }
        $due = CarbonImmutable::parse($o->due_at);
        if ($outstanding < (int) $o->amount_minor) {
            return $due->lt($asOf->startOfDay()) ? 'OVERDUE' : 'PARTIALLY_REMITTED';
        }
        if ($due->gt($asOf->endOfDay())) {
            return 'NOT_DUE';
        }

        return $due->lt($asOf->startOfDay()) ? 'OVERDUE' : 'DUE';
    }

    /** Emits premium.remittance.overdue once per overdue insurer payable (idempotent on the outbox). */
    public function flagOverdue(string $tenantId, ?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();
        $n = 0;
        DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('kind', 'PAYABLE')->where('creditor_type', 'carrier')
            ->whereIn('status', ObligationService::OPEN_STATUSES)->where('due_at', '<', $asOf->startOfDay())->orderBy('id')->get()
            ->each(function ($o) use (&$n, $asOf) {
                if (self::remittanceStatus($o, $asOf) !== 'OVERDUE'
                    || DB::table('outbox_messages')->where('event_name', 'premium.remittance.overdue')->where('aggregate_id', $o->id)->exists()) {
                    return;
                }
                $this->outbox->record('premium.remittance.overdue', 'financial_obligation', $o->id, ['obligation_id' => $o->id, 'insurer_id' => $o->creditor_id,
                    'outstanding_minor' => (int) $o->outstanding_minor, 'currency' => $o->currency, 'due_at' => (string) $o->due_at]);
                $n++;
            });

        return $n;
    }
}
