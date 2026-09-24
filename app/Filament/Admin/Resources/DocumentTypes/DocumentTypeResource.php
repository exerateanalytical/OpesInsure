<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTypes;

use App\Filament\Admin\Concerns\DocumentCatalogueAccess;
use App\Models\DocumentCatalogue\DocumentType;
use BackedEnum;
use Filament\Actions;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** Canonical Insurance Document Type Registry (owner 220 register + subtypes + evidence). Read-only; deactivate only. */
final class DocumentTypeResource extends Resource
{
    use DocumentCatalogueAccess;

    protected static ?string $model = DocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Document types';

    protected static ?string $modelLabel = 'document type';

    protected static ?int $navigationSort = 300;

    protected static ?string $slug = 'document-types';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('type_id')->columns([
            Tables\Columns\TextColumn::make('type_id')->label('ID')->searchable()->sortable()->fontFamily('mono'),
            Tables\Columns\TextColumn::make('canonical_code')->label('Code')->searchable()->fontFamily('mono')->wrap(),
            Tables\Columns\TextColumn::make('name_fr')->label('Français')->searchable()->wrap(),
            Tables\Columns\TextColumn::make('name_en')->label('English')->searchable()->wrap()->toggleable(),
            Tables\Columns\TextColumn::make('category')->badge(),
            Tables\Columns\TextColumn::make('kind')->badge()->color(fn (string $state) => match ($state) { 'EVIDENCE' => 'warning', 'SUBTYPE' => 'gray', 'FAMILY' => 'info', default => 'primary' }),
            Tables\Columns\TextColumn::make('document_origin')->label('Origin')->badge()->color('gray'),
            Tables\Columns\TextColumn::make('security_level')->label('Security')->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\IconColumn::make('verifiable')->boolean(),
            Tables\Columns\TextColumn::make('scope')->toggleable(),
            Tables\Columns\TextColumn::make('legal_reference')->placeholder('-')->toggleable(),
            Tables\Columns\TextColumn::make('numbering_family')->label('Numbering')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'ACTIVE' ? 'success' : 'gray'),
        ])->filters([
            Tables\Filters\SelectFilter::make('category')->options(fn () => DocumentType::query()->distinct()->orderBy('category')->pluck('category', 'category')->all()),
            Tables\Filters\SelectFilter::make('register_group')->label('Register group (A–N)')->options(array_combine(range('A', 'N'), range('A', 'N'))),
            Tables\Filters\SelectFilter::make('kind')->options(['TYPE' => 'Register type', 'FAMILY' => 'Claim family', 'SUBTYPE' => 'Subtype', 'EVIDENCE' => 'Customer / third-party evidence']),
            Tables\Filters\SelectFilter::make('document_origin')->label('Origin')->options(fn () => DocumentType::query()->distinct()->orderBy('document_origin')->pluck('document_origin', 'document_origin')->all()),
            Tables\Filters\SelectFilter::make('security_level')->options(fn () => DocumentType::query()->distinct()->orderBy('security_level')->pluck('security_level', 'security_level')->all()),
            Tables\Filters\TernaryFilter::make('verifiable'),
        ])->recordActions([Actions\ViewAction::make(), static::deactivateAction()]);
    }

    public static function deactivateAction(): Actions\Action
    {
        return Actions\Action::make('deactivate')->label('Deactivate')->icon(Heroicon::OutlinedArchiveBox)->color('gray')->requiresConfirmation()
            ->modalDescription('Seeded catalogue rows are never deleted. Deactivation closes the row (status INACTIVE, effective until today).')
            ->visible(fn ($record) => $record->status === 'ACTIVE' && static::canAccessDocumentCatalogue())
            ->action(function ($record) {
                $record->deactivate();
                Notification::make()->title('Deactivated')->success()->send();
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        $t = fn (string $f) => Infolists\Components\TextEntry::make($f)->placeholder('-');

        return $schema->components([
            Section::make('Identity')->columns(3)->schema([
                $t('type_id')->copyable(), $t('canonical_code')->copyable(), $t('kind')->badge(), $t('namespace'), $t('parent_type_id'), $t('same_as_type_id'),
                $t('name_fr'), $t('name_en'), $t('aliases')->badge()->label('Aliases (legacy codes)'),
            ]),
            Section::make('Classification')->columns(3)->schema([
                $t('register_group'), $t('register_group_code'), $t('category')->badge(), $t('stages')->badge(), $t('audience'), $t('scope'),
            ]),
            Section::make('Issuance & control')->columns(3)->schema([
                $t('document_origin')->badge(), $t('issuer_authority')->badge()->label('Who may issue'), $t('recipient'),
                $t('generation_triggers')->badge(), $t('numbering_family'), $t('security_level')->badge(),
                Infolists\Components\IconEntry::make('verifiable')->boolean(), Infolists\Components\IconEntry::make('is_evidence')->boolean()->label('Customer / third-party evidence'), $t('legal_reference'),
            ]),
            Section::make('Provenance')->columns(3)->schema([
                $t('catalogue_version'), $t('effective_from')->date(), $t('effective_until')->date()->placeholder('Open'), $t('status')->badge(),
                Infolists\Components\IconEntry::make('is_seeded')->boolean()->label('Protected (seeded)'), $t('source_reference')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDocumentTypes::route('/'), 'view' => Pages\ViewDocumentType::route('/{record}')];
    }
}
