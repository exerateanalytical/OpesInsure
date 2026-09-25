<?php

declare(strict_types=1);

namespace App\Application\ReferenceDatasets\Http;

use App\Application\ReferenceDatasets\ReferenceDatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Versioned institutional datasets (public holidays, hazard zones): /v1/reference-datasets. */
final class ReferenceDatasetController
{
    public function __construct(private readonly ReferenceDatasetService $datasets) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['kind' => ['nullable', Rule::in(ReferenceDatasetService::KINDS)], 'jurisdiction' => 'nullable|string|size:2', 'status' => 'nullable|in:DRAFT,ACTIVE,RETIRED']);

        return response()->json(['data' => DB::table('reference_datasets')
            ->when($d['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($d['jurisdiction'] ?? null, fn ($q, $v) => $q->where('jurisdiction', $v))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('kind')->orderBy('code')->orderByDesc('version')->get()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->datasets->show($id)]);
    }

    public function store(Request $r): JsonResponse
    {
        $kind = $r->input('kind');
        $d = $r->validate([
            'kind' => ['required', Rule::in(ReferenceDatasetService::KINDS)], 'jurisdiction' => 'required|string|size:2',
            'code' => 'required|string|max:80|regex:/^[A-Z][A-Z0-9_]*$/', 'source_name' => 'required|string|max:255',
            'source_reference' => 'nullable|string|max:255', 'source_url' => 'nullable|url|max:500',
            'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'verification_status' => 'nullable|in:UNVERIFIED,DISPUTED', 'verification_note' => 'nullable|string|max:2000',
            'entries' => 'required|array|min:1|max:5000',
        ] + ($kind === 'PUBLIC_HOLIDAYS' ? [
            'entries.*.date' => 'required|date_format:Y-m-d', 'entries.*.label' => 'required|string|max:160',
            'entries.*.holiday_type' => 'nullable|in:PUBLIC,RELIGIOUS_MOVABLE,DECREED', 'entries.*.legal_reference' => 'nullable|string|max:255',
        ] : [
            'entries.*.hazard_type' => 'required|string|max:32|regex:/^[A-Z][A-Z0-9_]*$/', 'entries.*.zone_code' => 'required|string|max:64',
            'entries.*.name' => 'required|string|max:160', 'entries.*.admin_area_code' => 'nullable|string|max:64',
            'entries.*.hazard_level' => 'nullable|string|max:24', 'entries.*.geometry' => 'nullable|array', 'entries.*.attributes' => 'nullable|array',
        ]));

        return response()->json(['data' => $this->datasets->draft($d, $r->user())], 201);
    }

    public function activate(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['verification_status' => 'nullable|in:UNVERIFIED,VERIFIED,DISPUTED', 'verification_note' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->datasets->activate($id, $r->user(), $d['verification_status'] ?? null, $d['verification_note'] ?? null)]);
    }

    public function retire(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->datasets->retire($id, $d['reason'])]);
    }
}
