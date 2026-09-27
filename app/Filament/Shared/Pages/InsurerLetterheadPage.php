<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\Documents\Letterhead\LetterheadService;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Actions\LetterheadActions;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Pages\LetterheadDesigner;
use App\Models\Carrier;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;

/**
 * Insurer self-service letterhead (/insurer): the LetterheadDesigner fixed to the signed-in insurer's own carrier
 * (PortalScope::carrierId, never a URL value). Insurer admins upload their logo, header, contact block and legal
 * footer, and choose whether the logo is shown publicly (website, app directory and the mobile logo_url /
 * carrier_logo_url). Every save is a PENDING_APPROVAL version whatever the platform setting: a second insurer
 * admin (or a platform admin) approves it; the uploader cannot approve their own version (LetterheadService).
 */
final class InsurerLetterheadPage extends LetterheadDesigner
{
    public const ROLES = ['CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN'];

    protected static ?string $navigationLabel = 'Letterhead & logo';

    protected static ?string $slug = 'letterhead';

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }

    public function getTitle(): string
    {
        return 'Letterhead & logo';
    }

    public static function carrierId(): ?string
    {
        return PortalScope::panel() === 'insurer' ? PortalScope::carrierId() : null;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return $user !== null && $tenant !== null && self::carrierId() !== null
            && $user->memberships()->where('status', 'ACTIVE')->where('tenant_id', $tenant)->whereIn('role_code', self::ROLES)->exists();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->pin();
        $this->refill();
    }

    /** Runs on every Livewire request: the owner can never be switched from the browser. */
    public function hydrate(): void
    {
        $this->pin();
    }

    private function pin(): void
    {
        $this->ownerType = 'CARRIER';
        $this->ownerId = self::carrierId();
    }

    public static function owners(string $type): array
    {
        $id = self::carrierId();
        $c = $id ? Carrier::with('party')->find($id) : null;

        return $c ? [$c->id => $c->party?->display_name ?? $c->cima_code] : [];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(LetterheadActions::formSchema());
    }

    protected function forcesMakerChecker(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            Action::make('rejectPending')->label('Reject pending version')->icon('lucide-x')->color('danger')
                ->visible(fn () => $this->pending() !== null)
                ->schema([Textarea::make('reason')->required()->minLength(5)])
                ->action(function (array $data) {
                    if (($p = $this->pending()) && ServiceValidation::run(fn () => app(LetterheadService::class)->reject($p, auth()->user(), $data['reason']))) {
                        Notification::make()->title('Letterhead version rejected')->success()->send();
                        $this->refill();
                    }
                }),
        ];
    }
}
