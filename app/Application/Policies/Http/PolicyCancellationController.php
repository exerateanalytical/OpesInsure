<?php

declare(strict_types=1);

namespace App\Application\Policies\Http;

use App\Application\Policies\Cancellation\CancellationService;
use App\Application\Policies\Cancellation\PolicyCancellation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-CAN-001 / WF-044 — cancellation request, review, decision, refund preview and the approver queue (BRK-062). */
final class PolicyCancellationController
{
    public const QUEUE_STATES = ['REQUESTED', 'UNDER_REVIEW'];

    public function __construct(private readonly CancellationService $cancellations) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function policy(string $id): Policy
    {
        return Policy::where('tenant_id', $this->tenant())->findOrFail($id);
    }

    private function case(string $id): PolicyCancellation
    {
        return PolicyCancellation::where('tenant_id', $this->tenant())->findOrFail($id);
    }

    public function preview(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['effective_at' => 'required|date', 'initiated_by' => ['required', Rule::in(CancellationService::INITIATORS)]]);

        return response()->json(['data' => $this->cancellations->quote($this->policy($policy), $d['effective_at'], $d['initiated_by'])]);
    }

    public function store(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate([
            'effective_at' => 'required|date', 'reason_code' => 'required|string|max:64',
            'initiated_by' => ['required', Rule::in(CancellationService::INITIATORS)], 'notes' => 'nullable|string|max:2000',
        ]);

        return response()->json(['data' => $this->cancellations->request($this->policy($policy), $d, $r->user())], 201);
    }

    /** Approver queue: open cancellations for the tenant, oldest first; ?status= narrows to one state. */
    public function index(Request $r): JsonResponse
    {
        $r->validate(['status' => ['sometimes', Rule::in(['REQUESTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED'])]]);
        $status = $r->query('status');
        $rows = PolicyCancellation::where('tenant_id', $this->tenant())
            ->whereIn('status', is_string($status) ? [$status] : self::QUEUE_STATES)
            ->orderBy('created_at')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }

    public function show(string $cancellation): JsonResponse
    {
        return response()->json(['data' => $this->case($cancellation)]);
    }

    public function review(Request $r, string $cancellation): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->cancellations->review($this->case($cancellation), $r->user(), $d['note'] ?? null)]);
    }

    public function approve(Request $r, string $cancellation): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->cancellations->approve($this->case($cancellation), $r->user(), $d['note'] ?? null)]);
    }

    public function reject(Request $r, string $cancellation): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->cancellations->reject($this->case($cancellation), $r->user(), $d['reason'])]);
    }
}
