<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Setup\Http;

use App\Application\CarrierOperations\Setup\CarrierSetupService;
use App\Application\CarrierOperations\Setup\Models\CarrierSetup;
use App\Models\Carrier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-SET-002 API: insurer setup lifecycle, 23-item checklist, attestations, transitions. */
final class CarrierSetupController
{
    public function __construct(private readonly CarrierSetupService $setups) {}

    public function show(Request $r, string $carrier): JsonResponse
    {
        $setup = CarrierSetup::where('carrier_id', Carrier::findOrFail($carrier)->id)->firstOrFail();

        return response()->json($this->payload($setup, $r));
    }

    public function store(Request $r, string $carrier): JsonResponse
    {
        $d = $r->validate(['notes' => 'nullable|string|max:2000']);
        $setup = $this->setups->open(Carrier::findOrFail($carrier), $r->user(), $d['notes'] ?? null);

        return response()->json($this->payload($setup, $r), 201);
    }

    public function attest(Request $r, string $carrier, string $item): JsonResponse
    {
        $d = $r->validate(['status' => 'required|in:COMPLETE,NOT_APPLICABLE,PENDING', 'evidence_reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000']);
        $setup = CarrierSetup::where('carrier_id', $carrier)->firstOrFail();

        return response()->json(['data' => $this->setups->attest($setup, strtoupper($item), $d['status'], $d['evidence_reference'] ?? null, $d['notes'] ?? null, $r->user())]);
    }

    public function transition(Request $r, string $carrier): JsonResponse
    {
        $d = $r->validate(['event' => 'required|string|max:48', 'reason' => 'nullable|string|max:2000']);
        $setup = CarrierSetup::where('carrier_id', $carrier)->firstOrFail();

        return response()->json($this->payload($this->setups->transition($setup, $d['event'], $r->user(), $d['reason'] ?? null), $r));
    }

    private function payload(CarrierSetup $setup, Request $r): array
    {
        return ['data' => $setup, 'meta' => ['checklist' => $this->setups->evaluate($setup), 'available_events' => $this->setups->available($setup, $r->user())]];
    }
}
