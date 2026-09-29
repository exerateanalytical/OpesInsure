<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\Help\HelpGuides;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * S11 in-app help, one page per panel (/admin/help, /insurer/help, /broker/help, /provider/help). Shows the guide for
 * the signed-in user's role (HelpGuides::resolve), lets the user switch to the other guides of the panel, searches the
 * sections (?q=) and links to the printable version. Content: resources/help/{en,fr}/*.md. Read-only, no data access.
 */
class HelpPage extends Page
{
    protected string $view = 'filament.shared.pages.help';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-life-buoy';

    protected static ?int $navigationSort = 1000;

    protected static ?string $slug = 'help';

    #[Url]
    public ?string $guide = null;

    #[Url]
    public string $q = '';

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public static function getNavigationLabel(): string
    {
        return __('help_guides.nav');
    }

    public function getTitle(): string
    {
        return __('help_guides.title');
    }

    public static function surface(): string
    {
        return (string) (Filament::getCurrentPanel()?->getId() ?? 'admin');
    }

    /**
     * S3 2026-09-29: "My workspace" — PUT web-experiences/{portal}/workspace (authenticated, no permission) through the same
     * PortalWorkspaceService::touch: records this portal's workspace for the signed-in user (current language, last seen,
     * preferred start page). Portal code from the panel: admin → ADMIN, broker → BROKER, insurer → CARRIER (no provider).
     */
    protected function getHeaderActions(): array
    {
        $portal = ['admin' => 'ADMIN', 'broker' => 'BROKER', 'insurer' => 'CARRIER'][self::surface()] ?? null;

        return [
            \App\Filament\Shared\Actions\WorkflowAction::make('workspaceSave', null, 'leftover_actions')->icon('lucide-layout-dashboard')
                ->visible($portal !== null)
                ->fillForm(fn () => ['start_page' => (\App\Models\PortalWorkspace::query()->where(['user_id' => auth()->id(), 'portal' => $portal])->value('preferences') ?? [])['start_page'] ?? 'dashboard'])
                ->schema([\Filament\Forms\Components\Select::make('start_page')->label(__('leftover_actions.fields.start_page'))->required()
                    ->options(collect(['dashboard', 'reports', 'search', 'help'])->mapWithKeys(fn ($k) => [$k => __('leftover_actions.start_pages.'.$k)])->all())])
                ->action(fn (\Filament\Actions\Action $action, array $data) => \App\Filament\Shared\Actions\WorkflowAction::run($action, null, fn () => app(\App\Application\WebExperiences\PortalWorkspaceService::class)
                    ->touch(rescue(fn () => app(\App\Domain\Tenancy\TenantContext::class)->id(), null, false), auth()->user(), (string) $portal, ['start_page' => $data['start_page']]))),
        ];
    }

    public function mount(): void
    {
        $this->guide = HelpGuides::resolve(self::surface(), auth()->user(), $this->guide);
    }

    protected function getViewData(): array
    {
        $surface = self::surface();
        $key = HelpGuides::resolve($surface, auth()->user(), $this->guide);
        $guide = HelpGuides::load($key);

        return [
            'guideKey' => $key,
            // S3: not 'guide' — the public $guide property (the guide key) shadows it on a Livewire re-render (header action).
            'guideData' => $guide,
            'sections' => HelpGuides::search($guide, $this->q),
            'offered' => HelpGuides::SURFACES[$surface] ?? [],
            'printUrl' => HelpGuides::printUrl($key),
        ];
    }
}
