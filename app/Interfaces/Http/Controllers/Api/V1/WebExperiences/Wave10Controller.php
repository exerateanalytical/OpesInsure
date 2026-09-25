<?php
namespace App\Interfaces\Http\Controllers\Api\V1\WebExperiences;

use App\Application\WebExperiences\{PortalDashboardQuery,PortalWorkspaceService};
use App\Application\Quotes\Http\QuoteWorkflowController;
use App\Application\Quotes\QuoteComparisonService;
use App\Domain\Tenancy\TenantContext;
use App\Models\MarketplacePublication;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\Gate;

final class Wave10Controller
{
    private const DASHBOARD_PERMISSIONS = ['ADMIN' => 'tenant.manage', 'BROKER' => 'broker.portal.read', 'CARRIER' => 'carrier.dashboard.read', 'AGENT' => 'agent.clients.read', 'CUSTOMER' => 'customers.read'];

    private function tenant(): ?string { return app(TenantContext::class)->id(); }
    private function owns(MarketplacePublication $item): void { if ($item->tenant_id !== $this->tenant()) abort(404); }

    public function dashboard(Request $request, string $portal, PortalDashboardQuery $query): JsonResponse
    {
        abort_unless(in_array(strtoupper($portal), PortalWorkspaceService::PORTALS, true), 404);
        // The dashboard is tenant-wide counts, so every portal needs a staff/
        // partner read permission — including CUSTOMER, whose own view is the
        // owner-scoped mobile API, not these tenant totals (audit A1).
        $permission = self::DASHBOARD_PERMISSIONS[strtoupper($portal)];
        if (! $request->user()->hasPermission($permission)) {
            abort(403, 'Permission denied.');
        }
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

    /**
     * Legacy alias of POST /quote-comparisons (REQ-DST-003): maps the old field names onto the canonical endpoint so
     * there is one comparison implementation (QuoteComparisonService) with its tenant/ownership scoping and offer
     * validation. `expires_at` is accepted for compatibility but the service derives it from the offers' validity.
     */
    public function saveComparison(Request $request, QuoteWorkflowController $canonical, QuoteComparisonService $svc): JsonResponse
    {
        $data = $request->validate(['quote_request_id'=>'required|uuid','selected_offer_ids'=>'required|array|min:1|max:'.QuoteComparisonService::MAX,'selected_offer_ids.*'=>'uuid','expires_at'=>'sometimes|date']);
        $request->merge(['quote_id' => $data['quote_request_id'], 'offer_ids' => array_values($data['selected_offer_ids'])]);
        return $canonical->storeComparison($request, $svc);
    }
}
