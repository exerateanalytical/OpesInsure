<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CaseTypes;

use App\Application\Cases\Models\CaseType;
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

/** REQ-CAS-001 / REQ-CAL-001 - Case types (navigation group "Cases & tasks"). */
final class CaseTypeResource extends Resource
{
    use CasesAccess;

    protected static ?string $model = CaseType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Case types';

    protected static ?int $navigationSort = 603;

    protected static function casesPermission(): string
    {
        return 'cases.admin';
    }

    protected static function casesWritePermission(): ?string
    {
        return null;
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
            Forms\Components\TextInput::make('code')->disabled(),
            Forms\Components\TextInput::make('version')->disabled(),
            Forms\Components\TextInput::make('name')->disabled(),
            Forms\Components\TextInput::make('family_code')->disabled(),
            Forms\Components\TextInput::make('status')->disabled(),
            Forms\Components\TextInput::make('default_confidentiality')->disabled(),
            Forms\Components\TextInput::make('valid_from')->disabled(),
            Forms\Components\TextInput::make('valid_to')->disabled(),
            Forms\Components\Textarea::make('states')->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT))->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('transitions')->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT))->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('sla_policies')->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT))->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->searchable(),
            Tables\Columns\TextColumn::make('version'),
            Tables\Columns\TextColumn::make('name'),
            Tables\Columns\TextColumn::make('family_code')->label('Family (OQ-6.4)')->placeholder('UNVERIFIED'),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('default_confidentiality')->badge(),
        ])->recordActions([Actions\ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCaseTypes::route('/'), 'view' => Pages\ViewCaseType::route('/{record}')];
    }
}
