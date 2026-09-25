<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Application\Audit\AuditWriter;
use App\Application\Engines\EngineEvaluationRecorder;
use App\Application\Engines\EngineResult;
use App\Application\Rules\Models\RuleSet;
use App\Application\Rules\RuleEngine;
use App\Application\Rules\RuleSetResolver;
use App\Application\Rules\RuleSetService;
use App\Domain\Rules\RuleDefinition;
use App\Domain\Rules\RuleSetEvaluator;
use App\Models\ProposalSubmission;
use App\Models\UnderwritingCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-UW-002 (PRE §23–25, WRS WF-017) + REQ-UW-004 — the SYSTEM evaluate step (PRE POST /underwriting/evaluate).
 *
 * Runs every APPROVED, effective UNDERWRITING-domain rule set (PLATFORM → LINE → PRODUCT_VERSION, rules engine
 * REQ-RUL-002) over the proposal's latest immutable submission snapshot, and records one append-only EngineResult
 * (engine RULES, operation underwriting.evaluate) carrying the resolved rule set versions, inputs hash and per-rule trace.
 * The case keeps the latest summary (recommendation, explainable risk score, rule set versions).
 *
 * It never decides: the outcome stays a human decision (UnderwritingService::decide), which records the evaluation it
 * was taken against. Pass 1 computes the risk score; pass 2 re-runs the rules with `underwriting.risk_score` as a fact
 * so a rule can refer on a score threshold stored in the rule set (thresholds live in rules, not in code).
 */
final class UnderwritingDecisionService
{
    public const OPERATION = 'underwriting.evaluate';

    public function __construct(
        private readonly RuleSetResolver $resolver,
        private readonly RuleSetService $sets,
        private readonly EngineEvaluationRecorder $recorder,
        private readonly AuditWriter $audit,
        private readonly RuleSetEvaluator $evaluator = new RuleSetEvaluator,
    ) {}

    /** @return array<string, mixed> the evaluation summary (also stored on the case) */
    public function evaluate(UnderwritingCase $c, ?User $actor = null): array
    {
        UnderwritingCaseMachine::assertCan($c, 'evaluate');
        $submission = ProposalSubmission::where('proposal_id', $c->proposal_id)->orderByDesc('sequence')->first();
        if (! $submission) {
            throw ValidationException::withMessages(['proposal' => 'The proposal has no submitted snapshot to evaluate.']);
        }
        $snap = (array) $submission->snapshot;
        $at = new \DateTimeImmutable;
        $flags = array_values(array_map('strval', (array) ($snap['referral_flags'] ?? $c->referral_reasons ?? [])));
        $facts = $this->facts($snap, $flags, $at);

        $sets = $this->resolver->resolve('UNDERWRITING', $snap['product_id'] ?? null, $snap['line_code'] ?? null, $at);
        $definitions = $sets->map(fn (RuleSet $s) => $this->sets->toDefinition($s))->all();
        $versions = [];
        $scoring = [];
        foreach ($definitions as $def) {
            $versions['rule_set:'.$def->code] = (string) $def->version;
            foreach ($def->ordered() as $rule) {
                if (isset($rule->outcome['score']) && $rule->enabled) {
                    $scoring[] = $rule;
                }
            }
        }

        // Pass 1: risk score.
        [$fired1, $trace1] = $this->run($definitions, $facts);
        $score = UnderwritingRecommendation::score($fired1, $trace1, $scoring, $flags);
        // Pass 2: decision rules may read the score.
        $facts['underwriting.risk_score'] = $score['score'];
        $facts['underwriting.risk_band'] = $score['band'];
        [$fired, $trace] = $this->run($definitions, $facts);
        $rec = UnderwritingRecommendation::combine($fired, $flags);
        $capacity = $this->capacity($c, $snap, $at);
        if ($capacity && in_array($capacity['result'], ['CAPACITY_EXCEEDED', 'FACULTATIVE_REQUIRED'], true)) {
            // REQ-CAT-002: an accumulation breach is an additional referral factor (never an automatic decline).
            $rec['recommendation'] = in_array($rec['recommendation'], ['REFER', 'DECLINE'], true) ? $rec['recommendation'] : 'REFER';
            $rec['reasons'][] = 'CAPACITY:'.$capacity['result'];
        }

        $result = new EngineResult(
            engine: RuleEngine::ENGINE, outcome: $rec['recommendation'], referenceAt: $at, recordedAsOf: new \DateTimeImmutable,
            inputsHash: EngineResult::hashInputs(['domain' => 'UNDERWRITING', 'submission_hash' => $submission->snapshot_hash, 'facts' => $facts, 'versions' => $versions]),
            trace: $trace, resolvedVersions: $versions + ['engine' => RuleEngine::VERSION, 'proposal_submission' => $submission->id.'#'.$submission->sequence],
            blocking: $rec['recommendation'] !== 'AUTO_ACCEPT', reasons: $rec['reasons'],
        );

        return DB::transaction(function () use ($c, $result, $rec, $score, $versions, $submission, $actor, $capacity): array {
            $evaluationId = $this->recorder->record($result, self::OPERATION, 'underwriting_case', $c->id, $c->tenant_id);
            $c->update(['recommendation' => $rec['recommendation'], 'risk_score' => $score['score'], 'risk_band' => $score['band'], 'risk_factors' => $score['factors'],
                'rule_set_versions' => $versions, 'engine_evaluation_id' => $evaluationId, 'evaluated_at' => now()]);
            $summary = ['engine_evaluation_id' => $evaluationId, 'recommendation' => $rec['recommendation'], 'reasons' => $rec['reasons'], 'conditions' => $rec['conditions'],
                'requested_items' => $rec['requested_items'], 'risk_score' => $score['score'], 'risk_band' => $score['band'], 'risk_factors' => $score['factors'],
                'rule_set_versions' => $versions, 'inputs_hash' => $result->inputsHash, 'proposal_submission_id' => $submission->id, 'decision_is_human' => true]
                + ($capacity ? ['capacity' => $capacity] : []);
            $this->audit->record('underwriting.evaluated', 'underwriting_case', $c->id, ['engine_evaluation_id' => $evaluationId, 'recommendation' => $rec['recommendation'],
                'risk_score' => $score['score'], 'rule_set_versions' => $versions, 'actor' => $actor?->id]);

            return $summary;
        });
    }

    /**
     * REQ-CAT-002 capacity factor (LOCK-019): only when the snapshot carries a sum insured; NOT_CONFIGURED (no limit set) adds nothing.
     *
     * @return array<string, mixed>|null
     */
    private function capacity(UnderwritingCase $c, array $snap, \DateTimeImmutable $at): ?array
    {
        $risk = (array) ($snap['risk_facts'] ?? []);
        $terms = (array) ($snap['terms'] ?? []);
        $sum = $risk['sum_insured_minor'] ?? $terms['sum_insured_minor'] ?? null;
        if (! is_numeric($sum) || ! class_exists(\App\Application\Accumulation\CapacityService::class)) {
            return null;
        }
        try {
            $check = app(\App\Application\Accumulation\CapacityService::class)->check((string) $c->tenant_id, [
                'location' => is_array($risk['address'] ?? null) ? $risk['address'] + $risk : $risk, 'zone_id' => null,
                'peril_code' => (string) ($risk['peril_code'] ?? 'ALL'), 'sum_insured_minor' => (int) $sum,
                'currency' => (string) ($terms['currency'] ?? $snap['currency'] ?? 'XAF'), 'line_code' => $snap['line_code'] ?? null,
                'date' => $at->format('Y-m-d'), 'subject_type' => 'underwriting_case', 'subject_id' => $c->id,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $check['result'] === \App\Application\Accumulation\CapacityService::NOT_CONFIGURED ? null : $check;
    }

    /** @return array{0: list<array{rule: RuleDefinition, unknown: bool, missing: list<string>}>, 1: list<array<string, mixed>>} */
    private function run(array $definitions, array $facts): array
    {
        $fired = [];
        $trace = [];
        foreach ($definitions as $def) {
            $ev = $this->evaluator->evaluate($def, $facts);
            $fired = [...$fired, ...$ev['fired']];
            $trace = [...$trace, ...$ev['trace']];
        }

        return [$fired, $trace];
    }

    /** Facts from the immutable submission snapshot (same key conventions as the proposal BIND gate). */
    private function facts(array $snap, array $flags, \DateTimeImmutable $at): array
    {
        $facts = [];
        foreach ((array) ($snap['risk_facts'] ?? []) as $k => $v) {
            $facts['risk.'.$k] = $v;
            $facts[$k] = $v;
        }
        foreach ((array) ($snap['answers'] ?? []) as $k => $v) {
            $facts['proposal.answers.'.$k] = $v;
        }
        $terms = (array) ($snap['terms'] ?? []);

        return $facts + array_filter([
            'proposal.total_minor' => isset($terms['total_minor']) ? (int) $terms['total_minor'] : null,
            'proposal.premium_minor' => isset($terms['premium_minor']) ? (int) $terms['premium_minor'] : null,
            'proposal.referral_flags' => $flags,
            'proposal.referral_flag_count' => count($flags),
            'proposal.kyc_status' => is_array($snap['kyc'] ?? null) ? ($snap['kyc']['status'] ?? null) : ($snap['kyc'] ?? null),
            'context.today' => $at->format('Y-m-d'),
            'context.line_code' => isset($snap['line_code']) ? strtoupper((string) $snap['line_code']) : null,
            'context.carrier_id' => $snap['carrier_id'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
