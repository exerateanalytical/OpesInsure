<?php
namespace App\Providers\Filament;

use App\Interfaces\Http\Middleware\ResolveAdminPanelTenant;use Filament\Http\Middleware\Authenticate;use Filament\Http\Middleware\AuthenticateSession;use Filament\Http\Middleware\DisableBladeIconComponents;use Filament\Http\Middleware\DispatchServingFilamentEvent;use Filament\Pages;use Filament\Panel;use Filament\PanelProvider;use Filament\Support\Colors\Color;use Filament\Widgets;use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;use Illuminate\Cookie\Middleware\EncryptCookies;use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;use Illuminate\Routing\Middleware\SubstituteBindings;use Illuminate\Session\Middleware\StartSession;use Illuminate\View\Middleware\ShareErrorsFromSession;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel):Panel{return $panel->default()->id('admin')->path('admin')->login()->passwordReset()->profile()->brandName('OpesInsure')->brandLogoHeight('2.25rem')->darkMode(false)->colors(static::v3Colors())->viteTheme('resources/css/filament/admin/theme.css')->discoverResources(in:app_path('Filament/Admin/Resources'),for:'App\\Filament\\Admin\\Resources')->discoverPages(in:app_path('Filament/Admin/Pages'),for:'App\\Filament\\Admin\\Pages')->pages([Pages\Dashboard::class])->discoverWidgets(in:app_path('Filament/Admin/Widgets'),for:'App\\Filament\\Admin\\Widgets')->widgets([Widgets\AccountWidget::class,Widgets\FilamentInfoWidget::class])->middleware([EncryptCookies::class,AddQueuedCookiesToResponse::class,StartSession::class,AuthenticateSession::class,ShareErrorsFromSession::class,VerifyCsrfToken::class,SubstituteBindings::class,DisableBladeIconComponents::class,DispatchServingFilamentEvent::class])->authMiddleware([Authenticate::class,ResolveAdminPanelTenant::class]);}

    /**
     * Color::hex() only keeps the input's hue and applies a generic lightness/chroma
     * ramp — none of the v3 spec's exact hex values survive at any shade. Filament
     * components pull their "interactive" color from anywhere in the 400-700 range
     * (e.g. the login submit button uses 400, most badges/links use 600), and v3 §2
     * requires a single exact brand hue everywhere ("Insurance Blue is the only
     * general interactive color") rather than a ramp of related blues. So 400-700 all
     * get the exact base hex; 50/100 and 200/300 get the spec's soft/border stops.
     */
    /** Shared with the insurer / broker portals (PortalPanelFactory) so every panel keeps the one v3 palette. */
    public static function v3ColorPalette(): array
    {
        return self::v3Colors();
    }

    private static function v3Colors(): array
    {
        $withStops = function (string $base, string $soft, string $border, string $text): array {
            $palette = Color::hex($base);
            $palette[50] = $soft;
            $palette[100] = $soft;
            $palette[200] = $border;
            $palette[300] = $border;
            $palette[400] = $base;
            $palette[500] = $base;
            $palette[600] = $base;
            $palette[700] = $text;
            $palette[800] = $text;

            return $palette;
        };

        return [
            'primary' => $withStops('#155FCC', '#EFF5FF', '#AFCDF7', '#124FA9'),
            'success' => $withStops('#07855B', '#E7F6F0', '#9DDAC6', '#056B49'),
            'warning' => $withStops('#B77800', '#FFF6DD', '#EAC66F', '#764B00'),
            'danger' => $withStops('#C9363E', '#FDEDEF', '#E8A9AE', '#98272E'),
            'gray' => [
                50 => '#F5F7F8',
                100 => '#ECF0F3',
                200 => '#DCE3E8',
                300 => '#C3CCD3',
                400 => '#94A0AA',
                500 => '#7A8994',
                600 => '#566776',
                700 => '#3C4C5B',
                800 => '#26333F',
                900 => '#1A232C',
                950 => '#111820',
            ],
        ];
    }
}
