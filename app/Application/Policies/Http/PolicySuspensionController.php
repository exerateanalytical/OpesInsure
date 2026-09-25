<?php

declare(strict_types=1);

namespace App\Application\Policies\Http;

use App\Application\Policies\Suspension\PolicySuspension;
use App\Application\Policies\Suspension\PolicySuspensionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-POL-006 / WF-046 / WF-047 — manual suspension and the maker-checker reinstatement queue. */
final class PolicySuspensionController
{
    public function __construct(private readonly PolicySuspensionService $suspensions) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function policy(string $id): Policy
    {
        return Policy::where('tenant_id', $this->tenant())->findOrFail($id);
    }

    public function suspend(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'notes' => 'nullable|string|max:2000', 'effective_at' => 'nullable|date']);

        return response()->json(['data' => $this->suspensions->suspend($this->policy($policy), $d['reason_code'], $r->user(), [
            'source' => 'MANUAL', 'notes' => $d['notes'] ?? null, 'effective_at' => $d['effective_at'] ?? null,
        ])], 201);
    }

    public function requestReinstatement(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->suspensions->requestReinstatement($this->policy($policy), $d['reason_code'], $r->user(), $d['notes'] ?? null)], 201);
    }

    public function reinstate(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64']);

        return response()->json(['data' => $this->suspensions->reinstate($this->policy($policy), $d['reason_code'], $r->user())]);
    }

    public function rejectReinstatement(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->suspensions->rejectReinstatement($this->policy($policy), $d['reason'], $r->user())]);
    }

    public function queue(Request $r): JsonResponse
    {
        $r->validate(['status' => ['sometimes', Rule::in(PolicySuspension::OPEN_STATES)]]);
        $status = $r->query('status');

        return response()->json(['data' => $this->suspensions->queue($this->tenant(), is_string($status) ? $status : null)]);
    }
}
