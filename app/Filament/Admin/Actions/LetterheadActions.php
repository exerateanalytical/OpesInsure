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
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Letterhead upload / approval actions, used by the Institutional directory (insurers, CARRIER) and the
 * Organizations screen (brokers and other tenants, TENANT). Owner rule: only licensed/authorized artwork,
 * each version carrying who authorized it, when and the source; audited by LetterheadService.
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
        return Action::make('letterhead')->label('Letterhead')->icon(Heroicon::OutlinedPhoto)
            ->visible(fn () => self::allowed())
            ->modalDescription('Upload only artwork the institution has licensed or authorized for use. Every save creates a new audited version; issued documents keep the version they were issued with.')
            ->fillForm(function (Model $record) use ($ownerType, $ownerId) {
                $cur = LetterheadResolver::current($ownerType, $ownerId($record));

                return $cur ? $cur->only(['brand_color', 'registered_address', 'rccm', 'niu', 'licence_reference', 'public_display', 'authorized_by', 'authorization_source', 'authorization_note'])
                    + ['authorized_on' => $cur->authorized_on?->toDateString(), 'current' => 'v'.$cur->version.($cur->logo_path ? ' · logo' : '').($cur->header_path ? ' · header' : '')] : ['current' => 'None (text wordmark)'];
            })
            ->schema([
                Forms\Components\TextInput::make('current')->label('Current version')->disabled()->dehydrated(false),
                Forms\Components\FileUpload::make('logo')->label('Logo (PNG, JPG or SVG, max 512 KB, min 64x32 px)')
                    ->disk(self::UPLOAD_DISK)->directory(self::UPLOAD_DIR)->visibility('private')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])->maxSize(512),
                Forms\Components\Toggle::make('remove_logo')->label('Remove the current logo'),
                Forms\Components\FileUpload::make('header')->label('Letterhead header image (optional, PNG/JPG/SVG, max 1 MB, min 600x60 px)')
                    ->disk(self::UPLOAD_DISK)->directory(self::UPLOAD_DIR)->visibility('private')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])->maxSize(1024),
                Forms\Components\Toggle::make('remove_header')->label('Remove the current header image'),
                Forms\Components\ColorPicker::make('brand_color')->label('Brand colour')->regex('/^#[0-9A-Fa-f]{6}$/'),
                Forms\Components\Textarea::make('registered_address')->label('Registered address')->rows(2)->maxLength(500),
                Forms\Components\TextInput::make('rccm')->label('RCCM')->maxLength(64),
                Forms\Components\TextInput::make('niu')->label('NIU')->maxLength(64),
                Forms\Components\TextInput::make('licence_reference')->label('Licence / approval reference')->maxLength(120),
                Forms\Components\Toggle::make('public_display')->label('Show the logo publicly (website and app directory)'),
                Forms\Components\TextInput::make('authorized_by')->label('Authorized by (person / institution)')->required()->maxLength(190),
                Forms\Components\DatePicker::make('authorized_on')->label('Authorized on')->required()->maxDate(now()),
                Forms\Components\TextInput::make('authorization_source')->label('Authorization source (letter, email, contract reference)')->required()->maxLength(255),
                Forms\Components\Textarea::make('authorization_note')->label('Licence / authorization note')->rows(2)->maxLength(2000),
            ])
            ->action(function (Model $record, array $data) use ($ownerType, $ownerId) {
                $read = function ($path): ?string {
                    $path = is_array($path) ? (array_values($path)[0] ?? null) : $path;
                    if (! $path) {
                        return null;
                    }
                    $disk = Storage::disk(self::UPLOAD_DISK);
                    $bytes = $disk->get($path);
                    $disk->delete($path);

                    return $bytes;
                };
                $logo = $read($data['logo'] ?? null);
                $header = $read($data['header'] ?? null);
                $asset = ServiceValidation::run(fn () => app(LetterheadService::class)->publish($ownerType, (string) $ownerId($record), $data, $logo, $header, auth()->user(),
                    (bool) ($data['remove_logo'] ?? false), (bool) ($data['remove_header'] ?? false)));
                if ($asset) {
                    Notification::make()->title($asset->status === 'ACTIVE' ? 'Letterhead v'.$asset->version.' active (audited)' : 'Letterhead v'.$asset->version.' awaiting approval')->success()->send();
                }
            });
    }

    /** Maker-checker approval of the pending version (visible only when one is pending). */
    public static function approve(string $ownerType, Closure $ownerId): Action
    {
        $pending = fn (Model $record) => LetterheadAsset::where('owner_type', $ownerType)->where($ownerType === 'CARRIER' ? 'carrier_id' : 'tenant_id', $ownerId($record))
            ->where('status', 'PENDING_APPROVAL')->orderByDesc('version')->first();

        return Action::make('approveLetterhead')->label('Approve letterhead')->icon(Heroicon::OutlinedCheckBadge)->color('success')
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
