<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Import;

use App\Application\Import\ImportPipeline;
use App\Application\Import\ImportTargetRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\Import\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-IMP-001 — generic import API: upload → map → (validate, duplicates, preview) → submit → approve/reject → import.
 * Batches are scoped to the caller's tenant; approval is maker-checker through ApprovalService.
 */
final class ImportBatchController
{
    public function __construct(
        private readonly ImportPipeline $pipeline,
        private readonly ImportTargetRegistry $targets,
        private readonly TenantContext $tenant,
    ) {}

    public function targets(): JsonResponse
    {
        return response()->json(['data' => collect($this->targets->options())->map(fn ($label, $key) => [
            'key' => $key, 'label' => $label, 'fields' => $this->targets->get($key)->fields(),
        ])->values()]);
    }

    public function index(Request $r): JsonResponse
    {
        $q = ImportBatch::where('tenant_id', $this->tenant->id())->when($r->query('target'), fn ($q, $t) => $q->where('target', $t))
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->limit(100);

        return response()->json(['data' => $q->get()->map(fn ($b) => $this->present($b, false))]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate(['target' => 'required|string|max:64', 'params' => 'nullable|array', 'mapping' => 'nullable|array',
            'file' => 'required|file|max:20480|mimes:csv,txt,xlsx,json']);
        $file = $r->file('file');
        $batch = $this->pipeline->upload($d['target'], $d['params'] ?? [], $file->getRealPath(), $file->getClientOriginalName(), $r->user(),
            $d['mapping'] ?? [], $this->tenant->id());

        return response()->json(['data' => $this->present($batch)], 201);
    }

    public function show(string $batch): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($batch))]);
    }

    public function map(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['mapping' => 'required|array']);

        return response()->json(['data' => $this->present($this->pipeline->map($this->find($batch), $d['mapping']))]);
    }

    public function submit(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present($this->pipeline->submit($this->find($batch), $r->user(), $d['reason'] ?? null))]);
    }

    public function approve(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present($this->pipeline->approve($this->find($batch), $r->user(), $d['note'] ?? null))]);
    }

    public function reject(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['note' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->present($this->pipeline->reject($this->find($batch), $r->user(), $d['note']))]);
    }

    public function cancel(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present($this->pipeline->cancel($this->find($batch), $r->user(), $d['reason'] ?? 'Cancelled'))]);
    }

    private function find(string $id): ImportBatch
    {
        return ImportBatch::where('tenant_id', $this->tenant->id())->whereKey($id)->firstOrFail();
    }

    private function present(ImportBatch $b, bool $full = true): array
    {
        $out = $b->only(['id', 'target', 'target_params', 'format', 'filename', 'status', 'approval_request_id', 'created_by', 'approved_by', 'imported_at', 'imported_count', 'created_at']);
        $out['summary'] = ['rows' => count($b->raw_rows ?? []), 'new' => $b->report['valid'] ?? 0, 'duplicates' => count($b->report['duplicates'] ?? []),
            'errors' => count($b->report['errors'] ?? []), 'needs_mapping' => $b->report['needs_mapping'] ?? []];
        if ($full) {
            $out += ['source_columns' => $b->source_columns, 'mapping' => $b->mapping, 'errors' => $b->report['errors'] ?? [],
                'duplicates' => $b->report['duplicates'] ?? [], 'preview' => $b->report['preview'] ?? [], 'result' => $b->result];
        }

        return $out;
    }
}
