<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Recoveries\Http;

use App\Application\Reinsurance\Recoveries\RecoveryService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Batch 14C / E7 — REQ-REI-004 reinsurance recoveries (REI screens as APIs). */
final class RecoveryController
{
    public function __construct(private readonly TenantContext $tenant, private readonly RecoveryService $service) {}

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['status' => 'sometimes|in:ESTIMATED,NOTIFIED,AGREED,BILLED,SETTLED,CLOSED,DISPUTED', 'treaty_id' => 'sometimes|uuid', 'claim_id' => 'sometimes|uuid', 'large_loss' => 'sometimes|boolean']);

        return response()->json(['data' => $this->service->index($this->tenant->id(), $f)]);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->service->summary($this->tenant->id())]);
    }

    public function claim(string $claim): JsonResponse
    {
        return response()->json(['data' => $this->service->forClaim($this->tenant->id(), $claim)]);
    }

    public function preview(string $claim): JsonResponse
    {
        return response()->json(['data' => $this->service->preview($this->tenant->id(), $claim)]);
    }

    public function estimate(Request $r, string $claim): JsonResponse
    {
        return response()->json(['data' => $this->service->estimate($this->tenant->id(), $claim, $r->user()?->id)]);
    }

    public function show(string $recovery): JsonResponse
    {
        return response()->json(['data' => $this->service->show($this->tenant->id(), $recovery)]);
    }

    public function notify(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['note' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->service->notify($this->tenant->id(), $recovery, $r->user()?->id, $d['note'] ?? null)]);
    }

    public function agree(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['amount_minor' => 'nullable|integer|min:1', 'note' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->service->agree($this->tenant->id(), $recovery, $d['amount_minor'] ?? null, $r->user()?->id, $d['note'] ?? null)]);
    }

    public function bill(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['due_at' => 'required|date']);

        return response()->json(['data' => $this->service->bill($this->tenant->id(), $recovery, $d['due_at'], $r->user()?->id)]);
    }

    public function receive(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['reinsurer_id' => 'required|uuid', 'amount_minor' => 'required|integer|min:1', 'reference' => 'required|string|max:128']);

        return response()->json(['data' => $this->service->receive($this->tenant->id(), $recovery, $d['reinsurer_id'], (int) $d['amount_minor'], $d['reference'], $r->user()?->id)]);
    }

    public function dispute(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->service->dispute($this->tenant->id(), $recovery, $d['reason'], $r->user()?->id)]);
    }

    public function close(Request $r, string $recovery): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->service->close($this->tenant->id(), $recovery, $d['reason'], $r->user()?->id)]);
    }

    public function threshold(Request $r, string $treaty): JsonResponse
    {
        $d = $r->validate(['threshold_minor' => 'present|nullable|integer|min:1', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->service->setLargeLossThreshold($this->tenant->id(), $treaty, $d['threshold_minor'], $d['reason'])]);
    }
}
