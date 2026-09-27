<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMakes\Pages;

use App\Filament\Admin\Resources\VehicleMakes\VehicleMakeResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewVehicleMake extends RecordDetailPage
{
    protected static string $resource = VehicleMakeResource::class;
}
