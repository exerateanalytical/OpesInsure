<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VerificationLookups;

use App\Application\Documents\Engine\DocumentAccessPolicy;
use App\Application\Documents\Engine\DocumentRegister;
use App\Filament\Admin\Concerns\DocumentEngineAccess;
use App\Filament\Admin\Resources\GeneratedDocuments\GeneratedDocumentResource;
use App\Models\PublicVerificationLookup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only log of public verification lookups (QR page and API). Only hashes are stored: the reference, token and
 * requester fingerprint are never shown in clear. Lookups of documents above the viewer's security level are hidden.
 */
final class VerificationLookupResource extends \App\Filament\Shared\LocalizedResource
{
    use DocumentEngineAccess;

    protected static ?string $model = PublicVerificationLookup::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-scan-search';

    protected static ?string $navigationLabel = 'Verification lookups';

    protected static ?int $navigationSort = 313;

    protected static ?string $slug = 'document-engine/verification-lookups';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('occurred_at', 'desc')
            ->recordUrl(fn ($record) => $record->document_id ? GeneratedDocumentResource::getUrl('view', ['record' => $record->document_id]) : null)
            ->columns([
                \App\Filament\Shared\Columns::date('occurred_at')->label('When')->sortable(),
                Tables\Columns\TextColumn::make('channel')->badge(),
                Tables\Columns\TextColumn::make('result')->badge()->color(fn ($state) => match ($state) {
                    'valid' => 'success', 'expired', 'not_yet_active', 'superseded', 'replaced' => 'warning', 'not_found' => 'gray', default => 'danger' }),
                Tables\Columns\TextColumn::make('document.document_number')->searchable()->label('Document')->fontFamily('mono')->placeholder('—')
                    ->url(fn ($record) => $record->document_id ? GeneratedDocumentResource::getUrl('view', ['record' => $record->document_id]) : null),
                Tables\Columns\TextColumn::make('document.document_type_code')->label('Type')->placeholder('—'),
                Tables\Columns\IconColumn::make('token_presented')->label('Token')->boolean(),
                Tables\Columns\TextColumn::make('reference_hash')->label('Reference (hash)')->fontFamily('mono')->limit(12),
                Tables\Columns\TextColumn::make('request_fingerprint_hash')->label('Requester (hash)')->fontFamily('mono')->limit(12)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')->options(['QR_PAGE' => 'QR page', 'API' => 'API']),
                Tables\Filters\SelectFilter::make('result')->options(fn () => PublicVerificationLookup::query()->distinct()->orderBy('result')->pluck('result', 'result')->all()),
                Tables\Filters\TernaryFilter::make('matched')->label('Matched a document')->queries(
                    true: fn (Builder $query) => $query->whereNotNull('document_id'), false: fn (Builder $query) => $query->whereNull('document_id')),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $levels = collect(DocumentRegister::SECURITY_LEVELS)->filter(fn ($l) => DocumentAccessPolicy::staffMay(auth()->user(), new \App\Models\Document(['security_level' => $l])))->all();

        return parent::getEloquentQuery()->with('document')
            ->where(fn (Builder $q) => $q->whereNull('document_id')->orWhereHas('document', fn (Builder $d) => $d->whereIn('security_level', $levels)));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVerificationLookups::route('/')];
    }
}
