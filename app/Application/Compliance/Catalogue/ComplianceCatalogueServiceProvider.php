<?php

declare(strict_types=1);

namespace App\Application\Compliance\Catalogue;

use App\Application\Import\ImportTargetRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Gap Closure Pack 08 / 09 wiring: import targets on the generic pipeline, Data Readiness gates, routes, sync command. */
final class ComplianceCatalogueServiceProvider extends ServiceProvider
{
    public const TARGETS = [
        'country_risk_ratings' => Import\CountryRiskRatingTarget::class,
        'regulatory_report_dictionary_lines' => Import\RegulatoryDictionaryLineTarget::class,
        'compliance_controls' => Import\ComplianceControlTarget::class,
    ];

    public function register(): void
    {
        $this->app->afterResolving(ImportTargetRegistry::class, function (ImportTargetRegistry $r): void {
            foreach (self::TARGETS as $k => $c) {
                $r->register($k, $c);
            }
        });
    }

    public function boot(): void
    {
        if ($this->app->resolved(ImportTargetRegistry::class)) {
            foreach (self::TARGETS as $k => $c) {
                $this->app->make(ImportTargetRegistry::class)->register($k, $c);
            }
        }
        ComplianceCatalogueReadiness::register();
        if ($this->app->runningInConsole()) {
            Artisan::command('compliance:catalogue-sync', function (): void {
                $this->info(json_encode(app(ComplianceCatalogueSeeder::class)->run()));
            })->purpose('Idempotently seed Gap Closure Pack 08/09 catalogue codes (never overwrites)');
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/compliance_catalogue.php'));
        }
    }
}
