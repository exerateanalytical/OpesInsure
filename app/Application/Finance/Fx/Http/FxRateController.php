<?php

declare(strict_types=1);

namespace App\Application\Finance\Fx\Http;

use App\Application\Finance\Fx\FxRateService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Batch 9-7: REQ-PAY-013 FX rate API (append-only rates, as-of lookup). */
final class FxRateController
{
    public function __construct(private TenantContext $tenant) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['base_currency' => 'nullable|string|size:3', 'quote_currency' => 'nullable|string|size:3']);
        $tid = $this->tenant->id();
        $q = DB::table('fx_rates')->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tid))
            ->when($d['base_currency'] ?? null, fn ($q, $c) => $q->where('base_currency', strtoupper($c)))
            ->when($d['quote_currency'] ?? null, fn ($q, $c) => $q->where('quote_currency', strtoupper($c)));

        return response()->json(['data' => $q->orderByDesc('effective_at')->limit(500)->get()]);
    }

    public function store(Request $r, FxRateService $fx): JsonResponse
    {
        $d = $r->validate([
            'base_currency' => 'required|string|size:3', 'quote_currency' => 'required|string|size:3', 'rate' => 'required|numeric|gt:0',
            'source' => 'required|in:'.implode(',', FxRateService::SOURCES), 'source_reference' => 'nullable|string|max:128', 'effective_at' => 'required|date',
        ]);

        return response()->json(['data' => $fx->record($this->tenant->id(), $d, $r->user())], 201);
    }

    public function lookup(Request $r, FxRateService $fx): JsonResponse
    {
        $d = $r->validate(['from' => 'required|string|size:3', 'to' => 'required|string|size:3', 'as_of' => 'nullable|date']);
        $plan = $fx->resolve($this->tenant->id(), $d['from'], $d['to'], $d['as_of'] ?? null);

        return response()->json(['data' => [
            'method' => $plan['method'], 'pegged' => FxRateService::pegged($d['from'], $d['to']),
            'legs' => array_map(fn ($l) => ['fx_rate_id' => $l['rate']->id, 'base_currency' => $l['rate']->base_currency, 'quote_currency' => $l['rate']->quote_currency,
                'rate' => $l['rate']->rate, 'source' => $l['rate']->source, 'effective_at' => $l['rate']->effective_at, 'inverse' => $l['inverse']], $plan['legs']),
        ]]);
    }
}
