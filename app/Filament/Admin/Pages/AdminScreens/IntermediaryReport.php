<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** REG-005 Intermediary reports (see RegulatoryReportScreen). */
final class IntermediaryReport extends RegulatoryReportScreen
{
    protected const CODE = 'REG-005';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-bar-chart';

    protected static ?int $navigationSort = 73;

    protected static ?string $slug = 'reports/intermediaries';

    protected static string $screen = 'reg_005';
}
