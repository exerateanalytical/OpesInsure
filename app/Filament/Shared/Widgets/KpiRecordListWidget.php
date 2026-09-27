<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Application\Reporting\Kpi\KpiCatalogueService;
use App\Application\Reporting\Kpi\KpiEvaluator;
use App\Models\User;

/** Record list = the governed KPI drill-down (KpiEvaluator::drill, tenant-scoped) — same records as the dashboard tile and the reporting API. */
abstract class KpiRecordListWidget extends RecordListWidget
{
    /** KpiQueryRegistry code. */
    protected static string $kpi;

    /** Resource slug the rows link to. */
    protected static string $resource;

    protected function rows(string $tenantId, User $user): array
    {
        $kpi = app(KpiCatalogueService::class)->resolve($tenantId, static::$kpi);
        $d = app(KpiEvaluator::class)->drill($tenantId, $kpi, [], [], 1, 10);

        return array_map(fn (array $r) => $r + ['_url' => $this->recordUrl(static::$resource, $r['id'] ?? null)], $d['rows']);
    }
}
