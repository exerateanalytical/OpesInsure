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
    }

    public function boot(): void
    {
        Gate::before(fn ($user, string $ability, array $arguments = []) => $user instanceof User
            ? app(PortalAuthorization::class)->before($user, $ability, $arguments)
            : null);
    }
}
