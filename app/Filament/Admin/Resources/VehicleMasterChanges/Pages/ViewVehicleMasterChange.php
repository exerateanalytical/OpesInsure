<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMasterChanges\Pages;

use App\Filament\Admin\Resources\VehicleMasterChanges\VehicleMasterChangeResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewVehicleMasterChange extends RecordDetailPage
{
    protected static string $resource = VehicleMasterChangeResource::class;
}
