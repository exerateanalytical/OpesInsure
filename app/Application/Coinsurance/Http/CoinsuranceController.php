<?php

declare(strict_types=1);

namespace App\Application\Coinsurance\Http;

use App\Application\Coinsurance\CoinsuranceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-COI-001 co-insurance API (routes: "Batch 13D" block in routes/api.php). */
final class CoinsuranceController
{
    public function __construct(private readonly CoinsuranceService $service, private readonly TenantContext $tenant) {}

    public function index(Request $r): JsonResponse
    {
        $r->validate(['policy_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->service->list($this->tenant->id(), $r->query('policy_id'))]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'reference' => 'required|string|max:64',
            'policy_id' => 'nullable|uuid',
            'currency' => 'nullable|in:XAF',
            'allow_partial_placement' => 'nullable|boolean',
            'lead_rights' => 'nullable|array',
            'lead_rights.*' => 'string|max:64',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after:effective_from',
            'participants' => 'required|array|min:2|max:50',
            'participants.*.carrier_id' => 'required|uuid',
            'participants.*.role' => 'required|string|max:32',
            'participants.*.share_bps' => 'required|integer|min:1|max:10000',
            'participants.*.share_overrides_bps' => 'nullable|array',
            'settlement_method' => 'nullable|string|max:32|regex:/^[A-Z0-9_]+$/',
            'agreement_document_id' => 'nullable|uuid',
        ]);

        return response()->json(['data' => $this->service->create($this->tenant->id(), $d, $r->user())], 201);
    }

    public function show(string $arrangement): JsonResponse
    {
        return response()->json(['data' => $this->service->show($this->tenant->id(), $arrangement)]);
    }

    public function activate(Request $r, string $arrangement): JsonResponse
    {
        return response()->json(['data' => $this->service->activate($this->tenant->id(), $arrangement, $r->user())]);
    }

    public function terminate(Request $r, string $arrangement): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000']);

        return response()->json(['data' => $this->service->terminate($this->tenant->id(), $arrangement, $d['reason'], $r->user())]);
    }

    public function preview(Request $r, string $arrangement): JsonResponse
    {
        $d = $r->validate(['basis' => 'required|string', 'total_minor' => 'required|integer']);

        return response()->json(['data' => $this->service->compute($this->service->show($this->tenant->id(), $arrangement), $d['basis'], (int) $d['total_minor'])]);
    }

    public function apportion(Request $r, string $arrangement): JsonResponse
    {
        $d = $r->validate([
            'basis' => 'required|in:PREMIUM,CLAIM,COMMISSION,RESERVE,SETTLEMENT',
            'total_minor' => 'required|integer',
            'source_type' => 'required|string|max:64',
            'source_id' => 'nullable|uuid',
            'idempotency_key' => 'required|string|max:128',
        ]);
        $row = $this->service->apportion($this->tenant->id(), $arrangement, $d['basis'], (int) $d['total_minor'], $d['source_type'], $d['source_id'] ?? null, $d['idempotency_key'], $r->user());

        return response()->json(['data' => $row], $row['replayed'] ? 200 : 201);
    }

    public function apportionments(string $arrangement): JsonResponse
    {
        return response()->json(['data' => $this->service->apportionments($this->tenant->id(), $arrangement)]);
    }
}
