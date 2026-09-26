<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament;

use App\Application\Providers\Workspace\Filament\Pages;
use App\Filament\Shared\Middleware\SetPanelLocale;
use App\Providers\Filament\PortalPanelFactory;
use Filament\Http\Middleware\{AuthenticateSession, DisableBladeIconComponents, DispatchServingFilamentEvent};
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\{AddQueuedCookiesToResponse, EncryptCookies};
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Provider Portal (experience layer #7, PROVIDER_PORTAL) — hospital / clinic web workspace at /provider. Same shell as
 * every panel (PortalPanelFactory::chrome: palette, EN/FR switch, sidebar). Entry: users who act for a provider
 * (ProviderScope) with an ACTIVE tenant membership; each page is gated by its spec permission and reads through the
 * same services as the API (ProviderWorkspaceService / ProviderOperationsService), so web and API never diverge.
 */
final class ProviderPanelProvider extends PanelProvider
{
    /** @return list<class-string> */
    public static function pages(): array
    {
        return [Pages\ProviderDashboardPage::class, Pages\EligibilityPage::class, Pages\PreauthorizationsPage::class, Pages\AdmissionsPage::class,
            Pages\TreatmentEpisodesPage::class, Pages\ClaimsPage::class, Pages\AccountsPage::class, Pages\SettlementsPage::class, Pages\ReconciliationsPage::class,
            Pages\DisputesPage::class, Pages\ContractsPage::class, Pages\DocumentsPage::class, Pages\ReportsPage::class, Pages\NotificationsPage::class,
            Pages\ProfilePage::class, Pages\FacilitiesPage::class, Pages\UsersPage::class, Pages\IntegrationPage::class, Pages\AuditPage::class];
    }

    public function panel(Panel $panel): Panel
    {
        return PortalPanelFactory::chrome($panel->id('provider')->path('provider')
            ->login(ProviderLogin::class)->passwordReset()
            ->brandName(fn (): string => 'OpesInsure · '.__('provider_workspace.portal'))
            ->pages(self::pages())
            ->homeUrl(fn () => Pages\ProviderDashboardPage::getUrl())
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, AuthenticateSession::class, ShareErrorsFromSession::class,
                VerifyCsrfToken::class, SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class, SetPanelLocale::class])
            ->authMiddleware([AuthenticateProviderPanel::class, ResolveProviderPanelScope::class], isPersistent: true));
    }
}
