<?php

declare(strict_types=1);

namespace App\Application\Claims\Recovery\Http;

use App\Application\Claims\Recovery\ClaimRecoveryService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Agent C15 — REQ-REC-001 claim recoveries as receivables. */
final class ClaimRecoveryController
{
    public function __construct(private TenantContext $tenant, private ClaimRecoveryService $service) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['claim_id' => 'nullable|uuid', 'status' => ['nullable', Rule::in([...ClaimRecoveryService::STATUSES, 'OPEN'])], 'type' => ['nullable', Rule::in(ClaimRecoveryService::TYPES)]]);
        $rows = ClaimRecovery::whereIn('claim_id', Claim::where('tenant_id', $this->tenant->id())->select('id'))
            ->when($d['claim_id'] ?? null, fn ($q, $v) => $q->where('claim_id', $v))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($d['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }

    public function show(string $recovery): JsonResponse
    {
        $x = $this->recovery($recovery);

        return response()->json(['data' => $x->toArray() + ['receipts' => DB::table('claim_recovery_receipts')->where('claim_recovery_id', $x->id)->orderBy('received_at')->get()]]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'claim_id' => 'required|uuid', 'type' => ['required', Rule::in(ClaimRecoveryService::TYPES)], 'counterparty_name' => 'required|string|max:255',
            'target_amount_minor' => 'required|integer|min:1', 'due_at' => 'nullable|date', 'debtor_party_id' => 'nullable|uuid|exists:parties,id', 'notes' => 'nullable|string|max:2000',
        ]);
        $claim = Claim::where(['id' => $d['claim_id'], 'tenant_id' => $this->tenant->id()])->firstOrFail();

        return response()->json(['data' => $this->service->open($claim, $d, $r->user())], 201);
    }

    public function receive(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['amount_minor' => 'required|integer|min:1', 'reference' => 'required|string|max:120']);

        return response()->json(['data' => $this->service->receive($this->recovery($recovery), $d['amount_minor'], $d['reference'], $r->user())]);
    }

    public function dispute(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->service->dispute($this->recovery($recovery), $d['reason'], $r->user())]);
    }

    public function resolveDispute(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['resolution' => 'required|string|max:2000']);

        return response()->json(['data' => $this->service->resolveDispute($this->recovery($recovery), $d['resolution'], $r->user())]);
    }

    public function close(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:255']);

        return response()->json(['data' => $this->service->close($this->recovery($recovery), $d['reason'], $r->user())]);
    }

    private function recovery(string $id): ClaimRecovery
    {
        return ClaimRecovery::whereKey($id)->whereIn('claim_id', Claim::where('tenant_id', $this->tenant->id())->select('id'))->firstOrFail();
    }
}
