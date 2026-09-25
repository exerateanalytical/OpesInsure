<?php

declare(strict_types=1);

namespace App\Application\Reinsurance;

/**
 * REQ-REI-002: pure cession arithmetic (integer minor units, no I/O).
 *
 * Order of application on one risk: QUOTA_SHARE on gross -> SURPLUS on what QS retained ->
 * EXCESS_OF_LOSS layers on the net retained sum -> STOP_LOSS priced on net retained premium.
 * Proportional treaties cede premium pro rata to ceded sum; non-proportional treaties cede
 * premium at their rate on the premium presented to them. Commission / brokerage / tax are
 * percentages of ceded premium; net ceded = ceded - commission - brokerage - tax.
 * Participant amounts are split by share with largest-remainder rounding so they reconcile.
 */
final class CessionCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $versions  treaty version rows (+ treaty_type, participants[])
     * @return array<int, array<string, mixed>>
     */
    public function calculate(?int $sumInsured, int $grossPremium, array $versions): array
    {
        $order = ['QUOTA_SHARE' => 1, 'SURPLUS' => 2, 'EXCESS_OF_LOSS' => 3, 'STOP_LOSS' => 4, 'OTHER' => 5];
        usort($versions, fn ($a, $b) => ($order[$a['treaty_type']] ?? 9) <=> ($order[$b['treaty_type']] ?? 9));

        $netSum = $sumInsured;
        $netPremium = $grossPremium;
        $out = [];

        foreach ($versions as $v) {
            $subjectSum = $netSum;
            $subjectPremium = $netPremium;
            $cededSum = null;
            $layers = [];
            switch ($v['treaty_type']) {
                case 'QUOTA_SHARE':
                    $pct = (float) $v['cession_percent'];
                    if ($subjectSum !== null) {
                        $cededSum = self::pct($subjectSum, $pct);
                        if ($v['max_capacity_minor'] !== null && $cededSum > (int) $v['max_capacity_minor']) {
                            $cededSum = (int) $v['max_capacity_minor'];
                        }
                        $fraction = $subjectSum > 0 ? $cededSum / $subjectSum : 0.0;
                    } else {
                        $fraction = $pct / 100;
                    }
                    $cededPremium = (int) round($subjectPremium * $fraction);
                    break;
                case 'SURPLUS':
                    if ($subjectSum === null) {
                        throw new \DomainException('SURPLUS cession needs the sum insured.');
                    }
                    $retention = (int) $v['retention_minor'];
                    $capacity = $retention * (int) $v['lines'];
                    if ($v['max_capacity_minor'] !== null) {
                        $capacity = min($capacity, (int) $v['max_capacity_minor']);
                    }
                    $cededSum = min(max($subjectSum - $retention, 0), $capacity);
                    $fraction = $subjectSum > 0 ? $cededSum / $subjectSum : 0.0;
                    $cededPremium = (int) round($subjectPremium * $fraction);
                    break;
                case 'EXCESS_OF_LOSS':
                    $cededSum = 0;
                    $cededPremium = 0;
                    foreach ($v['layers'] as $i => $layer) {
                        $inLayer = $subjectSum === null ? null : min(max($subjectSum - (int) $layer['attachment_minor'], 0), (int) $layer['limit_minor']);
                        $premium = self::pct($subjectPremium, (float) ($layer['rate_percent'] ?? 0));
                        $layers[] = ['layer' => $layer['layer'] ?? $i + 1, 'attachment_minor' => (int) $layer['attachment_minor'], 'limit_minor' => (int) $layer['limit_minor'],
                            'exposed_minor' => $inLayer, 'premium_minor' => $premium];
                        $cededSum += (int) $inLayer;
                        $cededPremium += $premium;
                    }
                    if ($subjectSum === null) {
                        $cededSum = null;
                    }
                    $fraction = $subjectPremium > 0 ? $cededPremium / $subjectPremium : 0.0;
                    break;
                case 'STOP_LOSS':
                    $cededPremium = self::pct($subjectPremium, (float) $v['rate_percent']);
                    $fraction = $subjectPremium > 0 ? $cededPremium / $subjectPremium : 0.0;
                    break;
                default:
                    continue 2;
            }

            $commission = self::pct($cededPremium, (float) $v['commission_percent']);
            $brokerage = self::pct($cededPremium, (float) $v['brokerage_percent']);
            $tax = self::pct($cededPremium, (float) $v['tax_percent']);

            $out[] = [
                'treaty_id' => $v['treaty_id'], 'treaty_version_id' => $v['id'], 'treaty_type' => $v['treaty_type'],
                'subject_sum_minor' => $subjectSum, 'ceded_sum_minor' => $cededSum, 'ceded_percent' => round($fraction * 100, 4),
                'ceded_premium_minor' => $cededPremium, 'commission_minor' => $commission, 'brokerage_minor' => $brokerage, 'tax_minor' => $tax,
                'net_ceded_premium_minor' => $cededPremium - $commission - $brokerage - $tax, 'layers' => $layers,
                'shares' => $this->split($v['participants'], ['ceded_sum_minor' => $cededSum, 'ceded_premium_minor' => $cededPremium, 'commission_minor' => $commission, 'brokerage_minor' => $brokerage, 'tax_minor' => $tax]),
            ];

            // Proportional treaties reduce the net retained risk; XL protects it without taking the sum.
            if (in_array($v['treaty_type'], ['QUOTA_SHARE', 'SURPLUS'], true)) {
                if ($netSum !== null) {
                    $netSum -= (int) $cededSum;
                }
                $netPremium -= $cededPremium;
            }
        }

        return $out;
    }

    /** Net retained exposure/premium after all proportional cessions. */
    public static function net(?int $sumInsured, int $grossPremium, array $cessions): array
    {
        $sum = $sumInsured;
        $premium = $grossPremium;
        foreach ($cessions as $c) {
            $premium -= $c['ceded_premium_minor'];
            if (in_array($c['treaty_type'], ['QUOTA_SHARE', 'SURPLUS', 'EXCESS_OF_LOSS'], true) && $sum !== null) {
                $sum -= (int) $c['ceded_sum_minor'];
            }
        }

        return ['net_sum_minor' => $sum, 'net_premium_minor' => $premium];
    }

    public static function pct(int $amount, float $percent): int
    {
        return (int) round($amount * $percent / 100);
    }

    /** Largest-remainder split of each amount across participants by share_percent. */
    private function split(array $participants, array $amounts): array
    {
        $shares = array_map(fn ($p) => ['reinsurer_id' => $p['reinsurer_id'], 'share_percent' => (float) $p['share_percent']], $participants);
        foreach ($amounts as $key => $amount) {
            if ($amount === null) {
                foreach ($shares as &$s) {
                    $s[$key] = null;
                }
                unset($s);

                continue;
            }
            $raw = array_map(fn ($s) => $amount * $s['share_percent'] / 100, $shares);
            $floors = array_map(fn ($r) => (int) floor($r), $raw);
            $target = (int) round($amount * array_sum(array_column($shares, 'share_percent')) / 100);
            $left = $target - array_sum($floors);
            $order = array_keys($raw);
            usort($order, fn ($a, $b) => ($raw[$b] - $floors[$b]) <=> ($raw[$a] - $floors[$a]));
            foreach ($order as $i) {
                if ($left <= 0) {
                    break;
                }
                $floors[$i]++;
                $left--;
            }
            foreach ($shares as $i => &$s) {
                $s[$key] = $floors[$i];
            }
            unset($s);
        }
        foreach ($shares as &$s) {
            $s['net_premium_minor'] = $s['ceded_premium_minor'] - $s['commission_minor'] - $s['brokerage_minor'] - $s['tax_minor'];
        }

        return $shares;
    }
}
