<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTemplates;

use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Documents\Engine\DocumentTemplateService;
use App\Filament\Admin\Concerns\DocumentEngineAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Carrier;
use App\Models\DocumentTemplate;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * DOC-ADM-006 template directory, 007 create, 008 designer (DRAFT only),
 * 009 version history (view page). Workflow DRAFT → REVIEW → APPROVED →
 * PUBLISHED → RETIRED through DocumentTemplateService (maker-checker).
 */
final class DocumentTemplateResource extends Resource
{
    use DocumentEngineAccess;

    protected static ?string $model = DocumentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $navigationLabel = 'Templates';

    protected static ?int $navigationSort = 304;

    protected static ?string $slug = 'document-engine/templates';

    public static function canCreate(): bool
    {
        return static::canAccessDocumentEngine();
    }

    public static function canEdit($record): bool
    {
        return static::canAccessDocumentEngine() && $record->status === 'DRAFT';
    }

    public static function form(Schema $schema): Schema
    {
        $types = collect(app(DocumentRegister::class)->types())->mapWithKeys(fn ($t) => [$t['code'] => $t['id'].' · '.$t['name_en']])->all();

        return $schema->components([
            Section::make('Scope')->columns(3)->schema([
                Forms\Components\Select::make('document_type_code')->label('Document type')->options($types)->searchable()->required()->disabledOn('edit'),
                Forms\Components\Select::make('ownership')->options(array_combine(DocumentTemplateService::OWNERSHIPS, DocumentTemplateService::OWNERSHIPS))->required()->live()->disabledOn('edit'),
                Forms\Components\Select::make('language')->options(['BILINGUAL' => 'Bilingual FR/EN', 'FR' => 'Français', 'EN' => 'English'])->required()->disabledOn('edit'),
                Forms\Components\Select::make('carrier_id')->label('Insurer')->options(fn () => Carrier::with('party')->get()->mapWithKeys(fn ($c) => [$c->id => $c->party?->display_name ?? $c->cima_code])->all())
                    ->visible(fn ($get) => $get('ownership') === 'INSURER')->required(fn ($get) => $get('ownership') === 'INSURER')->disabledOn('edit'),
                Forms\Components\Select::make('broker_tenant_id')->label('Broker')->options(fn () => Tenant::where('type', 'BROKER')->pluck('legal_name', 'id')->all())
                    ->visible(fn ($get) => $get('ownership') === 'BROKER')->required(fn ($get) => $get('ownership') === 'BROKER')->disabledOn('edit'),
                Forms\Components\TextInput::make('insurance_class')->helperText('LIFE for life-specific templates (required for life policies).')->maxLength(40)->disabledOn('edit'),
                Forms\Components\DatePicker::make('effective_from')->default(now()),
                Forms\Components\DatePicker::make('effective_until')->afterOrEqual('effective_from'),
            ]),
            Section::make('Designer')->schema([
                Forms\Components\TextInput::make('title_fr')->maxLength(200),
                Forms\Components\TextInput::make('title_en')->maxLength(200),
                Forms\Components\Repeater::make('content.sections')->label('Sections')->schema([
                    Forms\Components\TextInput::make('heading_fr'), Forms\Components\TextInput::make('heading_en'),
                    Forms\Components\Textarea::make('body_fr')->rows(3), Forms\Components\Textarea::make('body_en')->rows(3),
                ])->columns(2)->helperText('Placeholders: {policy_number} {insured_name} {carrier_name} {product_name} {subject} {coverage_start} {coverage_end} {document_number} {event}'),
                Forms\Components\Toggle::make('content.show_coverages')->label('Print the cover table'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('document_type_code')->label('Type')->searchable()->fontFamily('mono'),
            Tables\Columns\TextColumn::make('ownership')->badge(),
            Tables\Columns\TextColumn::make('carrier.party.display_name')->label('Insurer')->placeholder('—'),
            Tables\Columns\TextColumn::make('language')->badge(),
            Tables\Columns\TextColumn::make('insurance_class')->placeholder('any'),
            Tables\Columns\TextColumn::make('version'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'PUBLISHED' => 'success', 'DRAFT', 'REVIEW' => 'warning', 'APPROVED' => 'info', default => 'gray' }),
            Tables\Columns\TextColumn::make('effective_from')->date(),
            Tables\Columns\TextColumn::make('effective_until')->date()->placeholder('Open'),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(array_combine(['DRAFT', 'REVIEW', 'APPROVED', 'PUBLISHED', 'RETIRED'], ['DRAFT', 'REVIEW', 'APPROVED', 'PUBLISHED', 'RETIRED'])),
            Tables\Filters\SelectFilter::make('ownership')->options(array_combine(DocumentTemplateService::OWNERSHIPS, DocumentTemplateService::OWNERSHIPS)),
            Tables\Filters\SelectFilter::make('language')->options(['FR' => 'FR', 'EN' => 'EN', 'BILINGUAL' => 'BILINGUAL']),
        ])->recordActions([
            Actions\ViewAction::make(),
            Actions\EditAction::make()->label('Designer'),
            self::transition('submit', 'Submit for review', 'DRAFT'),
            self::transition('approve', 'Approve', 'REVIEW'),
            self::transition('publish', 'Publish', 'APPROVED'),
            Actions\Action::make('retire')->color('danger')->requiresConfirmation()->visible(fn ($record) => $record->status !== 'RETIRED')
                ->schema([Forms\Components\TextInput::make('reason')->required()->minLength(5)])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => app(DocumentTemplateService::class)->retire($record, $data['reason'], auth()->user()))),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')->columns(4)->schema([
                Infolists\Components\TextEntry::make('document_type_code'), Infolists\Components\TextEntry::make('ownership')->badge(),
                Infolists\Components\TextEntry::make('language'), Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('version'), Infolists\Components\TextEntry::make('content_hash')->copyable(),
                Infolists\Components\TextEntry::make('title_fr'), Infolists\Components\TextEntry::make('title_en'),
            ]),
            Section::make('Version history (DOC-ADM-009)')->schema([
                Infolists\Components\TextEntry::make('history')->hiddenLabel()->state(fn (DocumentTemplate $r) => DocumentTemplate::where('code', $r->code)->orderByDesc('version')->get()
                    ->map(fn ($t) => 'v'.$t->version.' · '.$t->status.' · from '.$t->effective_from?->toDateString().($t->effective_until ? ' to '.$t->effective_until->toDateString() : '').($t->published_at ? ' · published '.$t->published_at->toDateString() : ''))->all())->listWithLineBreaks(),
            ]),
        ]);
    }

    private static function transition(string $action, string $label, string $from): Actions\Action
    {
        return Actions\Action::make($action)->label($label)->requiresConfirmation()->visible(fn ($record) => $record->status === $from)
            ->action(fn ($record) => ServiceValidation::run(fn () => app(DocumentTemplateService::class)->{$action}($record, auth()->user())));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentTemplates::route('/'),
            'create' => Pages\CreateDocumentTemplate::route('/create'),
            'view' => Pages\ViewDocumentTemplate::route('/{record}'),
            'edit' => Pages\EditDocumentTemplate::route('/{record}/edit'),
        ];
    }
}
