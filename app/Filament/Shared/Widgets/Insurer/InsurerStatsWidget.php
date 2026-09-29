<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets\Insurer;

use App\Application\WebExperiences\InsurerDashboards;
use App\Filament\Shared\Pages\Insurer\InsurerDashboardPage;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Route;

/**
 * KPI tiles of one /insurer dashboard (CAR-002 … CAR-007), from InsurerDashboards (governed KPIs, own carrier only).
 * $dashboard picks the tile set; the dashboard page's period filter narrows the period-based tiles.
 */
final class InsurerStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    public string $dashboard = 'operations';

    /** Tile drill-down list => the read permission of that list (PortalAuthorization::READ_PERMISSIONS). */
    private const BOOK_READS = ['claims' => 'claims.view', 'policies' => 'policies.read', 'quotes' => 'quotes.read'];

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return InsurerDashboardPage::mayView('operations') || InsurerDashboardPage::mayView('claims');
    }

    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'sm' => 2, 'lg' => 4];
    }

    protected function getHeading(): ?string
    {
        return __('insurer_screens.dashboards.'.$this->dashboard.'.stats');
    }

    protected function getStats(): array
    {
        if (! InsurerDashboardPage::mayView($this->dashboard)) {
            return [];
        }
        $svc = app(InsurerDashboards::class);
        $period = $this->pageFilters['period'] ?? null;
        $tiles = match ($this->dashboard) {
            'production' => $svc->production($period),
            'portfolio' => $svc->portfolio(),
            'claims' => $svc->claimsPerformance($period),
            'brokers' => $svc->brokerProduction(),
            'products' => $svc->productSummary($period),
            default => $svc->operations(),
        };
        $panel = Filament::getCurrentOrDefaultPanel()->getId();
        // As PortalDashboardMetrics: a drill-down only for a reader of that book; claims figures only for a claims reader
        // (owner rule: no claims permission = no claims anywhere in the portal).
        $user = auth()->user();
        $reads = fn (?string $book) => ! isset(self::BOOK_READS[(string) $book]) || ($user instanceof \App\Models\User
            && \App\Application\WebExperiences\PortalAuthorization::allowsRead($user, self::BOOK_READS[(string) $book]));
        $tiles = array_values(array_filter($tiles, fn (array $t) => $t['url'] !== 'claims' || $reads('claims')));
        $tiles = array_map(fn (array $t) => $reads($t['url']) ? $t : ['url' => null] + $t, $tiles);

        return array_map(function (array $t) use ($panel): Stat {
            // The portal tiles' shared labels (web_experience.metrics) first, the carrier-screen labels for the rest.
            $label = \Illuminate\Support\Facades\Lang::has('web_experience.metrics.'.$t['key']) ? __('web_experience.metrics.'.$t['key']) : __('insurer_screens.tiles.'.$t['key']);
            $stat = Stat::make($label, $t['value'])->color($t['tone'])->extraAttributes(['data-metric' => $t['key']]);
            foreach (["filament.{$panel}.resources.{$t['url']}.index", "filament.{$panel}.pages.{$t['url']}"] as $route) {
                if ($t['url'] && Route::has($route)) {
                    return $stat->url(route($route));
                }
            }

            return $stat;
        }, $tiles);
    }
}
