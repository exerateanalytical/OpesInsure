<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleVariants\Pages;

use App\Filament\Admin\Resources\VehicleVariants\VehicleVariantResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewVehicleVariant extends RecordDetailPage
{
    protected static string $resource = VehicleVariantResource::class;
}
