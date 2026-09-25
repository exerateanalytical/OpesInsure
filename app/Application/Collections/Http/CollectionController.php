<?php

declare(strict_types=1);

namespace App\Application\Collections\Http;

use App\Application\Collections\CollectionService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Agent C15 — REQ-REC-003 collections worklist, promises-to-pay, escalation, write-off maker-checker. */
final class CollectionController
{
    public function __construct(private TenantContext $tenant, private CollectionService $service) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['stage' => 'nullable|in:'.implode(',', CollectionService::STAGES), 'status' => 'nullable|in:ACTIVE,SETTLED,WRITTEN_OFF,CLOSED', 'type' => 'nullable|string|max:32']);
        $rows = DB::table('collection_accounts as a')->join('financial_obligations as o', 'o.id', '=', 'a.financial_obligation_id')
            ->where('a.tenant_id', $this->tenant->id())
            ->when($d['stage'] ?? null, fn ($q, $v) => $q->where('a.stage', $v))
            ->where('a.status', $d['status'] ?? 'ACTIVE')
            ->when($d['type'] ?? null, fn ($q, $v) => $q->where('o.type', $v))
            ->orderBy('o.due_at')->limit(500)
            ->get(['a.*', 'o.type', 'o.outstanding_minor', 'o.currency', 'o.due_at', 'o.debtor_type', 'o.debtor_id', 'o.source_type', 'o.source_id']);

        return response()->json(['data' => $rows]);
    }

    public function show(string $obligation): JsonResponse
    {
        $a = DB::table('collection_accounts')->where(['tenant_id' => $this->tenant->id(), 'financial_obligation_id' => $obligation])->first() ?? abort(404);

        return response()->json(['data' => (array) $a + [
            'notices' => DB::table('collection_notices')->where('collection_account_id', $a->id)->orderBy('issued_at')->get(),
            'promises' => DB::table('collection_promises')->where('collection_account_id', $a->id)->orderBy('created_at')->get(),
            'write_off_requests' => DB::table('collection_write_off_requests')->where('financial_obligation_id', $obligation)->orderBy('created_at')->get(),
        ]]);
    }

    public function run(): JsonResponse
    {
        return response()->json(['data' => $this->service->run($this->tenant->id())]);
    }

    public function promise(Request $r, string $obligation): JsonResponse
    {
        $d = $r->validate(['amount_minor' => 'required|integer|min:1', 'promised_for' => 'required|date', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->service->promise($this->tenant->id(), $obligation, $d['amount_minor'], $d['promised_for'], $d['notes'] ?? null, $r->user())], 201);
    }

    public function escalate(Request $r, string $obligation): JsonResponse
    {
        $d = $r->validate(['reason' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->service->escalate($this->tenant->id(), $obligation, $r->user(), $d['reason'] ?? 'Manual escalation')]);
    }

    public function requestWriteOff(Request $r, string $obligation): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);

        return response()->json(['data' => $this->service->requestWriteOff($this->tenant->id(), $obligation, $d['reason'], $r->user())], 201);
    }

    public function approveWriteOff(Request $r, string $request): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->service->approveWriteOff($this->tenant->id(), $request, $r->user(), $d['note'] ?? null)]);
    }

    public function rejectWriteOff(Request $r, string $request): JsonResponse
    {
        $d = $r->validate(['note' => 'required|string|max:1000']);

        return response()->json(['data' => $this->service->rejectWriteOff($this->tenant->id(), $request, $r->user(), $d['note'])]);
    }
}
