<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CalendarExceptions;

use App\Application\Cases\Models\CalendarException;
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

/** REQ-CAS-001 / REQ-CAL-001 - Calendar exceptions (navigation group "Cases & tasks"). */
final class CalendarExceptionResource extends Resource
{
    use CasesAccess;

    protected static ?string $model = CalendarException::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Calendar exceptions';

    protected static ?int $navigationSort = 606;

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
            Forms\Components\DatePicker::make('date')->required(),
            Forms\Components\Select::make('kind')->options(['HOLIDAY' => 'Holiday', 'CLOSURE' => 'Closure', 'EXTRA_DAY' => 'Extra working day'])->required(),
            Forms\Components\TextInput::make('label')->required()->maxLength(160),
            Forms\Components\TextInput::make('source_reference')->maxLength(255)->helperText('Official source (OQ-6.2): decree / gazette reference.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('jurisdiction'),
            Tables\Columns\TextColumn::make('date')->date()->sortable(),
            Tables\Columns\TextColumn::make('kind')->badge(),
            Tables\Columns\TextColumn::make('label'),
            Tables\Columns\TextColumn::make('source_reference')->placeholder('UNVERIFIED')->toggleable(),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCalendarExceptions::route('/'), 'create' => Pages\CreateCalendarException::route('/create'), 'edit' => Pages\EditCalendarException::route('/{record}/edit')];
    }
}
