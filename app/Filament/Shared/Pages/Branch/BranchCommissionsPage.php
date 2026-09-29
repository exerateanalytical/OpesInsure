<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Application\WebExperiences\Money;
use App\Filament\Shared\Columns;

/** BRM-011 Branch Commissions — commission accruals on the caller's branch policies (API permission commission.read). */
final class BranchCommissionsPage extends BranchScreen
{
    public const PERMISSION = 'commission.read';

    public const KEY = 'commissions';

    protected static ?string $slug = 'branch/commissions';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-percent';

    protected static ?int $navigationSort = 15;

    public function kpis(): array
    {
        $a = $this->scoped('commission_accruals');

        return [
            self::kpi('commission_accrued', Money::display((int) (clone $a)->sum('amount_minor'), 'XAF')),
            self::kpi('commission_paid', Money::display((int) (clone $a)->sum('paid_minor'), 'XAF'), 'success'),
            self::kpi('commission_clawed_back', Money::display((int) (clone $a)->sum('clawed_back_minor'), 'XAF')),
        ];
    }

    protected function rows(): array
    {
        return $this->scoped('commission_accruals', 'a')->leftJoin('policies as x', 'x.id', '=', 'a.policy_id')->leftJoin('partners as pa', 'pa.id', '=', 'a.partner_id')
            ->leftJoin('parties as pp', 'pp.id', '=', 'pa.party_id')->orderByDesc('a.created_at')->limit(self::LIMIT)
            ->get(['a.id', 'x.policy_number', \Illuminate\Support\Facades\DB::raw('coalesce(pp.display_name, pa.legal_name) as partner'), 'a.status', 'a.amount_minor', 'a.paid_minor', 'a.earned_at'])->all();
    }

    protected function columns(): array
    {
        return [
            self::col('policy_number', 'policy'), self::col('partner'), Columns::status('status', __('branch_screens.columns.status')),
            self::money('amount_minor', 'amount'), self::money('paid_minor', 'paid'), Columns::date('earned_at', false, __('branch_screens.columns.earned')),
        ];
    }
}
