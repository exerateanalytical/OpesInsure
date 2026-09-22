<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Policies;

use App\Application\Policies\MobileWalletService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileWalletController
{
    public function index(Request $request, MobileWalletService $service): JsonResponse
    {
        return response()->json(['data' => $service->wallet($request->user(), app(TenantContext::class)->id())]);
    }

    public function show(string $policy, Request $request, MobileWalletService $service): JsonResponse
    {
        return response()->json(['data' => $service->policy($policy, $request->user(), app(TenantContext::class)->id())]);
    }

    public function certificate(string $policy, Request $request, MobileWalletService $service): JsonResponse
    {
        return response()->json(['data' => $service->certificate($policy, $request->user(), app(TenantContext::class)->id())]);
    }
}
