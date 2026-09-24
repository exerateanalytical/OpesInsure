<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\FinancialDistribution;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\CarrierScopeResolver;
use App\Application\FinancialDistribution\MobileCarrierFinanceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carrier-side mobile dashboard — read-only, see MobileCarrierFinanceService.
 */
final class MobileCarrierFinanceController
{
    public function __construct(private readonly CarrierScopeResolver $scope)
    {
    }

    private function carrierId(Request $request): ?string
    {
        return $this->scope->carrierIdFor($request->user(), app(TenantContext::class)->id());
    }

    public function dashboard(Request $request, MobileCarrierFinanceService $service, AuditWriter $audit): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $service->dashboard($tenantId, $this->carrierId($request));
        $audit->record('mobile.carrier.dashboard.viewed', 'tenant', $tenantId);

        return response()->json(['data' => $data]);
    }

    public function settlements(Request $request, MobileCarrierFinanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->settlements(app(TenantContext::class)->id(), 20, $this->carrierId($request))]);
    }

    public function settlement(string $settlement, Request $request, MobileCarrierFinanceService $service, AuditWriter $audit): JsonResponse
    {
        $data = $service->settlement($settlement, app(TenantContext::class)->id(), $this->carrierId($request));
        $audit->record('mobile.carrier.settlement.viewed', 'settlement_batch', $data->id);

        return response()->json(['data' => $data]);
    }

    public function bordereaux(Request $request, MobileCarrierFinanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->bordereaux(app(TenantContext::class)->id(), 20, $this->carrierId($request))]);
    }

    public function bordereau(string $bordereau, Request $request, MobileCarrierFinanceService $service, AuditWriter $audit): JsonResponse
    {
        $data = $service->bordereau($bordereau, app(TenantContext::class)->id(), $this->carrierId($request));
        $audit->record('mobile.carrier.bordereau.viewed', 'bordereau', $data->id);

        return response()->json(['data' => $data]);
    }
}
