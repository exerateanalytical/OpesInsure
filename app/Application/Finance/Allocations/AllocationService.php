<?php

declare(strict_types=1);

namespace App\Application\Finance\Allocations;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PAY-004 — allocation engine: splits a confirmed payment over what the payer owes
 * (payable premium components and open receivable obligations) in the order set by the
 * tenant's active allocation rule version.
 *
 *  - idempotent: one run per (tenant, idempotency_key); a replay returns the same run;
 *  - never over-allocates: per payment (≤ amount) and per target (≤ outstanding), under row locks;
 *  - reversible: reverse() appends negative REVERSAL rows (the table is append-only in Postgres);
 *  - obligations (agent 9-1) are settled through ObligationGateway, never touched directly.
 */
final class AllocationService
{
    public const ALLOCATABLE_PAYMENT_STATUSES = ['SUCCEEDED'];

    public const REVERSAL_REASONS = ['ALLOCATION_ERROR', 'REFUND', 'PAYMENT_REVERSED', 'CHARGEBACK'];

    public function __construct(
        private readonly AllocationRuleService $rules,
        private readonly ObligationGateway $obligations,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param list<string> $obligationIds */
    public function allocate(string $tenantId, string $paymentId, ?string $policyId, array $obligationIds, ?int $amountMinor, string $idempotencyKey, ?User $actor): array
    {
        return DB::transaction(function () use ($tenantId, $paymentId, $policyId, $obligationIds, $amountMinor, $idempotencyKey, $actor) {
            $payment = Str::isUuid($paymentId) ? DB::table('payment_intents')->where('tenant_id', $tenantId)->where('id', $paymentId)->lockForUpdate()->first() : null;
            abort_unless($payment, 404);
            $replay = DB::table('payment_allocation_runs')->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
            if ($replay) {
                if ($replay->payment_intent_id !== $payment->id) {
                    throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was used for another payment.']]);
                }

                return $this->run($replay->id) + ['replayed' => true];
            }
            if (! in_array($payment->status, self::ALLOCATABLE_PAYMENT_STATUSES, true)) {
                throw ValidationException::withMessages(['payment' => ["Only confirmed payments can be allocated (status {$payment->status})."]]);
            }
            $available = (int) $payment->amount_minor - $this->allocatedMinor($payment->id);
            $amount = $amountMinor ?? $available;
            if ($amount <= 0 || $amount > $available) {
                throw ValidationException::withMessages(['amount_minor' => ["Cannot allocate {$amount}; {$available} {$payment->currency} is unallocated on this payment."]]);
            }
            $policyId = $this->resolvePolicy($tenantId, $payment, $policyId);
            $rule = $this->rules->active($tenantId);
            $targets = AllocationRuleService::order($this->targets($tenantId, $payment->currency, $policyId, $obligationIds), $rule);

            $runId = (string) Str::uuid();
            $now = now();
            $rows = [];
            $remaining = $amount;
            foreach ($targets as $t) {
                if ($remaining === 0) {
                    break;
                }
                $take = min($remaining, $t['outstanding_minor']);
                $remaining -= $take;
                $rows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'run_id' => $runId, 'payment_intent_id' => $payment->id,
                    'premium_component_id' => $t['premium_component_id'], 'financial_obligation_id' => $t['financial_obligation_id'],
                    'target_category' => $t['category'], 'kind' => 'ALLOCATION', 'amount_minor' => $take, 'currency' => $payment->currency,
                    'reverses_allocation_id' => null, 'reason_code' => null, 'sequence' => count($rows) + 1, 'created_at' => $now];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['payment' => ['Nothing is outstanding to allocate this payment against.']]);
            }
            DB::table('payment_allocation_runs')->insert(['id' => $runId, 'tenant_id' => $tenantId, 'payment_intent_id' => $payment->id, 'policy_id' => $policyId,
                'idempotency_key' => $idempotencyKey, 'allocation_rule_version_id' => $rule['id'], 'rule_version' => $rule['version'],
                'rule_snapshot' => json_encode(['strategy' => $rule['strategy'], 'priority' => $rule['priority']]), 'allocated_minor' => $amount - $remaining,
                'currency' => $payment->currency, 'status' => 'APPLIED', 'created_by' => $actor?->id, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('payment_allocations')->insert($rows);
            foreach ($rows as $r) {
                if ($r['financial_obligation_id']) {
                    $this->obligations->settle($r['financial_obligation_id'], $r['amount_minor'], 'payment_allocation:'.$r['id']);
                }
            }
            $this->audit->record('payment.allocated', 'payment_intent', $payment->id, ['run_id' => $runId, 'allocated_minor' => $amount - $remaining, 'rule_version' => $rule['version'], 'lines' => count($rows)]);
            $this->outbox->record('payment.allocated', 'payment_intent', $payment->id, ['payment_intent_id' => $payment->id, 'run_id' => $runId, 'policy_id' => $policyId,
                'allocated_minor' => $amount - $remaining, 'currency' => $payment->currency, 'rule_version' => $rule['version']]);

            return $this->run($runId) + ['replayed' => false];
        });
    }

    public function reverse(string $tenantId, string $runId, string $reasonCode, string $reason, ?User $actor): array
    {
        if (! in_array($reasonCode, self::REVERSAL_REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => ['Unknown reversal reason.']]);
        }

        return DB::transaction(function () use ($tenantId, $runId, $reasonCode, $reason, $actor) {
            $run = Str::isUuid($runId) ? DB::table('payment_allocation_runs')->where('tenant_id', $tenantId)->where('id', $runId)->lockForUpdate()->first() : null;
            abort_unless($run, 404);
            if ($run->status === 'REVERSED') {
                return $this->run($run->id) + ['replayed' => true];
            }
            $now = now();
            $originals = DB::table('payment_allocations')->where('run_id', $run->id)->where('kind', 'ALLOCATION')->orderBy('sequence')->get();
            $seq = $originals->count();
            foreach ($originals as $a) {
                $id = (string) Str::uuid();
                DB::table('payment_allocations')->insert(['id' => $id, 'tenant_id' => $tenantId, 'run_id' => $run->id, 'payment_intent_id' => $a->payment_intent_id,
                    'premium_component_id' => $a->premium_component_id, 'financial_obligation_id' => $a->financial_obligation_id, 'target_category' => $a->target_category,
                    'kind' => 'REVERSAL', 'amount_minor' => -(int) $a->amount_minor, 'currency' => $a->currency, 'reverses_allocation_id' => $a->id,
                    'reason_code' => $reasonCode, 'sequence' => ++$seq, 'created_at' => $now]);
                if ($a->financial_obligation_id) {
                    $this->obligations->settle($a->financial_obligation_id, -(int) $a->amount_minor, 'payment_allocation_reversal:'.$id);
                }
            }
            DB::table('payment_allocation_runs')->where('id', $run->id)->update(['status' => 'REVERSED', 'reversed_at' => $now, 'reversal_reason' => $reasonCode.': '.$reason, 'updated_at' => $now]);
            $this->audit->record('payment.allocation.reversed', 'payment_intent', $run->payment_intent_id, ['run_id' => $run->id, 'reason_code' => $reasonCode, 'actor_id' => $actor?->id], $reason);
            $this->outbox->record('payment.allocation.reversed', 'payment_intent', $run->payment_intent_id, ['payment_intent_id' => $run->payment_intent_id, 'run_id' => $run->id,
                'policy_id' => $run->policy_id, 'reversed_minor' => (int) $run->allocated_minor, 'reason_code' => $reasonCode]);

            return $this->run($run->id) + ['replayed' => false];
        });
    }

    /** Allocation summary of one payment (runs, lines, unallocated remainder). */
    public function forPayment(string $tenantId, string $paymentId): array
    {
        $payment = Str::isUuid($paymentId) ? DB::table('payment_intents')->where('tenant_id', $tenantId)->where('id', $paymentId)->first() : null;
        abort_unless($payment, 404);
        $allocated = $this->allocatedMinor($payment->id);
        $runs = DB::table('payment_allocation_runs')->where('payment_intent_id', $payment->id)->orderBy('created_at')->pluck('id');

        return ['payment_intent_id' => $payment->id, 'payment_status' => $payment->status, 'amount_minor' => (int) $payment->amount_minor, 'currency' => $payment->currency,
            'allocated_minor' => $allocated, 'unallocated_minor' => (int) $payment->amount_minor - $allocated, 'runs' => $runs->map(fn ($id) => $this->run($id))->all()];
    }

    public function run(string $runId): array
    {
        $r = DB::table('payment_allocation_runs')->find($runId);
        $lines = DB::table('payment_allocations')->where('run_id', $runId)->orderBy('sequence')->get()->map(fn ($a) => [
            'id' => $a->id, 'kind' => $a->kind, 'sequence' => (int) $a->sequence, 'premium_component_id' => $a->premium_component_id,
            'financial_obligation_id' => $a->financial_obligation_id, 'target_category' => $a->target_category, 'amount_minor' => (int) $a->amount_minor,
            'currency' => $a->currency, 'reverses_allocation_id' => $a->reverses_allocation_id, 'reason_code' => $a->reason_code,
        ])->all();

        return ['id' => $r->id, 'payment_intent_id' => $r->payment_intent_id, 'policy_id' => $r->policy_id, 'idempotency_key' => $r->idempotency_key,
            'status' => $r->status, 'rule_version' => (int) $r->rule_version, 'rule' => json_decode($r->rule_snapshot, true), 'allocated_minor' => (int) $r->allocated_minor,
            'currency' => $r->currency, 'reversed_at' => $r->reversed_at, 'reversal_reason' => $r->reversal_reason, 'created_at' => $r->created_at, 'lines' => $lines];
    }

    private function allocatedMinor(string $paymentId): int
    {
        return (int) DB::table('payment_allocations')->where('payment_intent_id', $paymentId)->sum('amount_minor');
    }

    private function resolvePolicy(string $tenantId, object $payment, ?string $policyId): ?string
    {
        if ($policyId !== null) {
            $ok = Str::isUuid($policyId) && DB::table('policies')->where('tenant_id', $tenantId)->where('id', $policyId)->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['policy_id' => ['Unknown policy.']]);
            }

            return $policyId;
        }

        return DB::table('policies')->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('payment_intent_id', $payment->id)->orWhere('proposal_id', $payment->proposal_id))
            ->orderBy('created_at')->value('id');
    }

    /** @return list<array<string, mixed>> */
    private function targets(string $tenantId, string $currency, ?string $policyId, array $obligationIds): array
    {
        $targets = [];
        $linked = [];
        if ($policyId !== null) {
            $components = DB::table('premium_components')->where('policy_id', $policyId)->where('payable', true)->whereNull('closure')
                ->where('currency', $currency)->orderBy('id')->lockForUpdate()->get();
            $paid = DB::table('payment_allocations')->whereIn('premium_component_id', $components->pluck('id'))
                ->groupBy('premium_component_id')->selectRaw('premium_component_id, SUM(amount_minor) AS paid')->pluck('paid', 'premium_component_id');
            foreach ($components as $c) {
                if ($c->financial_obligation_id) {
                    $linked[] = $c->financial_obligation_id;
                }
                $outstanding = (int) $c->amount_minor - (int) ($paid[$c->id] ?? 0);
                if ($outstanding > 0) {
                    $targets[] = ['key' => 'C:'.$c->line_key, 'category' => AllocationRuleService::componentCategory($c->component), 'due_at' => $c->due_at,
                        'outstanding_minor' => $outstanding, 'premium_component_id' => $c->id, 'financial_obligation_id' => $c->financial_obligation_id];
                }
            }
        }
        foreach ($this->obligations->openReceivables($tenantId, $obligationIds, $obligationIds === [] ? $policyId : null) as $o) {
            if (in_array($o['id'], $linked, true) || $o['currency'] !== $currency) {
                continue;
            }
            $targets[] = ['key' => 'O:'.$o['id'], 'category' => AllocationRuleService::obligationCategory($o['type']), 'due_at' => $o['due_at'],
                'outstanding_minor' => $o['outstanding_minor'], 'premium_component_id' => null, 'financial_obligation_id' => $o['id']];
        }

        return $targets;
    }
}
