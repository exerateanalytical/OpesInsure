<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\CarrierOperations\QuoteRequests\Console\SweepQuoteRequestsCommand;
use App\Application\CarrierOperations\QuoteRequests\QuoteRequestService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * REQ-QUO-006 manual quotation (AOM Mode 1): carrier quote requests on the case engine;
 * routes/carrier_quote_requests.php. Needs CasesServiceProvider + DistributionServiceProvider.
 */
final class CarrierQuoteRequestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QuoteRequestService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SweepQuoteRequestsCommand::class]);
            $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('carrier-quote-requests:sweep')->hourly()->withoutOverlapping());
        }
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/carrier_quote_requests.php'));
        }
    }
}
