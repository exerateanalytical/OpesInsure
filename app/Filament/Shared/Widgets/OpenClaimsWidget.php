<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

/** Open claims (KPI claims.open). */
final class OpenClaimsWidget extends KpiRecordListWidget
{
    protected static ?int $sort = 4;

    protected static ?string $permission = 'claims.read';

    protected static string $kpi = 'claims.open';

    protected static string $resource = 'claims';

    public function heading(): string
    {
        return __('dashboards.widgets.open_claims');
    }

    protected function columns(): array
    {
        return ['claim_number' => __('dashboards.columns.claim_number'), 'status' => __('dashboards.columns.status'),
            'current_reserve_minor' => __('dashboards.columns.reserve'), 'currency' => __('dashboards.columns.currency'), 'created_at' => __('dashboards.columns.created_at')];
    }
}
