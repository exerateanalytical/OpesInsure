<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Filament\Shared\Widgets\Insurer\{InsurerChartWidget, InsurerStatsWidget};

/** CAR-004 Portfolio dashboard: policies in force, in-force premium, customers, expiries; mix by status and line. */
final class PortfolioDashboard extends InsurerDashboardPage
{
    protected static string $dashboard = 'portfolio';

    protected static ?string $slug = 'portfolio-dashboard';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-briefcase';

    protected static ?int $navigationSort = 2;

    public function getWidgets(): array
    {
        return [InsurerStatsWidget::make(['dashboard' => 'portfolio']),
            InsurerChartWidget::make(['chart' => 'portfolio_status']), InsurerChartWidget::make(['chart' => 'portfolio_line'])];
    }
}
