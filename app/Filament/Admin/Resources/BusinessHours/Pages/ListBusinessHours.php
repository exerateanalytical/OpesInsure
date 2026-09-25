<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BusinessHours\Pages;

use App\Filament\Admin\Resources\BusinessHours\BusinessHoursResource;
use Filament\Resources\Pages\ListRecords;

final class ListBusinessHours extends ListRecords
{
    protected static string $resource = BusinessHoursResource::class;
}
