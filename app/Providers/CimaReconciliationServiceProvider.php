<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Approvals\ApprovalService;
use App\Application\Regulatory\Approvals\CimaAuthorizationApprovalHandler;
use App\Application\Regulatory\CimaAuthorizationService;
use App\Application\Regulatory\OrganizationNameHistoryService;
use App\Models\Carrier;
use App\Models\Partner;
use Illuminate\Support\ServiceProvider;

/**
 * Batch 3 / 3A CIMA reconciliation: REQ-DUP-017 (insurer CIMA authorizations decided by the approval engine),
 * REQ-SEED-003 (name/brand history captured on every carrier/partner name change).
 */
final class CimaReconciliationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->afterResolving(ApprovalService::class, function (ApprovalService $approvals): void {
            $approvals->registerHandler(CimaAuthorizationService::APPROVAL_ACTION, CimaAuthorizationApprovalHandler::class);
        });
        if ($this->app->resolved(ApprovalService::class)) {
            $this->app->make(ApprovalService::class)->registerHandler(CimaAuthorizationService::APPROVAL_ACTION, CimaAuthorizationApprovalHandler::class);
        }

        foreach ([Carrier::class, Partner::class] as $model) {
            $model::saved(function ($org): void {
                if ($org->wasRecentlyCreated || $org->wasChanged(['legal_name', 'trade_name', 'short_name'])) {
                    rescue(fn () => app(OrganizationNameHistoryService::class)->capture($org), null, true);
                }
            });
        }
    }
}
