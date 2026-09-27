<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\WebExperiences\PortalAuthorization;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Columns;
use App\Filament\Shared\Concerns\ListScreen;
use App\Models\RegisterRow;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

/**
 * UI audit 2026-09-27: one READ-ONLY register screen for records that have an API and permissions but no model or
 * resource (quote requests, referrals, co/reinsurance, KYC, cashier sessions, FX rates). Subclasses only declare
 * the table, the permissions (any one grants access) and the columns; tenant and carrier scoping, the shared column
 * convention (Columns) and the standard list behaviour (ListScreen) live here. Writes stay in the APIs.
 */
abstract class RegisterPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static string $registerTable = '';

    /** @var list<string> */
    protected static array $permissions = [];

    /** @var array<string, array{0: string, 1: string}> column => [kind text|money|date|day|status, label] */
    protected static array $columns = [];

    protected static ?string $carrierColumn = 'carrier_id';

    protected static string $label = '';

    protected static ?string $group = null;

    protected static string $defaultSort = 'created_at';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && collect(static::$permissions)->contains(fn (string $p) => PortalAuthorization::allowsRead($u, $p));
    }

    public static function getNavigationLabel(): string
    {
        return static::$label;
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$group;
    }

    public function getTitle(): string
    {
        return (string) (collect((array) trans('navigation.labels'))->get(static::$label) ?? static::$label);
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

    /** Tenant (and, in a portal, carrier) scope; override when the table has no tenant_id. */
    protected function scope(Builder $q, string $tenantId): Builder
    {
        $q->where(static::$registerTable.'.tenant_id', $tenantId);
        if (static::$carrierColumn !== null && ($carrier = PortalScope::carrierId()) !== null) {
            $q->where(static::$registerTable.'.'.static::$carrierColumn, $carrier);
        }

        return $q;
    }

    public function table(Table $table): Table
    {
        $columns = [];
        foreach (static::$columns as $name => [$kind, $label]) {
            $label = __('navigation.columns.'.$label);
            $columns[] = match ($kind) {
                'money' => Columns::money($name, 'currency', $label),
                'date' => Columns::date($name, true, $label),
                'day' => Columns::date($name, false, $label),
                'status' => Columns::status($name, $label),
                default => Columns::text($name, $label)->searchable(),
            };
        }

        return ListScreen::apply($table
            ->query(fn () => $this->scope(RegisterRow::on_(static::$registerTable)->select(static::$registerTable.'.*'), (string) ($this->tenantId ?? app(TenantContext::class)->id())))
            ->defaultSort(static::$registerTable.'.'.static::$defaultSort, 'desc')
            ->columns($columns), static::$registerTable);
    }
}
