<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMasterReviews\Pages;

use App\Filament\Admin\Resources\VehicleMasterReviews\VehicleMasterReviewResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewVehicleMasterReview extends RecordDetailPage
{
    protected static string $resource = VehicleMasterReviewResource::class;
}
