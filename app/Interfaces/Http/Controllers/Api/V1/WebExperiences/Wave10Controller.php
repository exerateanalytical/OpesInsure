<?php
namespace App\Interfaces\Http\Controllers\Api\V1\WebExperiences;

use App\Application\WebExperiences\{PortalDashboardQuery,PortalWorkspaceService};
use App\Domain\Tenancy\TenantContext;
use App\Models\MarketplacePublication;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\Gate;

final class Wave10Controller
{
    private function tenant(): ?string { return app(TenantContext::class)->id(); }
    private function owns(MarketplacePublication $item): void { if ($item->tenant_id !== $this->tenant()) abort(404); }

    public function dashboard(Request $request, string $portal, PortalDashboardQuery $query): JsonResponse
    {
        abort_unless(in_array(strtoupper($portal), PortalWorkspaceService::PORTALS, true), 404);
        return response()->json($query->handle($this->tenant(), strtoupper($portal)));
    }

    public function workspace(Request $request, string $portal, PortalWorkspaceService $service): JsonResponse
    {
        $data = $request->validate(['preferences'=>'sometimes|array']);
        return response()->json($service->touch($this->tenant(), $request->user(), strtoupper($portal), $data['preferences'] ?? []));
    }

    public function publish(Request $request, PortalWorkspaceService $service): JsonResponse
    {
        Gate::authorize('create', MarketplacePublication::class);
        $data = $request->validate(['product_id'=>'required|uuid','tariff_version_id'=>'nullable|uuid','channels'=>'required|array|min:1','channels.*'=>'in:WEB','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after:starts_at']);
        return response()->json($service->publish($this->tenant(), $data, $request->user()), 201);
    }

    public function approve(Request $request, MarketplacePublication $publication, PortalWorkspaceService $service): JsonResponse
    {
        $this->owns($publication);
        Gate::authorize('approve', $publication);
        $data = $request->validate(['expected_version'=>'required|integer|min:1']);
        return response()->json($service->approve($publication, $request->user(), $data['expected_version']));
    }

    public function saveComparison(Request $request, PortalWorkspaceService $service): JsonResponse
    {
        $data = $request->validate(['quote_request_id'=>'required|uuid','selected_offer_ids'=>'required|array|min:1|max:5','selected_offer_ids.*'=>'uuid','expires_at'=>'required|date|after:now']);
        return response()->json($service->saveComparison($this->tenant(), $request->user(), $data), 201);
    }
}
