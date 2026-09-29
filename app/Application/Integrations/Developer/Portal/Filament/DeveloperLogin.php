<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament;

use App\Application\Integrations\Developer\Portal\PartnerDeveloperPortalService;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Contracts\Auth\Authenticatable;

/** Developer portal login: admits users linked to an integration client. */
final class DeveloperLogin extends Login
{
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof User && app(PartnerDeveloperPortalService::class)->link($user) !== null;
    }
}
