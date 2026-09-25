<?php

declare(strict_types=1);

namespace App\Application\Capabilities\Http;

use App\Application\Capabilities\CapabilityCatalogue;
use App\Application\Capabilities\CapabilityPinner;
use App\Application\Capabilities\CapabilityProfileService;
use App\Application\Capabilities\CapabilityResolver;
use App\Application\Capabilities\Models\CapabilityPin;
use App\Application\Capabilities\Models\CapabilityProfile;
use App\Models\Carrier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-AOM-001 API: capability catalogue, versioned profiles (maker-checker), resolution, maturity, pins. */
final class CapabilityController
{
    public function __construct(private readonly CapabilityProfileService $profiles, private readonly CapabilityResolver $resolver) {}

    public function catalogue(): JsonResponse
    {
        return response()->json(['data' => ['execution_modes' => CapabilityCatalogue::EXECUTION_MODES, 'capabilities' => CapabilityCatalogue::describe(), 'maturity_levels' => CapabilityCatalogue::MATURITY]]);
    }

    public function index(string $carrier): JsonResponse
    {
        $c = Carrier::findOrFail($carrier);

        return response()->json(['data' => CapabilityProfile::with('modes')->where('carrier_id', $c->id)->orderByDesc('version')->get()]);
    }

    public function show(string $profile): JsonResponse
    {
        $p = CapabilityProfile::with('modes')->findOrFail($profile);

        return response()->json(['data' => $p, 'meta' => ['incoherences' => $p->status === 'DRAFT' ? $this->profiles->incoherences($p) : []]]);
    }

    public function store(Request $r, string $carrier): JsonResponse
    {
        $c = Carrier::findOrFail($carrier);
        $d = $this->validateModes($r) + $r->validate(['notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->profiles->draft($c, $d['modes'], $r->user(), $d['notes'] ?? null)], 201);
    }

    public function replaceModes(Request $r, string $profile): JsonResponse
    {
        $d = $this->validateModes($r);

        return response()->json(['data' => $this->profiles->replaceModes(CapabilityProfile::findOrFail($profile), $d['modes'], $r->user())]);
    }

    public function submit(Request $r, string $profile): JsonResponse
    {
        return response()->json(['data' => $this->profiles->submit(CapabilityProfile::findOrFail($profile), $r->user())]);
    }

    public function approve(Request $r, string $profile): JsonResponse
    {
        return response()->json(['data' => $this->profiles->approve(CapabilityProfile::findOrFail($profile), $r->user())]);
    }

    public function reject(Request $r, string $profile): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->profiles->reject(CapabilityProfile::findOrFail($profile), $r->user(), $d['reason'])]);
    }

    public function resolve(Request $r, string $carrier): JsonResponse
    {
        $c = Carrier::findOrFail($carrier);
        $d = $r->validate(['capability' => ['nullable', Rule::in(array_keys(CapabilityCatalogue::CAPABILITIES))], 'product_id' => 'nullable|uuid', 'on' => 'nullable|date']);
        $on = isset($d['on']) ? new \DateTimeImmutable($d['on']) : null;
        $data = isset($d['capability'])
            ? $this->resolver->mode($c->id, $d['capability'], $d['product_id'] ?? null, $on)
            : $this->resolver->all($c->id, $d['product_id'] ?? null, $on);

        return response()->json(['data' => $data]);
    }

    public function maturity(Request $r, string $carrier): JsonResponse
    {
        $c = Carrier::findOrFail($carrier);
        $profile = $this->resolver->profileAt($c->id);
        $m = CapabilityResolver::maturity($this->resolver->all($c->id), $profile !== null);

        return response()->json(['data' => $m + ['profile_id' => $profile?->id, 'profile_version' => $profile?->version, 'note' => 'Integration state, not a quality ranking.']]);
    }

    public function pin(Request $r, CapabilityPinner $pinner): JsonResponse
    {
        $d = $r->validate([
            'subject_type' => ['required', Rule::in(CapabilityPinner::SUBJECT_TYPES)], 'subject_id' => 'required|uuid', 'carrier_id' => 'required|uuid|exists:carriers,id',
            'capability' => ['required', Rule::in(array_keys(CapabilityCatalogue::CAPABILITIES))], 'product_id' => 'nullable|uuid|exists:insurance_products,id',
        ]);
        $existing = $pinner->pinned($d['subject_type'], $d['subject_id'], $d['capability']);
        $pin = $existing ?? $pinner->pin($d['subject_type'], $d['subject_id'], $d['carrier_id'], $d['capability'], $d['product_id'] ?? null, $r->user());

        return response()->json(['data' => $pin], $existing ? 200 : 201);
    }

    public function pins(Request $r): JsonResponse
    {
        $d = $r->validate(['subject_type' => ['required', Rule::in(CapabilityPinner::SUBJECT_TYPES)], 'subject_id' => 'required|uuid']);

        return response()->json(['data' => CapabilityPin::where($d)->orderBy('capability')->get()]);
    }

    private function validateModes(Request $r): array
    {
        return $r->validate([
            'modes' => 'present|array', 'modes.*.capability' => 'required|string|max:40', 'modes.*.mode' => 'required|string|max:40',
            'modes.*.scope_product_id' => 'nullable|uuid', 'modes.*.scope_class_code' => 'nullable|string|max:64',
            'modes.*.config' => 'nullable|array', 'modes.*.fallback_mode' => 'nullable|string|max:40',
        ]);
    }
}
