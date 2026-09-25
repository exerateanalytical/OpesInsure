<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Approvals\ApprovalService;
use App\Application\Import\ImportBatchApprovalHandler;
use App\Application\Import\ImportPipeline;
use App\Application\Import\ImportTargetRegistry;
use App\Application\MasterData\MasterDataMergeService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * REQ-IMP-001 generic import pipeline (+ REQ-MDM-007 maker-checker merges): target registry, the approval
 * handlers that let the generic approval inbox decide imports and merges, and routes/imports.php.
 */
final class ImportServiceProvider extends ServiceProvider
{
    public const HANDLERS = [
        ImportPipeline::APPROVAL_ACTION => ImportBatchApprovalHandler::class,
        MasterDataMergeService::APPROVAL_ACTION => MasterDataMergeService::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ImportTargetRegistry::class);
        $this->app->afterResolving(ApprovalService::class, function (ApprovalService $approvals): void {
            foreach (self::HANDLERS as $action => $handler) {
                $approvals->registerHandler($action, $handler);
            }
        });
    }

    public function boot(): void
    {
        if ($this->app->resolved(ApprovalService::class)) {
            foreach (self::HANDLERS as $action => $handler) {
                $this->app->make(ApprovalService::class)->registerHandler($action, $handler);
            }
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/imports.php'));
        }
    }
}
