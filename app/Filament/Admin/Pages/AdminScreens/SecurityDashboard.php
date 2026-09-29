<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Security\Findings\SecurityFindingService;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * OPS-014 Security dashboard — KPIs from the security centre (security.centre.read / security.findings.read, same
 * tenant-scoped reads as GET security-centre/*): open findings by severity, overdue remediation, flagged sign-ins
 * and privileged access in force; the table is the open-finding matrix by severity.
 */
final class SecurityDashboard extends AdminScreenPage
{
    public const OPEN = ['OPEN', 'TRIAGED', 'IN_REMEDIATION'];

    protected static string|BackedEnum|null $navigationIcon = 'lucide-shield-check';

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'operations/security-dashboard';

    protected static array $permissions = ['security.centre.read', 'security.findings.read'];

    protected static string $screen = 'security_dashboard';

    protected static string $group = 'Operations';

    private function findings(): \Illuminate\Database\Query\Builder
    {
        return DB::table('security_findings')->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000');
    }

    public function kpis(): array
    {
        $open = (clone $this->findings())->whereIn('status', self::OPEN);
        $critical = (clone $open)->whereIn('severity', ['CRITICAL', 'HIGH'])->count();
        $overdue = (clone $open)->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $members = DB::table('tenant_memberships')->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000')->select('user_id');
        $flagged = DB::table('login_activities')->whereIn('user_id', $members)->where('occurred_at', '>=', now()->subDay())
            ->when(DB::getDriverName() === 'pgsql', fn ($q) => $q->whereRaw("jsonb_array_length(coalesce(anomaly_flags, '[]'::jsonb)) > 0"))->count();
        $privileged = DB::table('privileged_access_grants')->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000')
            ->whereIn('status', ['APPROVED', 'ACTIVE'])->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();

        return [
            self::kpi('critical_high_open', $critical, $critical > 0 ? 'danger' : 'success', __('admin_screens.kpis.open_n', ['n' => (clone $open)->count()])),
            self::kpi('overdue_findings', $overdue, $overdue > 0 ? 'danger' : 'success'),
            self::kpi('flagged_signins_24h', $flagged, $flagged > 0 ? 'warning' : 'success'),
            self::kpi('privileged_grants', $privileged, $privileged > 0 ? 'warning' : null),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $stats = (clone $this->findings())->selectRaw("severity,
                        sum(case when status = 'OPEN' then 1 else 0 end) as open,
                        sum(case when status = 'TRIAGED' then 1 else 0 end) as triaged,
                        sum(case when status = 'IN_REMEDIATION' then 1 else 0 end) as in_remediation,
                        sum(case when status = 'RISK_ACCEPTED' then 1 else 0 end) as risk_accepted,
                        sum(case when status = 'RESOLVED' then 1 else 0 end) as resolved,
                        sum(case when status in ('OPEN','TRIAGED','IN_REMEDIATION') and due_at < ? then 1 else 0 end) as overdue", [now()])
                    ->groupBy('severity')->get()->keyBy('severity');
                $rows = [];
                foreach (SecurityFindingService::SEVERITIES as $sev) {
                    $s = $stats->get($sev);
                    $rows[$sev] = ['__key' => $sev, 'id' => $sev, 'severity' => __('admin_screens.severities.'.$sev)] + collect(['open', 'triaged', 'in_remediation', 'risk_accepted', 'resolved', 'overdue'])
                        ->mapWithKeys(fn ($k) => [$k => (int) ($s->{$k} ?? 0)])->all();
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('severity')->label(self::col('severity'))->badge(),
                TextColumn::make('open')->label(self::col('open'))->numeric(),
                TextColumn::make('triaged')->label(self::col('triaged'))->numeric(),
                TextColumn::make('in_remediation')->label(self::col('in_remediation'))->numeric(),
                TextColumn::make('overdue')->label(self::col('overdue'))->badge()->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'success'),
                TextColumn::make('risk_accepted')->label(self::col('risk_accepted'))->numeric(),
                TextColumn::make('resolved')->label(self::col('resolved'))->numeric(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
