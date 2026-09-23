<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Claims;

use App\Application\Claims\MobileClaimPartyService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileClaimPartyController
{
    public function index(string $claim, Request $request, MobileClaimPartyService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($claim, $request->user(), app(TenantContext::class)->id())]);
    }

    public function store(string $claim, Request $request, MobileClaimPartyService $service): JsonResponse
    {
        $data = $request->validate([
            'role' => 'required|in:DRIVER,PASSENGER,THIRD_PARTY,WITNESS,OTHER',
            'display_name' => 'required|string|max:255',
            'is_self' => 'sometimes|boolean',
            'contact_phone' => 'nullable|string|max:32',
            'contact_email' => 'nullable|email|max:255',
            'consent_given' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $party = $service->add($claim, $data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $party], 201);
    }
}
