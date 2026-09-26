<?php

declare(strict_types=1);

namespace App\Application\MarketData\Http;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\MarketData\MarketDataGates;
use App\Application\MarketData\MarketDataReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Gap closure 01 — market/product/agreement/commission gates (readiness) and agreement source verification. */
final class MarketDataController extends Controller
{
    public function readiness(MarketDataReadiness $readiness): JsonResponse
    {
        $pack = MarketDataGates::pack();
        $rows = [...$readiness->regulatory(), ...$readiness->distribution(), ...$readiness->commission(), ...$readiness->vehicles()];

        return response()->json(['data' => ['meta' => $pack['meta'] ?? [], 'sources' => $pack['sources'] ?? [], 'items' => $rows,
            'import_targets' => ['cima_insurer_authorizations', 'broker_directory_enrichment', 'commission_tables']]]);
    }

    public function verifyAgreementSource(Request $r, string $agreement, MarketDataGates $gates): JsonResponse
    {
        $v = $r->validate(['source_document' => ['required', 'string', 'max:512'], 'note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $gates->verifyAgreementSource($agreement, $r->user(), $v['source_document'], $v['note'] ?? null)]);
    }
}
