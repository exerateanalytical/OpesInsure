<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Filament\Shared\Widgets\Insurer\{InsurerChartWidget, InsurerStatsWidget};

/** CAR-005 Claims performance: reported / closed / open, paid, reserve, loss ratio, time to close; trend and status mix. */
final class ClaimsPerformanceDashboard extends InsurerDashboardPage
{
    protected static string $dashboard = 'claims';

    protected static ?string $slug = 'claims-performance';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-activity';

    protected static ?int $navigationSort = 3;

    public function getWidgets(): array
    {
        return [InsurerStatsWidget::make(['dashboard' => 'claims']),
            InsurerChartWidget::make(['chart' => 'claims_trend']), InsurerChartWidget::make(['chart' => 'claims_status'])];
    }
}
