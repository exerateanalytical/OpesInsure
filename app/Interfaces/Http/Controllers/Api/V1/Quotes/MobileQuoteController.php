<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Quotes;

use App\Application\Quotes\MobileQuoteService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileQuoteController
{
    public function index(Request $request, MobileQuoteService $service): JsonResponse
    {
        return response()->json(['data' => $service->list($request->user(), app(TenantContext::class)->id())]);
    }

    public function show(string $quote, Request $request, MobileQuoteService $service): JsonResponse
    {
        return response()->json(['data' => $service->show($quote, $request->user(), app(TenantContext::class)->id())]);
    }

    public function resume(string $quote, Request $request, MobileQuoteService $service): JsonResponse
    {
        return response()->json(['data' => $service->resume($quote, $request->user(), app(TenantContext::class)->id())]);
    }

    public function destroy(string $quote, Request $request, MobileQuoteService $service): JsonResponse
    {
        $result = $service->cancel($quote, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status]]);
    }
}
