<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleReferenceValues\Pages;

use App\Filament\Admin\Resources\VehicleReferenceValues\VehicleReferenceValueResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewVehicleReferenceValue extends RecordDetailPage
{
    protected static string $resource = VehicleReferenceValueResource::class;
}
