<?php

declare(strict_types=1);

namespace App\Application\Finance\Reports\Http;

use App\Application\Finance\Reports\FinanceReportRegistry;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Batch 10-10 — REQ-ACC-005 finance reports (ESR FIN-024): catalogue + run as JSON or CSV (?format=csv). Read-only. */
final class FinanceReportController
{
    public function __construct(private TenantContext $tenant) {}

    public function index(FinanceReportRegistry $reports): JsonResponse
    {
        return response()->json(['data' => $reports->catalogue()]);
    }

    public function show(Request $r, string $report, FinanceReportRegistry $reports): JsonResponse|Response
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'as_of' => 'nullable|date', 'currency' => 'nullable|string|size:3', 'format' => 'nullable|in:json,csv']);
        $result = $reports->run($this->tenant->id(), strtoupper($report), array_filter(array_intersect_key($d, array_flip(['from', 'to', 'as_of', 'currency']))));

        if (($d['format'] ?? 'json') === 'csv') {
            return response(FinanceReportRegistry::toCsv($result), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$result['code'].'-'.now()->format('Ymd').'.csv"',
            ]);
        }

        return response()->json(['data' => $result]);
    }
}
