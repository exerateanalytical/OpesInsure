<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\MasterData\MasterDataQualityReport;
use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** MDM-001 Master data dashboard and MDM-012 data sources (provenance breakdown). */
final class MasterDataDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|\UnitEnum|null $navigationGroup = 'Master data';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 399;

    protected static ?string $slug = 'master-data';

    protected string $view = 'filament.admin.pages.master-data';

    public static function canAccess(): bool
    {
        return MasterDataValueResource::canManageMasterData();
    }

    public function getTitle(): string
    {
        return 'Master data';
    }

    protected function getViewData(): array
    {
        $report = app(MasterDataQualityReport::class);

        return ['summary' => $report->summary(), 'completeness' => $report->completeness()];
    }
}
