<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Filament\Shared\Columns;

/** BRM-008 Branch Renewals — renewal cases of the caller's branch (API permission renewals.manage). */
final class BranchRenewalsPage extends BranchScreen
{
    public const PERMISSION = 'renewals.manage';

    public const KEY = 'renewals';

    protected static ?string $slug = 'branch/renewals';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-refresh-cw';

    protected static ?int $navigationSort = 13;

    public function kpis(): array
    {
        $r = $this->scoped('renewal_cases');
        $open = (clone $r)->whereIn('status', ['DUE', 'CONTACTED', 'QUOTED']);

        return [
            self::kpi('renewals_open', (clone $open)->count()),
            self::kpi('renewals_overdue', (clone $open)->where('due_on', '<', now()->toDateString())->count(), 'warning'),
            self::kpi('renewals_renewed', (clone $r)->whereNotNull('successor_policy_id')->count(), 'success'),
        ];
    }

    protected function rows(): array
    {
        return $this->scoped('renewal_cases', 'r')->leftJoin('policies as x', 'x.id', '=', 'r.policy_id')->leftJoin('parties as p', 'p.id', '=', 'x.party_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.assigned_to')->orderBy('r.due_on')->limit(self::LIMIT)
            ->get(['r.id', 'x.policy_number', 'p.display_name as customer', 'r.status', 'r.due_on', 'u.full_name as assigned', 'r.contact_attempts'])->all();
    }

    protected function columns(): array
    {
        return [
            self::col('policy_number', 'policy'), self::col('customer'), Columns::status('status', __('branch_screens.columns.status')),
            Columns::date('due_on', false, __('branch_screens.columns.due')), self::col('assigned'), self::col('contact_attempts', 'attempts'),
        ];
    }
}
