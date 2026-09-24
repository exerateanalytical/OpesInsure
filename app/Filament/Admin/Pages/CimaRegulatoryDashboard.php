<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Regulatory\CimaComplianceReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** PLT-CIMA-001 CIMA Regulatory Dictionary dashboard. */
final class CimaRegulatoryDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'CIMA Regulatory Dictionary';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 200;

    protected static ?string $slug = 'cima-regulatory-dictionary';

    protected string $view = 'filament.admin.pages.cima-regulatory-dashboard';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists();
    }

    public function getTitle(): string
    {
        return 'CIMA Regulatory Dictionary';
    }

    protected function getViewData(): array
    {
        return ['summary' => app(CimaComplianceReport::class)->summary()];
    }
}
