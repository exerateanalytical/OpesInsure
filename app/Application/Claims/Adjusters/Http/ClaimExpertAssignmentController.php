<?php

declare(strict_types=1);

namespace App\Application\Claims\Adjusters\Http;

use App\Application\Claims\Adjusters\ExpertAssignmentService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-CLM-009 — insurer side: /v1/claims/{id}/assignments (handler + expert history), appoint, review, cancel. */
final class ClaimExpertAssignmentController
{
    public function __construct(private readonly ExpertAssignmentService $svc, private readonly TenantContext $tenant) {}

    public function index(string $id): JsonResponse
    {
        return response()->json(['data' => $this->svc->forClaim($this->claim($id))]);
    }

    public function store(Request $r, string $id): JsonResponse
    {
        $d = $r->validate([
            'provider_id' => 'required|uuid', 'network_id' => 'required|uuid', 'fee_service_id' => 'required|uuid',
            'instructions' => 'nullable|string|max:5000',
        ]);

        return response()->json(['data' => $this->svc->assign($this->claim($id), $d, $r->user())], 201);
    }

    public function show(string $id, string $assignment): JsonResponse
    {
        $a = $this->svc->find($this->tenant->id(), $this->own($id, $assignment));
        $a->history = $this->svc->history($assignment);

        return response()->json(['data' => $a]);
    }

    public function acceptReport(Request $r, string $id, string $assignment): JsonResponse
    {
        $d = $r->validate(['notes' => 'nullable|string|max:5000']);

        return response()->json(['data' => $this->svc->acceptReport($this->tenant->id(), $this->own($id, $assignment), $d['notes'] ?? null, $r->user())]);
    }

    public function returnReport(Request $r, string $id, string $assignment): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:5000']);

        return response()->json(['data' => $this->svc->returnReport($this->tenant->id(), $this->own($id, $assignment), $d['reason'], $r->user())]);
    }

    public function cancel(Request $r, string $id, string $assignment): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:5000']);

        return response()->json(['data' => $this->svc->cancel($this->tenant->id(), $this->own($id, $assignment), $d['reason'], $r->user())]);
    }

    private function own(string $id, string $assignment): string
    {
        $this->claim($id);
        abort_if($this->svc->find($this->tenant->id(), $assignment)->claim_id !== $id, 404);

        return $assignment;
    }

    private function claim(string $id): Claim
    {
        return Claim::where(['id' => $id, 'tenant_id' => $this->tenant->id()])->firstOrFail();
    }
}
