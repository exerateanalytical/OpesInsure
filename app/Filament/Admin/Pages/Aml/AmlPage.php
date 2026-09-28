<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Aml;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\WorkflowAction;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Locked;

/**
 * Base for the AML staff screens (Trust & compliance group): gated by the API's read permission, tenant captured at
 * mount and re-applied on each Livewire round-trip (same as HealthQueuePage). Rows are always tenant-scoped.
 */
abstract class AmlPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    /** Permission gating the screen (the API's GET permission). */
    protected static string $permission = 'aml.screening.view';

    /** aml_actions.nav.<key> */
    protected static string $screen = 'hits';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        return WorkflowAction::allowed(static::$permission);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Trust & compliance';
    }

    public static function getNavigationLabel(): string
    {
        return __('aml_actions.nav.'.static::$screen);
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
        return __('aml_actions.columns.'.$key);
    }
}
