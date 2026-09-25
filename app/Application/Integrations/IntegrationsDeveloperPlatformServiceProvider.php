<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Application\Integrations\Carriers\Console\DispatchCarrierMessagesCommand;
use App\Application\Integrations\Developer\Console\GenerateOpenApiCommand;
use Illuminate\Console\Scheduling\Schedule;
use App\Application\Integrations\Developer\Http\DeveloperPortalController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Agent B3 — REQ-API-006 / REQ-API-007 / REQ-IAM-004 console wiring. */
final class IntegrationsDeveloperPlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateOpenApiCommand::class, DispatchCarrierMessagesCommand::class]);
        }
        // REQ-IAM-004 discovery lives at the host root (RFC 8414 / OIDC Discovery), outside the /api prefix.
        if (! $this->app->routesAreCached()) {
            Route::get('.well-known/openid-configuration', [DeveloperPortalController::class, 'openidConfiguration'])->name('oidc.configuration');
            Route::get('.well-known/jwks.json', [DeveloperPortalController::class, 'jwks'])->name('oidc.jwks');
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('carriers:dispatch-messages')->everyMinute()->withoutOverlapping();
        });
    }
}
