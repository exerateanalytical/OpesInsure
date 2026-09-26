<?php

declare(strict_types=1);

namespace App\Application\PrivateOnboarding\Http;

use App\Application\PrivateOnboarding\PrivateOnboardingService;
use App\Application\PrivateOnboarding\PrivateOnboardingTemplates;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Gap Closure Pack file 11 — dataset catalogue, CSV import templates, staged records, review, per-organization readiness. Upload goes through /api/v1/imports (target private_onboarding). */
final class PrivateOnboardingController
{
    public function __construct(private readonly TenantContext $tenant, private readonly PrivateOnboardingService $service) {}

    public function datasets(): JsonResponse
    {
        return response()->json(['data' => PrivateOnboardingTemplates::catalogue(), 'meta' => ['purpose' => PrivateOnboardingTemplates::pack()['purpose'] ?? null]]);
    }

    public function template(string $dataset): Response
    {
        $dataset = strtoupper($dataset);

        return response(PrivateOnboardingTemplates::csvTemplate($dataset), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.strtolower($dataset).'_template.csv"',
        ]);
    }

    public function records(Request $r): JsonResponse
    {
        $d = $r->validate(['dataset' => 'nullable|string|max:40', 'organization_type' => ['nullable', Rule::in(PrivateOnboardingTemplates::ORGANIZATION_TYPES)],
            'organization_id' => 'nullable|uuid', 'review_status' => 'nullable|in:RECEIVED,ACCEPTED,REJECTED', 'per_page' => 'nullable|integer|min:1|max:200']);

        return response()->json(DB::table('tenant_onboarding_records')->where(fn ($q) => $q->where('tenant_id', $this->tenant->id())->orWhereNull('tenant_id'))
            ->when($d['dataset'] ?? null, fn ($q, $v) => $q->where('dataset', strtoupper($v)))
            ->when($d['organization_type'] ?? null, fn ($q, $v) => $q->where('organization_type', $v))
            ->when($d['organization_id'] ?? null, fn ($q, $v) => $q->where('organization_id', $v))
            ->when($d['review_status'] ?? null, fn ($q, $v) => $q->where('review_status', $v))
            ->orderByDesc('created_at')->paginate((int) ($d['per_page'] ?? 50)));
    }

    public function review(Request $r, string $record): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|in:ACCEPTED,REJECTED', 'note' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->service->review($record, $d['decision'], $d['note'] ?? null, $r->user(), $this->tenant->id())]);
    }

    public function readiness(Request $r): JsonResponse
    {
        $d = $r->validate(['organization_type' => ['required', Rule::in(PrivateOnboardingTemplates::ORGANIZATION_TYPES)], 'organization_id' => 'nullable|uuid|required_unless:organization_type,TENANT']);

        return response()->json(['data' => $this->service->readiness($d['organization_type'], $d['organization_id'] ?? null)]);
    }
}
