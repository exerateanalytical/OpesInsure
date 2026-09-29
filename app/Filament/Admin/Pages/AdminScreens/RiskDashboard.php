<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** CMP-002 Risk dashboard — KPIs and alert mix over risk_alerts (fraud.alert.create / fraud.alert.decide, tenant-scoped). */
final class RiskDashboard extends AdminScreenPage
{
    public const OPEN = ['OPEN', 'UNDER_REVIEW'];

    protected static string|BackedEnum|null $navigationIcon = 'lucide-gauge';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'compliance/risk-dashboard';

    protected static array $permissions = ['fraud.alert.decide', 'fraud.alert.create', 'trust.fraud-alerts.decide'];

    protected static string $screen = 'risk_dashboard';

    protected static string $group = 'Trust & compliance';

    private function alerts(): \Illuminate\Database\Query\Builder
    {
        return DB::table('risk_alerts')->where('tenant_id', $this->tenantId);
    }

    public function kpis(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $open = (clone $this->alerts())->whereIn('status', self::OPEN);
        $high = (clone $open)->whereIn('severity', ['HIGH', 'CRITICAL'])->count();
        $overdue = (clone $open)->whereNotNull('review_due_at')->where('review_due_at', '<', now())->count();
        $decided = (clone $this->alerts())->where('decided_at', '>=', now()->subDays(30));
        $confirmed = (clone $decided)->whereIn('decision', ['CONFIRMED', 'CONFIRMED_FRAUD'])->count();
        $n = (clone $decided)->count();

        return [
            self::kpi('open_alerts', (clone $open)->count(), (clone $open)->exists() ? 'warning' : 'success'),
            self::kpi('high_critical', $high, $high > 0 ? 'danger' : 'success'),
            self::kpi('overdue_reviews', $overdue, $overdue > 0 ? 'danger' : 'success'),
            self::kpi('confirmed_rate_30d', $n > 0 ? round($confirmed * 100 / $n, 1).' %' : '—', null, __('admin_screens.kpis.decided_n', ['n' => $n])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $rows = (clone $this->alerts())->selectRaw("alert_type, count(*) as total,
                        sum(case when status in ('OPEN','UNDER_REVIEW') then 1 else 0 end) as open,
                        sum(case when status in ('OPEN','UNDER_REVIEW') and severity in ('HIGH','CRITICAL') then 1 else 0 end) as high,
                        round(avg(risk_score)) as avg_score, max(created_at) as latest")
                    ->groupBy('alert_type')->orderByDesc('open')->get();

                return self::keyed($rows, 'alert_type');
            })
            ->columns([
                TextColumn::make('alert_type')->label(self::col('alert_type'))->formatStateUsing(fn ($state) => Columns::humanise($state)),
                TextColumn::make('open')->label(self::col('open'))->badge()->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'success'),
                TextColumn::make('high')->label(self::col('high_critical'))->numeric(),
                TextColumn::make('total')->label(self::col('total'))->numeric(),
                TextColumn::make('avg_score')->label(self::col('avg_score'))->numeric(),
                TextColumn::make('latest')->label(self::col('latest'))->dateTime('d/m/Y H:i'),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
