<?php

declare(strict_types=1);

namespace App\Application\Claims\Types\Http;

use App\Application\Claims\Types\ClaimReportingService;
use App\Application\Claims\Types\ClaimTypeCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Agent C8 — REQ-CLM-007 claim types per line/product, insurer overrides (maker-checker) and the late-claim approval. */
final class ClaimTypeController
{
    public function __construct(private TenantContext $tenant, private ClaimTypeCatalogue $types, private ClaimReportingService $reporting) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['line_code' => 'required|string|max:32', 'product_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->types->effective($this->tenant->id(), $d['line_code'], $d['product_id'] ?? null),
            'meta' => ['notice' => ClaimTypeCatalogue::DEADLINE_NOTICE]]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|max:64', 'line_code' => 'required|string|max:32', 'product_id' => 'nullable|uuid',
            'labels' => 'sometimes|array', 'labels.en' => 'required_with:labels|string|max:160', 'labels.fr' => 'required_with:labels|string|max:160',
            'applicable_coverages' => 'sometimes|array|max:50', 'applicable_coverages.*' => 'string|max:64',
            'reporting_deadline_days' => 'sometimes|nullable|integer|min:1|max:3650', 'evidence_pack_code' => 'sometimes|nullable|string|max:96',
            'required_evidence_codes' => 'sometimes|array|max:50', 'required_evidence_codes.*' => 'string|max:64',
            'default_reserve_minor' => 'sometimes|nullable|integer|min:0', 'currency' => 'sometimes|nullable|string|size:3',
            'is_default' => 'sometimes|boolean', 'effective_from' => 'sometimes|date', 'effective_until' => 'sometimes|nullable|date|after_or_equal:effective_from',
        ]);

        return response()->json(['data' => $this->types->draft($this->tenant->id(), $d, $r->user())], 201);
    }

    public function approve(Request $r, string $version): JsonResponse
    {
        return response()->json(['data' => $this->types->approve($this->tenant->id(), $version, $r->user())]);
    }

    public function reporting(string $claim): JsonResponse
    {
        $c = $this->claim($claim);

        return response()->json(['data' => $this->reporting->check($c->id), 'meta' => ['notice' => ClaimTypeCatalogue::DEADLINE_NOTICE]]);
    }

    public function recommend(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate(['recommendation' => 'required|string|in:ACCEPT,REJECT', 'rationale' => 'required|string|max:4000']);

        return response()->json(['data' => $this->reporting->recommend($this->claim($claim), $d['recommendation'], $d['rationale'], $r->user())]);
    }

    public function decide(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|string|in:APPROVE,REJECT', 'rationale' => 'required|string|max:4000']);

        return response()->json(['data' => $this->reporting->decide($this->claim($claim), $d['decision'], $d['rationale'], $r->user())]);
    }

    private function claim(string $id): Claim
    {
        return Claim::where('tenant_id', $this->tenant->id())->whereKey($id)->firstOrFail();
    }
}
