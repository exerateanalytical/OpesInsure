<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMakes\Pages;

use App\Filament\Admin\Resources\VehicleMakes\VehicleMakeResource;
use Filament\Resources\Pages\ListRecords;

final class ListVehicleMakes extends ListRecords
{
    use \App\Filament\Shared\Concerns\OpensViewPage;

    protected static string $resource = VehicleMakeResource::class;

    protected function getHeaderActions(): array
    {
        return [VehicleMakeResource::createAction()];
    }
}
