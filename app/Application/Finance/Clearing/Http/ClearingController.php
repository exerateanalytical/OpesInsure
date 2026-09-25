<?php

declare(strict_types=1);

namespace App\Application\Finance\Clearing\Http;

use App\Application\Finance\Clearing\ClearingBatch;
use App\Application\Finance\Clearing\ClearingService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-PAY-011 — mobile-money clearing batches, reconciliation and suspense balance API (tenant-scoped). */
final class ClearingController
{
    public function __construct(private readonly ClearingService $clearing) {}

    private function tenantId(): string
    {
        return app(TenantContext::class)->id();
    }

    private function find(string $id): ClearingBatch
    {
        return ClearingBatch::where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => ['nullable', Rule::in(['OPEN', 'SETTLED', 'RECONCILED', 'VARIANCE'])], 'provider' => 'nullable|string|max:32', 'per_page' => 'nullable|integer|min:1|max:100']);
        $page = ClearingBatch::where('tenant_id', $this->tenantId())
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($d['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->orderByDesc('settlement_date')->paginate((int) ($d['per_page'] ?? 25));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(string $batch): JsonResponse
    {
        return response()->json(['data' => $this->find($batch)->load('items')]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate(['provider' => 'required|string|max:32', 'settlement_reference' => 'required|string|max:128', 'settlement_date' => 'required|date',
            'currency' => 'required|string|size:3', 'notes' => 'nullable|string|max:2000']);
        $batch = $this->clearing->open($this->tenantId(), $d, $r->user());

        return response()->json(['data' => $batch], $batch->wasRecentlyCreated ? 201 : 200);
    }

    public function attach(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['payment_ids' => 'required|array|min:1|max:1000', 'payment_ids.*' => 'required|uuid']);

        return response()->json(['data' => $this->clearing->attach($this->find($batch), $d['payment_ids'], $r->user())]);
    }

    public function settle(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['settled_minor' => 'required|integer|min:0', 'fee_minor' => 'nullable|integer|min:0', 'bank_reference' => 'required|string|max:128']);

        return response()->json(['data' => $this->clearing->settle($this->find($batch), $d, $r->user())]);
    }

    public function reconcile(Request $r, string $batch): JsonResponse
    {
        $d = $r->validate(['notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->clearing->reconcile($this->find($batch), $r->user(), $d['notes'] ?? null)]);
    }

    public function suspense(): JsonResponse
    {
        return response()->json(['data' => $this->clearing->suspense($this->tenantId())]);
    }
}
