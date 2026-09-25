<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Document letterheads (logo / header / legal footer per insurer and organisation): public logo route. */
final class LetterheadServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api/v1')->group(base_path('routes/letterheads.php'));
        }
    }
}
