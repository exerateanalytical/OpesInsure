<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Approvals\ApprovalService;
use App\Application\Customers\Matching\EntityMergeApprovalRouter;
use App\Application\Customers\Matching\PartyMergeService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Batch 4A party golden record (REQ-PTY-002/003/004): routes/party_golden_record.php and the entity.merge approval
 * routing. entity.merge is the one canonical merge action; EntityMergeApprovalRouter sends party_merges to
 * PartyMergeService and every other source (master data) to MasterDataMergeService. Registered after
 * ImportServiceProvider so this router is the final handler for the action.
 */
final class PartyGoldenRecordServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ApprovalService::class, function (ApprovalService $approvals): void {
            $approvals->registerHandler(PartyMergeService::APPROVAL_ACTION, EntityMergeApprovalRouter::class);
        });
    }

    public function boot(): void
    {
        if ($this->app->resolved(ApprovalService::class)) {
            $this->app->make(ApprovalService::class)->registerHandler(PartyMergeService::APPROVAL_ACTION, EntityMergeApprovalRouter::class);
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/party_golden_record.php'));
        }
    }
}
