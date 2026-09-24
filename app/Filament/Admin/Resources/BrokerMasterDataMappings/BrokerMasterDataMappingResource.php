<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BrokerMasterDataMappings;

use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Models\MasterData\BrokerMasterDataMapping;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** MDM-016 Broker mappings: a broker's internal codes for canonical values. */
final class BrokerMasterDataMappingResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = BrokerMasterDataMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Broker mappings';

    protected static ?int $navigationSort = 406;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Mapping')->columns(2)->schema([
            Forms\Components\Select::make('partner_id')->label('Broker')->required()->searchable()->options(fn () => \App\Models\Partner::orderBy('legal_name')->pluck('legal_name', 'id')->filter()->all()),
            \App\Filament\Admin\Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource::valueSelect(),
            Forms\Components\TextInput::make('external_code')->required()->maxLength(128), Forms\Components\TextInput::make('external_label'),
            Forms\Components\Select::make('status')->options(['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive'])->default('ACTIVE'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('value'))->columns([
            Tables\Columns\TextColumn::make('partner_id')->label('Broker')->state(fn ($record) => \App\Models\Partner::find($record->partner_id)?->legal_name),
            Tables\Columns\TextColumn::make('value.code')->label('Value'), Tables\Columns\TextColumn::make('value.list_code')->label('List'),
            Tables\Columns\TextColumn::make('external_code')->searchable(), Tables\Columns\TextColumn::make('status')->badge(),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrokerMasterDataMappings::route('/'),
            'create' => Pages\CreateBrokerMasterDataMapping::route('/create'),
            'edit' => Pages\EditBrokerMasterDataMapping::route('/{record}/edit'),
        ];
    }
}
