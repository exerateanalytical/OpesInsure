<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMasterChanges\Pages;

use App\Filament\Admin\Resources\VehicleMasterChanges\VehicleMasterChangeResource;
use Filament\Resources\Pages\ListRecords;

final class ListVehicleMasterChanges extends ListRecords
{
    protected static string $resource = VehicleMasterChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
