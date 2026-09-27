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
use App\Application\Providers\Workspace\Http\ProviderWorkspaceController;
use App\Interfaces\Http\Errors\ApiProblemException;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

    /** Active provider: the request attribute set by ResolveProviderPanelScope, else the panel binding it registers for the same request. */
    protected function scope(): ProviderScope
    {
        $s = request()->attributes->get(ProviderScope::ATTRIBUTE);
        if (! $s instanceof ProviderScope && app()->bound(ProviderScope::class.'@panel')) {
            $s = app(ProviderScope::class.'@panel');
        }

        return $s instanceof ProviderScope ? $s : ProviderScope::of(request());
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

    /** Record opened in the detail panel (claim EOB, settlement statement, contract tariffs …). */
    public ?string $selected = null;

    /**
     * Per-row actions: list of ['label' => …, 'action' => livewire method, 'arg' => …] or ['label' => …, 'url' => …].
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, string>>
     */
    public function rowActions(array $row): array
    {
        return [];
    }

    public function open(string $id): void
    {
        $this->selected = $id;
    }

    public function closeDetail(): void
    {
        $this->selected = null;
    }

    /** Detail panel for $selected: ['title' => …, 'cards' => [...], 'rows' => [...]]. @return array<string, mixed>|null */
    protected function detail(): ?array
    {
        return null;
    }

    /** @return array<string, mixed>|null */
    public function detailPayload(): ?array
    {
        if ($this->selected === null) {
            return null;
        }
        try {
            $d = $this->detail();
        } catch (ApiProblemException|HttpExceptionInterface $e) {
            return ['title' => $this->selected, 'cards' => [], 'rows' => [], 'error' => $e->getMessage()];
        }

        return $d === null ? null : $d + ['cards' => [], 'rows' => [], 'error' => null];
    }

    /**
     * Provider-side mutation through the SAME controller action as the API (validation, facility scope, audit, outbox and
     * document issuance are not re-implemented here). Validation errors land on the form fields; business refusals set
     * the screen state and message.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $fieldMap  request key => livewire property (error mapping)
     * @return array<string, mixed>|null the created/updated resource, null on refusal
     */
    protected function callWorkspace(string $action, array $input, ?string $id = null, array $fieldMap = []): ?array
    {
        $r = Request::create('/api/v1/provider-portal', 'POST', $input);
        $r->setUserResolver(fn () => $this->user());
        $r->attributes->set(ProviderScope::ATTRIBUTE, $this->scope());
        $this->resetErrorBag();
        try {
            $c = app(ProviderWorkspaceController::class);
            $res = $id === null ? $c->{$action}($r) : $c->{$action}($r, $id);
            $this->state = 'SUCCESS';
            $this->stateMessage = __('provider_workspace.states.SUCCESS');

            return json_decode((string) $res->getContent(), true)['data'] ?? [];
        } catch (ValidationException $e) {
            foreach ($e->errors() as $k => $msgs) {
                $this->addError($fieldMap[$k] ?? $k, $msgs[0]);
            }
            $this->state = 'VALIDATION_FAILED';
            $this->stateMessage = collect($e->errors())->flatten()->first();
        } catch (ApiProblemException|HttpExceptionInterface $e) {
            $this->state = 'VALIDATION_FAILED';
            $this->stateMessage = $e->getMessage();
        }

        return null;
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
