<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Models\CoverTermRule;
use App\Models\InsuranceProduct;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRP-005 (PRE §38–42) — effective-date rules, durations and instalment plans chosen on a proposal.
 *
 * Rules resolve product version → line → config('proposals.cover_terms') defaults (start now, 12 months, SINGLE:
 * today's behaviour). The selection is validated against the rule and stored on proposals.cover_terms with the
 * instalment schedule (first payment at bind; equal instalments, rounding remainder on the first; per-instalment fee).
 * Dates that depend on a later event (IMMEDIATE / PAYMENT_DATE / APPROVAL_DATE / MIDNIGHT_RULE) are resolved at
 * issuance with resolveStart(). Non-payment consequence comes from the rule; NULL is reported as UNVERIFIED.
 */
final class CoverTermsService
{
    public const EFFECTIVE_RULES = ['IMMEDIATE', 'SPECIFIED_DATE', 'PAYMENT_DATE', 'APPROVAL_DATE', 'MIDNIGHT_RULE', 'CUSTOM'];

    public const PLANS = ['SINGLE' => null, 'MONTHLY' => 1, 'QUARTERLY' => 3, 'SEMI_ANNUAL' => 6, 'ANNUAL' => 12, 'CUSTOM' => null];

    /** @return array<string,mixed> the rule that applies (row or defaults) */
    public function ruleFor(?InsuranceProduct $product, ?string $lineCode = null): array
    {
        $day = now()->toDateString();
        $scope = fn ($q) => $q->where('status', 'ACTIVE')->whereDate('effective_from', '<=', $day)
            ->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day));
        $row = ($product ? CoverTermRule::where('insurance_product_id', $product->id)->tap($scope)->latest('effective_from')->first() : null)
            ?? (($line = strtoupper((string) ($lineCode ?? $product?->line_code))) !== '' ? CoverTermRule::whereNull('insurance_product_id')->where('line_code', $line)->tap($scope)->latest('effective_from')->first() : null);
        $d = config('proposals.cover_terms');

        return $row ? [
            'rule_id' => $row->id, 'effective_date_rules' => $row->effective_date_rules, 'default_effective_rule' => $row->default_effective_rule,
            'durations' => $row->durations, 'instalment_plans' => $row->instalment_plans, 'max_advance_days' => $row->max_advance_days,
            'instalment_fee_minor' => $row->instalment_fee_minor, 'non_payment_consequence' => $row->non_payment_consequence ?? 'UNVERIFIED',
        ] : [
            'rule_id' => null, 'effective_date_rules' => $d['effective_date_rules'], 'default_effective_rule' => $d['default_effective_rule'],
            'durations' => $d['durations'], 'instalment_plans' => $d['instalment_plans'], 'max_advance_days' => $d['max_advance_days'],
            'instalment_fee_minor' => 0, 'non_payment_consequence' => 'UNVERIFIED',
        ];
    }

    /**
     * Validates a selection and builds the stored cover terms.
     *
     * @param  array{effective_rule?:string, start_date?:string, duration?:array{unit:string,value?:int}, instalment_plan?:string, custom_instalments?:list<int>}  $in
     * @return array<string,mixed>
     */
    public function select(?InsuranceProduct $product, string $lineCode, int $totalMinor, array $in): array
    {
        $rule = $this->ruleFor($product, $lineCode);
        $tz = config('proposals.cover_terms.timezone', 'Africa/Douala');
        $today = CarbonImmutable::now($tz)->startOfDay();

        $effective = strtoupper((string) ($in['effective_rule'] ?? $rule['default_effective_rule']));
        if (! in_array($effective, self::EFFECTIVE_RULES, true) || ! in_array($effective, $rule['effective_date_rules'], true)) {
            throw ValidationException::withMessages(['effective_rule' => "Effective-date rule {$effective} is not allowed for this product."]);
        }
        $start = null;
        if (in_array($effective, ['SPECIFIED_DATE', 'CUSTOM'], true)) {
            if (empty($in['start_date'])) {
                throw ValidationException::withMessages(['start_date' => 'A start date is required for this effective-date rule.']);
            }
            $start = CarbonImmutable::parse($in['start_date'], $tz);
            if ($start->lt($today)) {
                throw ValidationException::withMessages(['start_date' => 'Cover cannot start in the past (no backdating on a proposal).']);
            }
            if ($start->gt($today->addDays((int) $rule['max_advance_days']))) {
                throw ValidationException::withMessages(['start_date' => "Cover cannot start more than {$rule['max_advance_days']} days ahead."]);
            }
        }

        $duration = $in['duration'] ?? $rule['durations'][0];
        $duration = ['unit' => strtoupper((string) ($duration['unit'] ?? 'MONTH')), 'value' => isset($duration['value']) ? (int) $duration['value'] : null];
        if (! $this->durationAllowed($duration, $rule['durations'])) {
            throw ValidationException::withMessages(['duration' => 'This cover duration is not offered for this product.']);
        }
        $months = $duration['unit'] === 'MONTH' ? (int) $duration['value'] : 0;

        $plan = strtoupper((string) ($in['instalment_plan'] ?? $rule['instalment_plans'][0] ?? 'SINGLE'));
        if (! array_key_exists($plan, self::PLANS) || ! in_array($plan, $rule['instalment_plans'], true)) {
            throw ValidationException::withMessages(['instalment_plan' => "Instalment plan {$plan} is not offered for this product."]);
        }
        $schedule = $this->schedule($plan, $months, $totalMinor, (int) $rule['instalment_fee_minor'], $in['custom_instalments'] ?? null);

        return [
            'rule_id' => $rule['rule_id'], 'effective_rule' => $effective, 'start_date' => $start?->toDateString(),
            'ends_before' => $start && $duration['unit'] !== 'CUSTOM' ? ($duration['unit'] === 'DAY' ? $start->addDays($duration['value']) : $start->addMonths($months))->toDateString() : null,
            'duration' => $duration, 'instalment_plan' => $plan, 'schedule' => $schedule,
            'total_payable_minor' => array_sum(array_column($schedule, 'amount_minor')),
            'non_payment_consequence' => $rule['non_payment_consequence'], 'timezone' => $tz,
        ];
    }

    /** Start instant for rules resolved by a later event (issuance / payment / approval). */
    public function resolveStart(array $terms, \DateTimeInterface $event): CarbonImmutable
    {
        $tz = $terms['timezone'] ?? config('proposals.cover_terms.timezone', 'Africa/Douala');
        $at = CarbonImmutable::instance($event)->setTimezone($tz);

        return match ($terms['effective_rule'] ?? 'IMMEDIATE') {
            'SPECIFIED_DATE', 'CUSTOM' => CarbonImmutable::parse($terms['start_date'], $tz)->max($at),
            'MIDNIGHT_RULE' => $at->addDay()->startOfDay(),
            default => $at,
        };
    }

    private function durationAllowed(array $d, array $allowed): bool
    {
        if (! in_array($d['unit'], ['DAY', 'MONTH', 'CUSTOM'], true)) {
            return false;
        }
        foreach ($allowed as $a) {
            $unit = strtoupper((string) ($a['unit'] ?? ''));
            if ($unit === 'CUSTOM' && $d['unit'] !== 'CUSTOM' && ($d['value'] ?? 0) >= 1 && ($d['unit'] !== 'MONTH' || $d['value'] <= 12)) {
                return true; // CUSTOM rule: any 1 day … 12 months
            }
            if ($unit === $d['unit'] && (int) ($a['value'] ?? 0) === (int) $d['value']) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{sequence:int, due:string, amount_minor:int, fee_minor:int}> */
    private function schedule(string $plan, int $months, int $total, int $fee, ?array $custom): array
    {
        if ($plan === 'CUSTOM') {
            $parts = array_map('intval', (array) $custom);
            if ($parts === [] || array_sum($parts) !== $total || min($parts) <= 0) {
                throw ValidationException::withMessages(['custom_instalments' => 'Custom instalments must be positive and add up to the total premium.']);
            }
        } else {
            $period = self::PLANS[$plan];
            $n = $period === null || $months === 0 ? 1 : max(1, (int) ceil($months / $period));
            $base = intdiv($total, $n);
            $parts = array_fill(0, $n, $base);
            $parts[0] += $total - $base * $n;
        }
        $out = [];
        foreach ($parts as $i => $amount) {
            $f = $i === 0 ? 0 : $fee;
            $out[] = ['sequence' => $i + 1, 'due' => $i === 0 ? 'AT_BIND' : ($plan === 'CUSTOM' ? 'CUSTOM_'.($i + 1) : '+'.($i * (self::PLANS[$plan] ?? 0)).'M'), 'amount_minor' => $amount + $f, 'fee_minor' => $f];
        }

        return $out;
    }
}
