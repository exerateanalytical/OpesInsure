<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleVariants\Pages;

use App\Filament\Admin\Resources\VehicleVariants\VehicleVariantResource;
use Filament\Resources\Pages\ListRecords;

final class ListVehicleVariants extends ListRecords
{
    protected static string $resource = VehicleVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [VehicleVariantResource::createAction()];
    }
}
