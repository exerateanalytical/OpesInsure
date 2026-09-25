<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BusinessHours\Pages;

use App\Filament\Admin\Resources\BusinessHours\BusinessHoursResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateBusinessHours extends CreateRecord
{
    protected static string $resource = BusinessHoursResource::class;
}
