<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Application\WebExperiences\Money;
use App\Filament\Shared\Columns;

/** BRM-007 Branch Policies — policies of the caller's branch (API permission policies.read). */
final class BranchPoliciesPage extends BranchScreen
{
    public const PERMISSION = 'policies.read';

    public const KEY = 'policies';

    protected static ?string $slug = 'branch/policies';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-shield-check';

    protected static ?int $navigationSort = 12;

    public function kpis(): array
    {
        $p = $this->scoped('policies');
        $active = (clone $p)->where('status', 'ACTIVE');

        return [
            self::kpi('policies_active', (clone $active)->count(), null, Money::display((int) (clone $active)->sum('premium_minor'), 'XAF')),
            self::kpi('policies_expiring', (clone $active)->whereBetween('coverage_ends_at', [now(), now()->addDays(30)])->count(), 'warning'),
            self::kpi('policies_total', (clone $p)->count()),
        ];
    }

    protected function rows(): array
    {
        return $this->scoped('policies', 'x')->leftJoin('parties as p', 'p.id', '=', 'x.party_id')->orderByDesc('x.created_at')->limit(self::LIMIT)
            ->get(['x.id', 'x.policy_number', 'p.display_name as customer', 'x.status', 'x.premium_minor', 'x.coverage_starts_at', 'x.coverage_ends_at'])->all();
    }

    protected function columns(): array
    {
        return [
            self::col('policy_number', 'number'), self::col('customer'), Columns::status('status', __('branch_screens.columns.status')),
            self::money('premium_minor', 'premium'),
            Columns::date('coverage_starts_at', false, __('branch_screens.columns.starts')), Columns::date('coverage_ends_at', false, __('branch_screens.columns.ends')),
        ];
    }
}
