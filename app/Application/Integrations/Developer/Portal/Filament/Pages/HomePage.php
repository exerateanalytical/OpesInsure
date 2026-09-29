<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use App\Application\Integrations\Developer\Portal\Filament\DeveloperPanelProvider;
use BackedEnum;

/** DEV-001 Developer Portal Home — own connection status, keys, 30-day traffic, webhook health, shortcuts. */
final class HomePage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-layout-dashboard';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'home';

    protected static string $screen = 'home';

    protected function cards(): array
    {
        return ['client_name' => $this->client()->name, 'role' => $this->link()->role] + $this->svc()->summary($this->client());
    }

    protected function rows(): array
    {
        $out = [];
        foreach (DeveloperPanelProvider::pages() as $page) {
            if ($page === self::class) {
                continue;
            }
            $out[] = ['screen' => $page::getNavigationLabel(), 'url' => $page::getUrl()];
        }

        return $out;
    }
}
