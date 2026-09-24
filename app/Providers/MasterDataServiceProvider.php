<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Institutional master data: API routes (routes/master_data.php) and the
 * deploy hook — deploy.sh runs `optimize`, which runs opesinsure:seed-master-data.
 */
final class MasterDataServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->optimizes(optimize: 'opesinsure:seed-master-data', key: 'master-data');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/master_data.php'));
        }
    }
}
