<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Settlements;

use App\Application\Settlements\Http\SettlementResource;
use App\Application\Settlements\SettlementService;
use App\Domain\Tenancy\TenantContext;

/**
 * Settlement read. REQ-DUP-011 (Batch 10-4): the payload is built by
 * App\Application\Settlements\SettlementService + SettlementResource, the same
 * representation GET carrier-settlements/{settlement} serves (a route alias of
 * this action). Writes live in the carrier-settlements/* routes
 * (FinancialDistributionController → SettlementService) and the Batch 10-4
 * ledger lifecycle (App\Application\Settlements\Http\SettlementLifecycleController).
 */
final class SettlementController
{
    public function show(string $batch, SettlementService $settlements): SettlementResource
    {
        return new SettlementResource($settlements->find(app(TenantContext::class)->id(), $batch));
    }
}
