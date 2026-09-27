<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BusinessHours\Pages;

use App\Filament\Admin\Resources\BusinessHours\BusinessHoursResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewBusinessHours extends RecordDetailPage
{
    protected static string $resource = BusinessHoursResource::class;
}
