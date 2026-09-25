<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MasterData;

use App\Application\MasterData\MasterDataMergeService;
use App\Application\MasterData\MasterDataOverrideService;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\MasterData\BrokerMasterDataMapping;
use App\Models\MasterData\CarrierMasterDataMapping;
use App\Models\MasterData\MasterDataMergeRequest;
use App\Models\MasterData\MasterDataTenantOverride;
use App\Models\MasterData\MasterDataValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-MDM-006 tenant overrides, private values and carrier/broker mappings; REQ-MDM-007 maker-checker merges
 * and duplicate detection. Tenant-scoped (tenant middleware); carriers/brokers map only for themselves.
 */
final class MasterDataOwnershipController
{
    public function __construct(
        private readonly MasterDataOverrideService $overrides,
        private readonly MasterDataMergeService $merges,
        private readonly TenantContext $tenant,
    ) {}

    public function overrides(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->overrides->overridesFor($this->tenant->id(), $r->query('domain'))]);
    }

    public function setOverride(Request $r): JsonResponse
    {
        $d = $r->validate(['value_id' => 'required|uuid', 'action' => 'required|string|in:HIDE,ALIAS,INTERNAL_CODE', 'text' => 'nullable|string|max:128']);
        $o = $this->overrides->setOverride($this->tenant->id(), $this->platformValue($d['value_id']), $d['action'], $d['text'] ?? null, $r->user()->id);

        return response()->json(['data' => $o->only(['id', 'value_id', 'action', 'alias', 'internal_code'])], 201);
    }

    public function removeOverride(Request $r, string $override): JsonResponse
    {
        $o = MasterDataTenantOverride::where('tenant_id', $this->tenant->id())->whereKey($override)->firstOrFail();
        $this->overrides->removeOverride($o, $r->user()->id);

        return response()->json(['data' => ['id' => $override, 'removed' => true]]);
    }

    public function addPrivateValue(Request $r, string $domain, string $list): JsonResponse
    {
        $d = $r->validate(['label_en' => 'required|string|max:200', 'label_fr' => 'nullable|string|max:200', 'parent_code' => 'nullable|string|max:128']);
        $v = $this->overrides->addPrivateValue($this->tenant->id(), $domain, $list, $d, $r->user()->id);

        return response()->json(['data' => $v->only(['id', 'domain_code', 'list_code', 'code', 'label_en', 'label_fr', 'parent_code', 'source_type'])], 201);
    }

    public function carrierMappings(Request $r, PartnerWorkspaceScope $scope): JsonResponse
    {
        $carrier = $this->carrier($r, $scope);

        return response()->json(['data' => CarrierMasterDataMapping::with('value:id,domain_code,list_code,code,label_en')->where('carrier_id', $carrier)->latest()->limit(500)->get()]);
    }

    public function putCarrierMapping(Request $r, PartnerWorkspaceScope $scope): JsonResponse
    {
        $d = $r->validate(['value_id' => 'required|uuid', 'external_code' => 'required|string|max:128', 'external_label' => 'nullable|string|max:255', 'target' => 'nullable|string|max:64']);
        $m = $this->overrides->mapForCarrier($this->carrier($r, $scope), $this->platformValue($d['value_id']), $d['external_code'], $d['external_label'] ?? null, $d['target'] ?? 'CODE', $r->user()->id);

        return response()->json(['data' => $m]);
    }

    public function brokerMappings(Request $r, PartnerWorkspaceScope $scope): JsonResponse
    {
        return response()->json(['data' => BrokerMasterDataMapping::with('value:id,domain_code,list_code,code,label_en')->where('partner_id', $this->broker($r, $scope))->latest()->limit(500)->get()]);
    }

    public function putBrokerMapping(Request $r, PartnerWorkspaceScope $scope): JsonResponse
    {
        $d = $r->validate(['value_id' => 'required|uuid', 'external_code' => 'required|string|max:128', 'external_label' => 'nullable|string|max:255']);
        $m = $this->overrides->mapForBroker($this->broker($r, $scope), $this->platformValue($d['value_id']), $d['external_code'], $d['external_label'] ?? null, $r->user()->id);

        return response()->json(['data' => $m]);
    }

    public function duplicates(): JsonResponse
    {
        return response()->json(['data' => $this->merges->duplicateGroups()]);
    }

    public function requestMerge(Request $r): JsonResponse
    {
        $d = $r->validate(['from_value_id' => 'required|uuid', 'into_value_id' => 'required|uuid', 'reason' => 'nullable|string|max:500']);
        $mr = $this->merges->request($this->platformValue($d['from_value_id']), $this->platformValue($d['into_value_id']), $r->user(), $d['reason'] ?? null);

        return response()->json(['data' => $mr], 201);
    }

    public function decideMerge(Request $r, string $merge): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|in:APPROVED,REJECTED', 'note' => 'nullable|string|max:500|required_if:decision,REJECTED']);
        $mr = MasterDataMergeRequest::findOrFail($merge);
        $mr = $d['decision'] === 'APPROVED' ? $this->merges->approveRequest($mr, $r->user(), $d['note'] ?? null) : $this->merges->rejectRequest($mr, $r->user(), $d['note']);

        return response()->json(['data' => $mr]);
    }

    private function platformValue(string $id): MasterDataValue
    {
        return MasterDataValue::whereNull('tenant_id')->whereKey($id)->firstOrFail();
    }

    private function carrier(Request $r, PartnerWorkspaceScope $scope): string
    {
        return $scope->carrierId($r->user(), $this->tenant->id()) ?? abort(403, 'Only an insurer user can manage insurer mappings.');
    }

    private function broker(Request $r, PartnerWorkspaceScope $scope): string
    {
        return $scope->broker($r->user())?->id ?? abort(403, 'Only a broker user can manage broker mappings.');
    }
}
