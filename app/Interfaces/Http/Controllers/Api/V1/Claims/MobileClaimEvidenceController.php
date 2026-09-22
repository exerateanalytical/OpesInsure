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
        return response()->json(['data' => $service->list($claim, $request->user(), app(TenantContext::class)->id())]);
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

        return response()->json(['data' => $result], 201);
    }
}
