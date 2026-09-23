<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\FinancialDistribution;

use App\Application\Audit\AuditWriter;
use App\Application\FinancialDistribution\MobilePartnerFinanceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Broker/agent mobile dashboard — read-only, see MobilePartnerFinanceService.
 */
final class MobileBrokerFinanceController
{
    public function dashboard(Request $request, MobilePartnerFinanceService $service, AuditWriter $audit): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $service->dashboard($request->user(), $tenantId);
        $audit->record('mobile.broker.dashboard.viewed', 'tenant', $tenantId);

        return response()->json(['data' => $data]);
    }

    public function receivables(Request $request, MobilePartnerFinanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->receivables($request->user(), app(TenantContext::class)->id())]);
    }

    public function commissionAccruals(Request $request, MobilePartnerFinanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->commissionAccruals($request->user(), app(TenantContext::class)->id())]);
    }

    public function statements(Request $request, MobilePartnerFinanceService $service): JsonResponse
    {
        return response()->json(['data' => $service->statements($request->user(), app(TenantContext::class)->id())]);
    }

    public function statement(string $statement, Request $request, MobilePartnerFinanceService $service, AuditWriter $audit): JsonResponse
    {
        $data = $service->statement($statement, $request->user(), app(TenantContext::class)->id());
        $audit->record('mobile.broker.statement.viewed', 'partner_statement', $data->id);

        return response()->json(['data' => $data]);
    }
}
