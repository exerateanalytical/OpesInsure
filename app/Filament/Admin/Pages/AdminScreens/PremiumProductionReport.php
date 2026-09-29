<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** REG-002 Premium production reports (see RegulatoryReportScreen). */
final class PremiumProductionReport extends RegulatoryReportScreen
{
    protected const CODE = 'REG-002';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-bar-chart';

    protected static ?int $navigationSort = 71;

    protected static ?string $slug = 'reports/premium-production';

    protected static string $screen = 'reg_002';
}
