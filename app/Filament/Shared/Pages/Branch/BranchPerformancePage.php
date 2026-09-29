<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Filament\Shared\Columns;

/**
 * BRM-016 Branch Performance Reports — year-to-date production per member of the caller's branch (API permission
 * policies.read): proposals they created, the policies issued from them and that premium, and claims on those policies.
 */
final class BranchPerformancePage extends BranchScreen
{
    public const PERMISSION = 'policies.read';

    public const KEY = 'performance';

    protected static ?string $slug = 'branch/performance';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-bar-chart-3';

    protected static ?int $navigationSort = 19;

    protected function rows(): array
    {
        $since = now()->startOfYear();
        $rows = [];
        foreach (\Illuminate\Support\Facades\DB::table('tenant_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $this->tenantId)->where('m.branch_id', $this->branchId)->where('m.status', 'ACTIVE')
            ->orderBy('u.full_name')->get(['m.id', 'm.user_id', 'u.full_name', 'm.role_code']) as $m) {
            $proposals = $this->scoped('proposals')->where('created_by', $m->user_id)->where('created_at', '>=', $since);
            $policies = $this->scoped('policies', 'x')->join('proposals as pr', 'pr.id', '=', 'x.proposal_id')->where('pr.created_by', $m->user_id)
                ->whereRaw('coalesce(x.issued_at, x.created_at) >= ?', [$since]);
            $rows[] = ['id' => $m->id, 'name' => $m->full_name, 'role' => Columns::humanise($m->role_code),
                'proposals' => (clone $proposals)->count(), 'policies' => (clone $policies)->count(), 'premium_minor' => (int) (clone $policies)->sum('x.premium_minor'),
                'claims' => $this->scoped('claims')->whereIn('policy_id', (clone $policies)->select('x.id'))->count()];
        }
        usort($rows, fn ($a, $b) => $b['premium_minor'] <=> $a['premium_minor']);

        return $rows;
    }

    protected function columns(): array
    {
        return [self::col('name'), self::col('role'), self::col('proposals'), self::col('policies'), self::money('premium_minor', 'premium'), self::col('claims')];
    }
}
