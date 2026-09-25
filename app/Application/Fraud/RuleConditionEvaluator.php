<?php

declare(strict_types=1);

namespace App\Application\Fraud;

/**
 * REQ-FRD-001 — evaluates a fraud_rule_versions.conditions tree against a fact set and explains the result.
 *
 * Grammar (JSON):
 *   {"op":"always"}
 *   {"op":"all"|"any","conditions":[ ...nodes ]}
 *   {"op":"gt"|"gte"|"lt"|"lte"|"eq"|"neq","fact":"<fact>","value":<scalar>}
 *   {"op":"in","fact":"<fact>","value":[...]}
 * A comparison on a missing (null) fact never fires: absence of data is not a fraud indicator.
 * Unknown operators never fire either and are reported in the explanation.
 */
final class RuleConditionEvaluator
{
    public const COMPARATORS = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'eq' => '=', 'neq' => '!='];

    /**
     * @param  array<string,mixed>  $node
     * @param  array<string,mixed>  $facts
     * @return array{matched: bool, reasons: list<string>}
     */
    public function evaluate(array $node, array $facts): array
    {
        $op = (string) ($node['op'] ?? '');
        if ($op === 'always') {
            return ['matched' => true, 'reasons' => ['always']];
        }
        if ($op === 'all' || $op === 'any') {
            $results = array_map(fn ($c) => $this->evaluate((array) $c, $facts), (array) ($node['conditions'] ?? []));
            if ($results === []) {
                return ['matched' => false, 'reasons' => []];
            }
            $matched = $op === 'all'
                ? ! in_array(false, array_column($results, 'matched'), true)
                : in_array(true, array_column($results, 'matched'), true);
            $reasons = [];
            foreach ($results as $r) {
                if ($r['matched']) {
                    array_push($reasons, ...$r['reasons']);
                }
            }

            return ['matched' => $matched, 'reasons' => $matched ? $reasons : []];
        }

        $fact = (string) ($node['fact'] ?? '');
        $actual = $facts[$fact] ?? null;
        $expected = $node['value'] ?? null;
        if ($actual === null) {
            return ['matched' => false, 'reasons' => []];
        }
        if ($op === 'in') {
            $ok = in_array($actual, (array) $expected, false);

            return ['matched' => $ok, 'reasons' => $ok ? [sprintf('%s = %s (in %s)', $fact, $this->show($actual), json_encode($expected))] : []];
        }
        if (! isset(self::COMPARATORS[$op])) {
            return ['matched' => false, 'reasons' => []];
        }
        $ok = match ($op) {
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
        };

        return ['matched' => $ok, 'reasons' => $ok ? [sprintf('%s = %s %s %s', $fact, $this->show($actual), self::COMPARATORS[$op], $this->show($expected))] : []];
    }

    private function show(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : (string) json_encode($v);
    }
}
