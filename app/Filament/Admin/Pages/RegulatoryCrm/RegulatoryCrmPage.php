<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RegulatoryCrm;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\WorkflowAction;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Locked;

/**
 * Base of the service-fed staff screens for regulatory returns/rules/inspections, insurer onboarding, CRM leads, rule
 * sets and collections (same pattern as AmlPage): gated by the API's GET permission(s), tenant captured at mount and
 * re-applied on each Livewire round-trip; tenant-owned rows are always read for the current tenant only.
 */
abstract class RegulatoryCrmPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    /** @var list<string> the screen opens for a holder of ANY of these (the API GET permission(s)). */
    protected static array $permissions = [];

    /** regulatory_crm_actions.nav.<key> */
    protected static string $screen = '';

    protected static string $group = 'Trust & compliance';

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
        return __('regulatory_crm_actions.nav.'.static::$screen);
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

    protected function ready(): bool
    {
        return $this->tenantId !== null && auth()->user() !== null;
    }

    protected static function col(string $key): TextColumn
    {
        return TextColumn::make($key)->label(__('regulatory_crm_actions.columns.'.$key));
    }

    /** @param  iterable<object|array>  $rows */
    protected static function keyed(iterable $rows, string $key = 'id'): array
    {
        $out = [];
        foreach ($rows as $r) {
            $a = (array) (is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : $r);
            $a = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : (is_array($v) || is_object($v) ? json_encode($v) : $v), $a);
            $a['__key'] = (string) $a[$key];
            $a['id'] = (string) $a[$key];
            $out[$a['__key']] = $a;
        }

        return $out;
    }
}
