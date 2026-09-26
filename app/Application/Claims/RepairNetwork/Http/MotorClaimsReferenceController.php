<?php

declare(strict_types=1);

namespace App\Application\Claims\RepairNetwork\Http;

use App\Application\Claims\RepairNetwork\RepairNetworkService;
use App\Application\Claims\Taxonomy\ClaimTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Agent GP3 — gap pack 03 API: claims taxonomies, motor classifications, garage network and technical experts. */
final class MotorClaimsReferenceController
{
    public function __construct(private readonly ClaimTaxonomy $taxonomy, private readonly RepairNetworkService $network) {}

    public function taxonomies(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->taxonomy->catalogue($r->query('locale') === 'fr' ? 'fr' : 'en')]);
    }

    public function resolveCause(Request $r): JsonResponse
    {
        $f = $r->validate(['code' => 'required|string|max:64', 'category' => 'nullable|string|max:32']);
        $code = ClaimTaxonomy::causeFor($f['code'], $f['category'] ?? null);

        return response()->json(['data' => ['input' => strtoupper($f['code']), 'category' => $f['category'] ?? null, 'cause_of_loss' => $code,
            'candidates' => ClaimTaxonomy::CAUSE_OF_LOSS[strtoupper($f['code'])] ?? []]], $code === null ? 422 : 200);
    }

    public function vehicleClasses(): JsonResponse
    {
        return response()->json(['data' => $this->taxonomy->vehicleClasses()]);
    }

    public function garages(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->network->list('GARAGE', $this->filters($r))]);
    }

    public function experts(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->network->list('EXPERT', $this->filters($r))]);
    }

    public function show(string $provider): JsonResponse
    {
        return response()->json(['data' => $this->network->show($provider)]);
    }

    public function capabilities(Request $r, string $provider): JsonResponse
    {
        $f = $r->validate(['kind' => ['required', Rule::in(['SERVICE', 'SPECIALTY', 'VEHICLE_MAKE'])], 'codes' => 'required|array|min:1', 'codes.*' => 'string|max:64']);

        return response()->json(['data' => $this->network->setCapabilities($provider, $f['kind'], $f['codes'], $r->user()->id, 'MANUAL')]);
    }

    public function verify(Request $r, string $provider): JsonResponse
    {
        $f = $r->validate(['source_url' => 'nullable|url|max:500', 'source_reference' => 'nullable|string|max:191']);

        return response()->json(['data' => $this->network->verifySource($provider, $f, $r->user())]);
    }

    private function filters(Request $r): array
    {
        return $r->validate(['status' => 'nullable|string|max:16', 'data_status' => 'nullable|string|max:32', 'city' => 'nullable|string|max:64', 'capability' => 'nullable|string|max:64']);
    }
}
