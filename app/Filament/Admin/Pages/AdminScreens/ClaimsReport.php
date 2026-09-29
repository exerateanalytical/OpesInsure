<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** REG-004 Claims reports (see RegulatoryReportScreen). */
final class ClaimsReport extends RegulatoryReportScreen
{
    protected const CODE = 'REG-004';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-bar-chart';

    protected static ?int $navigationSort = 72;

    protected static ?string $slug = 'reports/claims';

    protected static string $screen = 'reg_004';
}
