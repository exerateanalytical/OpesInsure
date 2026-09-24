<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Policies;

use App\Application\Policies\MobileWalletService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Support\MobileList;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileWalletController
{
    /** { data: Policy[] (+carrier_name, product_name, line_code, days_to_expiry), meta: {...} } — see MobileList. */
    public function index(Request $request, MobileWalletService $service): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 20), 100));
        $page = $service->wallet($request->user(), app(TenantContext::class)->id(), $perPage);

        return response()->json(MobileList::fromPaginator($page, fn (Policy $p) => array_merge($p->toArray(), $service->summary($p))));
    }

    public function show(string $policy, Request $request, MobileWalletService $service): JsonResponse
    {
        return response()->json(['data' => $service->policyDetail($policy, $request->user(), app(TenantContext::class)->id())]);
    }

    public function certificate(string $policy, Request $request, MobileWalletService $service): JsonResponse
    {
        return response()->json(['data' => $service->certificate($policy, $request->user(), app(TenantContext::class)->id())]);
    }
}
