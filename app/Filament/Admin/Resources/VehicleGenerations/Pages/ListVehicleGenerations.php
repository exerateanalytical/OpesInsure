<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleGenerations\Pages;

use App\Filament\Admin\Resources\VehicleGenerations\VehicleGenerationResource;
use Filament\Resources\Pages\ListRecords;

final class ListVehicleGenerations extends ListRecords
{
    protected static string $resource = VehicleGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [VehicleGenerationResource::createAction()];
    }
}
