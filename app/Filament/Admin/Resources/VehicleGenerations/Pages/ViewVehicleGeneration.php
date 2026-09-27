<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleGenerations\Pages;

use App\Filament\Admin\Resources\VehicleGenerations\VehicleGenerationResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewVehicleGeneration extends RecordDetailPage
{
    protected static string $resource = VehicleGenerationResource::class;
}
