<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RiskTransfer;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\WorkflowAction;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

/**
 * Base of the risk-transfer workbench screens (reinsurance, co-insurance, accumulation, catastrophe events, legal matters,
 * developer portal, carrier connectors, capability profiles, compliance catalogue, insurance checks). Gated by the API's
 * GET permission (any one of static::$permissions); tenant captured at mount and re-applied on each Livewire round-trip
 * (same as AmlPage). Rows come from the same service / table the API list endpoint reads, always tenant-scoped where the
 * record is tenant-owned. Writes go through App\Filament\Shared\Actions\*Actions (same service, same permission).
 */
abstract class RiskTransferPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    /** @var list<string> any one grants the screen */
    protected static array $permissions = [];

    /** risk_transfer_actions.nav.<key> */
    protected static string $screen = '';

    protected static string $group = 'Reinsurance & co-insurance';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        return collect(static::$permissions)->contains(fn (string $p) => WorkflowAction::allowed($p));
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$group;
    }

    public static function getNavigationLabel(): string
    {
        return __('risk_transfer_actions.nav.'.static::$screen);
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

    /** @return array<string, array<string, mixed>> rows keyed by id (Filament array records) */
    protected static function rows(iterable $items): array
    {
        $out = [];
        foreach ($items as $r) {
            $r = (array) $r;
            $out[(string) $r['id']] = ['__key' => (string) $r['id']] + $r;
        }

        return $out;
    }

    /** @param  array<string, string>  $cols  field => kind (text|status|date|money) */
    protected static function columns(array $cols): array
    {
        return collect($cols)->map(function (string $kind, string $name) {
            $c = TextColumn::make($name)->label(__('risk_transfer_actions.columns.'.$name));

            return match ($kind) {
                'status' => $c->badge(),
                'date' => $c->dateTime(),
                'money' => $c->numeric(),
                default => $c->wrap(),
            };
        })->values()->all();
    }

    /**
     * @param  list<Action>  $header
     * @param  list<Action>  $record
     */
    protected function workbench(Table $table, callable $rows, array $cols, array $header = [], array $record = []): Table
    {
        return $table
            ->records(fn (): array => $this->tenantId === null || auth()->user() === null ? [] : $rows($this->tenantId))
            ->columns(static::columns($cols))
            ->headerActions($header)
            ->recordActions($record)
            ->emptyStateHeading(__('risk_transfer_actions.empty'));
    }
}
