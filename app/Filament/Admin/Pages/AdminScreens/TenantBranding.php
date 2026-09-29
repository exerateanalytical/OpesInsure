<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Letterhead\LetterheadService;
use App\Filament\Admin\Actions\LetterheadActions;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Letterhead\LetterheadAsset;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ADM-007 Tenant branding — the organisation's own logo, header artwork and brand colour (owner type TENANT of the
 * versioned, audited letterhead assets). Editing publishes a new version through LetterheadService::publish; with
 * letterheads.maker_checker on, another user approves it (LetterheadService::approve refuses the uploader).
 */
final class TenantBranding extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-palette';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'organisation/branding';

    protected static array $permissions = ['documents.letterheads.manage'];

    protected static string $screen = 'tenant_branding';

    protected static string $group = 'Administration';

    public static function canAccess(): bool
    {
        return LetterheadActions::allowed() || parent::canAccess();
    }

    private static function canApprove(): bool
    {
        return LetterheadActions::allowed() || (bool) auth()->user()?->hasPermission('documents.letterheads.approve');
    }

    private function pending(): ?LetterheadAsset
    {
        return $this->tenantId ? LetterheadAsset::where('owner_type', 'TENANT')->where('tenant_id', $this->tenantId)->where('status', 'PENDING_APPROVAL')->orderByDesc('version')->first() : null;
    }

    public function extraView(): ?array
    {
        $current = LetterheadResolver::current('TENANT', $this->tenantId);

        return ['filament.admin.pages.partials.tenant-branding', [
            'current' => $current,
            'logo' => rescue(fn () => LetterheadResolver::dataUri($current, 'logo'), null, false),
            'header' => rescue(fn () => LetterheadResolver::dataUri($current, 'header'), null, false),
            'pending' => $this->pending(),
        ]];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editBranding')->label(__('admin_screens.editBranding.label'))->icon('lucide-pencil')
                ->modalHeading(__('admin_screens.editBranding.label'))
                ->modalDescription(__('admin_screens.editBranding.help'))
                ->visible(fn () => $this->tenantId !== null && self::canAccess())
                ->fillForm(fn () => LetterheadActions::fillFrom(LetterheadResolver::current('TENANT', $this->tenantId)))
                ->schema(LetterheadActions::formSchema())
                ->action(function (array $data): void {
                    abort_unless(self::canAccess() && $this->tenantId !== null, 403);
                    LetterheadActions::save('TENANT', $this->tenantId, $data);
                }),
            Action::make('approveBranding')->label(__('admin_screens.approveBranding.label'))->icon('lucide-badge-check')->color('success')
                ->visible(fn () => self::canApprove() && $this->pending() !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    abort_unless(self::canApprove(), 403);
                    if (($p = $this->pending()) && ServiceValidation::run(fn () => app(LetterheadService::class)->approve($p, auth()->user()))) {
                        Notification::make()->success()->title(__('admin_screens.approveBranding.done'))->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => LetterheadAsset::query()->where('owner_type', 'TENANT')->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000'))
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->label(self::col('version'))->prefix('v'),
                \App\Filament\Shared\Columns::status('status', self::col('status')),
                TextColumn::make('brand_color')->label(self::col('brand_color'))->placeholder('—'),
                TextColumn::make('logo_path')->label(self::col('logo'))->formatStateUsing(fn ($state) => $state ? __('admin_screens.yes') : __('admin_screens.no'))->placeholder(__('admin_screens.no')),
                TextColumn::make('authorized_by')->label(self::col('authorized_by'))->placeholder('—'),
                \App\Filament\Shared\Columns::date('created_at', true, self::col('created_at')),
            ])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
