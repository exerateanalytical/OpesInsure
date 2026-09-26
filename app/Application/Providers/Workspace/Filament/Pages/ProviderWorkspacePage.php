<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\Workspace\ProviderOperationsService;
use App\Application\Providers\Workspace\ProviderWorkspaceService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Shared provider-panel screen: title from provider_workspace.screens.<key> (EN/FR), permission gate, summary cards and
 * one table. Every screen renders the spec UI states (EMPTY, ERROR, PERMISSION_DENIED, INSURER_UNAVAILABLE, OFFLINE via
 * the Livewire offline indicator, LOADING via wire:loading). Data comes from the same services as the API.
 */
abstract class ProviderWorkspacePage extends Page
{
    protected string $view = 'provider-workspace.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    /** Spec permission gating the screen. */
    protected static string $permission = 'provider.dashboard.view';

    /** provider_workspace.screens.<key>. */
    protected static string $screen = 'provider_dashboard';

    public ?string $state = null;

    public ?string $stateMessage = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && rescue(fn () => $u->hasPermission(static::$permission), false, false);
    }

    public static function getNavigationLabel(): string
    {
        return __('provider_workspace.screens.'.static::$screen);
    }

    public function getTitle(): string
    {
        return __('provider_workspace.screens.'.static::$screen);
    }

    protected function user(): User
    {
        return auth()->user();
    }

    protected function scope(): ProviderScope
    {
        return ProviderScope::of(request());
    }

    protected function tenantId(): string
    {
        return app(TenantContext::class)->id();
    }

    protected function ws(): ProviderWorkspaceService
    {
        return app(ProviderWorkspaceService::class);
    }

    protected function ops(): ProviderOperationsService
    {
        return app(ProviderOperationsService::class);
    }

    /** Optional partial rendered above the table (forms). */
    public function extraView(): ?string
    {
        return null;
    }

    /** @return array<string, int|string|null> */
    protected function cards(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    abstract protected function rows(): array;

    /** @return list<string> column keys to show (empty = all keys of the first row) */
    protected function columns(): array
    {
        return [];
    }

    /** @return array{cards: array, columns: list<string>, rows: list<array>, state: string, message: ?string} */
    public function viewPayload(): array
    {
        try {
            $rows = array_map(fn ($r) => array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v, (array) $r), $this->rows());
            $cards = $this->cards();
            $state = $this->state ?? ($rows === [] ? 'EMPTY' : 'SUCCESS');
        } catch (Throwable $e) {
            report($e);
            [$rows, $cards, $state] = [[], [], 'ERROR'];
        }
        $cols = $this->columns() ?: array_keys($rows[0] ?? []);

        return ['cards' => $cards, 'columns' => $cols, 'rows' => $rows, 'state' => $state, 'message' => $this->stateMessage ?? __('provider_workspace.states.'.$state)];
    }
}
