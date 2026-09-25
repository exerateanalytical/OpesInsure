<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Import;

use App\Application\Import\Legacy\LegacyMigrationPipeline;
use App\Domain\Tenancy\TenantContext;
use App\Models\Import\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-IMP-002 — legacy migration API: stage → validate → dry-run → reconcile → submit → approve/reject → commit;
 * rollback of an uncommitted batch. Tenant-scoped; approval is maker-checker (legacy_migration.commit).
 */
final class LegacyMigrationController
{
    public function __construct(private readonly LegacyMigrationPipeline $pipeline, private readonly TenantContext $tenant) {}

    public function entities(): JsonResponse
    {
        return response()->json(['data' => collect($this->pipeline->options())->map(fn ($label, $key) => [
            'key' => $key, 'label' => $label, 'fields' => $this->pipeline->adapter($key)->fields(),
        ])->values()]);
    }

    public function index(Request $r): JsonResponse
    {
        $q = ImportBatch::where(['tenant_id' => $this->tenant->id(), 'pipeline' => LegacyMigrationPipeline::PIPELINE])
            ->when($r->query('entity'), fn ($q, $t) => $q->where('target', $t))->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->limit(100);

        return response()->json(['data' => $q->get()->map(fn ($b) => $this->present($b, false))]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate(['entity' => 'required|string|max:64', 'integration_client_id' => 'required|uuid|exists:integration_clients,id', 'mapping' => 'nullable|array',
            'control_totals' => 'nullable|array', 'control_totals.count' => 'nullable|integer|min:0', 'control_totals.amount_minor' => 'nullable|integer',
            'file' => 'required|file|max:20480|mimes:csv,txt,xlsx,json']);
        $file = $r->file('file');
        $batch = $this->pipeline->stage($d['entity'], $d['integration_client_id'], $file->getRealPath(), $file->getClientOriginalName(), $r->user(),
            $this->tenant->id(), $d['mapping'] ?? [], $d['control_totals'] ?? null);

        return response()->json(['data' => $this->present($batch)], 201);
    }

    public function show(string $batch): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($batch))]);
    }

    public function map(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['mapping' => 'required|array']);

        return $this->ok($this->pipeline->map($this->find($batch), $d['mapping']));
    }

    public function validateBatch(string $batch): JsonResponse
    {
        return $this->ok($this->pipeline->validate($this->find($batch)));
    }

    public function dryRun(Request $r, string $batch): JsonResponse
    {
        return $this->ok($this->pipeline->dryRun($this->find($batch), $r->user()));
    }

    public function reconcile(string $batch): JsonResponse
    {
        return $this->ok($this->pipeline->reconcile($this->find($batch)));
    }

    public function submit(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['reason' => 'nullable|string|max:500']);

        return $this->ok($this->pipeline->submit($this->find($batch), $r->user(), $d['reason'] ?? null));
    }

    public function approve(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);

        return $this->ok($this->pipeline->approve($this->find($batch), $r->user(), $d['note'] ?? null));
    }

    public function reject(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['note' => 'required|string|min:3|max:500']);

        return $this->ok($this->pipeline->reject($this->find($batch), $r->user(), $d['note']));
    }

    public function commit(Request $r, string $batch): JsonResponse
    {
        return $this->ok($this->pipeline->commit($this->find($batch), $r->user()));
    }

    public function rollback(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);

        return $this->ok($this->pipeline->rollback($this->find($batch), $r->user(), $d['reason']));
    }

    private function ok(ImportBatch $b): JsonResponse
    {
        return response()->json(['data' => $this->present($b)]);
    }

    private function find(string $id): ImportBatch
    {
        return ImportBatch::where(['tenant_id' => $this->tenant->id(), 'pipeline' => LegacyMigrationPipeline::PIPELINE])->whereKey($id)->firstOrFail();
    }

    private function present(ImportBatch $b, bool $full = true): array
    {
        $out = $b->only(['id', 'target', 'source_system', 'format', 'filename', 'status', 'approval_request_id', 'created_by', 'approved_by', 'imported_at',
            'imported_count', 'reconciled_at', 'rolled_back_at', 'rolled_back_by', 'created_at']);
        $out['entity'] = $b->target;
        $out['summary'] = ['rows' => count($b->raw_rows ?? []), 'valid' => $b->report['valid'] ?? 0, 'already_migrated' => count($b->report['already_migrated'] ?? []),
            'errors' => count($b->report['errors'] ?? []), 'needs_mapping' => $b->report['needs_mapping'] ?? [], 'balanced' => $b->reconciliation['balanced'] ?? null];
        if ($full) {
            $out += ['source_columns' => $b->source_columns, 'mapping' => $b->mapping, 'control_totals' => $b->control_totals, 'report' => $b->report,
                'dry_run' => $b->dry_run, 'reconciliation' => $b->reconciliation, 'result' => $b->result];
        }

        return $out;
    }
}
