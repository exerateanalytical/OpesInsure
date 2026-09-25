<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CalendarExceptions\Pages;

use App\Filament\Admin\Resources\CalendarExceptions\CalendarExceptionResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCalendarException extends CreateRecord
{
    protected static string $resource = CalendarExceptionResource::class;
}
