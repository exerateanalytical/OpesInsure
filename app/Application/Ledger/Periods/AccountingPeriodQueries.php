<?php

declare(strict_types=1);

namespace App\Application\Ledger\Periods;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FIN-023 read side of the accounting periods (AccountingPeriodService is the write side): the tenant's periods,
 * newest first, and the tenant guard every period action goes through (AccountingPeriodService::transition locks by
 * id only, so callers must prove the period belongs to the caller's tenant first).
 */
final class AccountingPeriodQueries
{
    /** @return Collection<int, object> */
    public function list(string $tenantId, int $limit = 60): Collection
    {
        return DB::table('accounting_periods')->where('tenant_id', $tenantId)
            ->orderByDesc('fiscal_year')->orderByDesc('period_number')->limit($limit)->get();
    }

    public function ownedOrFail(string $tenantId, string $periodId): object
    {
        return DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('id', $periodId)->first()
            ?? throw new NotFoundHttpException('Accounting period not found.');
    }
}
