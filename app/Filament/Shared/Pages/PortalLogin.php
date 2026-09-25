<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\WebExperiences\PortalAccess;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;

/** Login for the insurer / broker portals: admits the panel's PortalAccess roles. */
final class PortalLogin extends Login
{
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        return $user instanceof User && app(PortalAccess::class)->allows($user, Filament::getCurrentOrDefaultPanel()->getId());
    }
}
