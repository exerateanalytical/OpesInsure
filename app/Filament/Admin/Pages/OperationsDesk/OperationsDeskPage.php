<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\WorkflowAction;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Locked;

/**
 * Base for the service-fed staff screens of UI batches 24 / 26 (failed jobs, platform notification templates, adjuster
 * assignments, calendar breaks, charge tables, rating runs, reconciliation exceptions, release assurance, KPI catalogue).
 * Same shape as AmlPage: gated by the API's read permission (any of $permissions), tenant captured at mount and
 * re-applied on each Livewire round-trip. Labels: resources/lang/{en,fr}/operations_actions.php.
 */
abstract class OperationsDeskPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    /** @var list<string> any one of these opens the screen (the API's GET permission) */
    protected static array $permissions = [];

    /** operations_actions.nav.<key> */
    protected static string $screen = '';

    protected static string $group = 'Operations';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        foreach (static::$permissions as $p) {
            if (WorkflowAction::allowed($p)) {
                return true;
            }
        }

        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$group;
    }

    public static function getNavigationLabel(): string
    {
        return __('operations_actions.nav.'.static::$screen);
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    protected static function col(string $key): string
    {
        return __('operations_actions.columns.'.$key);
    }

    /** @param iterable<object|array> $rows @return array<string, array<string, mixed>> keyed by id */
    protected static function keyed(iterable $rows, string $key = 'id'): array
    {
        $out = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $out[(string) $r[$key]] = ['__key' => (string) $r[$key], 'id' => (string) $r[$key]] + $r;
        }

        return $out;
    }
}
