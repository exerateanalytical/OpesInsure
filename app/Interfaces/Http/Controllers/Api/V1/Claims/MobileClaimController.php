<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Claims;

use App\Application\Claims\MobileClaimService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileClaimController
{
    public function index(Request $request, MobileClaimService $service): JsonResponse
    {
        $filter = $request->validate(['policy_id' => 'sometimes|nullable|uuid']);

        return response()->json(['data' => $service->list($request->user(), app(TenantContext::class)->id(), max(1, min(100, (int) $request->query('per_page', 20))), $filter['policy_id'] ?? null)]);
    }

    public function show(string $claim, Request $request, MobileClaimService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($claim, $request->user(), app(TenantContext::class)->id())]);
    }

    public function timeline(string $claim, Request $request, MobileClaimService $service): JsonResponse
    {
        return response()->json(['data' => $service->timeline($claim, $request->user(), app(TenantContext::class)->id())]);
    }

    /**
     * First notice of loss. The Idempotency-Key header (enforced by the
     * `idempotency` middleware on this route) is threaded through as the
     * same value ClaimLifecycleService::fnol() stores its own domain-level
     * replay receipt under, so a dropped-connection retry is caught at both
     * the HTTP layer and the domain layer rather than relying on just one.
     */
    public function store(Request $request, MobileClaimService $service): JsonResponse
    {
        $data = $request->validate([
            'policy_id' => 'required|uuid',
            'incident_at' => 'required|date|before_or_equal:now',
            'incident_location' => 'nullable|string|max:255',
            'description' => 'required|string|min:10|max:5000',
            'incident_type' => 'nullable|string|max:64',
            'injuries_reported' => 'sometimes|boolean',
            'police_report_filed' => 'sometimes|boolean',
            'police_reference' => 'nullable|string|max:120',
            'estimated_loss_minor' => 'nullable|integer|min:0',
            'latitude' => \App\Application\Claims\ClaimIncidentService::rules()['latitude'],
            'longitude' => \App\Application\Claims\ClaimIncidentService::rules()['longitude'],
        ]);
        $data['idempotency_key'] = $request->header('Idempotency-Key');
        $coordinates = array_intersect_key($data, array_flip(['latitude', 'longitude']));
        unset($data['latitude'], $data['longitude']);

        $tenantId = app(TenantContext::class)->id();
        $claim = $service->fnol($data, $request->user(), $tenantId);
        // Coordinates go through the same incident path as PUT /mobile/claims/{id}/incident.
        if (array_filter($coordinates, fn ($v) => $v !== null) !== []) {
            $claim = app(\App\Application\Claims\ClaimIncidentService::class)->save($claim->id, $coordinates, $request->user(), $tenantId);
        }

        return response()->json(['data' => $claim], 201);
    }

    /** Claimant withdrawal of an early-stage claim (SUBMITTED / ACKNOWLEDGED / EVIDENCE_PENDING). */
    public function withdraw(string $claim, Request $request, MobileClaimService $service): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);

        return response()->json(['data' => $service->withdraw($claim, $data['reason'], $request->user(), app(TenantContext::class)->id())]);
    }
}
