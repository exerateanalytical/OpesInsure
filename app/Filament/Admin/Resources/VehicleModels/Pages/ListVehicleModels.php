<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleModels\Pages;

use App\Filament\Admin\Resources\VehicleModels\VehicleModelResource;
use Filament\Resources\Pages\ListRecords;

final class ListVehicleModels extends ListRecords
{
    protected static string $resource = VehicleModelResource::class;

    protected function getHeaderActions(): array
    {
        return [VehicleModelResource::createAction()];
    }
}
