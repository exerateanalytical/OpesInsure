<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CalendarExceptions\Pages;

use App\Application\Cases\CalendarAdminService;
use App\Filament\Admin\Resources\CalendarExceptions\CalendarExceptionResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class CreateCalendarException extends CreateRecord
{
    protected static string $resource = CalendarExceptionResource::class;

    /** POST admin/calendars/exceptions: the same CalendarAdminService::addException as the API (branch check, created_by, audit). */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CalendarAdminService::class)->addException(array_filter([
            'jurisdiction' => strtoupper((string) $data['jurisdiction']), 'branch_id' => $data['branch_id'] ?? null,
            'date' => Carbon::parse($data['date'])->toDateString(), 'kind' => $data['kind'], 'label' => $data['label'],
            'source_reference' => filled($data['source_reference'] ?? null) ? $data['source_reference'] : null,
        ], fn ($v) => $v !== null), auth()->user());
    }
}
