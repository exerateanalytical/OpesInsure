<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Documents\Security\PhysicalSecurityRegistry;
use Illuminate\Support\ServiceProvider;

/** Security Matrix §4–11 (agent D1): physical-control readiness rows in the Data Readiness registry. */
final class DocumentSecurityMatrixServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PhysicalSecurityRegistry::register();
    }
}
