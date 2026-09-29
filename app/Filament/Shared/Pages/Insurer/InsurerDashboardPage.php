<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Insurer;

use App\Application\WebExperiences\InsurerDashboards;
use App\Application\WebExperiences\PortalAuthorization;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

/**
 * /insurer analytical dashboards (CAR-003 … CAR-007): KPI tiles, charts and ranked tables from InsurerDashboards —
 * governed KPIs and breakdowns narrowed to the caller's own carrier (PortalScope). Read-only: gated by the carrier
 * workspace API read permission (carrier.dashboard.read; claims performance also opens for carrier.claims.read /
 * claims.view). Subclasses only pick the dashboard key, slug, icon and widgets. The /insurer home (CAR-002) is the
 * shared PortalDashboard, which shows the operations tiles for the insurer panel.
 */
abstract class InsurerDashboardPage extends Dashboard
{
    use HasFiltersForm;

    /** Dashboard key => read permissions (any one opens it; PortalAuthorization::allowsRead). */
    public const PERMISSIONS = [
        'operations' => ['carrier.dashboard.read'],
        'production' => ['carrier.dashboard.read'],
        'portfolio' => ['carrier.dashboard.read'],
        // Claims figures only for a claims reader (owner rule: no claims permission = no claims anywhere in the portal).
        'claims' => ['carrier.claims.read', 'claims.view'],
        'brokers' => ['carrier.dashboard.read'],
        'products' => ['carrier.dashboard.read'],
    ];

    protected static string $dashboard = 'production';

    protected static ?int $navigationSort = 1;

    public static function mayView(string $dashboard): bool
    {
        $u = auth()->user();
        if (! $u instanceof User || PortalScope::panel() !== 'insurer' || rescue(fn () => app(TenantContext::class)->id(), null, false) === null) {
            return false;
        }

        return collect(self::PERMISSIONS[$dashboard] ?? [])->contains(fn (string $p) => (bool) rescue(fn () => PortalAuthorization::allowsRead($u, $p), false, false));
    }

    public static function canAccess(): bool
    {
        return self::mayView(static::$dashboard);
    }

    public static function getRoutePath(\Filament\Panel $panel): string
    {
        return '/'.static::getSlug();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('insurer_screens.nav.dashboards');
    }

    public static function getNavigationLabel(): string
    {
        return __('insurer_screens.dashboards.'.static::$dashboard.'.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return __('insurer_screens.dashboards.'.static::$dashboard.'.subheading');
    }

    /** Period applies to the flow figures (issued, reported, paid …); stock figures (in force, open) are as of now. */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')->label(__('insurer_screens.filters.period'))->native(false)->selectablePlaceholder(false)
                ->default(InsurerDashboards::DEFAULT_PERIOD)
                ->options(collect(InsurerDashboards::PERIODS)->mapWithKeys(fn ($p) => [$p => __('insurer_screens.periods.'.$p)])->all()),
        ]);
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
