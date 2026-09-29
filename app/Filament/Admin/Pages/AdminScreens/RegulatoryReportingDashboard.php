<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** REG-001 Regulatory reporting dashboard (see RegulatoryReportScreen). */
final class RegulatoryReportingDashboard extends RegulatoryReportScreen
{
    protected const CODE = 'REG-001';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-landmark';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'reports/regulatory-dashboard';

    protected static string $screen = 'reg_001';
}
