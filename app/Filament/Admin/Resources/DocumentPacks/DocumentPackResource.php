<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentPacks;

use App\Filament\Admin\Concerns\DocumentCatalogueAccess;
use App\Filament\Admin\Resources\DocumentTypes\DocumentTypeResource;
use App\Models\DocumentCatalogue\DocumentPack;
use BackedEnum;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** Document packs per insurance class and lifecycle stage (+ universal packs). Read-only; deactivate only. */
final class DocumentPackResource extends Resource
{
    use DocumentCatalogueAccess;

    protected static ?string $model = DocumentPack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Document packs';

    protected static ?string $modelLabel = 'document pack';

    protected static ?int $navigationSort = 301;

    protected static ?string $slug = 'document-packs';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->modifyQueryUsing(fn ($query) => $query->withCount('items'))->columns([
            Tables\Columns\TextColumn::make('code')->searchable()->fontFamily('mono'),
            Tables\Columns\TextColumn::make('label_fr')->label('Français')->searchable()->wrap(),
            Tables\Columns\TextColumn::make('lifecycle_stage')->label('Stage')->badge(),
            Tables\Columns\TextColumn::make('scope')->badge()->color('gray'),
            Tables\Columns\TextColumn::make('class_codes')->label('Classes')->badge()->placeholder('All classes')->wrap(),
            Tables\Columns\TextColumn::make('items_count')->label('Documents'),
            Tables\Columns\IconColumn::make('is_universal')->boolean()->label('Universal'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'ACTIVE' ? 'success' : 'gray'),
        ])->filters([
            Tables\Filters\SelectFilter::make('lifecycle_stage')->options(fn () => DocumentPack::query()->distinct()->orderBy('lifecycle_stage')->pluck('lifecycle_stage', 'lifecycle_stage')->all()),
            Tables\Filters\SelectFilter::make('scope')->options(['POLICY' => 'Policy', 'PER_MEMBER' => 'Per member', 'PER_VEHICLE' => 'Per vehicle', 'PER_SHIPMENT' => 'Per shipment']),
            Tables\Filters\TernaryFilter::make('is_universal'),
        ])->recordActions([Actions\ViewAction::make(), DocumentTypeResource::deactivateAction()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pack')->columns(3)->schema([
                Infolists\Components\TextEntry::make('code')->copyable(), Infolists\Components\TextEntry::make('label_fr'), Infolists\Components\TextEntry::make('label_en'),
                Infolists\Components\TextEntry::make('lifecycle_stage')->badge(), Infolists\Components\TextEntry::make('scope'), Infolists\Components\TextEntry::make('class_codes')->badge()->placeholder('All classes'),
                Infolists\Components\TextEntry::make('owner_pack')->label('Owner register pack')->placeholder('-'), Infolists\Components\TextEntry::make('catalogue_version'), Infolists\Components\TextEntry::make('status')->badge(),
            ]),
            Section::make('Documents (each kept separately)')->schema([
                Infolists\Components\RepeatableEntry::make('items')->hiddenLabel()->columns(5)->schema([
                    Infolists\Components\TextEntry::make('document_type_id')->label('ID')->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('documentType.name_fr')->label('Document'),
                    Infolists\Components\TextEntry::make('requirement')->badge()->color(fn (string $state) => $state === 'REQUIRED' ? 'success' : 'gray'),
                    Infolists\Components\TextEntry::make('condition_note')->label('Condition')->placeholder('-')->columnSpan(2),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocumentPacks::route('/'), 'view' => Pages\ViewDocumentPack::route('/{record}')];
    }
}
