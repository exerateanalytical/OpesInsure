<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Payments;

use App\Application\Payments\MobilePaymentService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobilePaymentController
{
    public function index(Request $request, MobilePaymentService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->user(), app(TenantContext::class)->id())]);
    }

    public function show(string $payment, Request $request, MobilePaymentService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($payment, $request->user(), app(TenantContext::class)->id())]);
    }

    public function retry(string $payment, Request $request, MobilePaymentService $service): JsonResponse
    {
        return response()->json(['data' => $service->retry($payment, $request->user(), app(TenantContext::class)->id())], 202);
    }

    public function receipt(string $payment, Request $request, MobilePaymentService $service): JsonResponse
    {
        return response()->json(['data' => $service->receipt($payment, $request->user(), app(TenantContext::class)->id())]);
    }

    public function requestRefund(string $payment, Request $request, MobilePaymentService $service): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'reason_code' => 'required|string|max:64',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'required|string|min:16|max:128',
        ]);

        $refund = $service->requestRefund($payment, $data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $refund], $refund->wasRecentlyCreated ? 201 : 200);
    }
}
