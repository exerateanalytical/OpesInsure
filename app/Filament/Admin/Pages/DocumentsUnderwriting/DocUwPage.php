<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentsUnderwriting;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\WorkflowAction;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Locked;

/**
 * Base for the documents / correspondence / delegated-authority / signature staff screens (UI coverage batch 11): gated
 * by the API's read permission(s), tenant captured at mount and re-applied on each Livewire round-trip (as AmlPage).
 */
abstract class DocUwPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    /** Any of these permissions opens the screen; null = any signed-in staff user. @var list<string>|null */
    protected static ?array $permissions = null;

    /** doc_uw_actions.nav.<key> */
    protected static string $screen = 'correspondence';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        if (static::$permissions === null) {
            return WorkflowAction::allowed(null);
        }
        foreach (static::$permissions as $p) {
            if (WorkflowAction::allowed($p)) {
                return true;
            }
        }

        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('doc_uw_actions.nav.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('doc_uw_actions.nav.'.static::$screen);
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
        return __('doc_uw_actions.columns.'.$key);
    }

    /** @param iterable<object> $rows @return array<string, array<string, mixed>> keyed by id, scalar values only */
    protected static function keyed(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $a = array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v, (array) $r);
            $out[(string) $a['id']] = ['__key' => (string) $a['id']] + $a;
        }

        return $out;
    }
}
