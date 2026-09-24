<?php

declare(strict_types=1);

namespace App\Application\Engines;

use App\Application\Audit\AuditWriter;

/**
 * REQ-ENG-001: the common, deterministic result envelope every control engine returns
 * (INSURANCE_CONTROL_ENGINES_SPEC_V1 §0.2). Persist it with EngineEvaluationRecorder.
 */
final class EngineResult
{
    public const ENGINES = ['TEMPORAL', 'COVERAGE', 'AUTHORITY', 'REGCHANGE', 'AML', 'CASE', 'ACCUMULATION', 'DOCUMENT', 'RATING', 'FRAUD'];

    /**
     * @param  list<array{rule_code: string, rule_version?: string|int|null, source_table?: string|null, source_id?: string|null, condition?: string|null, input_values?: array, result: string|bool, message_key?: string|null}>  $trace
     * @param  array<string, string>  $resolvedVersions
     * @param  list<string>  $reasons
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $engine,
        public readonly string $outcome,
        public readonly \DateTimeImmutable $referenceAt,
        public readonly \DateTimeImmutable $recordedAsOf,
        public readonly string $inputsHash,
        public readonly array $trace = [],
        public readonly array $resolvedVersions = [],
        public readonly bool $blocking = false,
        public readonly array $reasons = [],
        public readonly array $warnings = [],
    ) {
        if (! in_array($engine, self::ENGINES, true)) {
            throw new \InvalidArgumentException("Unknown engine [{$engine}].");
        }
        if ($outcome === '' || strlen($outcome) > 32) {
            throw new \InvalidArgumentException('Outcome must be 1..32 characters.');
        }
        if (! preg_match('/^[0-9a-f]{64}$/', $inputsHash)) {
            throw new \InvalidArgumentException('inputs_hash must be a lowercase sha256 hex digest.');
        }
        foreach ($trace as $step) {
            if (! isset($step['rule_code']) || ! array_key_exists('result', $step)) {
                throw new \InvalidArgumentException('Every trace step needs rule_code and result.');
            }
        }
    }

    /** sha256 of the canonical (recursively key-sorted) JSON of the inputs. */
    public static function hashInputs(array $inputs): string
    {
        return hash('sha256', AuditWriter::canonicalJson($inputs));
    }

    /** Normalised trace: every step carries the full §0.2 key set, in a fixed order, so equal inputs give byte-identical JSON. */
    public function normalisedTrace(): array
    {
        return array_map(static fn (array $s) => [
            'rule_code' => $s['rule_code'],
            'rule_version' => $s['rule_version'] ?? null,
            'source_table' => $s['source_table'] ?? null,
            'source_id' => $s['source_id'] ?? null,
            'condition' => $s['condition'] ?? null,
            'input_values' => $s['input_values'] ?? [],
            'result' => $s['result'],
            'message_key' => $s['message_key'] ?? null,
        ], $this->trace);
    }

    public function toArray(): array
    {
        $versions = $this->resolvedVersions;
        ksort($versions);

        return [
            'engine' => $this->engine,
            'outcome' => $this->outcome,
            'reference_at' => $this->referenceAt->format(DATE_ATOM),
            'recorded_as_of' => $this->recordedAsOf->format(DATE_ATOM),
            'inputs_hash' => $this->inputsHash,
            'trace' => $this->normalisedTrace(),
            'resolved_versions' => $versions,
            'blocking' => $this->blocking,
            'reasons' => array_values($this->reasons),
            'warnings' => array_values($this->warnings),
        ];
    }
}
