<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Catalogue;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\WorkflowAction;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Locked;

/**
 * Base for the catalogue / reference-configuration staff screens (UI coverage batches 18 and 28): gated by the API's
 * read permission(s) (any of), tenant captured at mount and re-applied on each Livewire round-trip (AmlPage pattern).
 */
abstract class CatalogueConfigPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    /** @var list<string> any-of permissions gating the screen */
    protected static array $permissions = ['catalogue.view'];

    /** catalogue_actions.nav.<key> */
    protected static string $screen = 'carrier_products';

    protected static string|\UnitEnum|null $navigationGroup = 'Products & pricing';

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

    public static function getNavigationLabel(): string
    {
        return __('catalogue_actions.nav.'.static::$screen);
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
        return __('catalogue_actions.columns.'.$key);
    }
}
