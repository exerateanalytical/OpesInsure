<?php

declare(strict_types=1);

namespace App\Application\Finance\ReferenceMasters;

use App\Application\Import\ImportTargetRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Agent GP6 — gap closure pack 06 reference masters: routes, import target, data-readiness gates. */
final class FinanceReferenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ImportTargetRegistry::class, fn (ImportTargetRegistry $r) => $r->register('financial_institutions', FinancialInstitutionImportTarget::class));
    }

    public function boot(): void
    {
        FinanceReferenceReadiness::register();
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/finance_reference.php'));
        }
    }
}
