<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BusinessHours\Pages;

use App\Application\Cases\CalendarAdminService;
use App\Filament\Admin\Resources\BusinessHours\BusinessHoursResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class CreateBusinessHours extends CreateRecord
{
    protected static string $resource = BusinessHoursResource::class;

    /** POST admin/calendars/hours: the same CalendarAdminService::addHours as the API (branch check, created_by, audit). */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CalendarAdminService::class)->addHours(array_filter([
            'jurisdiction' => strtoupper((string) $data['jurisdiction']), 'branch_id' => $data['branch_id'] ?? null, 'weekday' => (int) $data['weekday'],
            'opens' => substr((string) $data['opens'], 0, 5), 'closes' => substr((string) $data['closes'], 0, 5),
            'valid_from' => Carbon::parse($data['valid_from'])->toDateString(),
            'valid_to' => filled($data['valid_to'] ?? null) ? Carbon::parse($data['valid_to'])->toDateString() : null,
        ], fn ($v) => $v !== null), auth()->user());
    }
}
