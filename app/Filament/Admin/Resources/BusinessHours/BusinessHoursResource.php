<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BusinessHours;

use App\Application\Cases\Models\CalendarBusinessHours;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Concerns\CasesAccess;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** REQ-CAS-001 / REQ-CAL-001 - Business hours (navigation group "Cases & tasks"). */
final class BusinessHoursResource extends Resource
{
    use CasesAccess;

    protected static ?string $model = CalendarBusinessHours::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Business hours';

    protected static ?int $navigationSort = 605;

    protected static function casesPermission(): string
    {
        return 'cases.calendar.manage';
    }

    protected static function casesWritePermission(): ?string
    {
        return 'cases.calendar.manage';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('jurisdiction')->required()->length(2)->default('CM'),
            Forms\Components\Select::make('weekday')->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'])->required(),
            Forms\Components\TimePicker::make('opens')->seconds(false)->required(),
            Forms\Components\TimePicker::make('closes')->seconds(false)->required()->after('opens'),
            Forms\Components\DatePicker::make('valid_from')->required(),
            Forms\Components\DatePicker::make('valid_to')->helperText('End-date instead of deleting.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('jurisdiction'),
            Tables\Columns\TextColumn::make('branch_id')->label('Branch')->placeholder('All branches'),
            Tables\Columns\TextColumn::make('weekday'),
            Tables\Columns\TextColumn::make('opens'),
            Tables\Columns\TextColumn::make('closes'),
            Tables\Columns\TextColumn::make('valid_from')->date(),
            Tables\Columns\TextColumn::make('valid_to')->date()->placeholder('open-ended'),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBusinessHours::route('/'), 'create' => Pages\CreateBusinessHours::route('/create'), 'edit' => Pages\EditBusinessHours::route('/{record}/edit')];
    }
}
