<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Partners;

use App\Application\Import\ImportPipeline;
use App\Application\Partners\BulkOnboarding\BrokerOnboardingService;
use App\Models\Import\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S9 — bulk broker onboarding API (platform tenant; tenant.manage + identity.invite):
 * template → upload (validate + per-row preview) → submit (maker) → approve/reject (another admin) → result report.
 */
final class BrokerOnboardingController
{
    public function __construct(private readonly BrokerOnboardingService $onboarding, private readonly ImportPipeline $pipeline) {}

    public function template(Request $r): Response
    {
        $this->guard($r);
        if ($r->query('format') === 'xlsx') {
            return response()->download($this->onboarding->templateXlsx(), 'broker-onboarding-template.xlsx')->deleteFileAfterSend();
        }

        return response($this->onboarding->templateCsv(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="broker-onboarding-template.csv"']);
    }

    public function index(Request $r): JsonResponse
    {
        $this->guard($r);

        return response()->json(['data' => $this->onboarding->query()->latest()->limit(100)->get()->map(fn ($b) => $this->present($b, false))]);
    }

    public function store(Request $r): JsonResponse
    {
        $this->guard($r);
        $d = $r->validate(['file' => 'required|file|max:10240|mimes:csv,txt,xlsx']);
        $file = $d['file'];

        return response()->json(['data' => $this->present($this->onboarding->upload($file->getRealPath(), $file->getClientOriginalName(), $r->user()))], 201);
    }

    public function show(Request $r, string $batch): JsonResponse
    {
        $this->guard($r);

        return response()->json(['data' => $this->present($this->find($batch))]);
    }

    public function submit(Request $r, string $batch): JsonResponse
    {
        $this->guard($r);
        $d = $r->validate(['reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present($this->pipeline->submit($this->find($batch), $r->user(), $d['reason'] ?? null))]);
    }

    public function approve(Request $r, string $batch): JsonResponse
    {
        $this->guard($r);
        $d = $r->validate(['note' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present($this->pipeline->approve($this->find($batch), $r->user(), $d['note'] ?? null))]);
    }

    public function reject(Request $r, string $batch): JsonResponse
    {
        $this->guard($r);
        $d = $r->validate(['note' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->present($this->pipeline->reject($this->find($batch), $r->user(), $d['note']))]);
    }

    public function cancel(Request $r, string $batch): JsonResponse
    {
        $this->guard($r);
        $d = $r->validate(['reason' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->present($this->pipeline->cancel($this->find($batch), $r->user(), $d['reason'] ?? 'Cancelled'))]);
    }

    public function report(Request $r, string $batch): Response
    {
        $this->guard($r);
        $b = $this->find($batch);

        return response($this->onboarding->reportCsv($b), 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="broker-onboarding-'.substr($b->id, 0, 8).'.csv"']);
    }

    private function guard(Request $r): void
    {
        abort_unless($this->onboarding->allows($r->user()), 403, __('security.platform_only'));
    }

    private function find(string $id): ImportBatch
    {
        return $this->onboarding->query()->whereKey($id)->firstOrFail();
    }

    private function present(ImportBatch $b, bool $full = true): array
    {
        $out = $b->only(['id', 'filename', 'format', 'status', 'approval_request_id', 'created_by', 'approved_by', 'imported_at', 'imported_count', 'created_at']);
        $out['summary'] = ['rows' => count($b->raw_rows ?? []), 'new' => $b->report['valid'] ?? 0, 'duplicates' => count($b->report['duplicates'] ?? []),
            'errors' => count($b->report['errors'] ?? []), 'created' => count($b->result['created'] ?? []), 'failed' => count($b->result['failed'] ?? []),
            'needs_mapping' => $b->report['needs_mapping'] ?? []];
        if ($full) {
            $out['rows'] = $this->onboarding->rows($b);
        }

        return $out;
    }
}
