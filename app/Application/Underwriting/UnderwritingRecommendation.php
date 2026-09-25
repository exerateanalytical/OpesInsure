<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Domain\Rules\RuleDefinition;

/**
 * REQ-UW-002 / REQ-UW-004 — pure combiner for UNDERWRITING-domain rule evaluations (RuleSetEvaluator output).
 *
 * Rule outcome shape (rules.outcome, domain UNDERWRITING):
 *   {result?: AUTO_ACCEPT|REFER|CONDITIONAL_ACCEPT|DECLINE|REQUEST_INFO|INSPECTION|MEDICAL, reason_code, conditions?: [...],
 *    items?: [{code, description}], score?: {factor, weight}}
 *   A rule with only `score` is a pure scoring factor. Unknown `result` values count as REFER (never silently accepted).
 *
 * Recommendation: most severe fired result wins (DECLINE > REFER > MEDICAL > INSPECTION > REQUEST_INFO >
 * CONDITIONAL_ACCEPT > AUTO_ACCEPT). A rule that could not be evaluated (missing facts) recommends REQUEST_INFO and
 * lists the missing facts as exact items. Disclosure referral flags always recommend at least REFER.
 *
 * Risk score (explainable): one row per scoring factor {factor, source, weight, input, fired, contribution};
 * score = min(100, Σ contribution). Band thresholds are platform defaults (LOW < 30 ≤ MEDIUM < 60 ≤ HIGH).
 */
final class UnderwritingRecommendation
{
    public const RESULTS = ['AUTO_ACCEPT', 'CONDITIONAL_ACCEPT', 'REQUEST_INFO', 'INSPECTION', 'MEDICAL', 'REFER', 'DECLINE'];

    /** Severity, ascending. */
    private const SEVERITY = ['AUTO_ACCEPT' => 0, 'CONDITIONAL_ACCEPT' => 1, 'REQUEST_INFO' => 2, 'INSPECTION' => 3, 'MEDICAL' => 4, 'REFER' => 5, 'DECLINE' => 6];

    /** Weight of one disclosure referral flag in the risk score when no scoring rule covers it. */
    public const REFERRAL_FLAG_WEIGHT = 20;

    public const BANDS = ['LOW' => 0, 'MEDIUM' => 30, 'HIGH' => 60];

    /**
     * @param  list<array{rule: RuleDefinition, unknown: bool, missing: list<string>}>  $fired
     * @param  list<array<string, mixed>>  $trace
     * @param  list<string>  $referralFlags
     * @return array{recommendation: string, reasons: list<string>, conditions: list<mixed>, requested_items: list<array{code: string, description: string}>}
     */
    public static function combine(array $fired, array $referralFlags): array
    {
        $results = [];
        $reasons = [];
        $conditions = [];
        $items = [];
        foreach ($fired as $f) {
            $o = $f['rule']->outcome;
            if ($f['unknown']) {
                if (! isset($o['result'])) {
                    continue; // a scoring-only rule that could not be read contributes nothing
                }
                $results[] = 'REQUEST_INFO';
                $reasons[] = 'MORE_INFORMATION_REQUIRED:'.$f['rule']->code;
                foreach ($f['missing'] as $fact) {
                    $items[] = ['code' => self::itemCode($fact), 'description' => "Provide {$fact}."];
                }

                continue;
            }
            if (! isset($o['result'])) {
                continue;
            }
            $r = strtoupper((string) $o['result']);
            $r = isset(self::SEVERITY[$r]) ? $r : 'REFER';
            $results[] = $r;
            $reasons[] = (string) ($o['reason_code'] ?? $f['rule']->code);
            if ($r === 'CONDITIONAL_ACCEPT' && isset($o['conditions'])) {
                $conditions = [...$conditions, ...(array) $o['conditions']];
            }
            foreach ((array) ($o['items'] ?? []) as $item) {
                if (is_array($item) && isset($item['code'], $item['description'])) {
                    $items[] = ['code' => (string) $item['code'], 'description' => (string) $item['description']];
                }
            }
        }
        foreach ($referralFlags as $flag) {
            $results[] = 'REFER';
            $reasons[] = 'DISCLOSURE:'.$flag;
        }
        $recommendation = 'AUTO_ACCEPT';
        foreach ($results as $r) {
            if (self::SEVERITY[$r] > self::SEVERITY[$recommendation]) {
                $recommendation = $r;
            }
        }

        return ['recommendation' => $recommendation, 'reasons' => array_values(array_unique($reasons)), 'conditions' => $conditions,
            'requested_items' => array_values(array_unique($items, SORT_REGULAR))];
    }

    /**
     * @param  list<array{rule: RuleDefinition, unknown: bool, missing: list<string>}>  $fired
     * @param  list<array<string, mixed>>  $trace  every evaluated rule (fired or not)
     * @param  list<RuleDefinition>  $scoringRules  every enabled rule carrying outcome.score
     * @return array{score: int, band: string, factors: list<array<string, mixed>>}
     */
    public static function score(array $fired, array $trace, array $scoringRules, array $referralFlags): array
    {
        $firedKnown = [];
        foreach ($fired as $f) {
            if (! $f['unknown']) {
                $firedKnown[$f['rule']->code] = true;
            }
        }
        $inputs = [];
        foreach ($trace as $t) {
            $inputs[$t['rule_code']] = $t['input_values'] ?? [];
        }
        $factors = [];
        foreach ($scoringRules as $rule) {
            $weight = (int) ($rule->outcome['score']['weight'] ?? 0);
            $on = isset($firedKnown[$rule->code]);
            $factors[] = ['factor' => (string) ($rule->outcome['score']['factor'] ?? $rule->code), 'source' => 'rule:'.$rule->code, 'weight' => $weight,
                'input' => $inputs[$rule->code] ?? [], 'fired' => $on, 'contribution' => $on ? $weight : 0];
        }
        foreach ($referralFlags as $flag) {
            $factors[] = ['factor' => 'DISCLOSURE_'.$flag, 'source' => 'disclosure_referral_flag', 'weight' => self::REFERRAL_FLAG_WEIGHT,
                'input' => ['referral_flag' => $flag], 'fired' => true, 'contribution' => self::REFERRAL_FLAG_WEIGHT];
        }
        $score = max(0, min(100, array_sum(array_column($factors, 'contribution'))));

        return ['score' => $score, 'band' => self::band($score), 'factors' => $factors];
    }

    public static function band(int $score): string
    {
        $band = 'LOW';
        foreach (self::BANDS as $name => $floor) {
            if ($score >= $floor) {
                $band = $name;
            }
        }

        return $band;
    }

    private static function itemCode(string $fact): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $fact));
    }
}
