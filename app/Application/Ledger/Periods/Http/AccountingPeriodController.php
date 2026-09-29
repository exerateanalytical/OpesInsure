<?php

declare(strict_types=1);

namespace App\Application\Ledger\Periods\Http;

use App\Application\Ledger\Periods\AccountingPeriodQueries;
use App\Application\Ledger\Periods\AccountingPeriodService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FIN-023 financial period closing over HTTP (routes/ledger_periods.php). Every action proves tenant ownership first,
 * then calls AccountingPeriodService (permission re-checked there; reopening is maker-checker: requester ≠ approver).
 */
final class AccountingPeriodController
{
    public function __construct(private readonly TenantContext $tenant, private readonly AccountingPeriodQueries $periods, private readonly AccountingPeriodService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->periods->list($this->tenant->id())->map(fn ($p) => $this->present($p))->values()]);
    }

    public function checklist(string $period): JsonResponse
    {
        $this->periods->ownedOrFail($this->tenant->id(), $period);

        return response()->json(['data' => $this->service->checklist($period)]);
    }

    public function startClose(Request $r, string $period): JsonResponse
    {
        $this->periods->ownedOrFail($this->tenant->id(), $period);

        return response()->json(['data' => $this->present($this->service->startClose($period, $r->user()))]);
    }

    public function close(Request $r, string $period): JsonResponse
    {
        $this->periods->ownedOrFail($this->tenant->id(), $period);

        return response()->json(['data' => $this->present($this->service->close($period, $r->user()))]);
    }

    public function requestReopen(Request $r, string $period): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000']);
        $this->periods->ownedOrFail($this->tenant->id(), $period);

        return response()->json(['data' => $this->present($this->service->requestReopen($period, $d['reason'], $r->user()))]);
    }

    public function approveReopen(Request $r, string $period): JsonResponse
    {
        $this->periods->ownedOrFail($this->tenant->id(), $period);

        return response()->json(['data' => $this->present($this->service->approveReopen($period, $r->user()))]);
    }

    public function rejectReopen(Request $r, string $period): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);
        $this->periods->ownedOrFail($this->tenant->id(), $period);

        return response()->json(['data' => $this->present($this->service->rejectReopen($period, $r->user(), $d['reason']))]);
    }

    private function present(object $p): array
    {
        return (array) $p + ['checklist' => is_string($p->checklist ?? null) ? json_decode($p->checklist, true) : ($p->checklist ?? null)];
    }
}
