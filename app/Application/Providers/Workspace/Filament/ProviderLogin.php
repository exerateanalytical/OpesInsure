<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Contracts\Auth\Authenticatable;

/** Provider Login screen: admits users who act for a provider (ProviderScope) with an active membership. */
final class ProviderLogin extends Login
{
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof User && ProviderPanelAccess::allows($user);
    }
}
