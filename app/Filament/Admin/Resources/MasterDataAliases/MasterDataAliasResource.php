<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataAliases;

use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Models\MasterData\MasterDataAlias;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** MDM-006 Aliases and abbreviations (EN/FR, merged codes, tenant aliases) used by search. */
final class MasterDataAliasResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataAlias::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Aliases';

    protected static ?int $navigationSort = 403;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Alias')->columns(2)->schema([
            Forms\Components\Select::make('value_id')->label('Value')->required()->searchable()
                ->getSearchResultsUsing(fn (string $s) => \App\Models\MasterData\MasterDataValue::where('search_text', 'like', '%'.\App\Application\MasterData\MasterDataNormalizer::normalize($s).'%')->limit(50)->get()->mapWithKeys(fn ($v) => [$v->id => "{$v->domain_code}.{$v->list_code}: {$v->label_en} ({$v->code})"])->all())
                ->getOptionLabelUsing(fn ($id) => ($v = \App\Models\MasterData\MasterDataValue::find($id)) ? "{$v->domain_code}.{$v->list_code}: {$v->label_en}" : null),
            Forms\Components\TextInput::make('alias')->required()->maxLength(255),
            Forms\Components\Select::make('alias_type')->options(['ALIAS' => 'Alias', 'ABBREVIATION' => 'Abbreviation', 'MERGED_CODE' => 'Merged code'])->default('ALIAS'),
            Forms\Components\Select::make('locale')->options(['en' => 'English', 'fr' => 'French']),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->modifyQueryUsing(fn ($query) => $query->with('value'))->columns([
            Tables\Columns\TextColumn::make('alias')->searchable(), Tables\Columns\TextColumn::make('alias_type')->badge(),
            Tables\Columns\TextColumn::make('value.code')->label('Value'), Tables\Columns\TextColumn::make('value.label_en')->label('Label'),
            Tables\Columns\TextColumn::make('value.list_code')->label('List')->toggleable(), Tables\Columns\TextColumn::make('locale')->placeholder('—'),
            Tables\Columns\IconColumn::make('is_seeded')->boolean()->label('Seeded'),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataAliases::route('/'),
            'create' => Pages\CreateMasterDataAlias::route('/create'),
            'edit' => Pages\EditMasterDataAlias::route('/{record}/edit'),
        ];
    }
}
