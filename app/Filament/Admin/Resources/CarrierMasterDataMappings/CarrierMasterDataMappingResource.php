<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarrierMasterDataMappings;

use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Models\MasterData\CarrierMasterDataMapping;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** MDM-015 Carrier mappings: insurer codes/classes for canonical values (e.g. occupation → insurer risk class, location → insurer zone). Meaning of the canonical value never changes. */
final class CarrierMasterDataMappingResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = CarrierMasterDataMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Carrier mappings';

    protected static ?int $navigationSort = 405;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Mapping')->columns(2)->schema([
            Forms\Components\Select::make('carrier_id')->label('Insurer')->required()->searchable()->options(fn () => \App\Models\Carrier::orderBy('legal_name')->pluck('legal_name', 'id')->filter()->all()),
            self::valueSelect(),
            Forms\Components\TextInput::make('target')->default('CODE')->required()->helperText('CODE, RISK_CLASS, LOCATION_RISK_ZONE, …'),
            Forms\Components\TextInput::make('external_code')->required()->maxLength(128), Forms\Components\TextInput::make('external_label'),
            Forms\Components\Select::make('status')->options(['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive'])->default('ACTIVE'),
        ])]);
    }

    public static function valueSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('value_id')->label('Canonical value')->required()->searchable()
            ->getSearchResultsUsing(fn (string $s) => \App\Models\MasterData\MasterDataValue::where('search_text', 'like', '%'.\App\Application\MasterData\MasterDataNormalizer::normalize($s).'%')->limit(50)->get()->mapWithKeys(fn ($v) => [$v->id => "{$v->domain_code}.{$v->list_code}: {$v->label_en} ({$v->code})"])->all())
            ->getOptionLabelUsing(fn ($id) => ($v = \App\Models\MasterData\MasterDataValue::find($id)) ? "{$v->domain_code}.{$v->list_code}: {$v->label_en}" : null);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('value'))->columns([
            Tables\Columns\TextColumn::make('carrier_id')->label('Insurer')->state(fn ($record) => \App\Models\Carrier::find($record->carrier_id)?->legal_name),
            Tables\Columns\TextColumn::make('value.code')->label('Value'), Tables\Columns\TextColumn::make('value.list_code')->label('List'),
            Tables\Columns\TextColumn::make('target')->badge(), Tables\Columns\TextColumn::make('external_code')->searchable(), Tables\Columns\TextColumn::make('status')->badge(),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCarrierMasterDataMappings::route('/'),
            'create' => Pages\CreateCarrierMasterDataMapping::route('/create'),
            'edit' => Pages\EditCarrierMasterDataMapping::route('/{record}/edit'),
        ];
    }
}
