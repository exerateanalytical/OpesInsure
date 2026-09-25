<?php

declare(strict_types=1);

namespace App\Application\Policies\IssuanceQueue;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Batch 7D — routes/issuance_ops.php: issuance exception queue (REQ-POL-004) and sticker custody chain (REQ-POL-007). */
final class IssuanceOpsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/issuance_ops.php'));
        }
    }
}
