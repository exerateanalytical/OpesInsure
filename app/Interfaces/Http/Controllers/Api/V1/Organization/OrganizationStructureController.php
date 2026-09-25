<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Organization;

use App\Application\Partners\AgentHierarchyService;
use App\Application\Tenancy\OrganizationStructureService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\TenantBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-TEN-001 / REQ-TEN-002 / REQ-ORG-001 — organization structure API. */
final class OrganizationStructureController
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    public function branchTree(OrganizationStructureService $org): JsonResponse
    {
        $tenant = DB::table('tenants')->where('id', $this->tenant->id())->first(['id', 'type', 'legal_name', 'timezone']);

        return response()->json(['data' => ['tenant' => $tenant, 'branches' => $org->tree($this->tenant->id())]]);
    }

    public function updateBranch(Request $r, string $branch, OrganizationStructureService $org): JsonResponse
    {
        $model = TenantBranch::query()->where('tenant_id', $this->tenant->id())->findOrFail($branch);
        $d = $r->validate([
            'name' => 'sometimes|string|max:160',
            'status' => 'sometimes|in:ACTIVE,SUSPENDED,CLOSED',
            'phone_e164' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|nullable|email',
            'manager_user_id' => 'sometimes|nullable|uuid|exists:users,id',
            'timezone' => 'sometimes|nullable|string|max:64',
            'parent_branch_id' => 'sometimes|nullable|uuid',
            'capabilities' => 'sometimes|array',
            'capabilities.*' => ['string', Rule::in(OrganizationStructureService::BRANCH_CAPABILITIES)],
        ]);

        return response()->json(['data' => $org->updateBranch($model, $d)]);
    }

    public function departments(): JsonResponse
    {
        return response()->json(['data' => DB::table('tenant_departments')->where('tenant_id', $this->tenant->id())->orderBy('code')->get()]);
    }

    public function storeDepartment(Request $r, OrganizationStructureService $org): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|alpha_dash|max:40',
            'name' => 'required|string|max:160',
            'branch_id' => 'nullable|uuid',
            'manager_user_id' => 'nullable|uuid|exists:users,id',
            'queue_code' => 'nullable|string|max:64',
            'limits' => 'sometimes|array',
        ]);

        return response()->json(['data' => $org->createDepartment($this->tenant->id(), $d)], 201);
    }

    public function placeAgent(Request $r, string $partner, AgentHierarchyService $hierarchy): JsonResponse
    {
        $model = Partner::query()->where('tenant_id', $this->tenant->id())->findOrFail($partner);
        $d = $r->validate([
            'supervisor_partner_id' => 'sometimes|nullable|uuid',
            'branch_id' => 'sometimes|nullable|uuid',
            'agent_type' => ['sometimes', 'nullable', Rule::in(AgentHierarchyService::AGENT_TYPES)],
            'reason_code' => 'required|string|max:64',
        ]);
        $reason = $d['reason_code'];
        unset($d['reason_code']);

        return response()->json(['data' => $hierarchy->place($model, $d, $reason, $r->user())]);
    }

    public function suspendAgent(Request $r, string $partner, AgentHierarchyService $hierarchy): JsonResponse
    {
        $model = Partner::query()->where('tenant_id', $this->tenant->id())->findOrFail($partner);
        $d = $r->validate(['notes' => 'required|string|min:5|max:2000', 'reassign_to_partner_id' => 'nullable|uuid']);
        $result = $hierarchy->suspend($model, $d['notes'], $r->user(), $d['reassign_to_partner_id'] ?? null);

        return response()->json(['data' => ['partner' => $result['partner'], 'reassigned_partner_ids' => $result['reassigned']]]);
    }

    public function downline(string $partner, AgentHierarchyService $hierarchy): JsonResponse
    {
        $model = Partner::query()->where('tenant_id', $this->tenant->id())->findOrFail($partner);

        return response()->json(['data' => $hierarchy->downline($model)]);
    }
}
