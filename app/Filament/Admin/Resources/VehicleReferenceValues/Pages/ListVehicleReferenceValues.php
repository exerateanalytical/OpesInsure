<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleReferenceValues\Pages;

use App\Filament\Admin\Resources\VehicleReferenceValues\VehicleReferenceValueResource;
use Filament\Resources\Pages\ListRecords;

final class ListVehicleReferenceValues extends ListRecords
{
    protected static string $resource = VehicleReferenceValueResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
