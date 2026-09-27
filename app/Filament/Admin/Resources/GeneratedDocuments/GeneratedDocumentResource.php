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
use Filament\Tables;
use Filament\Tables\Table;

/** DOC-ADM-016 generated documents registry (+ carrier original upload, revoke/replace request). Never deletes; restricted security levels hidden without the level permission. */
final class GeneratedDocumentResource extends Resource
{
    use DocumentEngineAccess;

    protected static ?string $model = \App\Models\Document::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-archive';

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

    /** Revoke / replace / cancel through DocumentStatusService (shared by the registry table and the detail page). */
    public static function statusChangeAction(): Actions\Action
    {
        return Actions\Action::make('statusChange')->label('Revoke / replace')->icon('lucide-ban')->color('danger')
            ->visible(fn ($record) => in_array($record->status, DocumentRegister::CURRENT_STATUSES, true))
            ->schema([
                Forms\Components\Select::make('action')->options(['REVOKE' => 'Revoke', 'REPLACE' => 'Replace', 'CANCEL' => 'Cancel'])->required()->live(),
                Forms\Components\Select::make('replacement_document_id')->label('Replacement document')->visible(fn ($get) => $get('action') === 'REPLACE')
                    ->options(fn ($record) => \App\Models\Document::where('policy_id', $record->policy_id)->whereKeyNot($record->id)->whereIn('status', DocumentRegister::CURRENT_STATUSES)->get()->mapWithKeys(fn ($d) => [$d->id => ($d->document_number ?? $d->verification_code).' · '.$d->document_type_code])->all()),
                Forms\Components\Textarea::make('reason')->required()->minLength(5),
            ])
            ->action(fn ($record, array $data) => ServiceValidation::run(fn () => app(\App\Application\Documents\Engine\DocumentStatusService::class)->request($record, $data['action'], $data['reason'], auth()->user(), $data['replacement_document_id'] ?? null)));
    }

    /**
     * Tamper check: upload a PDF, compare its SHA-256 with the registry record and verify the platform signature
     * (TamperCheck). On a row it checks against that record; in the header it identifies the record by fingerprint.
     */
    public static function tamperCheckAction(bool $identify = false): Actions\Action
    {
        return Actions\Action::make($identify ? 'tamperIdentify' : 'tamperCheck')->label($identify ? 'Tamper check a PDF' : 'Tamper check')
            ->icon('lucide-shield-check')->color('gray')
            ->modalDescription('The file is hashed and compared with the registry original and its platform signature. The file is not kept.')
            ->schema([Forms\Components\FileUpload::make('file')->label('PDF to check')->required()->disk('local')->directory('tamper-checks')
                ->acceptedFileTypes(['application/pdf'])->maxSize(20480)])
            ->action(fn (array $data, $record = null) => self::runTamperCheck($data, $identify ? null : $record));
    }

    /** @return array<string, mixed> the TamperCheck result, also shown as a persistent notification */
    public static function runTamperCheck(array $data, ?\App\Models\Document $record): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
        try {
            $bytes = (string) $disk->get($path);
        } finally {
            $disk->delete($path);
        }
        $check = app(\App\Application\Documents\Security\TamperCheck::class);
        $r = $record ? $check->against($record, $bytes) : $check->identify($bytes, fn ($d) => static::canView($d));
        $n = \Filament\Notifications\Notification::make()->persistent()->title('Tamper check: '.$r['verdict'])
            ->body(\App\Application\Documents\Security\TamperCheck::summary($r).' File SHA-256 '.substr($r['file_sha256'], 0, 16).'… · hash '.$r['hash']
                .' · signature '.$r['signature'].($r['document_number'] ? ' · document '.$r['document_number'] : ''));
        match ($r['verdict']) { 'AUTHENTIC' => $n->success(), 'UNKNOWN' => $n->warning(), default => $n->danger() };
        $n->send();

        return $r;
    }

    public static function canView($record): bool
    {
        return static::canAccessDocumentEngine() && DocumentAccessPolicy::staffMay(auth()->user(), $record);
    }

    public static function infolist(Schema $schema): Schema
    {
        $o = \App\Filament\Shared\Components\CoreRecordOverview::class;

        return $schema->components(\App\Filament\Shared\Components\RecordShell::detailTabs('document', [
            $o::section('document', [
                $o::text('document_number', 'number')->copyable()->fontFamily('mono'), $o::status(), $o::text('document_type_code', 'type'),
                $o::text('title', 'subject'), $o::text('policy.policy_number', 'policy'), $o::text('party.display_name', 'customer'),
                $o::text('issuer_type', 'issuer')->badge(), $o::text('document_origin', 'origin'), $o::text('document_stage', 'stage'),
                $o::text('language'), $o::text('template_version'), $o::text('status_reason'),
            ]),
            $o::section('integrity', [
                $o::text('security_level')->badge(), $o::text('verification_code')->copyable()->fontFamily('mono'), $o::text('verification_status', 'status'),
                $o::date('issued_at', 'issued', true), $o::date('valid_from', 'valid_from'), $o::date('valid_until', 'valid_until'),
                \Filament\Infolists\Components\IconEntry::make('is_carrier_original')->label(__('web_experience.fields.carrier_original'))->boolean(),
                $o::text('sha256')->fontFamily('mono')->columnSpanFull(),
            ]),
        ], null, true, ['financial'], [
            \Filament\Schemas\Components\Tabs\Tab::make('Revoke / replace history')->schema([\Filament\Infolists\Components\ViewEntry::make('status_history')->hiddenLabel()->view('filament.admin.documents.status-history')->columnSpanFull()]),
            \Filament\Schemas\Components\Tabs\Tab::make('Verification lookups')->schema([\Filament\Infolists\Components\ViewEntry::make('verification_lookups')->hiddenLabel()->view('filament.admin.documents.verification-lookups')->columnSpanFull()]),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('document_number')->searchable()->fontFamily('mono')->placeholder('carrier original'),
                Tables\Columns\TextColumn::make('document_type_code')->label('Type')->searchable(),
                Tables\Columns\TextColumn::make('policy.policy_number')->label('Policy')->searchable(),
                Tables\Columns\TextColumn::make('subject_label')->label('Subject')->placeholder('—'),
                \App\Filament\Shared\Columns::status('status'),
                Tables\Columns\TextColumn::make('issuer_type')->label('Issuer')->badge(),
                Tables\Columns\IconColumn::make('is_carrier_original')->label('Carrier')->boolean(),
                Tables\Columns\TextColumn::make('language'),
                Tables\Columns\TextColumn::make('verification_code')->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('template_version')->label('Tpl v')->placeholder('—'),
                \App\Filament\Shared\Columns::date('issued_at'),
                Tables\Columns\TextColumn::make('security_tier')->label('Tier')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('security_level')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sha256')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('document_type_code')->label('Type')->searchable()
                    ->options(fn () => collect(app(DocumentRegister::class)->types())->mapWithKeys(fn ($t) => [$t['code'] => $t['id'].' · '.$t['name_en']])->all()),
                Tables\Filters\SelectFilter::make('status')->options(array_combine(DocumentRegister::STATUSES, DocumentRegister::STATUSES)),
                Tables\Filters\SelectFilter::make('security_tier')->label('Tier')->options(['S1' => 'S1', 'S2' => 'S2', 'S3' => 'S3', 'S4' => 'S4', 'S5' => 'S5']),
                Tables\Filters\SelectFilter::make('issuer_type')->label('Issuer type')->options(fn () => \App\Models\Document::query()->whereNotNull('issuer_type')->distinct()->orderBy('issuer_type')->pluck('issuer_type', 'issuer_type')->all()),
                Tables\Filters\SelectFilter::make('issuer_carrier_id')->label('Issuer')->searchable()
                    ->options(fn () => \App\Models\Carrier::with('party')->get()->mapWithKeys(fn ($c) => [$c->id => $c->party?->display_name ?? $c->cima_code])->sort()->all()),
                Tables\Filters\TernaryFilter::make('is_carrier_original')->label('Carrier originals'),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                self::tamperCheckAction(),
                self::statusChangeAction(),
            ])
            ->headerActions([
                self::tamperCheckAction(identify: true),
                Actions\Action::make('carrierUpload')->label('Upload carrier document')->icon('lucide-upload')
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
            'view' => Pages\ViewGeneratedDocument::route('/{record}'),
        ];
    }
}
