<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Application\WebExperiences\Money;
use Illuminate\Support\Facades\DB;

/** BRM-002 Branch Production — policies written and premium by month for the caller's branch (API permission policies.read). */
final class BranchProductionPage extends BranchScreen
{
    public const PERMISSION = 'policies.read';

    public const KEY = 'production';

    protected static ?string $slug = 'branch/production';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-trending-up';

    protected static ?int $navigationSort = 10;

    private function written(): \Illuminate\Database\Query\Builder
    {
        return $this->scoped('policies')->whereNotIn('status', ['CANCELLED', 'VOID', 'DRAFT']);
    }

    public function kpis(): array
    {
        $month = (clone $this->written())->whereRaw('coalesce(issued_at, created_at) >= ?', [now()->startOfMonth()]);
        $year = (clone $this->written())->whereRaw('coalesce(issued_at, created_at) >= ?', [now()->startOfYear()]);
        $quotes = $this->scoped('quotes')->where('created_at', '>=', now()->startOfYear())->count();
        $converted = $this->scoped('quotes')->where('created_at', '>=', now()->startOfYear())->whereNotNull('accepted_at')->count();

        return [
            self::kpi('policies_month', (clone $month)->count(), null, Money::display((int) (clone $month)->sum('premium_minor'), 'XAF')),
            self::kpi('premium_ytd', Money::display((int) (clone $year)->sum('premium_minor'), 'XAF'), null, __('branch_screens.kpis.policies_n', ['n' => (clone $year)->count()])),
            self::kpi('conversion_ytd', $quotes > 0 ? round(100 * $converted / $quotes).' %' : '—', null, __('branch_screens.kpis.quotes_n', ['n' => $quotes])),
        ];
    }

    protected function rows(): array
    {
        $policies = (clone $this->written())->where(DB::raw('coalesce(issued_at, created_at)'), '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("to_char(coalesce(issued_at, created_at), 'YYYY-MM') as id, count(*) as policies, coalesce(sum(premium_minor), 0) as premium_minor")
            ->groupBy('id')->pluck('premium_minor', 'id');
        $counts = (clone $this->written())->where(DB::raw('coalesce(issued_at, created_at)'), '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("to_char(coalesce(issued_at, created_at), 'YYYY-MM') as id, count(*) as n")->groupBy('id')->pluck('n', 'id');
        $quotes = $this->scoped('quotes')->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("to_char(created_at, 'YYYY-MM') as id, count(*) as n")->groupBy('id')->pluck('n', 'id');
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $m = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $rows[] = ['id' => $m, 'month' => $m, 'quotes' => (int) ($quotes[$m] ?? 0), 'policies' => (int) ($counts[$m] ?? 0), 'premium_minor' => (int) ($policies[$m] ?? 0)];
        }

        return $rows;
    }

    protected function columns(): array
    {
        return [self::col('month'), self::col('quotes'), self::col('policies'), self::money('premium_minor', 'premium')];
    }
}
