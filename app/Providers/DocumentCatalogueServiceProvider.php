<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Document catalogue: public API routes and the deploy (optimize) seeding hook. */
final class DocumentCatalogueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->optimizes(optimize: 'opesinsure:seed-document-catalogue', key: 'document-catalogue');

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/document_catalogue.php'));
        }
    }
}
