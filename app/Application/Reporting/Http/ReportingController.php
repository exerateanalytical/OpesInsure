<?php

declare(strict_types=1);

namespace App\Application\Reporting\Http;

use App\Application\Reporting\Catalogue\ReportCatalogue;
use App\Application\Reporting\Dashboards\DashboardRegistry;
use App\Application\Reporting\Kpi\KpiCatalogueService;
use App\Application\Reporting\Kpi\KpiEvaluator;
use App\Application\Reporting\Kpi\KpiQueryRegistry;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Agent B2 — REQ-RPT-003 KPI catalogue, REQ-RPT-004 dashboards data API, REQ-RPT-005 unified report catalogue. */
final class ReportingController
{
    public function __construct(private TenantContext $tenant, private KpiCatalogueService $kpis) {}

    public function kpis(): JsonResponse
    {
        return response()->json(['data' => $this->kpis->catalogue($this->tenant->id())]);
    }

    public function queries(): JsonResponse
    {
        $out = [];
        foreach (KpiQueryRegistry::definitions() as $key => $q) {
            $out[] = ['query_key' => $key, 'label' => $q['label'], 'unit' => $q['unit'], 'sources' => $q['sources'], 'date_basis' => $q['date_basis'] ?? null,
                'filters' => array_keys($q['filters']), 'resource' => $q['record']['resource']];
        }

        return response()->json(['data' => $out]);
    }

    public function kpi(string $code): JsonResponse
    {
        return response()->json(['data' => ['effective' => $this->kpis->resolve($this->tenant->id(), $code), 'versions' => $this->kpis->versions($this->tenant->id(), $code)]]);
    }

    public function value(Request $r, string $code, KpiEvaluator $eval): JsonResponse
    {
        [$period, $filters] = $this->params($r);
        $kpi = $this->kpis->resolve($this->tenant->id(), $code);

        return response()->json(['data' => ['kpi' => ['code' => $kpi['code'], 'version' => $kpi['version'], 'status' => $kpi['status']], 'period' => $period, 'filters' => $filters]
            + $eval->value($this->tenant->id(), $kpi, $period, $filters)]);
    }

    public function drill(Request $r, string $code, KpiEvaluator $eval): JsonResponse
    {
        [$period, $filters] = $this->params($r);
        $kpi = $this->kpis->resolve($this->tenant->id(), $code);

        return response()->json(['data' => ['kpi' => ['code' => $kpi['code'], 'version' => $kpi['version']]]
            + $eval->drill($this->tenant->id(), $kpi, $period, $filters, (int) $r->query('page', 1), (int) $r->query('per_page', 50))]);
    }

    public function draft(Request $r): JsonResponse
    {
        $d = $r->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/'], 'name' => 'required|string|max:255', 'definition' => 'required|string|max:4000',
            'formula' => 'nullable|string|max:2000', 'query_key' => 'required|string|max:80', 'date_basis' => 'nullable|string|max:64', 'filters' => 'nullable|array',
            'currency' => 'nullable|string|size:3', 'owner' => 'required|string|max:120',
        ]);

        return response()->json(['data' => $this->kpis->draft($this->tenant->id(), $r->user(), $d)], 201);
    }

    public function submit(Request $r, string $definition): JsonResponse
    {
        return response()->json(['data' => $this->kpis->submit($this->tenant->id(), $definition, $r->user())]);
    }

    public function approve(Request $r, string $definition): JsonResponse
    {
        return response()->json(['data' => $this->kpis->approve($this->tenant->id(), $definition, $r->user())]);
    }

    public function reject(Request $r, string $definition): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->kpis->reject($this->tenant->id(), $definition, $r->user(), $d['reason'])]);
    }

    public function retire(Request $r, string $definition): JsonResponse
    {
        return response()->json(['data' => $this->kpis->retire($this->tenant->id(), $definition, $r->user())]);
    }

    public function dashboards(DashboardRegistry $d): JsonResponse
    {
        return response()->json(['data' => $d->index()]);
    }

    public function dashboard(string $dashboard, DashboardRegistry $d): JsonResponse
    {
        return response()->json(['data' => $d->data($this->tenant->id(), $dashboard)]);
    }

    public function dashboardDrill(Request $r, string $dashboard, string $tile, DashboardRegistry $d): JsonResponse
    {
        [, $filters] = $this->params($r);

        return response()->json(['data' => $d->drill($this->tenant->id(), $dashboard, $tile, $filters, (int) $r->query('page', 1), (int) $r->query('per_page', 50))]);
    }

    public function reports(ReportCatalogue $c): JsonResponse
    {
        return response()->json(['data' => $c->catalogue($this->tenant->id())]);
    }

    public function report(string $report, ReportCatalogue $c): JsonResponse
    {
        return response()->json(['data' => $c->show($this->tenant->id(), $report)]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function params(Request $r): array
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'last_days' => 'nullable|integer|min:1|max:3660', 'filters' => 'nullable|array',
            'filters.*' => 'nullable', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:'.KpiEvaluator::MAX_PAGE]);
        foreach ((array) ($d['filters'] ?? []) as $k => $v) {
            foreach ((array) $v as $x) {
                if (! is_scalar($x) || strlen((string) $x) > 120) {
                    abort(422, "Filter {$k} values must be short scalar values.");
                }
            }
        }

        return [array_filter(array_intersect_key($d, array_flip(['from', 'to', 'last_days']))), (array) ($d['filters'] ?? [])];
    }
}
