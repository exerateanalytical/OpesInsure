<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Selection-first form schemas: routes/input_forms.php (GET /api/v1/forms[/{form}]). */
final class InputFormsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/input_forms.php'));
        }
    }
}
