<?php

declare(strict_types=1);

namespace App\Application\Commissions\Machine\Http;

use App\Application\Commissions\Machine\CommissionLifecycleService;
use App\Application\Commissions\Machine\CommissionMachine;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommissionAccrual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Batch 10-1: REQ-COM-001 commission lifecycle (show with blueprint state + history, earn, approve, make-payable, adjust, dispute, resolve, reverse). */
final class CommissionLifecycleController
{
    public function __construct(private TenantContext $tenant, private CommissionLifecycleService $service) {}

    public function show(string $accrual): JsonResponse
    {
        $a = $this->owned($accrual);
        $history = DB::table('workflow_transition_history')->where('subject_type', CommissionMachine::SUBJECT)->where('subject_id', $a->id)->orderBy('occurred_at')->get();
        $movements = DB::table('commission_movements')->where('commission_accrual_id', $a->id)->orderBy('occurred_at')->get();

        return response()->json(['data' => $this->present($a) + ['history' => $history, 'movements' => $movements]]);
    }

    public function earn(string $accrual): JsonResponse
    {
        return $this->ok($this->service->earn($this->owned($accrual), request()->user()));
    }

    public function approve(Request $r, string $accrual): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:1000']);

        return $this->ok($this->service->approve($this->owned($accrual), $r->user(), $d['note'] ?? null));
    }

    public function makePayable(Request $r, string $accrual): JsonResponse
    {
        return $this->ok($this->service->makePayable($this->owned($accrual), $r->user()));
    }

    public function adjust(Request $r, string $accrual): JsonResponse
    {
        $d = $r->validate(['amount_minor' => 'required|integer|min:0', 'reason' => 'required|string|max:1000']);

        return $this->ok($this->service->adjust($this->owned($accrual), (int) $d['amount_minor'], $d['reason'], $r->user()));
    }

    public function dispute(Request $r, string $accrual): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);

        return $this->ok($this->service->dispute($this->owned($accrual), $d['reason'], $r->user()));
    }

    public function resolveDispute(Request $r, string $accrual): JsonResponse
    {
        $d = $r->validate(['amount_minor' => 'nullable|integer|min:0', 'note' => 'required|string|max:1000']);

        return $this->ok($this->service->resolveDispute($this->owned($accrual), isset($d['amount_minor']) ? (int) $d['amount_minor'] : null, $d['note'], $r->user()));
    }

    public function reverse(Request $r, string $accrual): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:60']);

        return $this->ok($this->service->reverse($this->owned($accrual), $d['reason'], $r->user()));
    }

    private function owned(string $id): CommissionAccrual
    {
        return CommissionAccrual::where('tenant_id', $this->tenant->id())->whereKey($id)->first() ?? abort(404);
    }

    private function ok(CommissionAccrual $a): JsonResponse
    {
        return response()->json(['data' => $this->present($a)]);
    }

    private function present(CommissionAccrual $a): array
    {
        return $a->toArray() + ['blueprint_state' => CommissionMachine::blueprintState($a->status)];
    }
}
