<?php

declare(strict_types=1);

namespace App\Application\Claims\Closure;

use Illuminate\Support\ServiceProvider;

/** REQ-CLM-014: tags the closure checklist guard onto C1's claim transition guard pipeline when it exists. */
final class ClaimClosureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (interface_exists(\App\Domain\Claims\ClaimTransitionGuard::class)) {
            $this->app->tag([ClosureChecklistGuard::class], 'claims.transition_guards');
        }
    }
}
