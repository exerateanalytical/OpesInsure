<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament;

use App\Application\Integrations\Developer\Portal\PartnerDeveloperPortalService;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;

/** Developer panel gate: signed in AND linked (ACTIVE) to at least one integration client; anyone else gets 403. */
final class AuthenticateDeveloperPanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();
        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return; /** @phpstan-ignore-line */
        }
        $this->auth->shouldUse(Filament::getAuthGuard());
        abort_unless(app(PartnerDeveloperPortalService::class)->link($guard->user()) !== null, 403);
    }
}
