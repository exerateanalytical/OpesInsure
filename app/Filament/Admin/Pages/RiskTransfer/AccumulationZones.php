<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Application\Accumulation\ExposureService;
use App\Filament\Shared\Actions\AccumulationActions;
use Filament\Tables\Table;

/** Accumulation zones, capacity limits and exposure snapshots — GET accumulation/zones via ExposureService::zones (accumulation.view). */
final class AccumulationZones extends RiskTransferPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-map';

    protected static ?int $navigationSort = 75;

    protected static ?string $slug = 'risk-transfer/accumulation';

    protected static array $permissions = ['accumulation.view'];

    protected static string $screen = 'accumulation';

    public function table(Table $table): Table
    {
        return $this->workbench($table,
            fn (string $t) => self::rows(collect(app(ExposureService::class)->zones($t))->map(fn ($z) => collect((array) $z)->map(fn ($v) => is_array($v) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? $x : json_encode($x), $v)) : $v)->all())),
            ['code' => 'text', 'name' => 'text', 'country_code' => 'text', 'geography_codes' => 'text', 'status' => 'status'],
            [AccumulationActions::zoneCreate(), AccumulationActions::capacitySetLimit(), AccumulationActions::locationsRebuild(), AccumulationActions::snapshotTake()]);
    }
}
