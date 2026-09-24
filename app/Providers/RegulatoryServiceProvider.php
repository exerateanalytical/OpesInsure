<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * CIMA Regulatory Dictionary: API routes (routes/regulatory.php) and the
 * deploy hook. deploy.sh runs `optimize`; opesinsure:seed-cima runs after
 * opesinsure:seed-regulatory and demo:seed (registered in AppServiceProvider,
 * which boots first), so demo carriers exist before their DEMO authorizations.
 */
final class RegulatoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->optimizes(optimize: 'opesinsure:seed-cima', key: 'cima-dictionary');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/regulatory.php'));
        }
    }
}
