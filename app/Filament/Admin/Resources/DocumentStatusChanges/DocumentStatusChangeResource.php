<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentStatusChanges;

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

/** DOC-ADM-017 revocation / replacement queue: maker-checker decisions (requester cannot decide). */
final class DocumentStatusChangeResource extends Resource
{
    use DocumentEngineAccess;

    protected static ?string $model = \App\Models\DocumentStatusChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $navigationLabel = 'Revocation & replacement';

    protected static ?int $navigationSort = 312;

    protected static ?string $slug = 'document-engine/revocations';

    public static function canCreate(): bool
    {
        return false && static::canAccessDocumentEngine();
    }

    public static function canEdit($record): bool
    {
        return false && static::canAccessDocumentEngine();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('document.document_number')->label('Document')->placeholder('carrier original'),
            Tables\Columns\TextColumn::make('document.document_type_code')->label('Type'),
            Tables\Columns\TextColumn::make('action')->badge(),
            Tables\Columns\TextColumn::make('reason')->wrap(),
            Tables\Columns\TextColumn::make('replacement.document_number')->label('Replacement')->placeholder('—'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'PENDING' => 'warning', 'APPROVED' => 'success', default => 'gray' }),
            Tables\Columns\TextColumn::make('created_at')->dateTime(),
        ])->recordActions([
            Actions\Action::make('approve')->color('success')->requiresConfirmation()->visible(fn ($record) => $record->status === 'PENDING')
                ->action(fn ($record) => ServiceValidation::run(fn () => app(\App\Application\Documents\Engine\DocumentStatusService::class)->approve($record, auth()->user()))),
            Actions\Action::make('reject')->color('danger')->visible(fn ($record) => $record->status === 'PENDING')
                ->schema([Forms\Components\Textarea::make('note')->required()])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => app(\App\Application\Documents\Engine\DocumentStatusService::class)->reject($record, auth()->user(), $data['note']))),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentStatusChanges::route('/'),
        ];
    }
}
