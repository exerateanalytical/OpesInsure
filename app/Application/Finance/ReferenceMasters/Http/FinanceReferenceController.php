<?php

declare(strict_types=1);

namespace App\Application\Finance\ReferenceMasters\Http;

use App\Application\Finance\ReferenceMasters\FinanceReferenceCatalogue;
use App\Application\Finance\ReferenceMasters\FinanceReferenceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Agent GP6 — banking / payment / GL reference masters API (gap closure pack 06). Tenant configuration is tenant-scoped. */
final class FinanceReferenceController
{
    public function __construct(private TenantContext $tenant, private FinanceReferenceService $svc) {}

    public function institutions(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->institutions(['type' => $r->query('type'), 'status' => $r->query('status'),
            'production_only' => $r->boolean('production_only')])]);
    }

    public function updateInstitution(string $institution, Request $r): JsonResponse
    {
        $d = $r->validate(['legal_name' => 'sometimes|string|max:255', 'trade_name' => 'nullable|string|max:255', 'bank_code' => 'nullable|string|max:20',
            'bic_swift' => 'nullable|string|max:11', 'operating_status' => 'nullable|string|max:30', 'head_office_city' => 'nullable|string|max:120',
            'website' => 'nullable|url', 'source_url' => 'nullable|url', 'verification_status' => 'sometimes|in:'.implode(',', FinanceReferenceCatalogue::GAP_STATUSES),
            'phones' => 'sometimes|array', 'branches' => 'sometimes|array', 'aliases' => 'sometimes|array', 'effective_from' => 'nullable|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->svc->updateInstitution($institution, $d, $r->user()->id, $d['reason'])]);
    }

    public function profiles(): JsonResponse
    {
        $rows = array_map(fn ($p) => (array) $p + ['missing' => $this->svc->profileGaps($p)], $this->svc->profiles($this->tenant->id()));

        return response()->json(['data' => $rows, 'meta' => ['provider_types' => FinanceReferenceCatalogue::PROVIDER_TYPES, 'settlement_cycles' => FinanceReferenceCatalogue::SETTLEMENT_CYCLES]]);
    }

    public function createProfile(Request $r): JsonResponse
    {
        return $this->saveProfile($r, null);
    }

    public function updateProfile(string $profile, Request $r): JsonResponse
    {
        return $this->saveProfile($r, $profile);
    }

    private function saveProfile(Request $r, ?string $profile): JsonResponse
    {
        $adapters = array_keys((array) config('payments.providers', [])) ?: ['fake', 'maviance', 'campay', 'mtn_momo', 'orange_money'];
        $d = $r->validate([
            'provider_type' => ($profile ? 'sometimes' : 'required').'|in:'.implode(',', FinanceReferenceCatalogue::PROVIDER_TYPES),
            'adapter_provider' => 'nullable|string|in:'.implode(',', $adapters),
            'financial_institution_id' => 'nullable|uuid', 'connection_id' => 'nullable|uuid', 'legal_entity_id' => 'nullable|uuid', 'api_base_url' => 'nullable|url|max:255',
            'environment' => 'sometimes|in:SANDBOX,PRODUCTION', 'merchant_identifier' => 'nullable|string|max:120', 'collection_account' => 'nullable|string|max:80',
            'settlement_account' => 'nullable|string|max:80', 'callback_profile' => 'sometimes|array', 'reconciliation_reference_rules' => 'sometimes|array',
            'settlement_cycle' => 'nullable|in:'.implode(',', FinanceReferenceCatalogue::SETTLEMENT_CYCLES), 'fees' => 'sometimes|array',
            'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from']);

        return response()->json(['data' => $this->svc->saveProfile($this->tenant->id(), $d, $r->user()->id, $profile)], $profile ? 200 : 201);
    }

    public function submitProfile(string $profile): JsonResponse
    {
        return response()->json(['data' => $this->svc->submitProfile($this->tenant->id(), $profile)]);
    }

    public function decideProfile(string $profile, Request $r): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|in:APPROVE,REJECT,SUSPEND', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->svc->decideProfile($this->tenant->id(), $profile, $d['decision'], $r->user()->id, $d['reason'])]);
    }

    public function controlAccounts(): JsonResponse
    {
        return response()->json(['data' => $this->svc->controlAccounts($this->tenant->id())]);
    }

    public function proposeControlAccount(Request $r): JsonResponse
    {
        $d = $r->validate(['control_code' => 'required|string|max:40', 'ledger_account_code' => 'required|string|max:64', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->svc->proposeControlAccount($this->tenant->id(), $d['control_code'], $d['ledger_account_code'], $r->user()->id, $d['reason'])], 201);
    }

    public function approveControlAccount(string $mapping, Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->approveControlAccount($this->tenant->id(), $mapping, $r->user()->id)]);
    }

    public function glEventMappings(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->svc->glEventMappings($this->tenant->id(), strtoupper((string) $r->query('currency', 'XAF')))]);
    }

    public function costCentres(): JsonResponse
    {
        return response()->json(['data' => $this->svc->costCentres($this->tenant->id())]);
    }

    public function createCostCentre(Request $r): JsonResponse
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'], 'name' => 'required|string|max:255', 'parent_id' => 'nullable|uuid',
            'branch_id' => 'nullable|uuid', 'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from']);

        return response()->json(['data' => $this->svc->createCostCentre($this->tenant->id(), $d, $r->user()->id)], 201);
    }

    public function costCentreStatus(string $costCentre, Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'required|in:ACTIVE,INACTIVE', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->svc->setCostCentreStatus($this->tenant->id(), $costCentre, $d['status'], $d['reason'])]);
    }
}
