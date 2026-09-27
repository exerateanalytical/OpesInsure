<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleModels\Pages;

use App\Filament\Admin\Resources\VehicleModels\VehicleModelResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewVehicleModel extends RecordDetailPage
{
    protected static string $resource = VehicleModelResource::class;
}
