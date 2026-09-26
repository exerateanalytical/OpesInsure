<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "reports" (Gap-Free spec ui_screen_register). */
final class ReportsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'reports';

    protected static string $permission = 'provider.reports.view';

    protected static string $screen = 'reports';

    protected function rows(): array
    {
        return array_map(fn ($r) => ['report' => $r, 'json' => url('/api/v1/provider-portal/reports/'.strtolower($r)), 'csv' => url('/api/v1/provider-portal/reports/'.strtolower($r).'?format=csv')], \App\Application\Providers\Workspace\ProviderWorkspaceRegister::REPORTS);
    }
}
