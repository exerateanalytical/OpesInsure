<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Widgets\Widget;
use Throwable;

/**
 * Dashboard list widget shared by the admin, insurer and broker home dashboards: a heading, a short record list from an
 * existing tenant-scoped query/service and an EMPTY / ERROR state. Refreshes every $pollingInterval: the panel tenant
 * middleware is persistent on Livewire round-trips (ScopesPanelTenant), so the poll sees the same tenant as the page.
 */
abstract class RecordListWidget extends Widget
{
    protected string $view = 'filament.shared.widgets.record-list';

    protected static bool $isLazy = false;

    /** Livewire poll interval (null = no refresh). */
    public ?string $pollingInterval = '120s';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    /** Permission needed to see the widget (null = any panel user). */
    protected static ?string $permission = null;

    abstract public function heading(): string;

    /** @return list<array<string, mixed>> */
    abstract protected function rows(string $tenantId, User $user): array;

    /** @return array<string, string> column key => label */
    abstract protected function columns(): array;

    public static function canView(): bool
    {
        $u = auth()->user();

        return $u instanceof User && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && (static::$permission === null || \App\Application\WebExperiences\PortalAuthorization::allowsRead($u, static::$permission));
    }

    /** Link to the record in the current panel (view page, else edit page) when that resource is registered there. */
    protected function recordUrl(string $resource, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $panel = \Filament\Facades\Filament::getCurrentOrDefaultPanel()?->getId();
        foreach (['view', 'edit'] as $page) {
            $route = "filament.{$panel}.resources.{$resource}.{$page}";
            if (\Illuminate\Support\Facades\Route::has($route)) {
                return rescue(fn () => route($route, ['record' => $id]), null, false);
            }
        }

        return null;
    }

    /** @return array{columns: array<string, string>, rows: list<array<string, mixed>>, error: bool} */
    public function payload(): array
    {
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);
        try {
            $rows = $tenant === null ? [] : $this->rows($tenant, auth()->user());
            $error = false;
        } catch (Throwable $e) {
            report($e);
            [$rows, $error] = [[], true];
        }

        return ['columns' => $this->columns(), 'rows' => array_slice($rows, 0, 10), 'error' => $error];
    }
}
