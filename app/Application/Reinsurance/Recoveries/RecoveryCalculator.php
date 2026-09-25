<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Recoveries;

use App\Application\Reinsurance\CessionCalculator;

/**
 * REQ-REI-004: pure recovery arithmetic on one claim loss (integer minor units, no I/O).
 *
 * Mirrors the cession order of CessionCalculator: FACULTATIVE placements first (share of the gross loss),
 * then QUOTA_SHARE and SURPLUS at the cession's ceded_percent of the loss presented to them,
 * then EXCESS_OF_LOSS layers on the net retained loss (per-occurrence: min(max(net - attachment, 0), limit)),
 * then STOP_LOSS on the aggregate (resolved by the caller through $stopLoss).
 * XL reinstatement premium per layer = layer premium x (recovered in layer / limit) x reinstatement_premium_percent,
 * charged only when the treaty layer defines reinstatements > 0 AND a reinstatement_premium_percent (no default).
 */
final class RecoveryCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $sources  [{source_type, source_key, treaty_type, ceded_percent, layers?, treaty_layers?, shares[]}]
     * @param  callable(array $source, int $subjectLoss): int|null  $stopLoss  recoverable for a STOP_LOSS source given this claim's retained loss
     * @return array<int, array<string, mixed>>
     */
    public function calculate(int $loss, array $sources, ?callable $stopLoss = null): array
    {
        $order = ['FACULTATIVE' => 0, 'QUOTA_SHARE' => 1, 'SURPLUS' => 2, 'EXCESS_OF_LOSS' => 3, 'STOP_LOSS' => 4];
        usort($sources, fn ($a, $b) => ($order[$a['treaty_type']] ?? 9) <=> ($order[$b['treaty_type']] ?? 9));

        $net = max($loss, 0);
        $facPresented = $net;
        $out = [];
        foreach ($sources as $s) {
            $layers = [];
            $reinstatement = 0;
            switch ($s['treaty_type']) {
                case 'FACULTATIVE':
                    $subject = $facPresented;
                    $recoverable = min(CessionCalculator::pct($facPresented, (float) $s['ceded_percent']), $net);
                    break;
                case 'QUOTA_SHARE':
                case 'SURPLUS':
                    $subject = $net;
                    $recoverable = CessionCalculator::pct($net, (float) $s['ceded_percent']);
                    break;
                case 'EXCESS_OF_LOSS':
                    $subject = $net;
                    $recoverable = 0;
                    $defs = [];
                    foreach ($s['treaty_layers'] ?? [] as $i => $d) {
                        $defs[(int) ($d['layer'] ?? $i + 1)] = $d;
                    }
                    foreach ($s['layers'] ?? [] as $i => $l) {
                        $no = (int) ($l['layer'] ?? $i + 1);
                        $limit = (int) $l['limit_minor'];
                        $in = min(max($net - (int) $l['attachment_minor'], 0), $limit);
                        $def = $defs[$no] ?? [];
                        $pct = isset($def['reinstatement_premium_percent']) ? (float) $def['reinstatement_premium_percent'] : null;
                        $rp = ($pct !== null && (int) ($def['reinstatements'] ?? 0) > 0 && $limit > 0)
                            ? (int) round((int) ($l['premium_minor'] ?? 0) * $in / $limit * $pct / 100) : 0;
                        $layers[] = ['layer' => $no, 'attachment_minor' => (int) $l['attachment_minor'], 'limit_minor' => $limit, 'recovered_minor' => $in,
                            'reinstatements' => (int) ($def['reinstatements'] ?? 0), 'reinstatement_premium_percent' => $pct, 'reinstatement_premium_minor' => $rp];
                        $recoverable += $in;
                        $reinstatement += $rp;
                    }
                    break;
                case 'STOP_LOSS':
                    $subject = $net;
                    $recoverable = $stopLoss ? min(max((int) $stopLoss($s, $net), 0), $net) : 0;
                    break;
                default:
                    continue 2;
            }
            $recoverable = min($recoverable, $net);
            $out[] = $s + ['subject_loss_minor' => $subject, 'recoverable_minor' => $recoverable, 'recovery_layers' => $layers, 'reinstatement_premium_minor' => $reinstatement,
                'share_amounts' => self::split($s['shares'] ?? [], $recoverable), 'share_reinstatement' => self::split($s['shares'] ?? [], $reinstatement)];
            $net -= $recoverable;
        }

        return $out;
    }

    /**
     * Stop loss on the aggregate retained loss ratio: attachment_ratio / limit_ratio are loss-ratio percentages
     * of the subject (net retained) premium. Returns the aggregate recoverable for the treaty.
     */
    public static function stopLossAggregate(int $aggregateLoss, int $subjectPremium, float $attachmentRatio, float $limitRatio): int
    {
        $attach = CessionCalculator::pct($subjectPremium, $attachmentRatio);
        $limit = CessionCalculator::pct($subjectPremium, $limitRatio);

        return min(max($aggregateLoss - $attach, 0), $limit);
    }

    /**
     * Largest-remainder split by share_percent so participant amounts reconcile to the total.
     *
     * @return array<int, int> index-aligned with $shares
     */
    public static function split(array $shares, int $amount): array
    {
        if ($shares === []) {
            return [];
        }
        $total = array_sum(array_map(fn ($s) => (float) $s['share_percent'], $shares));
        $raw = array_map(fn ($s) => $total > 0 ? $amount * (float) $s['share_percent'] / $total : 0, $shares);
        $floors = array_map(fn ($r) => (int) floor($r), $raw);
        $left = $amount - array_sum($floors);
        $order = array_keys($raw);
        usort($order, fn ($a, $b) => ($raw[$b] - $floors[$b]) <=> ($raw[$a] - $floors[$a]));
        foreach ($order as $i) {
            if ($left <= 0) {
                break;
            }
            $floors[$i]++;
            $left--;
        }

        return $floors;
    }
}
