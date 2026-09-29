<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Claims;

use App\Application\Claims\MobileClaimEvidenceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileClaimEvidenceController
{
    public function index(string $claim, Request $request, MobileClaimEvidenceService $service): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        // S4: `pending` is additive next to the paginator keys (data.data / data.current_page… are unchanged).
        return response()->json(['data' => $service->list($claim, $request->user(), $tenantId)->toArray()
            + ['pending' => $service->pending($claim, $request->user(), $tenantId)]]);
    }

    // Exactly one of document_id/upload_session_id: required_without makes
    // each mandatory when the other is absent, and prohibits rejects the
    // request if both are present — together they enforce "exactly one"
    // without a second manual check after validate() has already run.
    public function store(string $claim, Request $request, MobileClaimEvidenceService $service): JsonResponse
    {
        $data = $request->validate([
            'document_id' => 'required_without:upload_session_id|prohibits:upload_session_id|uuid',
            'upload_session_id' => 'required_without:document_id|prohibits:document_id|uuid',
            'evidence_type' => 'required|string|max:64',
            'purpose' => 'required|string|max:96',
        ]);

        $result = $service->attach($claim, $data, $request->user(), app(TenantContext::class)->id());

        // 202: accepted, the file is in its security check and will be attached automatically (Q1).
        return response()->json(['data' => $result], MobileClaimEvidenceService::deferred($result) ? 202 : 201);
    }
}
