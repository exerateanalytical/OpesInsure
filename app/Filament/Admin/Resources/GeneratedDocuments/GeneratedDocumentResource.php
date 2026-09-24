<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GeneratedDocuments;

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

/** DOC-ADM-016 generated documents registry (+ carrier original upload, revoke/replace request). Never deletes; restricted security levels hidden without the level permission. */
final class GeneratedDocumentResource extends Resource
{
    use DocumentEngineAccess;

    protected static ?string $model = \App\Models\Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Generated documents';

    protected static ?int $navigationSort = 311;

    protected static ?string $slug = 'document-engine/registry';

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
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('document_number')->searchable()->fontFamily('mono')->placeholder('carrier original'),
                Tables\Columns\TextColumn::make('document_type_code')->label('Type')->searchable(),
                Tables\Columns\TextColumn::make('policy.policy_number')->label('Policy')->searchable(),
                Tables\Columns\TextColumn::make('subject_label')->label('Subject')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => in_array($state, DocumentRegister::CURRENT_STATUSES, true) ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('issuer_type')->label('Issuer')->badge(),
                Tables\Columns\IconColumn::make('is_carrier_original')->label('Carrier')->boolean(),
                Tables\Columns\TextColumn::make('language'),
                Tables\Columns\TextColumn::make('verification_code')->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('template_version')->label('Tpl v')->placeholder('—'),
                Tables\Columns\TextColumn::make('issued_at')->dateTime(),
                Tables\Columns\TextColumn::make('security_level')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sha256')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(array_combine(DocumentRegister::STATUSES, DocumentRegister::STATUSES)),
                Tables\Filters\TernaryFilter::make('is_carrier_original')->label('Carrier originals'),
            ])
            ->recordActions([
                Actions\Action::make('statusChange')->label('Revoke / replace')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn ($record) => in_array($record->status, DocumentRegister::CURRENT_STATUSES, true))
                    ->schema([
                        Forms\Components\Select::make('action')->options(['REVOKE' => 'Revoke', 'REPLACE' => 'Replace', 'CANCEL' => 'Cancel'])->required()->live(),
                        Forms\Components\Select::make('replacement_document_id')->label('Replacement document')->visible(fn ($get) => $get('action') === 'REPLACE')
                            ->options(fn ($record) => \App\Models\Document::where('policy_id', $record->policy_id)->whereKeyNot($record->id)->whereIn('status', DocumentRegister::CURRENT_STATUSES)->get()->mapWithKeys(fn ($d) => [$d->id => ($d->document_number ?? $d->verification_code).' · '.$d->document_type_code])->all()),
                        Forms\Components\Textarea::make('reason')->required()->minLength(5),
                    ])
                    ->action(fn ($record, array $data) => ServiceValidation::run(fn () => app(\App\Application\Documents\Engine\DocumentStatusService::class)->request($record, $data['action'], $data['reason'], auth()->user(), $data['replacement_document_id'] ?? null))),
            ])
            ->headerActions([
                Actions\Action::make('carrierUpload')->label('Upload carrier document')->icon(Heroicon::OutlinedArrowUpTray)
                    ->schema([
                        Forms\Components\Select::make('policy_id')->label('Policy')->searchable()->required()
                            ->getSearchResultsUsing(fn (string $s) => \App\Models\Policy::where('policy_number', 'ilike', "%{$s}%")->limit(20)->pluck('policy_number', 'id')->all()),
                        Forms\Components\Select::make('document_type_code')->label('Document type')->options(fn () => collect(app(DocumentRegister::class)->types())->mapWithKeys(fn ($t) => [$t['code'] => $t['id'].' · '.$t['name_en']])->all())->searchable()->required(),
                        Forms\Components\DatePicker::make('issue_date')->required(),
                        Forms\Components\TextInput::make('carrier_document_number')->maxLength(100),
                        Forms\Components\TextInput::make('carrier_version')->maxLength(40),
                        Forms\Components\Select::make('language')->options(['FR' => 'FR', 'EN' => 'EN', 'BILINGUAL' => 'BILINGUAL'])->default('FR'),
                        Forms\Components\TextInput::make('subject_key')->label('Vehicle registration / member / shipment (optional)')->maxLength(120),
                        Forms\Components\FileUpload::make('file')->required()->disk('local')->directory('carrier-uploads')->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(20480),
                    ])
                    ->action(function (array $data) {
                        $disk = \Illuminate\Support\Facades\Storage::disk('local');
                        $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
                        $policy = \App\Models\Policy::findOrFail($data['policy_id']);
                        ServiceValidation::run(fn () => app(\App\Application\Documents\Engine\CarrierDocumentService::class)->upload($policy, (string) $disk->get($path), (string) $disk->mimeType($path), $data, auth()->user()));
                        $disk->delete($path);
                    }),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $levels = collect(DocumentRegister::SECURITY_LEVELS)->filter(fn ($l) => DocumentAccessPolicy::staffMay(auth()->user(), new \App\Models\Document(['security_level' => $l])))->all();

        return parent::getEloquentQuery()->whereNotNull('document_type_code')->whereIn('document_origin', DocumentRegister::ISSUED_ORIGINS)->whereIn('security_level', $levels);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGeneratedDocuments::route('/'),
        ];
    }
}
