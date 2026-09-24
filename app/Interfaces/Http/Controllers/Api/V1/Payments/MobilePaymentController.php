<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Payments;

use App\Application\Payments\MobilePaymentService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Support\MobileList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MobilePaymentController
{
    /** { data: Payment[], meta: { current_page, last_page, per_page, total, next_page } } */
    public function index(Request $request, MobilePaymentService $service): JsonResponse
    {
        $perPage = max(1, min($request->integer('per_page', 20), 100));

        return response()->json(MobileList::fromPaginator($service->list($request->user(), app(TenantContext::class)->id(), $perPage)));
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

    /** Signed-URL PDF of the receipt (no bearer token: the signature is the authorization). */
    public function receiptPdf(string $payment, MobilePaymentService $service): Response
    {
        return $service->receiptPdf($payment);
    }

    /**
     * Accepts the app's {reason} alone. amount_minor defaults to the
     * refundable balance (full amount when nothing was refunded yet),
     * reason_code to CUSTOMER_REQUEST, and the idempotency key comes from the
     * Idempotency-Key header (or body idempotency_key) — a retried request
     * returns the same refund instead of creating a second one.
     */
    public function requestRefund(string $payment, Request $request, MobilePaymentService $service): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
            'amount_minor' => 'nullable|integer|min:1',
            'reason_code' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|min:16|max:128',
        ]);
        $headerKey = (string) $request->header('Idempotency-Key', '');
        if ($headerKey !== '' && (strlen($headerKey) < 16 || strlen($headerKey) > 128)) {
            return response()->json(['message' => 'The Idempotency-Key header must be 16-128 characters.', 'errors' => ['idempotency_key' => ['The Idempotency-Key header must be 16-128 characters.']]], 422);
        }
        $data['idempotency_key'] = $data['idempotency_key'] ?? ($headerKey !== '' ? $headerKey : null);

        $refund = $service->requestRefund($payment, $data, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => $refund], $refund->wasRecentlyCreated ? 201 : 200);
    }
}
