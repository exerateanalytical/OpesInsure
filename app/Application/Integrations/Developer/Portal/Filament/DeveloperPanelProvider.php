<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament;

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
 * S1 — Partner Developer Portal (DEV-001..014) at /developers. Entry: users linked to an integration client
 * (integration_client_developers, ACTIVE). Every screen is scoped to that ONE client (ResolveDeveloperClient);
 * no tenant, no platform staff data. Same shell as every panel (PortalPanelFactory::chrome).
 */
final class DeveloperPanelProvider extends PanelProvider
{
    /** @return list<class-string> */
    public static function pages(): array
    {
        return [Pages\HomePage::class, Pages\DocumentationPage::class, Pages\ProductsPage::class, Pages\CredentialsPage::class, Pages\SandboxPage::class,
            Pages\ExplorerPage::class, Pages\EventCataloguePage::class, Pages\DeliveryLogsPage::class, Pages\RequestLogsPage::class, Pages\UsagePage::class, Pages\ChangelogPage::class];
    }

    public function panel(Panel $panel): Panel
    {
        return PortalPanelFactory::chrome($panel->id('developers')->path('developers')
            ->login(DeveloperLogin::class)->passwordReset()
            ->brandName(fn (): string => 'OpesInsure · '.__('developer_portal.portal'))
            ->pages(self::pages())
            ->homeUrl(fn () => Pages\HomePage::getUrl())
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, AuthenticateSession::class, ShareErrorsFromSession::class,
                VerifyCsrfToken::class, SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class, SetPanelLocale::class])
            ->authMiddleware([AuthenticateDeveloperPanel::class], isPersistent: true));
    }
}
