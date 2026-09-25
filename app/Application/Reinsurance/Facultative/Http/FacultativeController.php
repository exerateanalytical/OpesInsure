<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Facultative\Http;

use App\Application\Reinsurance\Facultative\FacultativePlacementService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Batch 14C — REQ-REI-003 facultative placements (slip, participants' lines, maker-checker binding). */
final class FacultativeController
{
    public function __construct(private readonly TenantContext $tenant, private readonly FacultativePlacementService $service) {}

    public function index(Request $r): JsonResponse
    {
        $data = $r->validate(['policy_id' => 'sometimes|uuid']);

        return response()->json(['data' => $this->service->list($this->tenant->id(), $data['policy_id'] ?? null)]);
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate(['policy_id' => 'required|uuid', 'reference' => 'sometimes|string|max:64', 'risk_description' => 'required|string|max:1000',
            'currency' => 'sometimes|string|size:3', 'sum_insured_minor' => 'sometimes|integer|min:1', 'premium_minor' => 'sometimes|integer|min:0',
            'placed_share_percent' => 'required|numeric|gt:0|max:100', 'commission_percent' => 'sometimes|numeric', 'brokerage_percent' => 'sometimes|numeric',
            'tax_percent' => 'sometimes|numeric', 'terms' => 'sometimes|array', 'period_from' => 'required|date', 'period_to' => 'required|date',
            'broker_id' => 'nullable|uuid', 'participants' => 'required|array|min:1', 'participants.*.reinsurer_id' => 'required|uuid',
            'participants.*.offered_percent' => 'required|numeric|gt:0|max:100', 'participants.*.written_percent' => 'nullable|numeric|min:0|max:100',
            'participants.*.is_lead' => 'sometimes|boolean']);

        return response()->json(['data' => $this->service->create($this->tenant->id(), $data)], 201);
    }

    public function show(string $placement): JsonResponse
    {
        return response()->json(['data' => $this->service->show($this->tenant->id(), $placement)]);
    }

    public function lines(Request $r, string $placement): JsonResponse
    {
        $data = $r->validate(['lines' => 'required|array|min:1', 'lines.*.reinsurer_id' => 'required|uuid', 'lines.*.written_percent' => 'required|numeric|min:0|max:100']);

        return response()->json(['data' => $this->service->recordWrittenLines($this->tenant->id(), $placement, $data['lines'])]);
    }

    public function submit(string $placement): JsonResponse
    {
        return response()->json(['data' => $this->service->submit($this->tenant->id(), $placement)]);
    }

    public function approve(Request $r, string $placement): JsonResponse
    {
        $data = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->service->approve($this->tenant->id(), $placement, $r->user(), $data['reason'])]);
    }

    public function reject(Request $r, string $placement): JsonResponse
    {
        $data = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->service->reject($this->tenant->id(), $placement, $r->user(), $data['reason'])]);
    }
}
