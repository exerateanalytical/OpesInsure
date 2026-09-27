<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

/** Policies expiring within 30 days (KPI policies.expiring_30d). */
final class ExpiringPoliciesWidget extends KpiRecordListWidget
{
    protected static ?int $sort = 3;

    protected static ?string $permission = 'policies.read';

    protected static string $kpi = 'policies.expiring_30d';

    protected static string $resource = 'policies';

    public function heading(): string
    {
        return __('dashboards.widgets.expiring_policies');
    }

    protected function columns(): array
    {
        return ['policy_number' => __('dashboards.columns.policy_number'), 'status' => __('dashboards.columns.status'),
            'coverage_ends_at' => __('dashboards.columns.coverage_ends_at'), 'premium_minor' => __('dashboards.columns.premium'), 'currency' => __('dashboards.columns.currency')];
    }
}
