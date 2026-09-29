<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use Illuminate\Support\Facades\DB;

/**
 * BRM-003 Branch Customers — customers of the caller's branch (API permission customers.read): parties with a quote or
 * policy of the branch, or an ACTIVE attribution recorded by a member of the branch (BookScope BRANCH rule).
 */
final class BranchCustomersPage extends BranchScreen
{
    public const PERMISSION = 'customers.read';

    public const KEY = 'customers';

    protected static ?string $slug = 'branch/customers';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-users';

    protected static ?int $navigationSort = 16;

    private function partyIds(): \Illuminate\Database\Query\Builder
    {
        return $this->scoped('policies')->select('party_id')
            ->union($this->scoped('quotes')->select('party_id'))
            ->union(DB::table('customer_attributions as ca')->join('tenant_customers as tc', 'tc.party_id', '=', 'ca.party_id')->where('tc.tenant_id', $this->tenantId)
                ->where('ca.status', 'ACTIVE')->whereIn('ca.recorded_by', $this->branchUsers())->select('ca.party_id'));
    }

    public function kpis(): array
    {
        $ids = DB::query()->fromSub($this->partyIds(), 'b');

        return [
            self::kpi('customers_total', (clone $ids)->count()),
            self::kpi('customers_insured', $this->scoped('policies')->where('status', 'ACTIVE')->distinct()->count('party_id'), 'success'),
        ];
    }

    protected function rows(): array
    {
        return DB::table('parties as p')->whereIn('p.id', $this->partyIds())->orderByDesc('p.created_at')->limit(self::LIMIT)
            ->get(['p.id', 'p.display_name as name', 'p.type', 'p.status', 'p.created_at'])
            ->map(function ($p) {
                $p->policies = $this->scoped('policies')->where('party_id', $p->id)->where('status', 'ACTIVE')->count();
                $p->quotes = $this->scoped('quotes')->where('party_id', $p->id)->count();

                return $p;
            })->all();
    }

    protected function columns(): array
    {
        return [
            self::col('name'), self::col('type'), \App\Filament\Shared\Columns::status('status', __('branch_screens.columns.status')),
            self::col('policies'), self::col('quotes'), \App\Filament\Shared\Columns::date('created_at', false, __('branch_screens.columns.since')),
        ];
    }
}
