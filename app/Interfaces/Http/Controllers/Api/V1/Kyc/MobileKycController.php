<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Kyc;

use App\Application\Kyc\MobileKycService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileKycController
{
    public function profile(Request $request, MobileKycService $service): JsonResponse
    {
        return response()->json(['data' => $service->profile($request->user(), app(TenantContext::class)->id())]);
    }

    public function updateProfile(Request $request, MobileKycService $service): JsonResponse
    {
        $data = $request->validate([
            'identifier_type' => 'required|string|max:32',
            'identifier_value' => 'required|string|max:64',
            'identifier_country' => 'nullable|string|size:2',
        ]);

        $result = $service->updateProfile($data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result]);
    }

    public function attachDocument(Request $request, MobileKycService $service): JsonResponse
    {
        $data = $request->validate([
            'document_id' => 'required|uuid',
            'purpose' => 'required|string|max:64',
        ]);

        $result = $service->attachDocument($data['document_id'], $data['purpose'], $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result], 201);
    }

    public function submit(Request $request, MobileKycService $service): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);

        $result = $service->submit($data['notes'] ?? null, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result], 201);
    }
}
