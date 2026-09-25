<?php

declare(strict_types=1);

namespace App\Application\Health\Benefits;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Batch 14 E5 — REQ-HLT-004 benefit schedule per product / plan.
 *
 * A health_benefit_schedules row names a benefit and adds what the Phase 5 product model lacks. Every
 * term left NULL falls back to the product model: coverage_limits (plan row preferred over product row)
 * for the period limit (PER_YEAR / PER_POLICY_PERIOD / FIXED_AMOUNT), the per-event limit (PER_EVENT)
 * and the family pool (AGGREGATE); product_coverages.waiting_period_days for the waiting period.
 */
final class BenefitSchedule
{
    public const PERIODS = ['POLICY_YEAR', 'CALENDAR_YEAR', 'LIFETIME'];

    public const SCOPES = ['INDIVIDUAL', 'FAMILY'];

    public function create(array $d, ?string $actorId = null): object
    {
        $id = (string) Str::uuid();
        DB::table('health_benefit_schedules')->insert([
            'id' => $id, 'tenant_id' => $d['tenant_id'] ?? null, 'insurance_product_id' => $d['insurance_product_id'], 'product_plan_id' => $d['product_plan_id'] ?? null,
            'coverage_definition_id' => $d['coverage_definition_id'] ?? null, 'benefit_code' => $d['benefit_code'], 'name' => json_encode($d['name'] ?? (object) []),
            'parent_schedule_id' => $d['parent_schedule_id'] ?? null, 'period_basis' => $d['period_basis'] ?? 'POLICY_YEAR', 'scope' => $d['scope'] ?? 'INDIVIDUAL',
            'period_limit_minor' => $d['period_limit_minor'] ?? null, 'family_limit_minor' => $d['family_limit_minor'] ?? null,
            'per_event_limit_minor' => $d['per_event_limit_minor'] ?? null, 'per_visit_limit_minor' => $d['per_visit_limit_minor'] ?? null,
            'max_visits_per_period' => $d['max_visits_per_period'] ?? null, 'copay_bp' => $d['copay_bp'] ?? null, 'waiting_period_days' => $d['waiting_period_days'] ?? null,
            'currency' => $d['currency'] ?? 'XAF', 'effective_from' => $d['effective_from'] ?? now()->toDateString(), 'effective_until' => $d['effective_until'] ?? null,
            'status' => 'ACTIVE', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('health_benefit_schedules')->find($id);
    }

    /** The schedule in force: tenant row over product-wide, plan row over product row. */
    public function find(string $tenantId, string $productId, ?string $planId, string $benefitCode, Carbon $at): ?object
    {
        $day = $at->toDateString();

        return DB::table('health_benefit_schedules')->where('insurance_product_id', $productId)->where('benefit_code', $benefitCode)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->where(fn ($q) => $q->whereNull('product_plan_id')->when($planId, fn ($q) => $q->orWhere('product_plan_id', $planId)))
            ->where('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $day))
            ->orderByRaw('product_plan_id IS NULL, tenant_id IS NULL, effective_from DESC')->first();
    }

    /** Effective terms of a schedule after the product-model fallback. */
    public function terms(object $s, ?string $planId): array
    {
        $cl = fn (array $types) => $s->coverage_definition_id === null ? null : DB::table('coverage_limits')
            ->where('insurance_product_id', $s->insurance_product_id)->where('coverage_definition_id', $s->coverage_definition_id)->whereIn('limit_type', $types)
            ->where(fn ($q) => $q->whereNull('product_plan_id')->when($planId, fn ($q) => $q->orWhere('product_plan_id', $planId)))
            ->whereNotNull('amount_minor')->orderByRaw('product_plan_id IS NULL')->value('amount_minor');
        $int = fn ($v) => $v === null ? null : (int) $v;
        $waiting = $s->waiting_period_days ?? $this->ruleWaitingDays($s->benefit_code) ?? ($s->coverage_definition_id === null ? null : DB::table('product_coverages')
            ->where('insurance_product_id', $s->insurance_product_id)->where('coverage_definition_id', $s->coverage_definition_id)->value('waiting_period_days'));

        return [
            'period_limit_minor' => $int($s->period_limit_minor ?? $cl(['PER_YEAR', 'PER_POLICY_PERIOD', 'FIXED_AMOUNT'])),
            'family_limit_minor' => $int($s->family_limit_minor ?? $cl(['AGGREGATE'])),
            'per_event_limit_minor' => $int($s->per_event_limit_minor ?? $cl(['PER_EVENT'])),
            'per_visit_limit_minor' => $int($s->per_visit_limit_minor),
            'max_visits_per_period' => $int($s->max_visits_per_period),
            'copay_bp' => $int($s->copay_bp),
            'waiting_period_days' => $int($waiting),
        ];
    }

    /** E2's health_benefit_rules (service → benefit_code mapping) may carry the waiting period (guarded). */
    private function ruleWaitingDays(string $benefitCode): ?int
    {
        if (! Schema::hasTable('health_benefit_rules') || ! Schema::hasColumns('health_benefit_rules', ['benefit_code', 'waiting_period_days'])) {
            return null;
        }
        $v = DB::table('health_benefit_rules')->where('benefit_code', $benefitCode)->whereNotNull('waiting_period_days')->max('waiting_period_days');

        return $v === null ? null : (int) $v;
    }

    /** Resolve member, product/plan, schedule, terms, waiting period for a benefit at a date. */
    public function context(string $tenantId, array|string $member, string $benefitCode, ?\DateTimeInterface $at = null): array
    {
        $m = is_string($member) ? ['member_ref' => $member] : $member;
        if (empty($m['member_ref'])) {
            throw new BenefitRefused('member_required', 'A member reference is required.');
        }
        $at = Carbon::instance($at ?? now());
        $m = $this->withMember($tenantId, $m);

        $policy = isset($m['policy_id']) ? DB::table('policies')->where('id', $m['policy_id'])->where('tenant_id', $tenantId)->first() : null;
        $snapshot = $policy ? (json_decode((string) $policy->terms_snapshot, true) ?: []) : [];
        $productId = $m['insurance_product_id'] ?? $snapshot['insurance_product_id'] ?? ($policy?->proposal_id ? DB::table('proposals as p')
            ->join('quote_offers as o', 'o.id', '=', 'p.quote_offer_id')->where('p.id', $policy->proposal_id)->value('o.product_id') : null);
        $planId = $m['product_plan_id'] ?? $snapshot['product_plan_id'] ?? null;

        $schedule = $productId ? $this->find($tenantId, $productId, $planId, $benefitCode, $at) : null;
        if ($schedule === null) {
            throw new BenefitRefused('schedule_not_found', "No benefit schedule for {$benefitCode} on this product/plan at {$at->toDateString()}.");
        }
        $terms = $this->terms($schedule, $planId);
        $start = $m['coverage_start'] ?? $policy?->coverage_starts_at;
        $start = $start ? Carbon::parse($start) : null;
        $waitingUntil = ($start && $terms['waiting_period_days']) ? $start->copy()->addDays($terms['waiting_period_days']) : null;

        return [
            'tenant_id' => $tenantId, 'member_ref' => (string) $m['member_ref'], 'family_ref' => isset($m['family_ref']) ? (string) $m['family_ref'] : null,
            'policy_id' => $policy?->id, 'insurance_product_id' => $productId, 'product_plan_id' => $planId, 'at' => $at,
            'period_anchor' => isset($m['period_anchor']) ? Carbon::parse($m['period_anchor']) : ($policy?->coverage_starts_at ? Carbon::parse($policy->coverage_starts_at) : $start),
            'schedule' => $schedule, 'terms' => $terms, 'plan_id' => $planId,
            'waiting_until' => $waitingUntil, 'in_waiting_period' => $waitingUntil !== null && $at->lt($waitingUntil),
        ];
    }

    /**
     * Accumulator targets, base first: the benefit's own subject row, its family pool, then the same for
     * each parent benefit (sub-limit chain).
     *
     * @return list<array{schedule:object,subject_type:string,subject_ref:string,limit_minor:?int,period:array{key:string,start:?string,end:?string}}>
     */
    public function targets(array $ctx): array
    {
        $out = [];
        $s = $ctx['schedule'];
        $seen = [];
        while ($s !== null && ! isset($seen[$s->id])) {
            $seen[$s->id] = true;
            $terms = $s->id === $ctx['schedule']->id ? $ctx['terms'] : $this->terms($s, $ctx['plan_id']);
            $period = $this->period($s->period_basis, $ctx['period_anchor'], $ctx['at']);
            $family = $ctx['family_ref'];
            if ($s->scope === 'FAMILY' && $family !== null) {
                $out[] = ['schedule' => $s, 'subject_type' => 'FAMILY', 'subject_ref' => $family, 'limit_minor' => $terms['family_limit_minor'] ?? $terms['period_limit_minor'], 'period' => $period];
            } else {
                $out[] = ['schedule' => $s, 'subject_type' => 'INDIVIDUAL', 'subject_ref' => $ctx['member_ref'], 'limit_minor' => $terms['period_limit_minor'], 'period' => $period];
                if ($family !== null && $terms['family_limit_minor'] !== null) {
                    $out[] = ['schedule' => $s, 'subject_type' => 'FAMILY', 'subject_ref' => $family, 'limit_minor' => $terms['family_limit_minor'], 'period' => $period];
                }
            }
            $s = $s->parent_schedule_id ? DB::table('health_benefit_schedules')->find($s->parent_schedule_id) : null;
        }

        return $out;
    }

    /** @return array{key:string,start:?string,end:?string} */
    public function period(string $basis, ?Carbon $anchor, Carbon $at): array
    {
        if ($basis === 'LIFETIME') {
            return ['key' => 'LIFETIME', 'start' => null, 'end' => null];
        }
        if ($basis === 'CALENDAR_YEAR' || $anchor === null) {
            return ['key' => 'CY'.$at->year, 'start' => $at->year.'-01-01', 'end' => $at->year.'-12-31'];
        }
        $start = $anchor->copy()->startOfDay()->setYear($at->year);
        if ($start->gt($at)) {
            $start->subYear();
        }

        return ['key' => 'PY'.$start->toDateString(), 'start' => $start->toDateString(), 'end' => $start->copy()->addYear()->subDay()->toDateString()];
    }

    /**
     * Fill policy / product / plan / family / coverage start from E2's health_members row when the member
     * ref is a health_members.id (guarded: the table and each column may not exist). Deliberately does not
     * call EligibilityService, which itself calls remaining().
     */
    private function withMember(string $tenantId, array $m): array
    {
        if (! Schema::hasTable('health_members') || ! Str::isUuid((string) $m['member_ref'])) {
            return $m;
        }
        $row = DB::table('health_members')->where('id', $m['member_ref'])->first();
        if ($row === null || (property_exists($row, 'tenant_id') && $row->tenant_id !== $tenantId)) {
            return $m;
        }
        $map = ['policy_id' => ['policy_id'], 'insurance_product_id' => ['insurance_product_id'], 'product_plan_id' => ['product_plan_id'],
            'family_ref' => ['family_ref', 'family_id', 'principal_member_id'], 'coverage_start' => ['coverage_start', 'coverage_starts_at', 'enrolled_at', 'effective_from']];
        foreach ($map as $key => $cols) {
            foreach ($cols as $col) {
                if (! isset($m[$key]) && isset($row->{$col})) {
                    $m[$key] = $row->{$col};
                }
            }
        }
        // A principal is the head of their own family pool.
        if (! isset($m['family_ref']) && property_exists($row, 'principal_member_id')) {
            $m['family_ref'] = $row->id;
        }

        return $m;
    }
}
