<?php

declare(strict_types=1);

namespace App\Filament\Shared\Middleware;

use App\Application\WebExperiences\PortalAccess;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;

/**
 * Insurer / broker portal gate (REQ-UI-001). Same as Filament's Authenticate
 * but decides entry with PortalAccess (role set per panel) instead of
 * User::canAccessPanel(), which is the admin panel's rule and refuses
 * CARRIER_* roles.
 */
final class AuthenticatePortal extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return; /** @phpstan-ignore-line */
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        abort_unless(app(PortalAccess::class)->allows($guard->user(), Filament::getCurrentOrDefaultPanel()->getId()), 403);
    }
}
