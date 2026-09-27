<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CalendarExceptions\Pages;

use App\Filament\Admin\Resources\CalendarExceptions\CalendarExceptionResource;
use App\Filament\Shared\Pages\RecordDetailPage;

final class ViewCalendarException extends RecordDetailPage
{
    protected static string $resource = CalendarExceptionResource::class;
}
