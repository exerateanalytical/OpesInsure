<?php

declare(strict_types=1);

namespace App\Application\Finance\Refunds\Http;

use App\Application\Finance\Refunds\RefundEngine;
use App\Domain\Tenancy\TenantContext;
use App\Models\PaymentIntentRecord;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-PAY-009 / WF-063 — refund queue and engine API (tenant-scoped). Approval stays on POST refunds/{refund}/approve. */
final class RefundQueueController
{
    public function __construct(private readonly RefundEngine $engine) {}

    private function tenantId(): string
    {
        return app(TenantContext::class)->id();
    }

    private function find(string $id): Refund
    {
        return Refund::where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => ['nullable', Rule::in([...RefundEngine::STATUSES, 'OPEN'])], 'source_type' => 'nullable|string|max:40',
            'payment_intent_id' => 'nullable|uuid', 'per_page' => 'nullable|integer|min:1|max:100']);
        $status = $d['status'] ?? 'OPEN';
        $page = Refund::where('tenant_id', $this->tenantId())
            ->when($status === 'OPEN', fn ($q) => $q->whereIn('status', ['CANDIDATE', 'CALCULATED', 'REQUESTED', 'APPROVED', 'PAID']), fn ($q) => $q->where('status', $status))
            ->when($d['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->when($d['payment_intent_id'] ?? null, fn ($q, $v) => $q->where('payment_intent_id', $v))
            ->orderBy('created_at')->paginate((int) ($d['per_page'] ?? 25));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(string $refund): JsonResponse
    {
        $row = $this->find($refund);

        return response()->json(['data' => $row->toArray() + ['events' => DB::table('financial_case_events')->where('case_type', 'REFUND')->where('case_id', $row->id)->orderBy('occurred_at')->get()]]);
    }

    public function candidate(Request $r, string $payment): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'amount_minor' => 'nullable|integer|min:1', 'notes' => 'nullable|string|max:2000',
            'source_type' => 'nullable|string|max:40', 'source_id' => 'nullable|uuid']);
        $intent = PaymentIntentRecord::where('tenant_id', $this->tenantId())->findOrFail($payment);
        $row = $this->engine->candidate($intent, $d['source_type'] ?? 'manual', $d['source_id'] ?? null, $d['reason_code'], $r->user(), $d['amount_minor'] ?? null, $d['notes'] ?? null);

        return response()->json(['data' => $row], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function calculate(Request $r, string $refund): JsonResponse
    {
        $d = $r->validate(['gross_minor' => 'nullable|integer|min:1', 'deductions' => 'nullable|array|max:20', 'deductions.*.code' => 'required|string|max:40',
            'deductions.*.amount_minor' => 'required|integer|min:0', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->engine->calculate($this->find($refund), $d, $r->user())]);
    }

    public function review(Request $r, string $refund): JsonResponse
    {
        $d = $r->validate(['notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->engine->review($this->find($refund), $r->user(), $d['notes'] ?? null)]);
    }

    public function reject(Request $r, string $refund): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->engine->reject($this->find($refund), $r->user(), $d['reason'])]);
    }

    public function pay(Request $r, string $refund): JsonResponse
    {
        $d = $r->validate(['payout_method' => ['required', Rule::in(RefundEngine::PAYOUT_METHODS)], 'provider_reference' => 'required|string|max:255']);

        return response()->json(['data' => $this->engine->pay($this->find($refund), $d, $r->user())]);
    }

    public function reconcile(Request $r, string $refund): JsonResponse
    {
        $d = $r->validate(['bank_reference' => 'required|string|max:128']);

        return response()->json(['data' => $this->engine->reconcile($this->find($refund), $r->user(), $d['bank_reference'])]);
    }
}
