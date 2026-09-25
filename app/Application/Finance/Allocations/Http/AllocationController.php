<?php

declare(strict_types=1);

namespace App\Application\Finance\Allocations\Http;

use App\Application\Finance\Allocations\AllocationRuleService;
use App\Application\Finance\Allocations\AllocationService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** REQ-PAY-004 — payment allocations and the per-tenant allocation-order rule. */
final class AllocationController
{
    public function __construct(private readonly AllocationService $allocations, private readonly AllocationRuleService $rules) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    public function show(string $payment): JsonResponse
    {
        return response()->json(['data' => $this->allocations->forPayment($this->tenant(), $payment)]);
    }

    /** Idempotent: the Idempotency-Key header (or idempotency_key) names the run; a replay returns 200 with the same run. */
    public function allocate(Request $r, string $payment): JsonResponse
    {
        $d = $r->validate([
            'policy_id' => 'nullable|uuid', 'amount_minor' => 'nullable|integer|min:1',
            'financial_obligation_ids' => 'sometimes|array|max:50', 'financial_obligation_ids.*' => 'uuid',
            'idempotency_key' => 'nullable|string|max:128',
        ]);
        $key = (string) ($r->header('Idempotency-Key') ?: ($d['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => ['Send an Idempotency-Key (at most 128 characters).']]);
        }
        $run = $this->allocations->allocate($this->tenant(), $payment, $d['policy_id'] ?? null, $d['financial_obligation_ids'] ?? [],
            isset($d['amount_minor']) ? (int) $d['amount_minor'] : null, $key, $r->user());

        return response()->json(['data' => $run], $run['replayed'] ? 200 : 201);
    }

    public function reverse(Request $r, string $run): JsonResponse
    {
        $d = $r->validate(['reason_code' => ['required', Rule::in(AllocationService::REVERSAL_REASONS)], 'reason' => 'required|string|max:255']);

        return response()->json(['data' => $this->allocations->reverse($this->tenant(), $run, $d['reason_code'], $d['reason'], $r->user())]);
    }

    public function rule(): JsonResponse
    {
        return response()->json(['data' => ['active' => $this->rules->active($this->tenant()), 'history' => $this->rules->history($this->tenant())]]);
    }

    public function publishRule(Request $r): JsonResponse
    {
        $d = $r->validate([
            'strategy' => ['required', Rule::in(AllocationRuleService::STRATEGIES)],
            'priority' => 'required|array|min:1|max:6', 'priority.*' => 'string', 'reason' => 'required|string|max:255',
        ]);

        return response()->json(['data' => $this->rules->publish($this->tenant(), $d['strategy'], $d['priority'], $d['reason'], $r->user())], 201);
    }
}
