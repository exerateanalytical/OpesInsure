<?php

declare(strict_types=1);

namespace App\Application\Providers\Http;

use App\Application\Providers\ProviderNetworkService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-PRV-002 — medical service catalogue, insurer networks, memberships, contracts, versioned tariffs. */
final class ProviderNetworkController
{
    public function __construct(private readonly ProviderNetworkService $svc, private readonly TenantContext $tenant) {}

    public function services(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->medicalServices($r->query('category'))]);
    }

    public function addService(Request $r): JsonResponse
    {
        $d = $r->validate(['code' => 'required|string|max:64|regex:/^[A-Za-z0-9_.-]+$/', 'name' => 'required|string|max:255', 'category_code' => 'required|string|max:64']);

        return response()->json(['data' => $this->svc->addMedicalService($d)], 201);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->svc->networks($this->tenant->id())]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|max:64|regex:/^[A-Za-z0-9_]+$/', 'name' => 'required|string|max:255', 'network_type_code' => 'required|string|max:64',
            'category' => 'nullable|in:HEALTH,GARAGE,ADJUSTER,EXPERT,SURVEYOR', 'carrier_id' => 'nullable|uuid|exists:carriers,id',
        ]);

        return response()->json(['data' => $this->svc->createNetwork($this->tenant->id(), $d, $r->user()?->id)], 201);
    }

    public function members(Request $r, string $network): JsonResponse
    {
        return response()->json(['data' => $this->svc->members($this->tenant->id(), $network, $r->query('as_of'))]);
    }

    public function addMember(Request $r, string $network): JsonResponse
    {
        $d = $r->validate(['provider_id' => 'required|uuid', 'facility_id' => 'nullable|uuid', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date']);

        return response()->json(['data' => $this->svc->addMember($this->tenant->id(), $network, $d, $r->user()?->id)], 201);
    }

    public function endMember(Request $r, string $membership): JsonResponse
    {
        $d = $r->validate(['effective_to' => 'required|date', 'reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->svc->endMembership($this->tenant->id(), $membership, $d['effective_to'], $d['reason'])]);
    }

    public function addContract(Request $r, string $network): JsonResponse
    {
        $d = $r->validate([
            'provider_id' => 'required|uuid', 'contract_number' => 'required|string|max:80', 'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after:effective_from', 'settlement_mode' => 'nullable|in:CASHLESS,REIMBURSEMENT,BOTH',
            'document_reference' => 'nullable|string|max:500',
        ]);

        return response()->json(['data' => $this->svc->createContract($this->tenant->id(), $network, $d, $r->user()?->id)], 201);
    }

    public function draftTariff(Request $r, string $contract): JsonResponse
    {
        $d = $r->validate([
            'effective_from' => 'required|date', 'currency' => 'nullable|string|size:3', 'lines' => 'required|array|min:1',
            'lines.*.medical_service_id' => 'required|uuid|exists:medical_services,id', 'lines.*.price_minor' => 'required|integer|min:0',
            'lines.*.contracted_price_minor' => 'required|integer|min:0', 'lines.*.copay_minor' => 'nullable|integer|min:0',
            'lines.*.insurer_share_percent' => 'required|numeric|between:0,100',
        ]);

        return response()->json(['data' => $this->svc->draftTariff($this->tenant->id(), $contract, $d['effective_from'], $d['currency'] ?? 'XAF', $d['lines'], $r->user()?->id)], 201);
    }

    public function approveTariff(Request $r, string $tariff): JsonResponse
    {
        return response()->json(['data' => $this->svc->approveTariff($this->tenant->id(), $tariff, (string) $r->user()->id)]);
    }

    public function price(Request $r, string $contract): JsonResponse
    {
        $d = $r->validate(['medical_service_id' => 'required|uuid', 'as_of' => 'nullable|date']);

        return response()->json(['data' => $this->svc->priceFor($this->tenant->id(), $contract, $d['medical_service_id'], $d['as_of'] ?? null)]);
    }
}
