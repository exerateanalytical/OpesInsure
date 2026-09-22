<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Uploads;

use App\Application\Uploads\ResumableUploadService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ResumableUploadController
{
    public function start(Request $request, ResumableUploadService $service): JsonResponse
    {
        $data = $request->validate([
            'resource_type' => 'required|string|max:64',
            'mime_type' => 'required|string|max:100',
            'total_chunks' => 'required|integer|min:1',
            'total_size_bytes' => 'required|integer|min:1',
            'expected_sha256' => 'nullable|size:64',
        ]);

        $session = $service->start($data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => ['id' => $session->id, 'status' => $session->status, 'total_chunks' => $session->total_chunks]], 201);
    }

    // Chunk bytes travel as base64 inside the JSON body (rather than
    // multipart/raw-binary) so this route can stay under the same
    // json.api-enforced mobile group as every other mutating mobile
    // endpoint instead of carving out a one-off exception to it.
    public function putChunk(string $upload, string $index, Request $request, ResumableUploadService $service): JsonResponse
    {
        $data = $request->validate(['data' => 'required|string']);

        $bytes = base64_decode($data['data'], true);

        if ($bytes === false) {
            throw ValidationException::withMessages(['data' => [__('wave12.upload_chunk_not_base64')]]);
        }

        return response()->json(['data' => $service->putChunk($upload, (int) $index, $bytes, $request->user(), app(TenantContext::class)->id())]);
    }

    public function status(string $upload, Request $request, ResumableUploadService $service): JsonResponse
    {
        return response()->json(['data' => $service->status($upload, $request->user(), app(TenantContext::class)->id())]);
    }

    public function finalize(string $upload, Request $request, ResumableUploadService $service): JsonResponse
    {
        $session = $service->finalize($upload, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => ['id' => $session->id, 'status' => $session->status, 'storage_key' => $session->storage_key]]);
    }
}
