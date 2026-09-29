<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Cases\CalendarAdminService;
use App\Application\Cases\Models\CalendarBusinessHours;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Illuminate\Support\Carbon;

/**
 * Business calendar actions (UI batch 24). Same permission + service + validation as routes/cases.php:
 *   hoursEnd    POST admin/calendars/hours/{id}/end    cases.calendar.manage  CalendarAdminService::endHours
 *   breakAdd    POST admin/calendars/breaks            cases.calendar.manage  CalendarAdminService::addBreak
 *   breakEnd    POST admin/calendars/breaks/{id}/end   cases.calendar.manage  CalendarAdminService::endBreak
 * POST admin/calendars/hours and admin/calendars/exceptions are the Business hours / Calendar exceptions create pages
 * (CreateBusinessHours / CreateCalendarException), which call CalendarAdminService::addHours / addException.
 */
final class CalendarActions
{
    private const L = 'operations_actions';

    private const P = 'cases.calendar.manage';

    public static function hoursEnd(): Action
    {
        return WorkflowAction::make('hoursEnd', self::P, self::L)->icon('lucide-calendar-x')
            ->visible(fn (CalendarBusinessHours $record) => $record->valid_to === null || $record->valid_to->isFuture())
            ->schema([self::validTo()])
            ->action(fn (Action $action, CalendarBusinessHours $record, array $data) => WorkflowAction::run($action, self::P,
                fn () => app(CalendarAdminService::class)->endHours($record->id, self::date($data['valid_to'])), __(self::L.'.hoursEnd.done')));
    }

    public static function breakAdd(): Action
    {
        return WorkflowAction::make('breakAdd', self::P, self::L)->icon('lucide-coffee')
            ->schema([
                TextInput::make('jurisdiction')->label(__(self::L.'.fields.jurisdiction'))->required()->length(2)->default('CM'),
                Select::make('weekday')->label(__(self::L.'.fields.weekday'))->options(self::weekdays()),
                TimePicker::make('starts')->label(__(self::L.'.fields.starts'))->seconds(false)->required(),
                TimePicker::make('ends')->label(__(self::L.'.fields.ends'))->seconds(false)->required()->after('starts'),
                TextInput::make('label')->label(__(self::L.'.fields.label'))->maxLength(120),
                DatePicker::make('valid_from')->label(__(self::L.'.fields.valid_from'))->required(),
                DatePicker::make('valid_to')->label(__(self::L.'.fields.valid_to'))->afterOrEqual('valid_from'),
            ])
            ->action(function (Action $action, array $data) {
                $d = array_filter([
                    'jurisdiction' => strtoupper($data['jurisdiction']), 'weekday' => filled($data['weekday'] ?? null) ? (int) $data['weekday'] : null,
                    'starts' => substr((string) $data['starts'], 0, 5), 'ends' => substr((string) $data['ends'], 0, 5), 'label' => $data['label'] ?? null,
                    'valid_from' => self::date($data['valid_from']), 'valid_to' => filled($data['valid_to'] ?? null) ? self::date($data['valid_to']) : null,
                ], fn ($v) => $v !== null && $v !== '');

                return WorkflowAction::run($action, self::P, fn () => app(CalendarAdminService::class)->addBreak($d, auth()->user()), __(self::L.'.breakAdd.done'));
            });
    }

    public static function breakEnd(): Action
    {
        return WorkflowAction::make('breakEnd', self::P, self::L)->icon('lucide-calendar-x')
            ->visible(fn (mixed $record) => ($record['valid_to'] ?? null) === null || $record['valid_to'] >= now()->toDateString())
            ->schema([self::validTo()])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, self::P,
                fn () => app(CalendarAdminService::class)->endBreak(WorkflowAction::id($record), self::date($data['valid_to'])), __(self::L.'.breakEnd.done')));
    }

    /** @return array<int, string> */
    public static function weekdays(): array
    {
        return collect(range(1, 7))->mapWithKeys(fn (int $d) => [$d => __(self::L.'.weekdays.'.$d)])->all();
    }

    private static function validTo(): DatePicker
    {
        return DatePicker::make('valid_to')->label(__(self::L.'.fields.valid_to'))->required();
    }

    private static function date(mixed $v): string
    {
        return Carbon::parse($v)->toDateString();
    }
}
