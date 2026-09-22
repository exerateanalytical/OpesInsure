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
        return response()->json(['data' => $service->list($request->user(), app(TenantContext::class)->id())]);
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
        ]);
        $data['idempotency_key'] = $request->header('Idempotency-Key');

        $claim = $service->fnol($data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $claim], 201);
    }
}
