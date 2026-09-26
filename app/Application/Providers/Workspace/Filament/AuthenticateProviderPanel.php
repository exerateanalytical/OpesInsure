<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;

/** Provider panel gate: Filament's Authenticate, deciding entry with ProviderPanelAccess instead of User::canAccessPanel (the admin rule). */
final class AuthenticateProviderPanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();
        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return; /** @phpstan-ignore-line */
        }
        $this->auth->shouldUse(Filament::getAuthGuard());
        abort_unless(ProviderPanelAccess::allows($guard->user()), 403);
    }
}
