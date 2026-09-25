<?php

declare(strict_types=1);

namespace App\Application\Finance\Obligations\Http;

use App\Application\Finance\Obligations\ObligationService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Batch 9-1: REQ-OBL-001 obligation list / show / aging / write-off / cancel, REQ-PAY-006 policy instalment schedule. */
final class ObligationController
{
    public function __construct(private TenantContext $tenant, private ObligationService $service) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'status' => 'nullable|string|max:20', 'kind' => 'nullable|in:RECEIVABLE,PAYABLE', 'type' => 'nullable|string|max:32',
            'policy_id' => 'nullable|uuid', 'debtor_id' => 'nullable|uuid', 'overdue' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);
        $q = DB::table('financial_obligations')->where('tenant_id', $this->tenant->id())
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($d['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($d['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($d['policy_id'] ?? null, fn ($q, $v) => $q->where('policy_id', $v))
            ->when($d['debtor_id'] ?? null, fn ($q, $v) => $q->where('debtor_id', $v))
            ->when($r->boolean('overdue'), fn ($q) => $q->whereIn('status', ObligationService::OPEN_STATUSES)->where('due_at', '<', now()->startOfDay()));
        $page = $q->orderBy('due_at')->orderBy('id')->paginate((int) ($d['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn ($o) => $this->present($o))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ]);
    }

    public function show(string $obligation): JsonResponse
    {
        $o = DB::table('financial_obligations')->where('tenant_id', $this->tenant->id())->where('id', $obligation)->first() ?? abort(404);
        $events = DB::table('financial_obligation_events')->where('financial_obligation_id', $o->id)->orderBy('occurred_at')->orderBy('id')->get();

        return response()->json(['data' => $this->present($o) + ['events' => $events]]);
    }

    public function aging(Request $r): JsonResponse
    {
        $d = $r->validate(['kind' => 'nullable|in:RECEIVABLE,PAYABLE']);

        return response()->json(['data' => $this->service->aging($this->tenant->id(), $d['kind'] ?? null)]);
    }

    public function writeOff(Request $r, string $obligation): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);
        $this->owned($obligation);

        return response()->json(['data' => $this->present($this->service->writeOff($obligation, $d['reason'], $r->user()->id))]);
    }

    public function cancel(Request $r, string $obligation): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);
        $this->owned($obligation);

        return response()->json(['data' => $this->present($this->service->cancel($obligation, $d['reason'], $r->user()->id))]);
    }

    public function policyInstalments(string $policy): JsonResponse
    {
        DB::table('policies')->where('tenant_id', $this->tenant->id())->where('id', $policy)->exists() || abort(404);

        return response()->json(['data' => DB::table('policy_premium_instalments')->where('policy_id', $policy)->orderBy('sequence')->get()]);
    }

    private function owned(string $id): void
    {
        DB::table('financial_obligations')->where('tenant_id', $this->tenant->id())->where('id', $id)->exists() || abort(404);
    }

    /** @return array<string, mixed> */
    private function present(object $o): array
    {
        $open = in_array($o->status, ObligationService::OPEN_STATUSES, true);

        return (array) $o + ['aging_bucket' => $open ? ObligationService::bucket($o->due_at) : null];
    }
}
