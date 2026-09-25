<?php

declare(strict_types=1);

namespace App\Application\Finance\PremiumStatus;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PAY-005 — premium components per policy.
 *
 * Components are snapshots: an amount is never edited. A line is either payable
 * (NET_PREMIUM, TAX, LEVY, STAMP_DUTY, SERVICE_FEE, OTHER_CHARGE — what a payer owes)
 * or informational (BASE/COVERAGE premium, RISK_LOADING, DISCOUNT, GROSS_PREMIUM — the breakdown).
 * A payable line can be closed as CANCELLED or WRITTEN_OFF (with reason); it is never deleted.
 */
final class PremiumComponentService
{
    public const COMPONENTS = ['BASE_PREMIUM', 'COVERAGE_PREMIUM', 'RISK_LOADING', 'DISCOUNT', 'NET_PREMIUM', 'TAX', 'LEVY', 'STAMP_DUTY', 'SERVICE_FEE', 'OTHER_CHARGE', 'GROSS_PREMIUM'];

    public const PAYABLE = ['NET_PREMIUM', 'TAX', 'LEVY', 'STAMP_DUTY', 'SERVICE_FEE', 'OTHER_CHARGE'];

    public const CLOSURES = ['CANCELLED', 'WRITTEN_OFF'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function policy(string $policyId, string $tenantId): Policy
    {
        $p = Str::isUuid($policyId) ? Policy::where('tenant_id', $tenantId)->find($policyId) : null;
        abort_unless($p, 404);

        return $p;
    }

    /** @return Collection<int, object> */
    public function components(Policy $policy): Collection
    {
        return DB::table('premium_components')->where('policy_id', $policy->id)->orderBy('due_at')->orderBy('line_key')->get();
    }

    /**
     * Idempotently record component lines (same line_key = same line; a re-send is ignored,
     * a conflicting re-send is refused).
     *
     * @param  list<array{line_key: string, component: string, amount_minor: int, due_at?: ?string, financial_obligation_id?: ?string, snapshot?: array}>  $lines
     * @return Collection<int, object>
     */
    public function record(Policy $policy, array $lines, string $source, ?User $actor): Collection
    {
        $currency = (string) ($policy->currency ?: 'XAF');
        foreach (array_values($lines) as $i => $l) {
            $component = strtoupper((string) ($l['component'] ?? ''));
            if (! in_array($component, self::COMPONENTS, true)) {
                throw ValidationException::withMessages(["components.{$i}.component" => ['Unknown premium component.']]);
            }
            $payable = in_array($component, self::PAYABLE, true);
            if (! is_int($l['amount_minor'] ?? null) || ($payable && $l['amount_minor'] <= 0)) {
                throw ValidationException::withMessages(["components.{$i}.amount_minor" => ['Payable components need a positive integer amount in minor units.']]);
            }
            if (isset($l['financial_obligation_id']) && ! Str::isUuid((string) $l['financial_obligation_id'])) {
                throw ValidationException::withMessages(["components.{$i}.financial_obligation_id" => ['Must be a uuid.']]);
            }
        }

        return DB::transaction(function () use ($policy, $lines, $source, $actor, $currency) {
            $now = now();
            $added = 0;
            foreach ($lines as $l) {
                $component = strtoupper($l['component']);
                $existing = DB::table('premium_components')->where('policy_id', $policy->id)->where('line_key', $l['line_key'])->first();
                if ($existing) {
                    if ($existing->component !== $component || (int) $existing->amount_minor !== $l['amount_minor']) {
                        throw ValidationException::withMessages(['components' => ["Line {$l['line_key']} already exists with different values; components are never edited."]]);
                    }

                    continue;
                }
                DB::table('premium_components')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'line_key' => $l['line_key'],
                    'component' => $component, 'amount_minor' => $l['amount_minor'], 'currency' => $currency, 'payable' => in_array($component, self::PAYABLE, true),
                    'due_at' => $l['due_at'] ?? null, 'financial_obligation_id' => $l['financial_obligation_id'] ?? null, 'source' => $source,
                    'snapshot' => json_encode($l['snapshot'] ?? (object) []), 'created_at' => $now, 'updated_at' => $now,
                ]);
                $added++;
            }
            if ($added > 0) {
                $this->audit->record('finance.premium_components.recorded', 'policy', $policy->id, ['added' => $added, 'source' => $source, 'actor_id' => $actor?->id]);
                $this->outbox->record('finance.premium_components.recorded', 'policy', $policy->id, ['policy_id' => $policy->id, 'added' => $added]);
            }

            return $this->components($policy);
        });
    }

    /** Snapshot components from the policy's bound terms (premium_minor / tax_minor / fee_minor / total_minor). */
    public function captureFromTerms(Policy $policy, ?User $actor): Collection
    {
        $terms = (array) ($policy->terms_snapshot ?? []);
        $due = $policy->coverage_starts_at?->toIso8601String();
        $lines = [];
        foreach (['premium_minor' => 'NET_PREMIUM', 'tax_minor' => 'TAX', 'fee_minor' => 'SERVICE_FEE'] as $k => $c) {
            if ((int) ($terms[$k] ?? 0) > 0) {
                $lines[] = ['line_key' => 'TERMS:'.$c, 'component' => $c, 'amount_minor' => (int) $terms[$k], 'due_at' => $due, 'snapshot' => [$k => (int) $terms[$k]]];
            }
        }
        $gross = (int) ($terms['total_minor'] ?? 0) ?: array_sum(array_column($lines, 'amount_minor'));
        if ($lines === []) {
            throw ValidationException::withMessages(['policy' => ['The policy terms carry no premium breakdown.']]);
        }
        $lines[] = ['line_key' => 'TERMS:GROSS_PREMIUM', 'component' => 'GROSS_PREMIUM', 'amount_minor' => $gross, 'due_at' => $due, 'snapshot' => ['total_minor' => $gross]];

        return $this->record($policy, $lines, 'POLICY_TERMS', $actor);
    }

    public function close(string $componentId, string $tenantId, string $closure, string $reason, User $actor): object
    {
        if (! in_array($closure, self::CLOSURES, true)) {
            throw ValidationException::withMessages(['closure' => ['Choose CANCELLED or WRITTEN_OFF.']]);
        }

        return DB::transaction(function () use ($componentId, $tenantId, $closure, $reason, $actor) {
            $c = Str::isUuid($componentId) ? DB::table('premium_components')->where('tenant_id', $tenantId)->where('id', $componentId)->lockForUpdate()->first() : null;
            abort_unless($c, 404);
            if (! $c->payable) {
                throw ValidationException::withMessages(['component' => ['Only payable components can be cancelled or written off.']]);
            }
            if ($c->closure !== null) {
                throw ValidationException::withMessages(['component' => ["Component is already {$c->closure}."]]);
            }
            DB::table('premium_components')->where('id', $c->id)->update(['closure' => $closure, 'closure_reason' => $reason, 'closed_by' => $actor->id, 'closed_at' => now(), 'updated_at' => now()]);
            $action = $closure === 'CANCELLED' ? 'finance.premium_component.cancelled' : 'finance.premium_component.written_off';
            $this->audit->record($action, 'premium_component', $c->id, ['policy_id' => $c->policy_id, 'amount_minor' => (int) $c->amount_minor], $reason);
            if ($closure === 'CANCELLED') {
                $this->outbox->record('finance.premium_component.cancelled', 'policy', $c->policy_id, ['policy_id' => $c->policy_id, 'premium_component_id' => $c->id]);
            } else {
                $this->outbox->record('finance.premium_component.written_off', 'policy', $c->policy_id, ['policy_id' => $c->policy_id, 'premium_component_id' => $c->id]);
            }

            return DB::table('premium_components')->find($c->id);
        });
    }
}
