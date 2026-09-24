<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataDomains;

use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Models\MasterData\MasterDataDomain;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;

/** MDM-002 Domains · MDM-017 catalog versions (per-domain catalog_version drives app sync). */
final class MasterDataDomainResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataDomain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Domains';

    protected static ?int $navigationSort = 400;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Domain')->columns(2)->schema([
            Forms\Components\TextInput::make('code')->disabled(),
            Forms\Components\Select::make('status')->options(['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive'])->required(),
            Forms\Components\TextInput::make('label_en')->required(), Forms\Components\TextInput::make('label_fr')->required(),
            Forms\Components\Textarea::make('description_en'), Forms\Components\Textarea::make('description_fr'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            Tables\Columns\TextColumn::make('code')->searchable(),
            Tables\Columns\TextColumn::make('label_en')->label('EN'), Tables\Columns\TextColumn::make('label_fr')->label('FR'),
            Tables\Columns\TextColumn::make('lists_count')->counts('lists')->label('Lists'),
            Tables\Columns\TextColumn::make('values')->label('Values')->state(fn ($record) => \App\Models\MasterData\MasterDataValue::where('domain_code', $record->code)->count()),
            Tables\Columns\TextColumn::make('catalog_version')->label('Version')->badge(),
            Tables\Columns\TextColumn::make('source_file')->label('File')->toggleable(),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('updated_at')->since()->label('Changed'),
        ])->recordActions([Actions\EditAction::make(),
            Actions\Action::make('values')->label('Values')->url(fn ($record) => MasterDataValueResource::getUrl('index', ['filters' => ['domain_code' => ['value' => $record->code]]])),
            Actions\Action::make('history')->label('History')->url(fn ($record) => \App\Filament\Admin\Resources\MasterDataChanges\MasterDataChangeResource::getUrl('index', ['filters' => ['domain_code' => ['value' => $record->code]]])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataDomains::route('/'),
            'edit' => Pages\EditMasterDataDomain::route('/{record}/edit'),
        ];
    }
}
