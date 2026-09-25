<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Shared\Middleware\{AuthenticatePortal, ResolvePortalTenant, SetPanelLocale};
use Filament\View\PanelsRenderHook;
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
        return self::chrome($panel->id($id)->path($id)
            ->login(PortalLogin::class)->passwordReset()->profile()
            ->brandName(fn (): string => 'OpesInsure · '.__('web_experience.portals.'.$id))
            ->resources($resources)
            ->pages([PortalDashboard::class])
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, AuthenticateSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class, SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class, SetPanelLocale::class])
            ->authMiddleware([AuthenticatePortal::class, ResolvePortalTenant::class]));
    }

    /**
     * Canonical UI handoff Batch 1 (foundation + shell), shared by EVERY panel
     * (admin included): one palette, one theme, 264/80 px collapsible sidebar,
     * light institutional palette only, EN/FR switch in the top bar and on the
     * auth pages. Panels add their own middleware; SetPanelLocale must be in it.
     */
    public static function chrome(Panel $panel): Panel
    {
        return $panel
            ->brandLogoHeight('2.25rem')->darkMode(false)
            ->colors(AdminPanelProvider::v3ColorPalette())
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->sidebarWidth('264px')->collapsedSidebarWidth('80px')->sidebarCollapsibleOnDesktop()
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament.shared.language-switch')->render())
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn (): string => '<div style="display:flex;justify-content:center;margin-top:1rem">'.view('filament.shared.language-switch')->render().'</div>');
    }
}
