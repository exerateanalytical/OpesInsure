<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use App\Domain\Rules\Expression\ExpressionEvaluator;
use App\Domain\Rules\Expression\InvalidExpression;

/**
 * REQ-RUL-002 — runs one rule set over a fact bag (PREP §3.4): enabled rules in priority/code order, a trace row for
 * every evaluated rule (fired or not), stop_processing honoured on a fired rule. Pure: the reference date arrives as
 * the `context.today` fact. Combination per domain lives in combineEligibility() / combineCompleteness().
 */
final class RuleSetEvaluator
{
    public function __construct(private readonly ExpressionEvaluator $expressions = new ExpressionEvaluator) {}

    /**
     * @return array{fired: list<array{rule: RuleDefinition, unknown: bool, missing: list<string>}>, trace: list<array<string, mixed>>, missing: list<string>, stopped_at: ?string}
     */
    public function evaluate(RuleSetDefinition $set, array $facts): array
    {
        $fired = [];
        $trace = [];
        $missing = [];
        $stoppedAt = null;
        foreach ($set->ordered() as $rule) {
            try {
                $r = $this->expressions->evaluate($rule->condition, $facts);
            } catch (InvalidExpression $e) {
                // A malformed stored rule never passes silently: it counts as UNKNOWN (never ELIGIBLE/COMPLETE by accident).
                $r = ['result' => null, 'referenced' => [], 'missing' => ['__invalid_rule__:'.$rule->code]];
            }
            $result = $r['result'] === null ? 'UNKNOWN' : ($r['result'] ? 'TRUE' : 'FALSE');
            $trace[] = [
                'rule_code' => $rule->code,
                'rule_version' => $set->version,
                'source_table' => $set->sourceTable === 'rule_sets' ? 'rules' : $set->sourceTable,
                'source_id' => $rule->id ?? $set->id,
                'condition' => self::canonical($rule->condition),
                'input_values' => $r['referenced'],
                'result' => $result,
                'message_key' => $rule->outcome['message_key'] ?? null,
            ];
            if ($r['result'] === true || $r['result'] === null) {
                $fired[] = ['rule' => $rule, 'unknown' => $r['result'] === null, 'missing' => $r['missing']];
                $missing = [...$missing, ...$r['missing']];
            }
            if ($r['result'] === true && $rule->stopProcessing) {
                $stoppedAt = $rule->code;
                break;
            }
        }
        $missing = array_values(array_unique($missing));
        sort($missing);

        return ['fired' => $fired, 'trace' => $trace, 'missing' => $missing, 'stopped_at' => $stoppedAt];
    }

    /**
     * Eligibility: worst outcome wins, every fired reason is kept. A rule whose condition is UNKNOWN (missing facts)
     * contributes MORE_INFORMATION_REQUIRED instead of its own outcome. No rule fired = ELIGIBLE.
     *
     * @return array{outcome: EligibilityOutcome, reasons: list<string>, conditions: list<array<string, mixed>>, missing: list<string>}
     */
    public static function combineEligibility(array $evaluation): array
    {
        $outcomes = [];
        $reasons = [];
        $conditions = [];
        foreach ($evaluation['fired'] as $f) {
            $declared = EligibilityOutcome::parse((string) ($f['rule']->outcome['result'] ?? 'INELIGIBLE'));
            if ($f['unknown']) {
                if ($declared === EligibilityOutcome::ELIGIBLE) {
                    continue;
                }
                $outcomes[] = EligibilityOutcome::MORE_INFORMATION_REQUIRED;
                $reasons[] = 'MORE_INFORMATION_REQUIRED:'.$f['rule']->code;

                continue;
            }
            $outcomes[] = $declared;
            $reasons[] = (string) ($f['rule']->outcome['reason_code'] ?? $f['rule']->code);
            if ($declared === EligibilityOutcome::CONDITIONAL && isset($f['rule']->outcome['conditions'])) {
                $conditions = [...$conditions, ...(array) $f['rule']->outcome['conditions']];
            }
        }

        return ['outcome' => EligibilityOutcome::worst(...$outcomes), 'reasons' => array_values(array_unique($reasons)), 'conditions' => $conditions, 'missing' => $evaluation['missing']];
    }

    /**
     * Completeness / data-quality gate (REQ-RUL-004): a rule fires when data is missing or wrong; outcome.result is
     * BLOCK (default) or WARN. UNKNOWN counts as fired — a gate never passes on data it could not read.
     *
     * @return array{outcome: string, blocking: bool, reasons: list<string>, warnings: list<string>}
     */
    public static function combineCompleteness(array $evaluation): array
    {
        $blocks = [];
        $warns = [];
        foreach ($evaluation['fired'] as $f) {
            $reason = (string) ($f['rule']->outcome['reason_code'] ?? $f['rule']->code);
            if (strtoupper((string) ($f['rule']->outcome['result'] ?? 'BLOCK')) === 'WARN') {
                $warns[] = $reason;
            } else {
                $blocks[] = $reason;
            }
        }
        $outcome = $blocks ? 'INCOMPLETE' : ($warns ? 'COMPLETE_WITH_WARNINGS' : 'COMPLETE');

        return ['outcome' => $outcome, 'blocking' => $blocks !== [], 'reasons' => array_values(array_unique($blocks)), 'warnings' => array_values(array_unique($warns))];
    }

    /** Canonical (recursively key-sorted) JSON so equal conditions give byte-identical traces. */
    public static function canonical(mixed $v): string
    {
        $sort = function ($x) use (&$sort) {
            if (! is_array($x)) {
                return $x;
            }
            if (! array_is_list($x)) {
                ksort($x);
            }

            return array_map($sort, $x);
        };

        return json_encode($sort($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
