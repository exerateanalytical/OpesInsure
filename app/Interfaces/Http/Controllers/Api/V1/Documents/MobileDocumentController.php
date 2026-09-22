<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Documents;

use App\Application\Documents\MobileDocumentService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileDocumentController
{
    public function index(Request $request, MobileDocumentService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->user(), app(TenantContext::class)->id())]);
    }

    public function show(string $document, Request $request, MobileDocumentService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($document, $request->user(), app(TenantContext::class)->id())]);
    }

    public function requestAccess(string $document, Request $request, MobileDocumentService $service): JsonResponse
    {
        $data = $request->validate(['purpose' => 'nullable|string|max:64']);

        $result = $service->requestAccess($document, $data['purpose'] ?? 'MOBILE_ACCESS', $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result]);
    }

    public function upload(Request $request, MobileDocumentService $service): JsonResponse
    {
        $data = $request->validate([
            'category' => 'required|string|max:48',
            'mime_type' => 'required|in:application/pdf,image/jpeg,image/png',
            'file_base64' => 'required|string',
        ]);

        $result = $service->upload($data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $result], 201);
    }
}
