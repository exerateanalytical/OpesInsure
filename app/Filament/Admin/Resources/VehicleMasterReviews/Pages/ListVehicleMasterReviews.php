<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMasterReviews\Pages;

use App\Filament\Admin\Resources\VehicleMasterReviews\VehicleMasterReviewResource;
use Filament\Resources\Pages\ListRecords;

final class ListVehicleMasterReviews extends ListRecords
{
    protected static string $resource = VehicleMasterReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
