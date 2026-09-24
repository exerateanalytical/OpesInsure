<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\MasterData\MasterDataQualityReport;
use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * MDM-020 Data quality: missing, duplicate, untranslated, unverified,
 * high-frequency manual entries, unused, failed mappings, stale; completeness %
 * per domain (data completeness, not a business rating).
 */
final class MasterDataQuality extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Master data';

    protected static ?string $navigationLabel = 'Data quality';

    protected static ?int $navigationSort = 409;

    protected static ?string $slug = 'master-data-quality';

    protected string $view = 'filament.admin.pages.master-data-quality';

    public static function canAccess(): bool
    {
        return MasterDataValueResource::canManageMasterData();
    }

    public function getTitle(): string
    {
        return 'Master data quality';
    }

    protected function getViewData(): array
    {
        $report = app(MasterDataQualityReport::class);

        return ['quality' => $report->quality(), 'completeness' => $report->completeness()];
    }
}
