<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentNumberingFamilies;

use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Application\Documents\Engine\DocumentRegister;
use App\Filament\Admin\Concerns\DocumentEngineAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** DOC-ADM-012 numbering families: continuous, gap-free counters per tenant + family (counters are never editable here). */
final class DocumentNumberingFamilyResource extends Resource
{
    use DocumentEngineAccess;

    protected static ?string $model = \App\Models\DocumentNumberingFamily::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?string $navigationLabel = 'Numbering';

    protected static ?int $navigationSort = 307;

    protected static ?string $slug = 'document-engine/numbering';

    public static function canCreate(): bool
    {
        return true && static::canAccessDocumentEngine();
    }

    public static function canEdit($record): bool
    {
        return true && static::canAccessDocumentEngine();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Numbering family')->columns(3)->schema([
                Forms\Components\Select::make('tenant_id')->label('Tenant (empty = platform default)')->options(fn () => \App\Models\Tenant::orderBy('legal_name')->pluck('legal_name', 'id')->all())->searchable()->disabledOn('edit'),
                Forms\Components\TextInput::make('family_code')->required()->maxLength(24)->helperText('POL, ATT-MOT, AVN, CLM, RCT, SET, CRT, DOC …')->disabledOn('edit'),
                Forms\Components\TextInput::make('prefix')->required()->maxLength(24),
                Forms\Components\Toggle::make('include_year')->default(true),
                Forms\Components\TextInput::make('pad')->numeric()->minValue(4)->maxValue(10)->default(6),
                Forms\Components\TagsInput::make('document_type_codes')->label('Extra document types claimed by this family'),
                Forms\Components\Select::make('status')->options(['ACTIVE' => 'ACTIVE', 'INACTIVE' => 'INACTIVE'])->default('ACTIVE'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('tenant_id')->label('Tenant')->state(fn ($record) => $record->tenant_id ? \App\Models\Tenant::whereKey($record->tenant_id)->value('legal_name') : 'Platform default'),
            Tables\Columns\TextColumn::make('family_code')->badge(),
            Tables\Columns\TextColumn::make('prefix')->fontFamily('mono'),
            Tables\Columns\IconColumn::make('include_year')->boolean(),
            Tables\Columns\TextColumn::make('pad'),
            Tables\Columns\TextColumn::make('status')->badge(),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentNumberingFamilies::route('/'),
            'create' => Pages\CreateDocumentNumberingFamily::route('/create'),
            'edit' => Pages\EditDocumentNumberingFamily::route('/{record}/edit'),
        ];
    }
}
