<?php

declare(strict_types=1);

namespace App\Application\Accumulation\Http;

use App\Application\Accumulation\CapacityService;
use App\Application\Accumulation\CatastropheEventService;
use App\Application\Accumulation\ExposureService;
use App\Application\Accumulation\LargeLossNotifier;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Agent E11 — REQ-CAT-001 exposure / accumulation, REQ-CAT-002 capacity, REQ-CAT-003 catastrophe events + large loss. */
final class AccumulationController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ExposureService $exposure,
        private readonly CapacityService $capacity,
        private readonly CatastropheEventService $events,
        private readonly LargeLossNotifier $largeLoss,
    ) {}

    public function zones(): JsonResponse
    {
        return response()->json(['data' => $this->exposure->zones($this->tenant->id())]);
    }

    public function createZone(Request $r): JsonResponse
    {
        $data = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:191', 'country_code' => 'nullable|string|size:2',
            'geography_codes' => 'sometimes|array', 'geography_codes.*' => 'string|max:191', 'polygon' => 'nullable|array', 'polygon.*' => 'array|size:2', 'polygon.*.*' => 'numeric']);

        return response()->json(['data' => $this->exposure->createZone($this->tenant->id(), $data)], 201);
    }

    public function rebuild(): JsonResponse
    {
        return response()->json(['data' => $this->exposure->rebuildLocations($this->tenant->id())]);
    }

    public function accumulation(Request $r): JsonResponse
    {
        $data = $r->validate(['as_of' => 'nullable|date', 'zone_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->exposure->accumulation($this->tenant->id(), isset($data['as_of']) ? new \DateTimeImmutable($data['as_of']) : null, $data['zone_id'] ?? null)]);
    }

    public function snapshot(Request $r): JsonResponse
    {
        $data = $r->validate(['currency' => 'required|string|size:3']);

        return response()->json(['data' => $this->exposure->snapshot($this->tenant->id(), $data['currency'], $r->user()?->id)], 201);
    }

    public function showSnapshot(string $snapshot): JsonResponse
    {
        return response()->json(['data' => $this->exposure->snapshotView($this->tenant->id(), $snapshot)]);
    }

    public function snapshots(): JsonResponse
    {
        return response()->json(['data' => DB::table('accumulation_snapshots')->where('tenant_id', $this->tenant->id())->orderByDesc('as_of')->limit(100)->get()]);
    }

    public function setLimit(Request $r): JsonResponse
    {
        $data = $r->validate(['zone_id' => 'nullable|uuid', 'peril_code' => 'sometimes|string|max:32', 'currency' => 'required|string|size:3',
            'retention_limit_minor' => 'nullable|integer|min:0', 'gross_limit_minor' => 'nullable|integer|min:0']);

        return response()->json(['data' => $this->capacity->setLimit($this->tenant->id(), $data, $r->user()?->id)], 201);
    }

    public function check(Request $r): JsonResponse
    {
        $data = $r->validate(['zone_id' => 'nullable|uuid', 'location' => 'nullable|array', 'peril_code' => 'sometimes|string|max:32', 'sum_insured_minor' => 'required|integer|min:0',
            'currency' => 'required|string|size:3', 'line_code' => 'nullable|string|max:32', 'date' => 'nullable|date', 'subject_type' => 'nullable|string|max:48', 'subject_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->capacity->check($this->tenant->id(), $data, $r->user()?->id)]);
    }

    public function declareEvent(Request $r): JsonResponse
    {
        $data = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:191', 'peril_code' => 'required|string|max:32', 'zone_ids' => 'sometimes|array',
            'zone_ids.*' => 'uuid', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after_or_equal:starts_at', 'currency' => 'required|string|size:3']);

        return response()->json(['data' => $this->events->declare($this->tenant->id(), $data, $r->user()?->id)], 201);
    }

    public function showEvent(string $event): JsonResponse
    {
        return response()->json(['data' => $this->events->show($this->tenant->id(), $event)]);
    }

    public function linkClaim(Request $r, string $event): JsonResponse
    {
        $data = $r->validate(['claim_id' => 'required|uuid']);

        return response()->json(['data' => $this->events->linkClaim($this->tenant->id(), $event, $data['claim_id'], $r->user()?->id)]);
    }

    public function aggregateEvent(string $event): JsonResponse
    {
        return response()->json(['data' => $this->events->aggregate($this->tenant->id(), $event)]);
    }

    public function closeEvent(Request $r, string $event): JsonResponse
    {
        $data = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->events->close($this->tenant->id(), $event, $data['reason'])]);
    }

    public function largeLossThreshold(Request $r): JsonResponse
    {
        $data = $r->validate(['currency' => 'required|string|size:3', 'threshold_minor' => 'required|integer|min:1', 'recipient_user_ids' => 'required|array|min:1', 'recipient_user_ids.*' => 'uuid|exists:users,id']);

        return response()->json(['data' => $this->largeLoss->configure($this->tenant->id(), $data['currency'], $data['threshold_minor'], $data['recipient_user_ids'])]);
    }

    public function largeLossCheck(string $claim): JsonResponse
    {
        return response()->json(['data' => $this->largeLoss->check($this->tenant->id(), $claim)]);
    }
}
