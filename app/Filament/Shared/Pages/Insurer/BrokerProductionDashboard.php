<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Filament\Shared\Widgets\Insurer\{InsurerChartWidget, InsurerStatsWidget, InsurerTableWidget};

/** CAR-006 Broker production: intermediaries ranked by the premium of the customers they brought to the carrier. */
final class BrokerProductionDashboard extends InsurerDashboardPage
{
    protected static string $dashboard = 'brokers';

    protected static ?string $slug = 'broker-production';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-handshake';

    protected static ?int $navigationSort = 4;

    public function getWidgets(): array
    {
        return [InsurerStatsWidget::make(['dashboard' => 'brokers']), InsurerChartWidget::make(['chart' => 'brokers_top']),
            InsurerTableWidget::make(['breakdown' => 'brokers'])];
    }
}
