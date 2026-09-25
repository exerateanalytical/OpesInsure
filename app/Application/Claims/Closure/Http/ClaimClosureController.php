<?php

declare(strict_types=1);

namespace App\Application\Claims\Closure\Http;

use App\Application\Claims\Closure\ClaimClosureChecklist;
use App\Application\Claims\Closure\ClaimClosureService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-CLM-014 (agent C14): closure checklist, close, reopen maker-checker, recovery transfer. */
final class ClaimClosureController
{
    public function __construct(private TenantContext $tenant, private ClaimClosureService $service) {}

    public function checklist(Request $r, string $claim): JsonResponse
    {
        return response()->json(['data' => $this->service->checklist($this->owned($claim), $r->query('reason_code'))]);
    }

    public function close(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate(['reason_code' => ['required', Rule::in(array_diff(ClaimClosureChecklist::REASONS, ['AUTO_INACTIVE_SETTLED']))], 'summary' => 'nullable|string|max:5000']);
        $c = $this->service->close($this->owned($claim), $d['reason_code'], $d['summary'] ?? null, $r->user());

        return response()->json(['data' => ['id' => $c->id, 'status' => $c->status, 'closed_at' => $c->closed_at]]);
    }

    public function history(string $claim): JsonResponse
    {
        $c = $this->owned($claim);

        return response()->json(['data' => [
            'closures' => DB::table('claim_closures')->where('claim_id', $c->id)->orderBy('closed_at')->get(),
            'reopen_requests' => DB::table('claim_reopen_requests')->where('claim_id', $c->id)->orderBy('created_at')->get(),
        ]]);
    }

    public function requestReopen(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate(['reason_code' => ['required', Rule::in(ClaimClosureService::REOPEN_REASONS)], 'justification' => 'required|string|max:5000', 'restore_reserve_minor' => 'nullable|integer|min:0']);

        return response()->json(['data' => $this->service->requestReopen($this->owned($claim), $d['reason_code'], $d['justification'], (int) ($d['restore_reserve_minor'] ?? 0), $r->user())], 201);
    }

    public function approveReopen(Request $r, string $request): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:2000']);
        $c = $this->service->approveReopen($request, $r->user(), $d['note'] ?? null);

        return response()->json(['data' => ['id' => $c->id, 'status' => $c->status, 'current_reserve_minor' => $c->current_reserve_minor]]);
    }

    public function rejectReopen(Request $r, string $request): JsonResponse
    {
        $d = $r->validate(['note' => 'required|string|max:2000']);

        return response()->json(['data' => $this->service->rejectReopen($request, $r->user(), $d['note'])]);
    }

    public function transferRecovery(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['transferee' => 'required|string|max:255']);

        return response()->json(['data' => $this->service->transferRecovery($recovery, $d['transferee'], $r->user())]);
    }

    private function owned(string $id): Claim
    {
        return Claim::where('tenant_id', $this->tenant->id())->findOrFail($id);
    }
}
