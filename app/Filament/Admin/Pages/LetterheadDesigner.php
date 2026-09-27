<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\Documents\Engine\SecureShellRenderer;
use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Letterhead\LetterheadService;
use App\Filament\Admin\Actions\LetterheadActions;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Carrier;
use App\Models\Letterhead\LetterheadAsset;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Letterhead designer (web UI phase 4, configuration screens): per insurer (CARRIER) or organisation (TENANT) —
 * logo / header artwork, brand colour, address, contact block, bilingual legal footer and the artwork authorization —
 * with a specimen PDF rendered by the real secure shell (SecureShellRenderer::specimen, pdf.engine-shell).
 * Same form, save path and validation as the Letterhead modal action (LetterheadActions); every save is a new
 * audited version, pending a second admin when letterheads.maker_checker is on.
 */
class LetterheadDesigner extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-paintbrush';

    protected static ?string $navigationLabel = 'Letterhead designer';

    protected static ?string $slug = 'document-engine/letterhead-designer';

    protected static ?int $navigationSort = 308;

    protected string $view = 'filament.admin.pages.letterhead-designer';

    /** Specimen document type used for the preview (a bilingual S3 schedule-like shell). */
    public const PREVIEW_TYPE = 'POLICY_SCHEDULE';

    #[Url(as: 'owner')]
    public ?string $ownerType = 'CARRIER';

    #[Url(as: 'id')]
    public ?string $ownerId = null;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return LetterheadActions::allowed();
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Document engine';
    }

    public function getTitle(): string
    {
        return 'Letterhead designer';
    }

    public function mount(): void
    {
        $this->ownerType = in_array($this->ownerType, LetterheadService::OWNERS, true) ? $this->ownerType : 'CARRIER';
        $this->refill();
    }

    public function refill(): void
    {
        $this->form->fill(['owner_type' => $this->ownerType, 'owner_id' => $this->ownerId]
            + LetterheadActions::fillFrom(LetterheadResolver::current((string) $this->ownerType, $this->ownerId)));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Owner')->description('Insurer letterheads print on the documents the insurer issues; organisation letterheads on broker / platform documents.')->columns(2)->schema([
                Select::make('owner_type')->label('Letterhead of')->options(['CARRIER' => 'Insurer', 'TENANT' => 'Organisation (broker / platform)'])->required()->live()
                    ->afterStateUpdated(function ($state) {
                        $this->ownerType = $state;
                        $this->ownerId = null;
                        $this->refill();
                    })->dehydrated(false),
                Select::make('owner_id')->label('Institution')->options(fn () => self::owners((string) $this->ownerType))->searchable()->required()->live()
                    ->afterStateUpdated(function ($state) {
                        $this->ownerId = $state;
                        $this->refill();
                    })->dehydrated(false),
            ]),
            ...LetterheadActions::formSchema(),
        ]);
    }

    /** @return array<string, string> */
    public static function owners(string $type): array
    {
        return $type === 'CARRIER'
            ? Carrier::with('party')->get()->mapWithKeys(fn ($c) => [$c->id => $c->party?->display_name ?? $c->cima_code])->sort()->all()
            : Tenant::orderBy('legal_name')->pluck('legal_name', 'id')->all();
    }

    public function issuerName(): string
    {
        return static::owners((string) $this->ownerType)[$this->ownerId] ?? 'Institution';
    }

    public function pending(): ?LetterheadAsset
    {
        return $this->ownerId ? LetterheadAsset::where('owner_type', $this->ownerType)->where($this->ownerType === 'CARRIER' ? 'carrier_id' : 'tenant_id', $this->ownerId)
            ->where('status', 'PENDING_APPROVAL')->orderByDesc('version')->first() : null;
    }

    /** Specimen PDF of the unsaved state (never stored). */
    public function previewPdf(): string
    {
        $state = $this->uploadedPaths($this->form->getRawState(), $stored);
        try {
            $lh = LetterheadActions::previewLetterhead((string) $this->ownerType, $this->ownerId, $this->issuerName(), $state);
        } finally {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($stored);
        }

        return app(SecureShellRenderer::class)->specimen(self::PREVIEW_TYPE, $this->issuerName(), $lh, [
            ['heading' => 'Spécimen de papier à en-tête / Letterhead specimen', 'paragraphs' => [
                'Aperçu non enregistré : ce document n\'a aucune valeur. / Unsaved preview: this document has no value.']],
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Preview PDF')->icon('lucide-eye')->color('gray')->disabled(fn () => ! $this->ownerId)
                ->action(function () {
                    try {
                        $pdf = $this->previewPdf();
                    } catch (ValidationException $e) {
                        ServiceValidation::notify($e);

                        return null;
                    }

                    return response()->streamDownload(function () use ($pdf) {
                        echo $pdf;
                    }, 'letterhead-specimen.pdf', ['Content-Type' => 'application/pdf']);
                }),
            Action::make('approvePending')->label('Approve pending version')->icon('lucide-badge-check')->color('success')->requiresConfirmation()
                ->visible(fn () => $this->pending() !== null)
                ->modalDescription(fn () => ($p = $this->pending()) ? 'Version '.$p->version.' authorized by '.$p->authorized_by.' ('.$p->authorization_source.').' : null)
                ->action(function () {
                    if (($p = $this->pending()) && ServiceValidation::run(fn () => app(LetterheadService::class)->approve($p, auth()->user()))) {
                        Notification::make()->title('Letterhead approved')->success()->send();
                        $this->refill();
                    }
                }),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->ownerId) {
            Notification::make()->danger()->title('Choose the institution first')->send();

            return;
        }
        $data = $this->form->getState();
        if (LetterheadActions::save((string) $this->ownerType, $this->ownerId, $data, $this->forcesMakerChecker())) {
            $this->refill();
        }
    }

    /** The insurer self-service page always routes a new version through a second approver. */
    protected function forcesMakerChecker(): bool
    {
        return false;
    }

    /** FileUpload raw state holds TemporaryUploadedFile objects until getState(); store them to read the bytes. */
    private function uploadedPaths(array $state, ?array &$stored = []): array
    {
        $stored = [];
        foreach (['logo', 'header'] as $kind) {
            $files = array_values((array) ($state[$kind] ?? []));
            $f = $files[0] ?? null;
            if ($f instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                $state[$kind] = $stored[] = $f->store('letterhead-uploads', 'local');
            }
        }

        return $state;
    }
}
