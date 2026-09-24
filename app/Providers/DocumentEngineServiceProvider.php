<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Documents\Engine\DocumentStatusService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Document engine: routes (routes/document_engine.php), register singleton, expiry sweep command. */
final class DocumentEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Application\Documents\Engine\CatalogueSource::class);
        $this->app->singleton(DocumentRegister::class);
    }

    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/document_engine.php'));
        }

        if ($this->app->runningInConsole()) {
            Artisan::command('documents:expire', function (DocumentStatusService $service): void {
                $this->info('Expired '.$service->expireDue().' document(s).');
            })->purpose('Persist EXPIRED on issued proof-of-cover documents past their validity');
        }
    }
}
