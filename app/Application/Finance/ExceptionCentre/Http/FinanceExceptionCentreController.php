<?php

declare(strict_types=1);

namespace App\Application\Finance\ExceptionCentre\Http;

use App\Application\Finance\ExceptionCentre\FinanceExceptionCentre;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Batch 10-10 — REQ-ACC-005 finance exception centre API (read-only). */
final class FinanceExceptionCentreController
{
    public function __construct(private TenantContext $tenant) {}

    public function index(Request $r, FinanceExceptionCentre $centre): JsonResponse
    {
        $d = $r->validate(['as_of' => 'nullable|date', 'sources' => 'nullable|array', 'sources.*' => 'string|in:'.implode(',', FinanceExceptionCentre::SOURCES)]);

        return response()->json(['data' => $centre->summary($this->tenant->id(), isset($d['as_of']) ? CarbonImmutable::parse($d['as_of']) : null, $d['sources'] ?? null)]);
    }
}
