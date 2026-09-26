<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "provider_dashboard" (Gap-Free spec ui_screen_register). */
final class ProviderDashboardPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'dashboard';

    protected static string $permission = 'provider.dashboard.view';

    protected static string $screen = 'provider_dashboard';

    protected function cards(): array
    {
        $d = $this->ws()->dashboard($this->tenantId(), $this->user(), $this->scope());

        return array_filter($d['provider_executive'] + $d['insurance_desk'] + $d['claims_billing'], fn ($v) => ! is_array($v));
    }

    protected function rows(): array
    {
        return $this->ws()->dashboard($this->tenantId(), $this->user(), $this->scope())['finance']['by_insurer'];
    }
}
