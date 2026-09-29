<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Filament\Shared\Actions\CalendarActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Calendar breaks (owner decision #22) — GET admin/calendars/breaks (cases.calendar.manage), same query as CaseConfigurationController::breaks. */
final class CalendarBreaks extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-coffee';

    protected static ?int $navigationSort = 607;

    protected static ?string $slug = 'calendars/breaks';

    protected static array $permissions = ['cases.calendar.manage'];

    protected static string $screen = 'calendar_breaks';

    protected static string $group = 'Cases & tasks';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => self::keyed(DB::table('calendar_breaks')->orderBy('jurisdiction')->orderBy('starts')->limit(500)->get()))
            ->columns([
                TextColumn::make('jurisdiction')->label(self::col('jurisdiction')),
                TextColumn::make('branch_id')->label(self::col('branch'))->placeholder(__('operations_actions.all_branches')),
                TextColumn::make('weekday')->label(self::col('weekday'))->formatStateUsing(fn ($state) => CalendarActions::weekdays()[(int) $state] ?? $state)
                    ->placeholder(__('operations_actions.every_day')),
                TextColumn::make('starts')->label(self::col('starts')),
                TextColumn::make('ends')->label(self::col('ends')),
                TextColumn::make('label')->label(self::col('label')),
                TextColumn::make('valid_from')->label(self::col('valid_from'))->date(),
                TextColumn::make('valid_to')->label(self::col('valid_to'))->date()->placeholder(__('operations_actions.open_ended')),
            ])
            ->headerActions([CalendarActions::breakAdd()])
            ->recordActions([CalendarActions::breakEnd()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
