<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Logistics;

use App\Application\Logistics\MobileDeliveryService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileDeliveryController
{
    public function show(string $delivery, Request $request, MobileDeliveryService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($delivery, $request->user(), app(TenantContext::class)->id())]);
    }

    public function updateAddress(string $delivery, Request $request, MobileDeliveryService $service): JsonResponse
    {
        $data = $request->validate(['address' => 'required|array']);

        return response()->json(['data' => $service->updateAddress($delivery, $data['address'], $request->user(), app(TenantContext::class)->id())]);
    }

    public function confirm(string $delivery, Request $request, MobileDeliveryService $service): JsonResponse
    {
        $data = $request->validate(['otp' => 'required|string|size:6']);

        return response()->json(['data' => $service->confirm($delivery, $data['otp'], $request->user(), app(TenantContext::class)->id())]);
    }
}
