<?php

declare(strict_types=1);

namespace App\Application\Settlements\Http;

use App\Application\Settlements\SettlementService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-STL-001 — broker–insurer settlement calculated from obligations (Batch 10-4). */
final class SettlementLifecycleController
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'carrier_id' => 'required|uuid|exists:carriers,id', 'partner_id' => 'nullable|uuid|exists:partners,id',
            'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start',
            'currency' => 'required|string|size:3', 'idempotency_key' => 'required|string|max:100',
        ]);
        $b = $this->settlements->draft([...$d, 'currency' => strtoupper($d['currency']), 'tenant_id' => $this->tenant()], $r->user());

        return (new SettlementResource($b))->response()->setStatusCode(201);
    }

    public function calculate(Request $r, string $batch): SettlementResource
    {
        return new SettlementResource($this->settlements->calculate($this->batch($batch), $r->user()));
    }

    public function review(Request $r, string $batch): SettlementResource
    {
        return new SettlementResource($this->settlements->submitForReview($this->batch($batch), $r->user()));
    }

    public function approve(Request $r, string $batch): SettlementResource
    {
        $d = $r->validate(['notes' => 'sometimes|string|min:20|max:2000']);

        return new SettlementResource($this->settlements->approve($this->batch($batch), $r->user(), $d['notes'] ?? null));
    }

    public function reject(Request $r, string $batch): SettlementResource
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:1000']);

        return new SettlementResource($this->settlements->reject($this->batch($batch), $r->user(), $d['reason']));
    }

    public function cancel(Request $r, string $batch): SettlementResource
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:1000']);

        return new SettlementResource($this->settlements->cancel($this->batch($batch), $r->user(), $d['reason']));
    }

    public function process(Request $r, string $batch): SettlementResource
    {
        return new SettlementResource($this->settlements->process($this->batch($batch), $r->user()));
    }

    public function fail(Request $r, string $batch): SettlementResource
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);

        return new SettlementResource($this->settlements->failProcessing($this->batch($batch), $d['reason'], $r->user()));
    }

    public function settle(Request $r, string $batch): SettlementResource
    {
        $d = $r->validate(['bank_reference' => 'required|string|max:120']);

        return new SettlementResource($this->settlements->settle($this->batch($batch), $d['bank_reference'], $r->user()));
    }

    public function reconcile(Request $r, string $batch): SettlementResource
    {
        $d = $r->validate(['reference' => 'required|string|max:120']);

        return new SettlementResource($this->settlements->reconcile($this->batch($batch), $d['reference'], $r->user()));
    }

    private function batch(string $id): \App\Models\SettlementBatch
    {
        return $this->settlements->find($this->tenant(), $id);
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
