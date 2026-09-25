<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CalendarExceptions\Pages;

use App\Filament\Admin\Resources\CalendarExceptions\CalendarExceptionResource;
use Filament\Resources\Pages\EditRecord;

final class EditCalendarException extends EditRecord
{
    protected static string $resource = CalendarExceptionResource::class;
}
