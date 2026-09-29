<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Filament\Shared\Widgets\Insurer\{InsurerChartWidget, InsurerStatsWidget, InsurerTableWidget};

/** CAR-007 Product performance: per product version — policies, premium, claims, incurred, loss ratio. */
final class ProductPerformanceDashboard extends InsurerDashboardPage
{
    protected static string $dashboard = 'products';

    protected static ?string $slug = 'product-performance';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-package-check';

    protected static ?int $navigationSort = 5;

    public function getWidgets(): array
    {
        return [InsurerStatsWidget::make(['dashboard' => 'products']), InsurerChartWidget::make(['chart' => 'products_premium']),
            InsurerTableWidget::make(['breakdown' => 'products'])];
    }
}
