<?php

declare(strict_types=1);

namespace App\Application\Rules;

use App\Application\Engines\EngineEvaluationRecorder;
use App\Application\Engines\EngineResult;
use App\Application\Rules\Models\RuleSet;
use App\Domain\Rules\EligibilityOutcome;
use App\Domain\Rules\RuleDefinition;
use App\Domain\Rules\RuleSetDefinition;
use App\Domain\Rules\RuleSetEvaluator;
use App\Models\InsuranceProduct;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RUL-002/003/004 — ProductEligibilityService + completeness gate (PRE §19–22, §85; ICE gap 14) on the shared
 * EngineResult envelope (engine RULES), logged through EngineEvaluationRecorder when a subject is given.
 *
 *  eligibility()  → outcome ELIGIBLE | CONDITIONAL | MORE_INFORMATION_REQUIRED | REFER_TO_UNDERWRITING | INELIGIBLE,
 *                   blocking unless ELIGIBLE/CONDITIONAL; reasons = fired reason codes; trace = one row per evaluated rule.
 *                   Legacy insurance_products.eligibility_rules {conditions:[{fact,operator,value}]} are adapted into a
 *                   synthetic rule (source_table insurance_products) so existing products keep their behaviour.
 *  completeness() → COMPLETE | COMPLETE_WITH_WARNINGS | INCOMPLETE (blocking) for QUOTE / BIND / ISSUE / CLAIM.
 */
final class RuleEngine
{
    public const ENGINE = 'RULES';

    public const VERSION = '1.0.0';

    public function __construct(
        private readonly RuleSetResolver $resolver,
        private readonly RuleSetService $sets,
        private readonly EngineEvaluationRecorder $recorder,
        private readonly RuleSetEvaluator $evaluator = new RuleSetEvaluator,
    ) {}

    /**
     * @param  array{type: string, id: ?string}|null  $subject  record the evaluation against this subject (decision log)
     * @return array{result: EngineResult, outcome: EligibilityOutcome, explanations: list<array<string, mixed>>, conditions: list<mixed>, evaluation_id: ?string}
     */
    public function eligibility(InsuranceProduct $product, array $facts, ?\DateTimeInterface $at = null, ?array $subject = null, ?string $tenantId = null): array
    {
        $at = $this->at($at);
        $facts = $this->withContext($facts, $at, $product->line_code, $product);
        $definitions = $this->resolver->resolve('ELIGIBILITY', $product->id, $product->line_code, $at)->map(fn (RuleSet $s) => $this->sets->toDefinition($s))->all();
        if ($legacy = $this->legacyEligibility($product)) {
            $definitions[] = $legacy;
        }

        $fired = [];
        $trace = [];
        $missing = [];
        $versions = [];
        foreach ($definitions as $def) {
            $ev = $this->evaluator->evaluate($def, $facts);
            $fired = [...$fired, ...$ev['fired']];
            $trace = [...$trace, ...$ev['trace']];
            $missing = [...$missing, ...$ev['missing']];
            $versions['rule_set:'.$def->code] = (string) $def->version;
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        $combined = RuleSetEvaluator::combineEligibility(['fired' => $fired, 'missing' => $missing]);
        $outcome = $combined['outcome'];

        $result = new EngineResult(
            engine: self::ENGINE, outcome: $outcome->value, referenceAt: $at, recordedAsOf: $this->at(null),
            inputsHash: EngineResult::hashInputs(['domain' => 'ELIGIBILITY', 'product_id' => $product->id, 'facts' => $facts, 'versions' => $versions]),
            trace: $trace, resolvedVersions: $versions + ['engine' => self::VERSION, 'insurance_product' => $product->id.'@'.$product->version],
            blocking: $outcome->blocking(), reasons: $combined['reasons'],
            warnings: array_map(fn ($f) => 'MISSING_FACT:'.$f, $missing),
        );
        $id = $subject ? $this->recorder->record($result, 'eligibility.check', $subject['type'], $subject['id'], $tenantId) : null;

        return ['result' => $result, 'outcome' => $outcome, 'explanations' => $this->explanations($fired), 'conditions' => $combined['conditions'], 'evaluation_id' => $id];
    }

    /**
     * @return array{result: EngineResult, explanations: list<array<string, mixed>>, evaluation_id: ?string}
     */
    public function completeness(string $operation, string $lineCode, ?InsuranceProduct $product, array $facts, ?\DateTimeInterface $at = null, ?array $subject = null, ?string $tenantId = null): array
    {
        $operation = strtoupper($operation);
        if (! in_array($operation, RuleSetService::OPERATIONS, true)) {
            throw new \InvalidArgumentException("Unknown gate operation [{$operation}].");
        }
        $at = $this->at($at);
        $facts = $this->withContext($facts, $at, $lineCode, $product) + ['context.operation' => $operation];
        $fired = [];
        $trace = [];
        $versions = [];
        foreach ($this->resolver->resolve('COMPLETENESS', $product?->id, $lineCode, $at, $operation) as $set) {
            $ev = $this->evaluator->evaluate($this->sets->toDefinition($set), $facts);
            $fired = [...$fired, ...$ev['fired']];
            $trace = [...$trace, ...$ev['trace']];
            $versions['rule_set:'.$set->code] = (string) $set->version;
        }
        $c = RuleSetEvaluator::combineCompleteness(['fired' => $fired]);
        $result = new EngineResult(
            engine: self::ENGINE, outcome: $c['outcome'], referenceAt: $at, recordedAsOf: $this->at(null),
            inputsHash: EngineResult::hashInputs(['domain' => 'COMPLETENESS', 'operation' => $operation, 'line_code' => strtoupper($lineCode), 'product_id' => $product?->id, 'facts' => $facts, 'versions' => $versions]),
            trace: $trace, resolvedVersions: $versions + ['engine' => self::VERSION], blocking: $c['blocking'], reasons: $c['reasons'], warnings: $c['warnings'],
        );
        $id = $subject && $trace !== [] ? $this->recorder->record($result, 'completeness.'.strtolower($operation), $subject['type'], $subject['id'], $tenantId) : null;

        return ['result' => $result, 'explanations' => $this->explanations($fired), 'evaluation_id' => $id];
    }

    /** Gate helper for callers (quote/bind/issue/claim): throws a 422 listing the blocking reasons. */
    public function assertComplete(string $operation, string $lineCode, ?InsuranceProduct $product, array $facts, ?array $subject = null, ?string $tenantId = null): void
    {
        $r = $this->completeness($operation, $lineCode, $product, $facts, null, $subject, $tenantId);
        if ($r['result']->blocking) {
            throw ValidationException::withMessages(['completeness' => array_map(fn ($reason) => "Incomplete data ({$operation}): {$reason}", $r['result']->reasons)]);
        }
    }

    /** Sandbox evaluation of one rule set version (any status) — no persistence (PRE §76 test sandbox). */
    public function simulate(RuleSet $set, array $facts, ?\DateTimeInterface $at = null): array
    {
        $at = $this->at($at);
        $facts = $this->withContext($facts, $at, $set->line_code, $set->insurance_product_id ? InsuranceProduct::find($set->insurance_product_id) : null);
        $ev = $this->evaluator->evaluate($this->sets->toDefinition($set), $facts);
        if ($set->domain === 'COMPLETENESS') {
            $c = RuleSetEvaluator::combineCompleteness($ev);
            $outcome = $c['outcome'];
        } else {
            $c = RuleSetEvaluator::combineEligibility($ev);
            $outcome = $c['outcome']->value;
        }

        return ['outcome' => $outcome, 'reasons' => $c['reasons'], 'trace' => $ev['trace'], 'missing' => $ev['missing'], 'stopped_at' => $ev['stopped_at'], 'explanations' => $this->explanations($ev['fired'])];
    }

    /** Legacy v1 eligibility ({conditions:[{fact,operator,value}]}, AND only) → one synthetic INELIGIBLE rule. */
    public function legacyEligibility(InsuranceProduct $product): ?RuleSetDefinition
    {
        $conds = $product->eligibility_rules['conditions'] ?? [];
        if (! is_array($conds) || $conds === []) {
            return null;
        }
        $args = array_map(fn (array $c) => ['op' => \App\Domain\Rules\Expression\ExpressionEvaluator::ALIASES[strtoupper((string) ($c['operator'] ?? 'EQUALS'))] ?? strtoupper((string) ($c['operator'] ?? 'EQUAL')),
            'left' => ['fact' => (string) $c['fact']], 'right' => ['value' => $c['value'] ?? null]], array_values($conds));

        return new RuleSetDefinition('LEGACY_PRODUCT_ELIGIBILITY', 'ELIGIBILITY', (int) $product->version, [
            new RuleDefinition('LEGACY_CONDITIONS_NOT_MET', 0, false, ['op' => 'NOT', 'arg' => ['op' => 'AND', 'args' => $args]],
                ['result' => 'INELIGIBLE', 'reason_code' => 'PRODUCT_ELIGIBILITY_CONDITIONS_NOT_MET', 'message_key' => 'rules.eligibility.conditions_not_met'],
                $product->id, 'The risk does not meet the product eligibility conditions.', "Le risque ne remplit pas les conditions d'éligibilité du produit."),
        ], $product->id, null, 'insurance_products');
    }

    private function explanations(array $fired): array
    {
        return array_map(fn (array $f) => [
            'rule_code' => $f['rule']->code, 'result' => $f['unknown'] ? 'MORE_INFORMATION_REQUIRED' : ($f['rule']->outcome['result'] ?? null),
            'reason_code' => $f['rule']->outcome['reason_code'] ?? $f['rule']->code, 'message_key' => $f['rule']->outcome['message_key'] ?? null,
            'explanation_en' => $f['rule']->explanationEn, 'explanation_fr' => $f['rule']->explanationFr, 'missing_facts' => $f['missing'],
        ], $fired);
    }

    private function withContext(array $facts, \DateTimeImmutable $at, ?string $lineCode, ?InsuranceProduct $product): array
    {
        return $facts + array_filter([
            'context.today' => $at->format('Y-m-d'),
            'context.line_code' => $lineCode ? strtoupper($lineCode) : null,
            'context.product_code' => $product?->code,
            'context.carrier_id' => $product?->carrier_id,
        ], fn ($v) => $v !== null);
    }

    private function at(?\DateTimeInterface $at): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($at ?? now());
    }
}
