<?php

declare(strict_types=1);

namespace App\Application\Engines;

use App\Application\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-ENG-001: persists an EngineResult into the append-only engine_evaluations decision log
 * and mirrors a pointer into the audit_log hash chain (ICE §0.5).
 */
final class EngineEvaluationRecorder
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function record(EngineResult $result, string $operation, string $subjectType, ?string $subjectId, ?string $tenantId = null): string
    {
        $id = (string) Str::uuid();
        $data = $result->toArray();
        $correlation = substr((string) (rescue(fn () => request()->header('X-Request-Id'), null, false) ?: Str::uuid()), 0, 64);

        DB::transaction(function () use ($id, $data, $operation, $subjectType, $subjectId, $tenantId, $correlation) {
            DB::table('engine_evaluations')->insert([
                'id' => $id,
                'tenant_id' => $tenantId ?? $this->tenantId(),
                'engine' => $data['engine'],
                'operation' => $operation,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'outcome' => $data['outcome'],
                'blocking' => $data['blocking'],
                'reference_at' => $data['reference_at'],
                'recorded_as_of' => $data['recorded_as_of'],
                'inputs_hash' => $data['inputs_hash'],
                'resolved_versions' => json_encode($data['resolved_versions'] ?: new \stdClass, JSON_THROW_ON_ERROR),
                'trace' => json_encode($data['trace'], JSON_THROW_ON_ERROR),
                'reasons' => json_encode($data['reasons'], JSON_THROW_ON_ERROR),
                'warnings' => json_encode($data['warnings'], JSON_THROW_ON_ERROR),
                'correlation_id' => $correlation,
                'actor_id' => auth()->id(),
                'created_at' => now(),
            ]);
            $this->audit->record('engine.evaluation.recorded', 'engine_evaluation', $id, [
                'engine' => $data['engine'], 'operation' => $operation, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'outcome' => $data['outcome'], 'blocking' => $data['blocking'], 'inputs_hash' => $data['inputs_hash'],
            ]);
        });

        return $id;
    }

    /** Latest evaluations for a subject, newest first (decision log read side). */
    public function forSubject(string $subjectType, string $subjectId, ?string $engine = null, int $limit = 50): array
    {
        return DB::table('engine_evaluations')->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->when($engine, fn ($q) => $q->where('engine', $engine))
            ->orderByDesc('created_at')->limit($limit)->get()->all();
    }

    private function tenantId(): ?string
    {
        return app()->bound(\App\Domain\Tenancy\TenantContext::class)
            ? rescue(fn () => app(\App\Domain\Tenancy\TenantContext::class)->id(), null, false)
            : null;
    }
}
