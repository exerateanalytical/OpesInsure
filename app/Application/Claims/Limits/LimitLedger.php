<?php

declare(strict_types=1);

namespace App\Application\Claims\Limits;

use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Models\Claim;
use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Batch 11 C4 — REQ-CLM-004 limit / aggregate exhaustion engine.
 *
 * policy_limits (Batch 7C) holds the terms and two running counters
 * (reserved_minor, consumed_minor); policy_limit_movements is the append-only
 * ledger of every counter change. remaining = amount − consumed − reserved.
 *
 *  - PER_CLAIM limits: headroom is evaluated per claim from that claim's movements.
 *  - AGGREGATE limits: headroom is the row's own counters (DB CHECK forbids < 0).
 *  - A movement against a PER_CLAIM limit cascades to every AGGREGATE limit of the
 *    same policy version that is policy-wide or on the same coverage, atomically.
 *
 * All writes lock the affected policy_limits rows (id order, FOR UPDATE), so two
 * concurrent reservations can never jointly exceed remaining: the second waits for
 * the first and is then refused with LimitExhausted.
 *
 * Public API used by other claims agents (reserves C5, settlement C13):
 * reserve(), release(), consume(), reverse(), remaining(), perClaimLimitId(), claimBalances().
 */
final class LimitLedger
{
    public const RESERVE = 'RESERVE';
    public const RELEASE = 'RELEASE';
    public const CONSUME = 'CONSUME';
    public const REVERSE = 'REVERSE';

    public function __construct(private PolicyChronologyWriter $chronology) {}

    /**
     * Earmark $amountMinor of the limit for the claim.
     *
     * @param  array{reference_type?:string,reference_id?:string,idempotency_key?:string,reason?:string,actor_id?:string}  $options
     * @return array{group_id:string,movements:list<object>}
     */
    public function reserve(Claim $claim, string $limitId, int $amountMinor, array $options = []): array
    {
        $this->positive($amountMinor);

        return $this->write($claim, $limitId, self::RESERVE, $amountMinor, $options, function (object $limit, array $claimBal) use ($amountMinor): array {
            $this->assertHeadroom($limit, $claimBal, $amountMinor);

            return [$amountMinor, 0];
        });
    }

    /** Give back part (or, with null, all) of the claim's outstanding reservation on the limit. */
    public function release(Claim $claim, string $limitId, ?int $amountMinor = null, array $options = []): array
    {
        $base = null;

        return $this->write($claim, $limitId, self::RELEASE, $amountMinor, $options, function (object $limit, array $claimBal) use (&$amountMinor, &$base): array {
            // The first (base) limit fixes the amount; cascaded aggregates follow it.
            if ($base === null) {
                $base = $limit->id;
                $amountMinor ??= $claimBal['reserved'];
                $this->positive($amountMinor);
                if ($amountMinor > $claimBal['reserved']) {
                    throw new LimitExhausted('insufficient_reserve', "Cannot release {$amountMinor}: only {$claimBal['reserved']} reserved for this claim.", $limit->id, $claimBal['reserved']);
                }
            }

            return [-min($amountMinor, $claimBal['reserved']), 0];
        });
    }

    /** Consume on payment: draws down the claim's reservation first, then free headroom. */
    public function consume(Claim $claim, string $limitId, int $amountMinor, array $options = []): array
    {
        $this->positive($amountMinor);

        return $this->write($claim, $limitId, self::CONSUME, $amountMinor, $options, function (object $limit, array $claimBal) use ($amountMinor): array {
            $fromReserve = min($amountMinor, $claimBal['reserved']);
            $this->assertHeadroom($limit, $claimBal, $amountMinor - $fromReserve);

            return [-$fromReserve, $amountMinor];
        });
    }

    /** Reverse a CONSUME group (payment reversal): consumption is returned to the limit, the reservation is not restored. */
    public function reverse(string $consumeGroupId, array $options = []): array
    {
        return DB::transaction(function () use ($consumeGroupId, $options): array {
            $originals = DB::table('policy_limit_movements')->where('group_id', $consumeGroupId)->orderBy('policy_limit_id')->get();
            if ($originals->isEmpty() || $originals->contains(fn ($m) => $m->movement_type !== self::CONSUME)) {
                throw new LimitExhausted('not_reversible', 'Only a CONSUME movement group can be reversed.');
            }
            $limits = DB::table('policy_limits')->whereIn('id', $originals->pluck('policy_limit_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = DB::table('policy_limit_movements')->whereIn('reverses_movement_id', $originals->pluck('id'))->get();
            if ($existing->isNotEmpty()) {
                return ['group_id' => $existing->first()->group_id, 'movements' => $existing->all()];
            }
            $group = (string) Str::uuid();
            $out = [];
            foreach ($originals as $m) {
                $out[] = $this->apply($limits[$m->policy_limit_id], $m->claim_id, $m->tenant_id, $group, self::REVERSE, (int) $m->amount_minor, 0, -(int) $m->consumed_delta_minor, $options + ['reverses_movement_id' => $m->id]);
            }

            return ['group_id' => $group, 'movements' => $out];
        });
    }

    /**
     * Limits of a coverage in the policy version effective at $asOf (loss date), with
     * remaining headroom. With a claim, PER_CLAIM limits also report that claim's
     * remaining; `remaining_minor` is the binding (smallest) headroom.
     */
    public function remaining(Policy|string $policy, string $coverageCode, ?\DateTimeInterface $asOf = null, ?Claim $claim = null): array
    {
        $policyId = $policy instanceof Policy ? $policy->id : $policy;
        $version = $this->chronology->asOf($policyId, $asOf ?? now());
        if ($version === null) {
            return ['policy_version_id' => null, 'coverage_code' => $coverageCode, 'remaining_minor' => null, 'limits' => []];
        }
        $coverageId = DB::table('policy_coverages')->where('policy_version_id', $version->id)->where('coverage_code', $coverageCode)->value('id');
        $limits = DB::table('policy_limits')->where('policy_version_id', $version->id)->where('limit_type', '<>', 'DEDUCTIBLE')
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('policy_coverage_id')->where('limit_type', 'AGGREGATE'))
                ->when($coverageId, fn ($q) => $q->orWhere('policy_coverage_id', $coverageId)))
            ->orderBy('limit_type')->orderBy('id')->get();

        $rows = $limits->map(fn ($l) => $this->present($l, $claim))->values()->all();
        $binding = array_map(fn ($r) => $r['limit_type'] === 'PER_CLAIM' ? ($r['claim_remaining_minor'] ?? $r['amount_minor']) : $r['remaining_minor'], $rows);

        return [
            'policy_version_id' => $version->id,
            'coverage_code' => $coverageCode,
            'remaining_minor' => $binding === [] ? null : min($binding),
            'limits' => $rows,
        ];
    }

    /** The PER_CLAIM limit of a coverage at the claim's loss date (the id to pass to reserve/consume). */
    public function perClaimLimitId(Claim $claim, string $coverageCode): ?string
    {
        $r = $this->remaining($claim->policy_id, $coverageCode, $claim->loss_occurred_at, $claim);

        return collect($r['limits'])->firstWhere('limit_type', 'PER_CLAIM')['limit_id'] ?? null;
    }

    /** Per-limit reserved/consumed totals for one claim, from the ledger. */
    public function claimBalances(Claim $claim): array
    {
        return DB::table('policy_limit_movements as m')->join('policy_limits as l', 'l.id', '=', 'm.policy_limit_id')
            ->where('m.claim_id', $claim->id)
            ->groupBy('m.policy_limit_id', 'l.limit_type', 'l.amount_minor', 'l.currency', 'l.policy_coverage_id')
            ->orderBy('m.policy_limit_id')
            ->get(['m.policy_limit_id as limit_id', 'l.limit_type', 'l.amount_minor', 'l.currency', 'l.policy_coverage_id',
                DB::raw('SUM(m.reserved_delta_minor) as reserved_minor'), DB::raw('SUM(m.consumed_delta_minor) as consumed_minor')])
            ->map(fn ($r) => ['limit_id' => $r->limit_id, 'limit_type' => $r->limit_type, 'policy_coverage_id' => $r->policy_coverage_id,
                'amount_minor' => (int) $r->amount_minor, 'currency' => $r->currency,
                'reserved_minor' => (int) $r->reserved_minor, 'consumed_minor' => (int) $r->consumed_minor])
            ->all();
    }

    public function present(object $l, ?Claim $claim = null): array
    {
        $row = [
            'limit_id' => $l->id, 'limit_type' => $l->limit_type, 'policy_coverage_id' => $l->policy_coverage_id,
            'currency' => $l->currency, 'amount_minor' => (int) $l->amount_minor,
            'consumed_minor' => (int) $l->consumed_minor, 'reserved_minor' => (int) $l->reserved_minor,
            'remaining_minor' => (int) $l->amount_minor - (int) $l->consumed_minor - (int) $l->reserved_minor,
        ];
        if ($claim !== null) {
            $b = $this->claimBalance($l->id, $claim->id);
            $row += ['claim_reserved_minor' => $b['reserved'], 'claim_consumed_minor' => $b['consumed'],
                'claim_remaining_minor' => $l->limit_type === 'PER_CLAIM' ? (int) $l->amount_minor - $b['reserved'] - $b['consumed'] : $row['remaining_minor']];
        }

        return $row;
    }

    /**
     * @param  callable(object $limit, array{reserved:int,consumed:int} $claimBal): array{0:int,1:int}  $deltas
     */
    private function write(Claim $claim, string $limitId, string $type, ?int $amountMinor, array $options, callable $deltas): array
    {
        return DB::transaction(function () use ($claim, $limitId, $type, $amountMinor, $options, $deltas): array {
            $base = DB::table('policy_limits')->where('id', $limitId)->first();
            if ($base === null || $base->policy_id !== $claim->policy_id) {
                throw new LimitExhausted('limit_not_found', 'Limit does not belong to the claim\'s policy.', $limitId);
            }
            if ($base->limit_type === 'DEDUCTIBLE') {
                throw new LimitExhausted('not_a_limit', 'Deductibles are not consumable limits.', $limitId);
            }
            $ids = $this->targetIds($base);
            // Lock in a stable order so concurrent writers serialise without deadlock.
            $locked = DB::table('policy_limits')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if (($key = $options['idempotency_key'] ?? null) !== null) {
                $prior = DB::table('policy_limit_movements')->where('policy_limit_id', $limitId)->where('idempotency_key', $key)->first();
                if ($prior !== null) {
                    if ($prior->movement_type !== $type || $prior->claim_id !== $claim->id) {
                        throw new LimitExhausted('idempotency_conflict', 'Idempotency key reused for a different movement.', $limitId);
                    }

                    return ['group_id' => $prior->group_id, 'movements' => DB::table('policy_limit_movements')->where('group_id', $prior->group_id)->get()->all()];
                }
            }

            // Base limit first (it fixes the amount), then cascaded aggregates.
            $order = array_merge([$limitId], array_values(array_diff($locked->keys()->all(), [$limitId])));
            $plan = [];
            foreach ($order as $id) {
                $limit = $locked[$id];
                [$r, $c] = $deltas($limit, $this->claimBalance($id, $claim->id));
                $plan[] = [$limit, $r, $c];
            }
            $group = (string) Str::uuid();
            $out = [];
            foreach ($plan as [$limit, $r, $c]) {
                $amount = $type === self::CONSUME ? $c : abs($r);
                if ($amount === 0) {
                    continue;
                }
                $out[] = $this->apply($limit, $claim->id, $claim->tenant_id, $group, $type, $amount, $r, $c, $options);
            }

            return ['group_id' => $group, 'movements' => $out];
        });
    }

    private function apply(object $limit, ?string $claimId, string $tenantId, string $group, string $type, int $amount, int $reservedDelta, int $consumedDelta, array $options): object
    {
        $reserved = (int) $limit->reserved_minor + $reservedDelta;
        $consumed = (int) $limit->consumed_minor + $consumedDelta;
        if ($reserved < 0 || $consumed < 0) {
            throw new LimitExhausted('negative_counter', 'Limit counters cannot go negative.', $limit->id);
        }
        DB::table('policy_limits')->where('id', $limit->id)->update(['reserved_minor' => $reserved, 'consumed_minor' => $consumed, 'updated_at' => now()]);
        $limit->reserved_minor = $reserved;
        $limit->consumed_minor = $consumed;

        $row = [
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'policy_id' => $limit->policy_id, 'policy_limit_id' => $limit->id,
            'claim_id' => $claimId, 'group_id' => $group, 'movement_type' => $type, 'amount_minor' => $amount,
            'reserved_delta_minor' => $reservedDelta, 'consumed_delta_minor' => $consumedDelta,
            'reserved_after_minor' => $reserved, 'consumed_after_minor' => $consumed, 'currency' => $limit->currency,
            'reverses_movement_id' => $options['reverses_movement_id'] ?? null,
            'reference_type' => $options['reference_type'] ?? null, 'reference_id' => $options['reference_id'] ?? null,
            // Unique per limit, so the same key may sit on every row of one cascaded group.
            'idempotency_key' => $options['idempotency_key'] ?? null,
            'reason' => $options['reason'] ?? null, 'actor_id' => $options['actor_id'] ?? null,
            'occurred_at' => now(), 'created_at' => now(),
        ];
        DB::table('policy_limit_movements')->insert($row);

        return (object) $row;
    }

    /** @return list<string> the limit plus the AGGREGATE limits it rolls up into. */
    private function targetIds(object $limit): array
    {
        if ($limit->limit_type === 'AGGREGATE') {
            return [$limit->id];
        }
        $aggregates = DB::table('policy_limits')->where('policy_version_id', $limit->policy_version_id)->where('limit_type', 'AGGREGATE')
            ->where(fn ($q) => $q->whereNull('policy_coverage_id')->when($limit->policy_coverage_id, fn ($q) => $q->orWhere('policy_coverage_id', $limit->policy_coverage_id)))
            ->pluck('id')->all();

        return array_values(array_unique([$limit->id, ...$aggregates]));
    }

    /** @return array{reserved:int,consumed:int} */
    private function claimBalance(string $limitId, string $claimId): array
    {
        $r = DB::table('policy_limit_movements')->where('policy_limit_id', $limitId)->where('claim_id', $claimId)
            ->selectRaw('COALESCE(SUM(reserved_delta_minor),0) as r, COALESCE(SUM(consumed_delta_minor),0) as c')->first();

        return ['reserved' => (int) $r->r, 'consumed' => (int) $r->c];
    }

    private function assertHeadroom(object $limit, array $claimBal, int $needed): void
    {
        if ($needed <= 0) {
            return;
        }
        $headroom = $limit->limit_type === 'PER_CLAIM'
            ? (int) $limit->amount_minor - $claimBal['reserved'] - $claimBal['consumed']
            : (int) $limit->amount_minor - (int) $limit->reserved_minor - (int) $limit->consumed_minor;
        if ($needed > $headroom) {
            throw new LimitExhausted('limit_exhausted', "{$limit->limit_type} limit {$limit->id} has {$headroom} remaining; {$needed} requested.", $limit->id, max(0, $headroom));
        }
    }

    private function positive(?int $amount): void
    {
        if ($amount === null || $amount <= 0) {
            throw new LimitExhausted('invalid_amount', 'Amount must be a positive integer (minor units).');
        }
    }
}
