<?php

declare(strict_types=1);

namespace App\Application\Rating\Http;

use App\Application\Identity\OwnershipScope;
use App\Application\Rating\ChargeTableService;
use App\Application\Rating\RatingService;
use App\Application\Rating\TariffGovernanceService;
use App\Application\Temporal\ReferenceInstant;
use App\Domain\Tenancy\TenantContext;
use App\Models\InsuranceProduct;
use App\Models\Quote;
use App\Models\TariffVersion;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RAT-001..005 HTTP surface (routes/rating.php). Create/submit/approve stay on the existing
 * TariffController (routes/api.php); this adds the rest of the PRE §75 lifecycle, charge tables,
 * rating-run snapshots/reproduction and the stateless preview (POST /v1/insurance/rate).
 */
final class RatingController
{
    public function __construct(private readonly OwnershipScope $own) {}

    public function tariff(string $tariff): JsonResponse
    {
        $t = TariffVersion::findOrFail($tariff);

        // occurred_at has second precision; same-second steps are ordered by their place in the PRE §75 lifecycle.
        $rank = array_flip(['DRAFT', 'IN_REVIEW', 'REJECTED', 'APPROVED', 'SCHEDULED', 'ACTIVE', 'EXPIRED', 'RETIRED']);
        $history = DB::table('tariff_status_history')->where('tariff_version_id', $t->id)->get()
            ->sortBy(fn ($h) => [(string) $h->occurred_at, $rank[$h->to_status] ?? 99])->values();

        return response()->json(['data' => [...$t->toArray(), 'history' => $history]]);
    }

    public function reject(Request $r, string $tariff, TariffGovernanceService $s): JsonResponse { return $this->transition($r, $tariff, 'reject', $s); }

    public function schedule(Request $r, string $tariff, TariffGovernanceService $s): JsonResponse { return $this->transition($r, $tariff, 'schedule', $s); }

    public function activate(Request $r, string $tariff, TariffGovernanceService $s): JsonResponse { return $this->transition($r, $tariff, 'activate', $s); }

    public function expire(Request $r, string $tariff, TariffGovernanceService $s): JsonResponse { return $this->transition($r, $tariff, 'expire', $s); }

    private function transition(Request $r, string $tariff, string $event, TariffGovernanceService $s): JsonResponse
    {
        $d = $r->validate(['notes' => 'required|string|min:10|max:2000', 'effective_until' => 'nullable|date']);
        $t = TariffVersion::findOrFail($tariff);
        $t = match ($event) {
            'reject' => $s->reject($t, $r->user(), $d['notes']),
            'schedule' => $s->schedule($t, $r->user(), $d['notes']),
            'activate' => $s->activate($t, $r->user(), $d['notes']),
            'expire' => $s->expire($t, $r->user(), $d['notes'], $d['effective_until'] ?? null),
        };

        return response()->json(['data' => $t]);
    }

    public function chargeCodes(): JsonResponse
    {
        return response()->json(['data' => DB::table('rating_charge_codes')->orderBy('kind')->orderBy('code')->get(),
            'meta' => ['notice' => 'Rates and legal references are UNVERIFIED pending owner confirmation (OQ-9).']]);
    }

    public function chargeTables(string $kind): JsonResponse
    {
        return response()->json(['data' => DB::table(ChargeTableService::TABLES[$kind])->orderByDesc('effective_from')->orderByDesc('version')->limit(200)->get()
            ->map(fn ($row) => [...(array) $row, 'rules' => json_decode($row->rules, true)])]);
    }

    public function storeChargeTable(Request $r, string $kind, ChargeTableService $s): JsonResponse
    {
        $d = $r->validate([
            'line_code' => [Rule::requiredIf($kind === 'tax'), 'nullable', 'string', 'max:32'], 'jurisdiction' => 'nullable|string|size:2',
            'code' => [Rule::requiredIf($kind === 'fee'), 'nullable', 'string', 'max:64'], 'tenant_id' => 'nullable|uuid|exists:tenants,id',
            'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'rules' => 'required|array', 'rules.charges' => 'required|array|min:1',
            'data_status' => 'nullable|in:DEMO_UNVERIFIED,OWNER_CONFIRMED', 'source_reference' => 'nullable|string|max:255', 'legal_basis' => 'nullable|string|max:2000',
        ]);

        return response()->json(['data' => $s->create($kind, $d, $r->user())], 201);
    }

    public function approveChargeTable(Request $r, string $kind, string $id, ChargeTableService $s): JsonResponse
    {
        return response()->json(['data' => $s->approve($kind, $id, $r->user())]);
    }

    public function requestChargeVerification(Request $r, string $kind, string $id, ChargeTableService $s): JsonResponse
    {
        $d = $r->validate(['legal_basis' => 'required|string|min:5|max:2000', 'source_reference' => 'required|string|max:255',
            'source_document' => 'nullable|string|max:500', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $s->requestVerification($kind, $id, $d, $r->user())]);
    }

    public function decideChargeVerification(Request $r, string $kind, string $id, ChargeTableService $s): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|in:CONFIRM,REJECT', 'notes' => 'required|string|min:10|max:2000']);

        return response()->json(['data' => $s->decideVerification($kind, $id, $d['decision'] === 'CONFIRM', $d['notes'], $r->user())]);
    }

    public function run(string $run): JsonResponse
    {
        $row = $this->runRow($run);

        return response()->json(['data' => [...(array) $row, ...collect(['input_snapshot', 'output_snapshot', 'resolved_versions', 'engine_result', 'branch_allocation'])
            ->mapWithKeys(fn ($k) => [$k => $row->{$k} === null ? null : json_decode($row->{$k}, true)])->all()]]);
    }

    public function reproduce(string $run, RatingService $s): JsonResponse
    {
        $this->runRow($run);
        try {
            return response()->json(['data' => $s->reproduce($run)]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['rating_run' => $e->getMessage()]);
        }
    }

    /**
     * Stateless preview (traceability §3.1: PRE POST /insurance/rate exists only as a preview of
     * quotes/{quote}/rate). Prices the quote's facts on reference_date (default today) for every eligible
     * product, or one product; persists nothing.
     */
    public function preview(Request $r, RatingService $s): JsonResponse
    {
        $d = $r->validate(['quote_id' => 'required|uuid', 'product_id' => 'nullable|uuid', 'reference_date' => 'nullable|date', 'recorded_as_of' => 'nullable|date']);
        $quote = $this->own->apply(Quote::where('tenant_id', app(TenantContext::class)->id()), $r->user())->findOrFail($d['quote_id']);
        $tz = (string) config('app.timezone', 'Africa/Douala');
        $at = ReferenceInstant::at(isset($d['reference_date']) ? CarbonImmutable::parse($d['reference_date'], $tz)->setTime(12, 0) : now(), $tz);
        $asOf = isset($d['recorded_as_of']) ? CarbonImmutable::parse($d['recorded_as_of']) : null;
        $products = InsuranceProduct::where('line_code', $quote->line_code)->when($d['product_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        $out = [];
        foreach ($products as $product) {
            $tariff = $s->tariffFor($product, $at, $asOf);
            if (! $tariff) {
                continue;
            }
            try {
                $res = $s->price($tariff, $quote->risk_facts, $quote->line_code, $quote->tenant_id, $at, $asOf);
                $out[] = ['product_id' => $product->id, 'carrier_id' => $product->carrier_id, 'tariff_version_id' => $tariff->id, 'tariff_version' => $tariff->version,
                    'pricing' => $res['pricing']->toArray(), 'engine_result' => $res['engine_result']->toArray()];
            } catch (DomainException $e) {
                $out[] = ['product_id' => $product->id, 'tariff_version_id' => $tariff->id, 'error' => $e->getMessage()];
            }
        }

        return response()->json(['data' => $out, 'meta' => ['stateless' => true, 'quote_id' => $quote->id, 'reference_date' => $at->businessDate()]]);
    }

    private function runRow(string $run): object
    {
        $row = DB::table('rating_runs')->where('id', $run)->where('tenant_id', app(TenantContext::class)->id())->first();

        return $row ?? abort(404);
    }
}
