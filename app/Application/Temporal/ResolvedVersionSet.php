<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use Carbon\CarbonImmutable;

final class ResolvedVersionSet
{
    /** @param list<ResolvedVersion> $versions */
    public function __construct(
        public readonly string $id,
        public readonly string $operation,
        public readonly CarbonImmutable $referenceAt,
        public readonly CarbonImmutable $recordedAsOf,
        public readonly array $versions,
        public readonly string $versionsHash,
    ) {}

    /** ICE §0.2 envelope, engine = TEMPORAL (engine_evaluations persistence belongs to REQ-ENG-001). */
    public function toEngineResult(): array
    {
        $map = [];
        foreach ($this->versions as $v) {
            $map[$v->artifactType] = $v->id;
        }

        return ['engine' => 'TEMPORAL', 'outcome' => 'RESOLVED', 'reference_at' => $this->referenceAt->toIso8601String(),
            'recorded_as_of' => $this->recordedAsOf->toIso8601String(), 'inputs_hash' => $this->versionsHash,
            'trace' => array_map(fn (ResolvedVersion $v) => ['rule_code' => 'TEMPORAL_RESOLVE', 'rule_version' => $v->version, 'source_table' => $v->sourceTable,
                'source_id' => $v->id, 'condition' => 'valid_at_reference', 'input_values' => $v->key, 'result' => true, 'message_key' => null], $this->versions),
            'resolved_versions' => $map, 'blocking' => false, 'reasons' => [], 'warnings' => []];
    }
}
