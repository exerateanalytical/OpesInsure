<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Application\WebExperiences\{BrokerBookMetrics, PortalScope};
use App\Domain\Tenancy\TenantContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * BRK-002 Operations Dashboard: the /broker home KPI row (production, quote pipeline, conversion, proposals, claims,
 * commissions, renewals due) for the caller's book (BrokerBookMetrics; each tile gated by its source's read permission).
 */
final class BrokerOperationsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -10;

    protected ?string $heading = null;

    public static function canView(): bool
    {
        return PortalScope::panel() === 'broker' && auth()->check();
    }

    protected function getHeading(): ?string
    {
        return __('broker_screens_a.operations.heading');
    }

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'sm' => 2, 'lg' => 4];
    }

    protected function getStats(): array
    {
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return $tenant === null ? [] : array_map(
            fn (array $m): Stat => Stat::make($m['label'], $m['value'])->color($m['tone'])->extraAttributes(['data-metric' => $m['key']]),
            app(BrokerBookMetrics::class)->operations($tenant),
        );
    }
}
