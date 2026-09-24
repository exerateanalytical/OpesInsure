<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentPackItems;

use App\Filament\Admin\Concerns\DocumentCatalogueAccess;
use App\Filament\Admin\Resources\DocumentTypes\DocumentTypeResource;
use App\Models\DocumentCatalogue\DocumentPack;
use App\Models\DocumentCatalogue\DocumentPackItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** Pack items across all packs (requirement + condition per document). */
final class DocumentPackItemResource extends Resource
{
    use DocumentCatalogueAccess;

    protected static ?string $model = DocumentPackItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Pack items';

    protected static ?string $modelLabel = 'pack item';

    protected static ?int $navigationSort = 302;

    protected static ?string $slug = 'document-pack-items';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->modifyQueryUsing(fn ($query) => $query->with(['pack', 'documentType']))->columns([
            Tables\Columns\TextColumn::make('pack.code')->label('Pack')->searchable()->fontFamily('mono'),
            Tables\Columns\TextColumn::make('sort_order')->label('#'),
            Tables\Columns\TextColumn::make('document_type_id')->label('ID')->searchable()->fontFamily('mono'),
            Tables\Columns\TextColumn::make('documentType.name_fr')->label('Document')->wrap(),
            Tables\Columns\TextColumn::make('requirement')->badge()->color(fn (string $state) => $state === 'REQUIRED' ? 'success' : 'gray'),
            Tables\Columns\TextColumn::make('condition_note')->label('Condition')->placeholder('-')->wrap(),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'ACTIVE' ? 'success' : 'gray'),
        ])->filters([
            Tables\Filters\SelectFilter::make('document_pack_id')->label('Pack')->searchable()->options(fn () => DocumentPack::orderBy('code')->pluck('code', 'id')->all()),
            Tables\Filters\SelectFilter::make('requirement')->options(array_combine(
                ['REQUIRED', 'CONDITIONAL', 'PRODUCT_DEPENDENT', 'WHERE_APPLICABLE', 'OPTIONAL', 'INTERNAL', 'THIRD_PARTY'],
                ['Required', 'Conditional', 'Product dependent', 'Where applicable', 'Optional', 'Internal', 'Third party'])),
        ])->recordActions([DocumentTypeResource::deactivateAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocumentPackItems::route('/')];
    }
}
