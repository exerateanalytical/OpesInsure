<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DocumentTemplates;

use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Documents\Engine\SecureShellRenderer;
use App\Application\Documents\Letterhead\LetterheadResolver;
use Closure;
use Illuminate\Support\HtmlString;
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

    protected static string|BackedEnum|null $navigationIcon = 'lucide-files';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?int $navigationSort = 304;

    protected static ?string $slug = 'document-engine/templates';

    /** Templates awaiting approval (REVIEW), shown on the navigation item so they are easy to find. */
    public static function getNavigationBadge(): ?string
    {
        $n = DocumentTemplate::where('status', 'REVIEW')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pending approval';
    }

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
                ])->columns(2)->helperText('Placeholders: '.implode(' ', array_keys(self::PLACEHOLDERS))),
                Forms\Components\Toggle::make('content.show_coverages')->label('Print the cover table'),
            ]),
            Section::make('Placeholders')->description('Replaced by the document engine at issuance; the specimen preview uses the sample values.')->collapsible()->schema([
                Forms\Components\Placeholder::make('placeholders_help')->hiddenLabel()->content(fn () => new HtmlString('<table class="text-sm"><tbody>'
                    .collect(self::PLACEHOLDERS)->map(fn ($p, $k) => '<tr><td class="pe-4 font-mono">'.e($k).'</td><td class="pe-4">'.e($p[0]).'</td><td class="text-gray-500">'.e($p[1]).'</td></tr>')->implode('')
                    .'</tbody></table>')),
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
            \App\Filament\Shared\Columns::status('status'),
            \App\Filament\Shared\Columns::date('effective_from', false),
            \App\Filament\Shared\Columns::date('effective_until', false)->placeholder('Open'),
        ])->filters([
            // The 12 system-seeded provider templates wait here for "Approve & publish".
            Tables\Filters\Filter::make('pending_approval')->label('Pending approval')->toggle()
                ->query(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'REVIEW')),
            Tables\Filters\SelectFilter::make('status')->options(array_combine(['DRAFT', 'REVIEW', 'APPROVED', 'PUBLISHED', 'RETIRED'], ['DRAFT', 'REVIEW', 'APPROVED', 'PUBLISHED', 'RETIRED'])),
            Tables\Filters\SelectFilter::make('ownership')->options(array_combine(DocumentTemplateService::OWNERSHIPS, DocumentTemplateService::OWNERSHIPS)),
            Tables\Filters\SelectFilter::make('language')->options(['FR' => 'FR', 'EN' => 'EN', 'BILINGUAL' => 'BILINGUAL']),
        ])->recordActions([
            Actions\ViewAction::make(),
            Actions\EditAction::make()->label('Designer'),
            ...\App\Filament\Shared\Actions\DocumentTemplateActions::all(),
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

    /** Placeholders DocumentEngine substitutes in section bodies: [description, specimen value]. */
    public const PLACEHOLDERS = [
        '{policy_number}' => ['Policy number', 'POL-SPECIMEN-000001'], '{insured_name}' => ['Insured / policyholder name', 'Jean SPECIMEN'],
        '{carrier_name}' => ['Insurer name', 'Insurer (specimen)'], '{product_name}' => ['Product name', 'Product (specimen)'],
        '{subject}' => ['Insured subject (vehicle, person ...)', 'LT-000-SP'], '{coverage_start}' => ['Cover start date (dd/mm/yyyy)', '01/01/2026'],
        '{coverage_end}' => ['Cover end date (dd/mm/yyyy)', '31/12/2026'], '{document_number}' => ['Document number', 'SPECIMEN'],
        '{event}' => ['Issuing event label', 'Specimen'],
    ];

    /** Specimen PDF of a template's (possibly unsaved) content through the secure shell. */
    public static function previewPdf(DocumentTemplate $t, ?array $content = null, ?string $titleEn = null, ?string $titleFr = null): string
    {
        $vars = array_map(fn ($p) => $p[1], self::PLACEHOLDERS);
        $sections = DocumentEngine::templateSections($content ?? (array) $t->content, (string) $t->language, $vars);
        [$issuer, $name, $carrierId, $tenantId] = match ($t->ownership) {
            'INSURER' => ['INSURER', $t->carrier?->party?->display_name ?? 'Insurer', $t->carrier_id, null],
            'BROKER' => ['BROKER', Tenant::find($t->broker_tenant_id)?->legal_name ?? 'Broker', null, $t->broker_tenant_id],
            default => ['PLATFORM', 'OpesInsure', null, null],
        };
        $letterhead = LetterheadResolver::forDocument($issuer, $name, $carrierId, $issuer === 'INSURER' ? $name : null, $tenantId, null);

        return app(SecureShellRenderer::class)->specimen($t->document_type_code, $name, $letterhead, $sections,
            $titleEn ?: $t->title_en, $titleFr ?: $t->title_fr, (string) $t->language);
    }

    /** @param  ?Closure(mixed): array<string, mixed>  $state  unsaved designer state (edit page) */
    public static function previewAction(?Closure $state = null): Actions\Action
    {
        return Actions\Action::make('previewPdf')->label('Preview PDF')->icon('lucide-eye')->color('gray')
            ->action(function (DocumentTemplate $record, $livewire) use ($state) {
                $d = $state ? $state($livewire) : [];
                $pdf = self::previewPdf($record, $d['content'] ?? null, $d['title_en'] ?? null, $d['title_fr'] ?? null);

                return response()->streamDownload(function () use ($pdf) {
                    echo $pdf;
                }, 'template-'.$record->document_type_code.'-specimen.pdf', ['Content-Type' => 'application/pdf']);
            });
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
