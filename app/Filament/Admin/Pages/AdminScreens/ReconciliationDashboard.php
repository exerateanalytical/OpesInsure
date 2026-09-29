<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** FIN-005 Reconciliation dashboard — KPIs over reconciliation_imports / reconciliation_items (reconciliation.read, tenant-scoped). */
final class ReconciliationDashboard extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-scale';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'finance/reconciliation-dashboard';

    protected static array $permissions = ['reconciliation.read'];

    protected static string $screen = 'reconciliation_dashboard';

    protected static string $group = 'Financial operations';

    private function items(): \Illuminate\Database\Query\Builder
    {
        return DB::table('reconciliation_items as i')->join('reconciliation_imports as m', 'm.id', '=', 'i.reconciliation_import_id')->where('m.tenant_id', $this->tenantId);
    }

    public function kpis(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $imports = DB::table('reconciliation_imports')->where('tenant_id', $this->tenantId);
        $total = (clone $this->items())->count();
        $matched = (clone $this->items())->where('i.status', 'MATCHED')->count();
        $open = (clone $this->items())->where('i.status', 'EXCEPTION')->whereNull('i.resolved_at');
        $oldest = (clone $open)->min('i.transaction_at');

        return [
            self::kpi('imports_30d', (clone $imports)->where('created_at', '>=', now()->subDays(30))->count()),
            self::kpi('match_rate', $total > 0 ? round($matched * 100 / $total, 1).' %' : '—', $total > 0 && $matched / $total < 0.9 ? 'warning' : 'success'),
            self::kpi('open_exceptions', (clone $open)->count(), (clone $open)->exists() ? 'danger' : 'success',
                $oldest ? __('admin_screens.kpis.oldest', ['date' => \Illuminate\Support\Carbon::parse($oldest)->format('d/m/Y')]) : null),
            self::kpi('open_variance', self::money((int) (clone $open)->sum(DB::raw('abs(coalesce(i.variance_minor, 0))')))),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $rows = (clone $this->items())
                    ->selectRaw("coalesce(m.provider, m.source_type) as provider, count(distinct m.id) as imports, count(*) as items,
                        sum(case when i.status = 'MATCHED' then 1 else 0 end) as matched,
                        sum(case when i.status = 'EXCEPTION' and i.resolved_at is null then 1 else 0 end) as exceptions,
                        sum(case when i.status = 'PENDING' then 1 else 0 end) as pending,
                        sum(abs(coalesce(i.variance_minor, 0))) as variance, max(m.created_at) as last_import")
                    ->groupByRaw('coalesce(m.provider, m.source_type)')->orderBy('provider')->get()
                    ->map(fn ($r) => (array) $r + [
                        'rate' => $r->items > 0 ? round($r->matched * 100 / $r->items, 1).' %' : '—',
                        'variance_display' => self::money((int) $r->variance),
                    ]);

                return self::keyed($rows, 'provider');
            })
            ->columns([
                TextColumn::make('provider')->label(self::col('provider')),
                TextColumn::make('imports')->label(self::col('imports'))->numeric(),
                TextColumn::make('items')->label(self::col('items'))->numeric(),
                TextColumn::make('rate')->label(self::col('match_rate')),
                TextColumn::make('pending')->label(self::col('pending'))->numeric(),
                TextColumn::make('exceptions')->label(self::col('exceptions'))->badge()->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'success'),
                TextColumn::make('variance_display')->label(self::col('variance'))->alignEnd(),
                TextColumn::make('last_import')->label(self::col('last_import'))->dateTime('d/m/Y H:i'),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
