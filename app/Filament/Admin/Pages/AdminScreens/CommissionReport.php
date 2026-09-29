<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** REG-006 Commission reports (see RegulatoryReportScreen). */
final class CommissionReport extends RegulatoryReportScreen
{
    protected const CODE = 'REG-006';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-bar-chart';

    protected static ?int $navigationSort = 74;

    protected static ?string $slug = 'reports/commissions';

    protected static string $screen = 'reg_006';
}
