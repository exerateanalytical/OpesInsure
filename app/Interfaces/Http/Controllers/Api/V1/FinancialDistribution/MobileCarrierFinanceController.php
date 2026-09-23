<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\FinancialDistribution;

use App\Application\Audit\AuditWriter;
use App\Application\FinancialDistribution\MobileCarrierFinanceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Carrier-side mobile dashboard — read-only, see MobileCarrierFinanceService.
 */
final class MobileCarrierFinanceController
{
    public function dashboard(MobileCarrierFinanceService $service, AuditWriter $audit): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $service->dashboard($tenantId);
        $audit->record('mobile.carrier.dashboard.viewed', 'tenant', $tenantId);

        return response()->json(['data' => $data]);
    }

    public function settlements(MobileCarrierFinanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->settlements(app(TenantContext::class)->id())]);
    }

    public function settlement(string $settlement, MobileCarrierFinanceService $service, AuditWriter $audit): JsonResponse
    {
        $data = $service->settlement($settlement, app(TenantContext::class)->id());
        $audit->record('mobile.carrier.settlement.viewed', 'settlement_batch', $data->id);

        return response()->json(['data' => $data]);
    }

    public function bordereaux(MobileCarrierFinanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->bordereaux(app(TenantContext::class)->id())]);
    }

    public function bordereau(string $bordereau, MobileCarrierFinanceService $service, AuditWriter $audit): JsonResponse
    {
        $data = $service->bordereau($bordereau, app(TenantContext::class)->id());
        $audit->record('mobile.carrier.bordereau.viewed', 'bordereau', $data->id);

        return response()->json(['data' => $data]);
    }
}
