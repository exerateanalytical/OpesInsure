<?php

declare(strict_types=1);

namespace App\Application\Health\Benefits;

use App\Application\Events\OutboxWriter;
use App\Models\Claim;
use App\Models\ClaimPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Batch 14 E5 — REQ-HLT-004 benefit accumulator, shared by cashless (provider claims / preauth, E3/E4)
 * and reimbursement (member claims on the claims engine).
 *
 * Terms come from health_benefit_schedules with the Phase 5 product model as fallback (BenefitSchedule).
 * Counters live on health_benefit_accumulators (one row per subject + schedule + period);
 * health_benefit_movements is the append-only ledger. A movement on a benefit cascades atomically to
 * the family pool (when one applies) and to every parent benefit it is a sub-limit of.
 *
 * Concurrency (same pattern as Batch 11 C4 LimitLedger): every write locks all affected accumulator rows
 * in id order FOR UPDATE, so two concurrent reservations can never jointly exceed the remaining
 * headroom — the second waits and is then refused with BenefitRefused(benefit_exhausted).
 *
 * `$member` is a member ref string, or an array: member_ref (required), family_ref, policy_id,
 * insurance_product_id, product_plan_id, coverage_start, period_anchor. Missing product/plan/coverage
 * start are looked up through E2's EligibilityService when it exists, else from the policy.
 *
 * Options on reserve/release/consume: holder (defaults to claim:<claim_id> or <reference_type>:<reference_id>),
 * claim_id, event_ref, visit_ref, reference_type, reference_id, idempotency_key, reason, actor_id,
 * allow_overrun (record the movement even past a limit — used for money already paid).
 */
final class BenefitAccumulator
{
    public const RESERVE = 'RESERVE';
    public const RELEASE = 'RELEASE';
    public const CONSUME = 'CONSUME';
    public const REVERSE = 'REVERSE';

    public function __construct(private BenefitSchedule $schedules, private OutboxWriter $outbox) {}

    public function reserve(string $tenantId, array|string $member, string $benefitCode, int $amountMinor, ?\DateTimeInterface $at = null, array $options = []): array
    {
        $this->positive($amountMinor);

        return $this->write($tenantId, $member, $benefitCode, $at, self::RESERVE, $options, function (array $target, array $held, callable $check) use ($amountMinor): array {
            $check($amountMinor);

            return [$amountMinor, 0, $amountMinor];
        });
    }

    /** Give back part (or, with null, all) of the holder's outstanding reservation. */
    public function release(string $tenantId, array|string $member, string $benefitCode, ?int $amountMinor = null, ?\DateTimeInterface $at = null, array $options = []): array
    {
        $base = true;

        return $this->write($tenantId, $member, $benefitCode, $at, self::RELEASE, $options, function (array $target, array $held) use (&$amountMinor, &$base): array {
            if ($base) {
                $base = false;
                $amountMinor ??= $held['reserved'];
                $this->positive($amountMinor);
                if ($amountMinor > $held['reserved']) {
                    throw new BenefitRefused('insufficient_reserve', "Cannot release {$amountMinor}: only {$held['reserved']} reserved by this holder.", $held['reserved']);
                }
            }
            $r = min($amountMinor, $held['reserved']);

            return [-$r, 0, $r];
        });
    }

    /** Consume (claim paid / service delivered): draws the holder's reservation first, then free headroom. */
    public function consume(string $tenantId, array|string $member, string $benefitCode, int $amountMinor, ?\DateTimeInterface $at = null, array $options = []): array
    {
        $this->positive($amountMinor);

        return $this->write($tenantId, $member, $benefitCode, $at, self::CONSUME, $options, function (array $target, array $held, callable $check) use ($amountMinor): array {
            $fromReserve = min($amountMinor, $held['reserved']);
            $check($amountMinor - $fromReserve);

            return [-$fromReserve, $amountMinor, $amountMinor];
        });
    }

    /** Reverse a CONSUME group (payment reversal): consumption returns to the pool, the reservation is not restored. */
    public function reverse(string $consumeGroupId, array $options = []): array
    {
        return DB::transaction(function () use ($consumeGroupId, $options): array {
            $originals = DB::table('health_benefit_movements')->where('group_id', $consumeGroupId)->orderBy('health_benefit_accumulator_id')->get();
            if ($originals->isEmpty() || $originals->contains(fn ($m) => $m->movement_type !== self::CONSUME)) {
                throw new BenefitRefused('not_reversible', 'Only a CONSUME movement group can be reversed.');
            }
            $rows = DB::table('health_benefit_accumulators')->whereIn('id', $originals->pluck('health_benefit_accumulator_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = DB::table('health_benefit_movements')->whereIn('reverses_movement_id', $originals->pluck('id'))->get();
            if ($existing->isNotEmpty()) {
                return ['group_id' => $existing->first()->group_id, 'movements' => $existing->all()];
            }
            $group = (string) Str::uuid();
            $out = [];
            foreach ($originals as $m) {
                $out[] = $this->apply($rows[$m->health_benefit_accumulator_id], $group, self::REVERSE, (int) $m->amount_minor, 0, -(int) $m->consumed_delta_minor, false,
                    ['member_ref' => $m->member_ref, 'holder' => $m->holder_ref, 'claim_id' => $m->claim_id, 'event_ref' => $m->event_ref, 'visit_ref' => $m->visit_ref, 'reverses_movement_id' => $m->id] + $options);
            }

            return ['group_id' => $group, 'movements' => $out];
        });
    }

    /**
     * Remaining benefit for a member at a date (read-only; nothing is created).
     * `remaining_minor` is the binding (smallest) headroom across the benefit, its family pool and its
     * parent benefits; null = unlimited.
     */
    public function remaining(string $tenantId, array|string $member, string $benefitCode, ?\DateTimeInterface $at = null, bool $strict = false): array
    {
        try {
            $ctx = $this->schedules->context($tenantId, $member, $benefitCode, $at);
        } catch (BenefitRefused $e) {
            // Non-strict (EligibilityService): no schedule = no benefit-level cap; policy limits still apply.
            if ($strict || $e->reasonCode !== 'schedule_not_found') {
                throw $e;
            }

            return ['benefit_code' => $benefitCode, 'schedule_id' => null, 'remaining_minor' => null, 'reason_code' => 'schedule_not_found'];
        }
        $targets = $this->schedules->targets($ctx);
        $rows = [];
        foreach ($targets as $t) {
            $row = DB::table('health_benefit_accumulators')->where($this->key($tenantId, $t))->first();
            $reserved = (int) ($row->reserved_minor ?? 0);
            $consumed = (int) ($row->consumed_minor ?? 0);
            $limit = $row !== null ? ($row->limit_minor === null ? null : (int) $row->limit_minor) : $t['limit_minor'];
            $rows[] = ['accumulator_id' => $row->id ?? null, 'benefit_code' => $t['schedule']->benefit_code, 'subject_type' => $t['subject_type'], 'subject_ref' => $t['subject_ref'],
                'period_key' => $t['period']['key'], 'limit_minor' => $limit, 'reserved_minor' => $reserved, 'consumed_minor' => $consumed,
                'remaining_minor' => $limit === null ? null : $limit - $reserved - $consumed];
        }
        $bounded = array_filter(array_column($rows, 'remaining_minor'), fn ($v) => $v !== null);
        $terms = $ctx['terms'];
        $base = $rows[0]['accumulator_id'] ?? null;

        return [
            'member_ref' => $ctx['member_ref'], 'family_ref' => $ctx['family_ref'], 'benefit_code' => $benefitCode, 'schedule_id' => $ctx['schedule']->id,
            'currency' => $ctx['schedule']->currency, 'at' => $ctx['at']->toIso8601String(),
            'period_key' => $targets[0]['period']['key'], 'period_start' => $targets[0]['period']['start'], 'period_end' => $targets[0]['period']['end'],
            'remaining_minor' => $bounded === [] ? null : max(0, min($bounded)),
            'copay_bp' => $terms['copay_bp'], 'per_event_limit_minor' => $terms['per_event_limit_minor'], 'per_visit_limit_minor' => $terms['per_visit_limit_minor'],
            'max_visits_per_period' => $terms['max_visits_per_period'], 'visits_used' => $base ? $this->visitsUsed($base) : 0,
            'waiting_period_days' => $terms['waiting_period_days'], 'waiting_until' => $ctx['waiting_until']?->toIso8601String(), 'in_waiting_period' => $ctx['in_waiting_period'],
            'accumulators' => $rows,
        ];
    }

    /** Split a gross amount into member copay and insurer share, capped by the remaining benefit. */
    public function adjudicate(string $tenantId, array|string $member, string $benefitCode, int $grossMinor, ?\DateTimeInterface $at = null): array
    {
        $r = $this->remaining($tenantId, $member, $benefitCode, $at, true);
        $copay = intdiv($grossMinor * (int) ($r['copay_bp'] ?? 0) + 5000, 10000);
        $insurer = $grossMinor - $copay;
        foreach (['per_visit_limit_minor', 'per_event_limit_minor'] as $cap) {
            if ($r[$cap] !== null) {
                $insurer = min($insurer, $r[$cap]);
            }
        }
        $payable = $r['in_waiting_period'] ? 0 : ($r['remaining_minor'] === null ? $insurer : min($insurer, $r['remaining_minor']));

        return ['gross_minor' => $grossMinor, 'copay_minor' => $copay, 'payable_minor' => max(0, $payable), 'member_share_minor' => $grossMinor - max(0, $payable),
            'remaining_minor' => $r['remaining_minor'], 'in_waiting_period' => $r['in_waiting_period'], 'currency' => $r['currency']];
    }

    /**
     * Reimbursement hook (ClaimPaymentService::paid): a paid health claim consumes its benefits.
     * Health claims carry loss_details.health = {member_ref, family_ref?, channel?, event_ref?,
     * benefits: [{benefit_code, amount_minor}]} (or a single benefit_code). Cashless claims
     * (channel CASHLESS) are consumed by their own flow and skipped here. Money already paid is
     * always recorded (allow_overrun) and an overrun raises health.benefit.overrun.
     */
    public function consumeForClaimPayment(ClaimPayment $p): void
    {
        $claim = Claim::find($p->claim_id);
        $h = $claim?->loss_details['health'] ?? null;
        if (! is_array($h) || empty($h['member_ref']) || strtoupper((string) ($h['channel'] ?? 'REIMBURSEMENT')) !== 'REIMBURSEMENT') {
            return;
        }
        $lines = $h['benefits'] ?? (isset($h['benefit_code']) ? [['benefit_code' => $h['benefit_code'], 'amount_minor' => (int) $p->amount_minor]] : []);
        $left = (int) $p->amount_minor;
        $member = ['policy_id' => $claim->policy_id] + array_intersect_key($h, array_flip(['member_ref', 'family_ref', 'insurance_product_id', 'product_plan_id', 'coverage_start', 'period_anchor']));
        foreach ($lines as $line) {
            $amount = min($left, (int) ($line['amount_minor'] ?? 0));
            if ($amount <= 0 || empty($line['benefit_code'])) {
                continue;
            }
            try {
                $res = $this->consume($claim->tenant_id, $member, (string) $line['benefit_code'], $amount, $claim->loss_occurred_at, [
                    'claim_id' => $claim->id, 'event_ref' => $h['event_ref'] ?? 'claim:'.$claim->id, 'visit_ref' => $line['visit_ref'] ?? null,
                    'reference_type' => 'claim_payment', 'reference_id' => $p->id, 'idempotency_key' => 'claim-payment:'.$p->id.':'.$line['benefit_code'],
                    'reason' => 'Reimbursement claim payment', 'allow_overrun' => true,
                ]);
            } catch (BenefitRefused $e) {
                if ($e->reasonCode === 'schedule_not_found') {
                    continue; // product has no benefit schedule: nothing to accumulate
                }
                throw $e;
            }
            $left -= $amount;
            if (collect($res['movements'])->contains(fn ($m) => (bool) $m->overrun)) {
                $this->outbox->record('health.benefit.overrun', 'claim', $claim->id, ['claim_payment_id' => $p->id, 'benefit_code' => $line['benefit_code'], 'member_ref' => $h['member_ref'], 'amount_minor' => $amount, 'group_id' => $res['group_id']]);
            }
        }
    }

    /** Reimbursement hook (ClaimPaymentService::reverse): reverse the payment's consumption groups. */
    public function reverseForClaimPayment(ClaimPayment $p, string $reason): void
    {
        $groups = DB::table('health_benefit_movements')->where('reference_type', 'claim_payment')->where('reference_id', $p->id)
            ->where('movement_type', self::CONSUME)->distinct()->pluck('group_id');
        foreach ($groups as $g) {
            $this->reverse($g, ['reason' => $reason, 'reference_type' => 'claim_payment_reversal', 'reference_id' => $p->id]);
        }
    }

    /**
     * @param  callable(array $target, array{reserved:int,consumed:int} $held, callable(int):void $check): array{0:int,1:int,2:int}  $deltas
     *                                                                                                                              returns [reserved delta, consumed delta, movement amount]
     */
    private function write(string $tenantId, array|string $member, string $benefitCode, ?\DateTimeInterface $at, string $type, array $options, callable $deltas): array
    {
        $ctx = $this->schedules->context($tenantId, $member, $benefitCode, $at);
        $targets = $this->schedules->targets($ctx);
        $holder = $options['holder'] ?? (isset($options['claim_id']) ? 'claim:'.$options['claim_id']
            : (isset($options['reference_type'], $options['reference_id']) ? $options['reference_type'].':'.$options['reference_id'] : 'member:'.$ctx['member_ref']));
        $options += ['member_ref' => $ctx['member_ref'], 'holder' => $holder];
        $overrunAllowed = (bool) ($options['allow_overrun'] ?? false);

        return DB::transaction(function () use ($tenantId, $ctx, $targets, $type, $options, $deltas, $holder, $overrunAllowed): array {
            foreach ($targets as $t) {
                DB::table('health_benefit_accumulators')->insertOrIgnore($this->key($tenantId, $t) + [
                    'id' => (string) Str::uuid(), 'policy_id' => $ctx['policy_id'], 'period_start' => $t['period']['start'], 'period_end' => $t['period']['end'],
                    'limit_minor' => $t['limit_minor'], 'currency' => $t['schedule']->currency, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $ids = array_map(fn ($t) => DB::table('health_benefit_accumulators')->where($this->key($tenantId, $t))->value('id'), $targets);
            // Lock in a stable order so concurrent writers serialise without deadlock.
            $locked = DB::table('health_benefit_accumulators')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $baseId = $ids[0];

            if (($key = $options['idempotency_key'] ?? null) !== null) {
                $prior = DB::table('health_benefit_movements')->where('health_benefit_accumulator_id', $baseId)->where('idempotency_key', $key)->first();
                if ($prior !== null) {
                    if ($prior->movement_type !== $type || $prior->holder_ref !== $holder) {
                        throw new BenefitRefused('idempotency_conflict', 'Idempotency key reused for a different movement.');
                    }

                    return ['group_id' => $prior->group_id, 'overrun' => (bool) $prior->overrun, 'movements' => DB::table('health_benefit_movements')->where('group_id', $prior->group_id)->get()->all()];
                }
            }

            $overrun = false;
            $refuse = function (string $code, string $msg, ?int $remaining = null) use ($overrunAllowed, &$overrun): void {
                if (! $overrunAllowed) {
                    throw new BenefitRefused($code, $msg, $remaining);
                }
                $overrun = true;
            };
            if (in_array($type, [self::RESERVE, self::CONSUME], true)) {
                if ($ctx['in_waiting_period']) {
                    $refuse('waiting_period', "Benefit {$ctx['schedule']->benefit_code} is in its waiting period until {$ctx['waiting_until']->toDateString()}.", 0);
                }
            }

            $plan = [];
            foreach ($targets as $i => $t) {
                $row = $locked[$ids[$i]];
                $held = $this->held($row->id, $holder);
                $check = function (int $needed) use ($row, $t, $i, $options, $holder, $ctx, $refuse): void {
                    if ($needed <= 0) {
                        return;
                    }
                    if ($row->limit_minor !== null) {
                        $headroom = (int) $row->limit_minor - (int) $row->reserved_minor - (int) $row->consumed_minor;
                        if ($needed > $headroom) {
                            $code = $t['subject_type'] === 'FAMILY' ? 'family_exhausted' : 'benefit_exhausted';
                            $refuse($code, "{$t['schedule']->benefit_code} ({$t['subject_type']}) has {$headroom} remaining; {$needed} requested.", max(0, $headroom));
                        }
                    }
                    if ($i === 0) {
                        $this->assertEventAndVisit($row, $ctx['terms'], $options, $holder, $needed, $refuse);
                    }
                };
                [$r, $c, $amount] = $deltas($t, $held, $check);
                $plan[] = [$row, $r, $c, $amount];
            }
            $group = (string) Str::uuid();
            $out = [];
            foreach ($plan as [$row, $r, $c, $amount]) {
                if ($amount === 0 || ($r === 0 && $c === 0)) {
                    continue;
                }
                $out[] = $this->apply($row, $group, $type, $amount, $r, $c, $overrun, $options);
            }

            return ['group_id' => $group, 'overrun' => $overrun, 'movements' => $out];
        });
    }

    private function assertEventAndVisit(object $row, array $terms, array $options, string $holder, int $needed, callable $refuse): void
    {
        if (($event = $options['event_ref'] ?? null) !== null && $terms['per_event_limit_minor'] !== null) {
            // Per-event cap is across periods for the member's benefit (an event may straddle a renewal).
            $used = (int) DB::table('health_benefit_movements as m')->join('health_benefit_accumulators as a', 'a.id', '=', 'm.health_benefit_accumulator_id')
                ->where('a.health_benefit_schedule_id', $row->health_benefit_schedule_id)->where('a.subject_type', $row->subject_type)->where('a.subject_ref', $row->subject_ref)
                ->where('m.event_ref', $event)->sum(DB::raw('m.reserved_delta_minor + m.consumed_delta_minor'));
            if ($used + $needed > $terms['per_event_limit_minor']) {
                $refuse('per_event_exceeded', "Per-event limit {$terms['per_event_limit_minor']} exceeded for event {$event}.", max(0, $terms['per_event_limit_minor'] - $used));
            }
        }
        if (($visit = $options['visit_ref'] ?? null) !== null) {
            if ($terms['per_visit_limit_minor'] !== null) {
                $used = (int) DB::table('health_benefit_movements')->where('health_benefit_accumulator_id', $row->id)->where('visit_ref', $visit)
                    ->sum(DB::raw('reserved_delta_minor + consumed_delta_minor'));
                if ($used + $needed > $terms['per_visit_limit_minor']) {
                    $refuse('per_visit_exceeded', "Per-visit limit {$terms['per_visit_limit_minor']} exceeded for visit {$visit}.", max(0, $terms['per_visit_limit_minor'] - $used));
                }
            }
            if ($terms['max_visits_per_period'] !== null) {
                $open = DB::table('health_benefit_movements')->where('health_benefit_accumulator_id', $row->id)->whereNotNull('visit_ref')
                    ->groupBy('visit_ref')->havingRaw('SUM(reserved_delta_minor + consumed_delta_minor) > 0')->pluck('visit_ref')->all();
                if (! in_array($visit, $open, true) && count($open) >= $terms['max_visits_per_period']) {
                    $refuse('visits_exhausted', "All {$terms['max_visits_per_period']} visits of the period are used.", 0);
                }
            }
        }
    }

    private function apply(object $row, string $group, string $type, int $amount, int $reservedDelta, int $consumedDelta, bool $overrun, array $options): object
    {
        $reserved = (int) $row->reserved_minor + $reservedDelta;
        $consumed = (int) $row->consumed_minor + $consumedDelta;
        if ($reserved < 0 || $consumed < 0) {
            throw new BenefitRefused('negative_counter', 'Accumulator counters cannot go negative.');
        }
        DB::table('health_benefit_accumulators')->where('id', $row->id)->update(['reserved_minor' => $reserved, 'consumed_minor' => $consumed, 'updated_at' => now()]);
        $row->reserved_minor = $reserved;
        $row->consumed_minor = $consumed;

        $m = [
            'id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'health_benefit_accumulator_id' => $row->id, 'group_id' => $group, 'movement_type' => $type,
            'member_ref' => $options['member_ref'], 'holder_ref' => $options['holder'], 'claim_id' => $options['claim_id'] ?? null,
            'event_ref' => $options['event_ref'] ?? null, 'visit_ref' => $options['visit_ref'] ?? null, 'amount_minor' => $amount,
            'reserved_delta_minor' => $reservedDelta, 'consumed_delta_minor' => $consumedDelta, 'reserved_after_minor' => $reserved, 'consumed_after_minor' => $consumed,
            'overrun' => $overrun, 'currency' => $row->currency, 'reverses_movement_id' => $options['reverses_movement_id'] ?? null,
            'reference_type' => $options['reference_type'] ?? null, 'reference_id' => $options['reference_id'] ?? null,
            // Unique per accumulator, so the same key may sit on every row of one cascaded group.
            'idempotency_key' => $options['idempotency_key'] ?? null, 'reason' => $options['reason'] ?? null, 'actor_id' => $options['actor_id'] ?? null,
            'occurred_at' => now(), 'created_at' => now(),
        ];
        DB::table('health_benefit_movements')->insert($m);

        return (object) $m;
    }

    /** @return array{reserved:int,consumed:int} */
    private function held(string $accumulatorId, string $holder): array
    {
        $r = DB::table('health_benefit_movements')->where('health_benefit_accumulator_id', $accumulatorId)->where('holder_ref', $holder)
            ->selectRaw('COALESCE(SUM(reserved_delta_minor),0) as r, COALESCE(SUM(consumed_delta_minor),0) as c')->first();

        return ['reserved' => (int) $r->r, 'consumed' => (int) $r->c];
    }

    private function visitsUsed(string $accumulatorId): int
    {
        return DB::table('health_benefit_movements')->where('health_benefit_accumulator_id', $accumulatorId)->whereNotNull('visit_ref')
            ->groupBy('visit_ref')->havingRaw('SUM(reserved_delta_minor + consumed_delta_minor) > 0')->pluck('visit_ref')->count();
    }

    /** @return array<string,string> */
    private function key(string $tenantId, array $t): array
    {
        return ['tenant_id' => $tenantId, 'health_benefit_schedule_id' => $t['schedule']->id, 'subject_type' => $t['subject_type'], 'subject_ref' => $t['subject_ref'], 'period_key' => $t['period']['key']];
    }

    private function positive(?int $amount): void
    {
        if ($amount === null || $amount <= 0) {
            throw new BenefitRefused('invalid_amount', 'Amount must be a positive integer (minor units).');
        }
    }
}
