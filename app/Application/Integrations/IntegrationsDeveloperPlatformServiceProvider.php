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
    public function register(): void
    {
        // S1 — partner developer portal (/developers).
        $this->app->register(\App\Application\Integrations\Developer\Portal\Filament\DeveloperPanelProvider::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateOpenApiCommand::class, DispatchCarrierMessagesCommand::class, \App\Application\Integrations\Developer\Console\SyncApiChangelogCommand::class]);
        }
        // S1 — platform staff link partner users to an integration client (platform tenant only, like developer/clients).
        if (! $this->app->routesAreCached()) {
            Route::prefix('api/v1')->middleware(['api', 'auth:api', 'tenant', 'json.api', 'platform.tenant'])->group(function (): void {
                $c = \App\Application\Integrations\Developer\Http\DeveloperLinksController::class;
                Route::get('developer/clients/{client}/developers', [$c, 'index'])->middleware('permission:integrations.manage')->whereUuid('client');
                Route::post('developer/clients/{client}/developers', [$c, 'store'])->middleware('permission:integrations.manage')->whereUuid('client');
                Route::post('developer/clients/{client}/developers/{user}/revoke', [$c, 'destroy'])->middleware('permission:integrations.revoke')->whereUuid(['client', 'user']);
            });
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
