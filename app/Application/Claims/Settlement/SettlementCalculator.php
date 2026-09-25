<?php

declare(strict_types=1);

namespace App\Application\Claims\Settlement;

use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-013 settlement formula (pure, integer minor units):
 *
 *   gross  = covered − excluded − deductible − prior payments ± adjustments   (floored at 0)
 *   amount = min(gross, remaining limit)                                       (when a limit applies)
 *
 * Every step is returned as an explainable breakdown line (code, label, operator, amount, running total).
 */
final class SettlementCalculator
{
    /**
     * @param  list<array{code:string, amount_minor:int, reason?:?string}>  $adjustments  signed amounts (+ increases, − decreases)
     * @return array{covered_minor:int, excluded_minor:int, deductible_minor:int, prior_payments_minor:int, adjustments_minor:int,
     *               remaining_limit_minor:?int, gross_minor:int, amount_minor:int, limit_capped:bool, lines:list<array<string,mixed>>}
     */
    public function calculate(int $covered, int $excluded, int $deductible, int $prior, array $adjustments, ?int $remainingLimit): array
    {
        foreach (['covered_minor' => $covered, 'excluded_minor' => $excluded, 'deductible_minor' => $deductible, 'prior_payments_minor' => $prior] as $k => $v) {
            if ($v < 0) {
                throw ValidationException::withMessages([$k => 'Settlement components must not be negative.']);
            }
        }

        $lines = [];
        $running = 0;
        $step = function (string $code, string $label, string $op, int $amount, array $extra = []) use (&$lines, &$running): void {
            $running = match ($op) { '=' => $amount, '+' => $running + $amount, '-' => $running - $amount };
            $lines[] = ['code' => $code, 'label' => $label, 'operator' => $op, 'amount_minor' => $amount, 'running_minor' => $running] + $extra;
        };

        $step('COVERED', 'Covered loss amount', '=', $covered);
        $step('EXCLUDED', 'Excluded / not covered items', '-', $excluded);
        $step('DEDUCTIBLE', 'Deductible (excess)', '-', $deductible);
        $step('PRIOR_PAYMENTS', 'Payments already made on this claim', '-', $prior);
        $adjTotal = 0;
        foreach ($adjustments as $a) {
            $amt = (int) $a['amount_minor'];
            $adjTotal += $amt;
            $step('ADJUSTMENT', 'Adjustment '.$a['code'], $amt >= 0 ? '+' : '-', abs($amt), ['adjustment_code' => $a['code'], 'reason' => $a['reason'] ?? null]);
        }
        $gross = max(0, $running);
        if ($running < 0) {
            $step('FLOOR', 'Result floored at zero', '=', 0);
        }
        $amount = $gross;
        $capped = false;
        if ($remainingLimit !== null && $gross > max(0, $remainingLimit)) {
            $amount = max(0, $remainingLimit);
            $capped = true;
            $step('LIMIT_CAP', 'Capped at the remaining policy limit', '=', $amount, ['remaining_limit_minor' => $remainingLimit]);
        }
        $lines[] = ['code' => 'SETTLEMENT', 'label' => 'Settlement amount payable', 'operator' => '=', 'amount_minor' => $amount, 'running_minor' => $amount];

        return [
            'covered_minor' => $covered, 'excluded_minor' => $excluded, 'deductible_minor' => $deductible, 'prior_payments_minor' => $prior,
            'adjustments_minor' => $adjTotal, 'remaining_limit_minor' => $remainingLimit, 'gross_minor' => $gross, 'amount_minor' => $amount,
            'limit_capped' => $capped, 'lines' => $lines,
        ];
    }
}
