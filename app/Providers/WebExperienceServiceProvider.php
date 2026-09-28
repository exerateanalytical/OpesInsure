<?php

declare(strict_types=1);

namespace App\Providers;

use App\Providers\Filament\{BrokerPanelProvider, InsurerPanelProvider};
use App\Application\WebExperiences\PortalAuthorization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * REQ-UI-001/002 web experiences: registers the insurer and broker Filament
 * panels next to the admin panel. One line in bootstrap/providers.php.
 */
final class WebExperienceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(InsurerPanelProvider::class);
        $this->app->register(BrokerPanelProvider::class);
        // UI audit 2026-09-27: one consistent, bilingual sidebar for every panel (see LocalizedNavigationManager).
        $this->app->scoped(\Filament\Navigation\NavigationManager::class, fn () => new \App\Filament\Shared\LocalizedNavigationManager);
    }

    public function boot(): void
    {
        Gate::before(fn ($user, string $ability, array $arguments = []) => $user instanceof User
            ? app(PortalAuthorization::class)->before($user, $ability, $arguments)
            : null);

        // Owner decision D3: Lucide icons across every panel (central mapping, see LucideIcons).
        \Filament\Facades\Filament::serving(fn () => \App\Filament\Shared\LucideIcons::apply());
        // UI audit 2026-09-27: one money/date/status convention for list columns (see Columns).
        \Filament\Facades\Filament::serving(fn () => \App\Filament\Shared\Columns::applyDefaults());
        // UI QA 2026-09-27: Filament always prints the active-filter count on the filter button, so
        // every list showed a "0" badge. Hide the badge while no filter is active (all panels).
        \Filament\Support\Facades\FilamentView::registerRenderHook(\Filament\View\PanelsRenderHook::BODY_END, fn (): string => <<<'HTML'
            <script>(()=>{const z=()=>document.querySelectorAll('.fi-ta-filters-trigger-action-ctn .fi-icon-btn-badge-ctn .fi-badge, .fi-ta-filters-dropdown .fi-icon-btn-badge-ctn .fi-badge, .fi-ta-filters-modal .fi-icon-btn-badge-ctn .fi-badge').forEach(b=>{const c=b.closest('.fi-icon-btn-badge-ctn')||b;c.style.display=b.textContent.trim()==='0'?'none':''});z();new MutationObserver(z).observe(document.body,{subtree:true,childList:true,characterData:true});})();</script>
            HTML);
    }
}
