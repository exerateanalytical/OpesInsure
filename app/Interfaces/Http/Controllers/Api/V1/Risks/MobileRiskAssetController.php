<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Risks;

use App\Application\Risks\MobileRiskAssetService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileRiskAssetController
{
    public function index(Request $request, MobileRiskAssetService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->user(), app(TenantContext::class)->id())]);
    }

    public function show(string $asset, Request $request, MobileRiskAssetService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($asset, $request->user(), app(TenantContext::class)->id())]);
    }

    public function store(Request $request, MobileRiskAssetService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:VEHICLE,PROPERTY,TRAVELLER,HEALTH_MEMBER',
            'external_reference' => 'nullable|string|max:100',
            'display_name' => 'required|string|max:160',
            'facts' => 'required|array',
        ]);

        $asset = $service->create($data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $asset], 201);
    }

    public function attachDocument(string $asset, Request $request, MobileRiskAssetService $service): JsonResponse
    {
        $data = $request->validate([
            'document_id' => 'required|uuid',
            'purpose' => 'required|string|max:64',
        ]);

        $result = $service->attachDocument($asset, $data['document_id'], $data['purpose'], $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result], 201);
    }

    public function requestScan(string $asset, Request $request, MobileRiskAssetService $service): JsonResponse
    {
        $data = $request->validate(['document_id' => 'nullable|uuid']);

        $result = $service->requestScan($asset, $data['document_id'] ?? null, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result]);
    }

    public function confirmScan(string $asset, string $document, Request $request, MobileRiskAssetService $service): JsonResponse
    {
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'facts' => 'required|array',
        ]);

        // Explicit cast: the 'integer' rule accepts a numeric string, but
        // MobileRiskAssetService::confirmScan() is typed `int $expectedVersion`
        // under this file's strict_types=1 — matches the same defensive cast
        // RiskAssetController::update() already does for the identical case.
        $result = $service->confirmScan($asset, $document, (int) $data['version'], $data['facts'], $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result]);
    }
}
