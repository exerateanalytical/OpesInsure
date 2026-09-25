<?php

declare(strict_types=1);

namespace App\Application\Providers\Http;

use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-PRV-001 / REQ-PRV-004 — canonical provider master (/v1/providers…). */
final class ProviderController
{
    public function __construct(private readonly ProviderRegistry $providers, private readonly ProviderNetworkService $network, private readonly TenantContext $tenant) {}

    public function index(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->providers->list($r->only(['category', 'status']))]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'category' => ['required', Rule::in(array_keys(ProviderRegistry::CATEGORIES))], 'name' => 'required|string|max:255',
            'provider_type_code' => 'required|string|max:64', 'party_id' => 'nullable|uuid|exists:parties,id',
            'registration_number' => 'nullable|string|max:120', 'city_code' => 'nullable|string|max:64', 'region_code' => 'nullable|string|max:64',
            'legacy_health_provider_id' => 'nullable|uuid|exists:health_providers,id', 'details' => 'nullable|array',
        ]);

        return response()->json(['data' => $this->providers->register($d, $r->user()?->id)], 201);
    }

    public function show(string $provider): JsonResponse
    {
        return response()->json(['data' => $this->providers->tree($provider)]);
    }

    public function transition(Request $r, string $provider): JsonResponse
    {
        $d = $r->validate(['to' => ['required', Rule::in(array_keys(ProviderRegistry::TRANSITIONS))], 'reason' => 'nullable|string|max:2000', 'evidence_reference' => 'nullable|string|max:500']);

        return response()->json(['data' => $this->providers->transition($provider, $d['to'], $d['reason'] ?? null, $d['evidence_reference'] ?? null, $r->user()?->id)]);
    }

    public function history(string $provider): JsonResponse
    {
        return response()->json(['data' => $this->providers->history($provider)]);
    }

    public function addFacility(Request $r, string $provider): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|max:64', 'name' => 'required|string|max:255', 'facility_type_code' => 'nullable|string|max:64',
            'city_code' => 'nullable|string|max:64', 'region_code' => 'nullable|string|max:64', 'address' => 'nullable|string|max:1000',
            'specialties' => 'nullable|array', 'specialties.*' => 'string|max:64',
        ]);

        return response()->json(['data' => $this->providers->addFacility($provider, $d)], 201);
    }

    public function addFacilityService(Request $r, string $facility): JsonResponse
    {
        $d = $r->validate(['medical_service_id' => 'required|uuid', 'specialty_code' => 'nullable|string|max:64']);

        return response()->json(['data' => $this->providers->addFacilityService($facility, $d['medical_service_id'], $d['specialty_code'] ?? null)], 201);
    }

    public function mapCode(Request $r, string $provider): JsonResponse
    {
        $d = $r->validate(['provider_code' => 'required|string|max:120', 'medical_service_id' => 'required|uuid']);

        return response()->json(['data' => $this->network->mapProviderCode($provider, $d['provider_code'], $d['medical_service_id'])], 201);
    }

    public function relate(Request $r, string $provider): JsonResponse
    {
        $d = $r->validate([
            'to_party_id' => 'required|uuid', 'type' => ['required', Rule::in(ProviderRegistry::RELATIONSHIP_TYPES)],
            'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date|after:valid_from',
        ]);

        return response()->json(['data' => $this->providers->relate($provider, $d['to_party_id'], $d['type'], $d['valid_from'] ?? null, $d['valid_to'] ?? null,
            $this->tenant->id(), $r->user()?->id)], 201);
    }
}
