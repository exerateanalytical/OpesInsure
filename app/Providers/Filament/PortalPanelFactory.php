<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Shared\Middleware\{AuthenticatePortal, ResolvePortalTenant};
use App\Filament\Shared\Pages\{PortalDashboard, PortalLogin};
use Filament\Http\Middleware\{AuthenticateSession, DisableBladeIconComponents, DispatchServingFilamentEvent};
use Filament\Panel;
use Illuminate\Cookie\Middleware\{AddQueuedCookiesToResponse, EncryptCookies};
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Shared shell for the web-experience panels (REQ-UI-001): same brand,
 * colours and theme as the admin panel, PortalAccess-based entry, tenant =
 * the membership that granted entry, shared dashboard. Each experience
 * provider only chooses its id/path and which existing resources it exposes
 * (resources are reused, never copied).
 */
final class PortalPanelFactory
{
    /** @param  list<class-string>  $resources */
    public static function configure(Panel $panel, string $id, array $resources): Panel
    {
        return $panel->id($id)->path($id)
            ->login(PortalLogin::class)->passwordReset()->profile()
            ->brandName('OpesInsure · '.__('web_experience.portals.'.$id))
            ->brandLogoHeight('2.25rem')->darkMode(false)
            ->colors(AdminPanelProvider::v3ColorPalette())
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->resources($resources)
            ->pages([PortalDashboard::class])
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, AuthenticateSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class, SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([AuthenticatePortal::class, ResolvePortalTenant::class]);
    }
}
