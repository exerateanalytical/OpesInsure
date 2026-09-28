<?php

declare(strict_types=1);

namespace App\Filament\Admin\Actions;

use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Letterhead\LetterheadService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Letterhead\LetterheadAsset;
use Closure;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Letterhead upload / approval, used by the Institutional directory (insurers, CARRIER), the Organizations screen
 * (brokers and other tenants, TENANT) and the Letterhead designer page (one form schema, one save path, one preview).
 * Owner rule: only licensed/authorized artwork, each version carrying who authorized it, when and the source;
 * audited by LetterheadService.
 */
final class LetterheadActions
{
    public const ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'];

    private const UPLOAD_DISK = 'local';

    private const UPLOAD_DIR = 'letterhead-uploads';

    public static function allowed(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', self::ROLES)->exists();
    }

    /** @param  Closure(Model): ?string  $ownerId */
    public static function edit(string $ownerType, Closure $ownerId): Action
    {
        return Action::make('letterhead')->label('Letterhead')->icon('lucide-image')
            ->visible(fn () => self::allowed())
            ->modalDescription('Upload only artwork the institution has licensed or authorized for use. Every save creates a new audited version; issued documents keep the version they were issued with.')
            ->fillForm(fn (Model $record) => self::fillFrom(LetterheadResolver::current($ownerType, $ownerId($record))))
            ->schema(self::formSchema())
            ->action(fn (Model $record, array $data) => self::save($ownerType, (string) $ownerId($record), $data));
    }

    /** @return array<string, mixed> Form state of the current version. */
    public static function fillFrom(?LetterheadAsset $cur): array
    {
        return $cur ? $cur->only(array_values(array_diff(LetterheadService::TEXT_FIELDS, ['authorized_on'])))
            + ['authorized_on' => $cur->authorized_on?->toDateString(), 'current' => 'v'.$cur->version.($cur->logo_path ? ' · logo' : '').($cur->header_path ? ' · header' : '')]
            : ['current' => 'None (text wordmark)'];
    }

    /** @return list<Section> */
    public static function formSchema(): array
    {
        return [
            Section::make('Artwork')->columns(2)->schema([
                Forms\Components\TextInput::make('current')->label('Current version')->disabled()->dehydrated(false)->columnSpanFull(),
                Forms\Components\FileUpload::make('logo')->label('Logo (PNG, JPG, WebP or SVG, max 512 KB, min 64x32 px)')
                    ->disk(self::UPLOAD_DISK)->directory(self::UPLOAD_DIR)->visibility('private')
                    ->acceptedFileTypes(LetterheadService::ACCEPTED_MIMES)->maxSize(512),
                Forms\Components\FileUpload::make('header')->label('Letterhead header image (optional, PNG/JPG/WebP/SVG, max 1 MB, min 600x60 px)')
                    ->disk(self::UPLOAD_DISK)->directory(self::UPLOAD_DIR)->visibility('private')
                    ->acceptedFileTypes(LetterheadService::ACCEPTED_MIMES)->maxSize(1024),
                Forms\Components\Toggle::make('remove_logo')->label('Remove the current logo'),
                Forms\Components\Toggle::make('remove_header')->label('Remove the current header image'),
                Forms\Components\ColorPicker::make('brand_color')->label('Brand colour')->regex('/^#[0-9A-Fa-f]{6}$/'),
                Forms\Components\Toggle::make('public_display')->label('Show the logo publicly (website and app directory)'),
            ]),
            Section::make('Address and legal identifiers')->columns(3)->schema([
                Forms\Components\Textarea::make('registered_address')->label('Registered address')->rows(2)->maxLength(500)->columnSpanFull(),
                Forms\Components\TextInput::make('rccm')->label('RCCM')->maxLength(64),
                Forms\Components\TextInput::make('niu')->label('NIU')->maxLength(64),
                Forms\Components\TextInput::make('licence_reference')->label('Licence / approval reference')->maxLength(120),
            ]),
            Section::make('Contact block')->columns(3)->schema([
                Forms\Components\TextInput::make('contact_phone')->label('Phone')->tel()->maxLength(32),
                Forms\Components\TextInput::make('contact_email')->label('Email')->email()->maxLength(190),
                Forms\Components\TextInput::make('website')->maxLength(190),
            ]),
            Section::make('Legal footer text')->columns(2)->schema([
                Forms\Components\Textarea::make('footer_text_fr')->label('Footer (FR)')->rows(2)->maxLength(1000),
                Forms\Components\Textarea::make('footer_text_en')->label('Footer (EN)')->rows(2)->maxLength(1000),
            ]),
            Section::make('Authorization of the artwork')->columns(3)->schema([
                Forms\Components\TextInput::make('authorized_by')->label('Authorized by (person / institution)')->required()->maxLength(190),
                Forms\Components\DatePicker::make('authorized_on')->label('Authorized on')->required()->maxDate(now()),
                Forms\Components\TextInput::make('authorization_source')->label('Authorization source (letter, email, contract reference)')->required()->maxLength(255),
                Forms\Components\Textarea::make('authorization_note')->label('Licence / authorization note')->rows(2)->maxLength(2000)->columnSpanFull(),
            ]),
        ];
    }

    /** Publishes a new audited version from the form state (uploaded temp files are consumed). */
    public static function save(string $ownerType, string $ownerId, array $data, bool $forceMakerChecker = false): ?LetterheadAsset
    {
        $logo = self::uploaded($data['logo'] ?? null, true);
        $header = self::uploaded($data['header'] ?? null, true);
        $asset = ServiceValidation::run(fn () => app(LetterheadService::class)->publish($ownerType, $ownerId, $data, $logo, $header, auth()->user(),
            (bool) ($data['remove_logo'] ?? false), (bool) ($data['remove_header'] ?? false), $forceMakerChecker));
        if ($asset) {
            Notification::make()->title($asset->status === 'ACTIVE' ? 'Letterhead v'.$asset->version.' active (audited)' : 'Letterhead v'.$asset->version.' awaiting approval')->success()->send();
        }

        return $asset;
    }

    /**
     * Unsaved letterhead as the document shell sees it (same keys as LetterheadResolver::forDocument). New uploads
     * are checked by LetterheadService::inspect; otherwise the current version's artwork is used.
     *
     * @return array<string, mixed>
     */
    public static function previewLetterhead(string $ownerType, ?string $ownerId, string $issuerName, array $data): array
    {
        $cur = LetterheadResolver::current($ownerType, $ownerId);
        $art = function (string $kind) use ($data, $cur): ?string {
            if ($bytes = self::uploaded($data[$kind] ?? null, false)) {
                $f = app(LetterheadService::class)->inspect($bytes, $kind);

                return LetterheadResolver::embedUri($f['bytes'], $f['mime']);
            }

            return empty($data['remove_'.$kind]) ? LetterheadResolver::dataUri($cur, $kind) : null;
        };
        $draft = new LetterheadAsset(array_intersect_key($data, array_flip(LetterheadService::TEXT_FIELDS)));
        $color = $data['brand_color'] ?? null;

        return ['issuer' => ['name' => $issuerName, 'logo' => $art('logo'), 'header' => $art('header')], 'cobrand' => null,
            'footer_lines' => $draft->footerLines(), 'color' => is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : null, 'snapshot' => null];
    }

    private static function uploaded(mixed $path, bool $consume): ?string
    {
        $path = is_array($path) ? (array_values($path)[0] ?? null) : $path;
        if (! is_string($path) || $path === '') {
            return null;
        }
        $disk = Storage::disk(self::UPLOAD_DISK);
        if (! $disk->exists($path)) {
            return null;
        }
        $bytes = $disk->get($path);
        if ($consume) {
            $disk->delete($path);
        }

        return $bytes;
    }

    /** Maker-checker approval of the pending version (visible only when one is pending). */
    public static function approve(string $ownerType, Closure $ownerId): Action
    {
        $pending = fn (Model $record) => LetterheadAsset::where('owner_type', $ownerType)->where($ownerType === 'CARRIER' ? 'carrier_id' : 'tenant_id', $ownerId($record))
            ->where('status', 'PENDING_APPROVAL')->orderByDesc('version')->first();

        return Action::make('approveLetterhead')->label('Approve letterhead')->icon('lucide-badge-check')->color('success')
            ->visible(fn (Model $record) => self::allowed() && $pending($record) !== null)
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record) => ($p = $pending($record)) ? 'Version '.$p->version.' authorized by '.$p->authorized_by.' on '.$p->authorized_on?->toDateString().' ('.$p->authorization_source.').' : null)
            ->action(function (Model $record) use ($pending) {
                if (($p = $pending($record)) && ServiceValidation::run(fn () => app(LetterheadService::class)->approve($p, auth()->user()))) {
                    Notification::make()->title('Letterhead approved')->success()->send();
                }
            });
    }
}
