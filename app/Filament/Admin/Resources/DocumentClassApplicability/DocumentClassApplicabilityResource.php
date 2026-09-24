<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentClassApplicability;

use App\Filament\Admin\Concerns\DocumentCatalogueAccess;
use App\Filament\Admin\Resources\DocumentTypes\DocumentTypeResource;
use App\Models\DocumentCatalogue\DocumentTypeClassApplicability;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** Which document types apply to which insurance class (derived from the class packs). */
final class DocumentClassApplicabilityResource extends Resource
{
    use DocumentCatalogueAccess;

    protected static ?string $model = DocumentTypeClassApplicability::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Class applicability';

    protected static ?string $modelLabel = 'class applicability';

    protected static ?string $pluralModelLabel = 'class applicability';

    protected static ?int $navigationSort = 303;

    protected static ?string $slug = 'document-class-applicability';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('class_code')->modifyQueryUsing(fn ($query) => $query->with('documentType'))->columns([
            Tables\Columns\TextColumn::make('class_code')->label('Class')->searchable()->badge(),
            Tables\Columns\TextColumn::make('document_type_id')->label('ID')->searchable()->fontFamily('mono'),
            Tables\Columns\TextColumn::make('documentType.name_fr')->label('Document')->wrap(),
            Tables\Columns\TextColumn::make('documentType.category')->label('Category')->badge()->color('gray'),
            Tables\Columns\TextColumn::make('source'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'ACTIVE' ? 'success' : 'gray'),
        ])->filters([
            Tables\Filters\SelectFilter::make('class_code')->label('Class')->searchable()->options(fn () => DocumentTypeClassApplicability::query()->distinct()->orderBy('class_code')->pluck('class_code', 'class_code')->all()),
        ])->recordActions([DocumentTypeResource::deactivateAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocumentClassApplicability::route('/')];
    }
}
