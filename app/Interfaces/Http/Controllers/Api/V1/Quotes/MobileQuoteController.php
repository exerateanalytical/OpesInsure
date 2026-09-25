<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Quotes;

use App\Application\Quotes\QuoteService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-DUP-007: thin mobile adapter over the canonical QuoteService (MobileQuoteService merged away).
 * Routes and response shapes are unchanged for app 1.3.0: data = paginator | {quote, offers} | {id, status}.
 */
final class MobileQuoteController
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->quotes->ownedList($request->user(), $this->tenant())]);
    }

    public function show(string $quote, Request $request): JsonResponse
    {
        $q = $this->quotes->markViewed($this->quotes->owned($quote, $request->user(), $this->tenant()), $request->user());

        return response()->json(['data' => $this->quotes->envelope($q)]);
    }

    public function resume(string $quote, Request $request): JsonResponse
    {
        $q = $this->quotes->owned($quote, $request->user(), $this->tenant());
        $this->quotes->assertResumable($q);

        return response()->json(['data' => $this->quotes->envelope($q)]);
    }

    public function destroy(string $quote, Request $request): JsonResponse
    {
        $result = $this->quotes->cancel($this->quotes->owned($quote, $request->user(), $this->tenant()), $request->user());

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status]]);
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
