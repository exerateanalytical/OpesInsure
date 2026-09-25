<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

use App\Models\Catalogue\CoverageDeductible;
use App\Models\Catalogue\CoverageLimit;
use App\Models\InsuranceProduct;
use Illuminate\Support\Collection;

/**
 * REQ-PRD-005 — computed indemnifiable amount for one coverage of a version:
 *   deductible = Σ(FIXED, PERCENTAGE, COMBINED clamped to its min/max), then
 *                MINIMUM / MAXIMUM floors and caps, never above the loss;
 *   indemnity  = min(loss − deductible, every applicable limit).
 * Plan-specific rows replace version-level rows of the same type.
 * Pure configuration arithmetic (integer minor units, half-up rounding); no side effects.
 */
final class IndemnityCalculator
{
    /** @param array{loss_minor:int,sum_insured_minor?:?int,consumed_minor?:int,plan_id?:?string} $input */
    public function compute(InsuranceProduct $v, string $coverageId, array $input): array
    {
        $loss = max(0, (int) $input['loss_minor']);
        $si = isset($input['sum_insured_minor']) ? (int) $input['sum_insured_minor'] : null;
        $plan = $input['plan_id'] ?? null;
        $trace = [];

        $deductibles = $this->effective(CoverageDeductible::where(['insurance_product_id' => $v->id, 'coverage_definition_id' => $coverageId])->get(), $plan, 'deductible_type');
        $deductible = 0;
        $waitingDays = null;
        foreach ($deductibles->whereIn('deductible_type', ['FIXED', 'PERCENTAGE', 'COMBINED']) as $d) {
            $base = $d->percentage_basis === 'SUM_INSURED' ? ($si ?? 0) : $loss;
            $amount = match ($d->deductible_type) {
                'FIXED' => (int) $d->amount_minor,
                default => self::pct($base, (int) $d->percentage_bp),
            };
            if ($d->deductible_type === 'COMBINED') {
                $amount = max($amount, (int) ($d->minimum_minor ?? 0));
                $amount = $d->maximum_minor !== null ? min($amount, (int) $d->maximum_minor) : $amount;
            }
            $trace[] = ['step' => 'deductible.'.$d->deductible_type, 'amount_minor' => $amount];
            $deductible += $amount;
        }
        foreach ($deductibles->where('deductible_type', 'MINIMUM') as $d) {
            $deductible = max($deductible, (int) $d->minimum_minor);
            $trace[] = ['step' => 'deductible.MINIMUM', 'amount_minor' => $deductible];
        }
        foreach ($deductibles->where('deductible_type', 'MAXIMUM') as $d) {
            $deductible = min($deductible, (int) $d->maximum_minor);
            $trace[] = ['step' => 'deductible.MAXIMUM', 'amount_minor' => $deductible];
        }
        foreach ($deductibles->where('deductible_type', 'DAYS') as $d) {
            $waitingDays = (int) $d->days;
            $trace[] = ['step' => 'deductible.DAYS', 'days' => $waitingDays];
        }
        $deductible = min($deductible, $loss);
        $indemnity = $loss - $deductible;

        $limits = $this->effective(CoverageLimit::where(['insurance_product_id' => $v->id, 'coverage_definition_id' => $coverageId])->get(), $plan, 'limit_type');
        $cap = null;
        foreach ($limits as $l) {
            $value = match ($l->limit_type) {
                'UNLIMITED' => null,
                'PERCENT_OF_SUM_INSURED' => $si === null ? null : self::pct($si, (int) $l->percentage_bp),
                'PERCENT_OF_LOSS' => self::pct($loss, (int) $l->percentage_bp),
                'AGGREGATE', 'PER_YEAR', 'PER_POLICY_PERIOD' => max(0, (int) $l->amount_minor - (int) ($input['consumed_minor'] ?? 0)),
                default => (int) $l->amount_minor,
            };
            $trace[] = ['step' => 'limit.'.$l->limit_type, 'cap_minor' => $value];
            if ($value !== null) {
                $cap = $cap === null ? $value : min($cap, $value);
            }
        }
        if ($cap !== null) {
            $indemnity = min($indemnity, $cap);
        }

        return ['loss_minor' => $loss, 'deductible_minor' => $deductible, 'waiting_days' => $waitingDays, 'limit_cap_minor' => $cap,
            'indemnifiable_minor' => $indemnity, 'currency' => $v->carrierProduct?->currency ?? 'XAF', 'trace' => $trace];
    }

    private static function pct(int $base, int $bp): int
    {
        return intdiv($base * $bp + 5000, 10000);
    }

    /** Plan rows override version-level rows of the same type. */
    private function effective(Collection $rows, ?string $plan, string $typeColumn): Collection
    {
        $version = $rows->whereNull('product_plan_id')->keyBy($typeColumn);
        if ($plan) {
            foreach ($rows->where('product_plan_id', $plan) as $row) {
                $version[$row->{$typeColumn}] = $row;
            }
        }

        return $version->values();
    }
}
