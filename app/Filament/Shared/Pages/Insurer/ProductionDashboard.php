<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Filament\Shared\Widgets\Insurer\{InsurerChartWidget, InsurerStatsWidget};

/** CAR-003 Production dashboard: issued policies, written premium, quotes and conversion; monthly trend. */
final class ProductionDashboard extends InsurerDashboardPage
{
    protected static string $dashboard = 'production';

    protected static ?string $slug = 'production-dashboard';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-trending-up';

    protected static ?int $navigationSort = 1;

    public function getWidgets(): array
    {
        return [InsurerStatsWidget::make(['dashboard' => 'production']),
            InsurerChartWidget::make(['chart' => 'production_count']), InsurerChartWidget::make(['chart' => 'production_premium'])];
    }
}
