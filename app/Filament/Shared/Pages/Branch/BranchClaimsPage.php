<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Filament\Shared\Columns;

/** BRM-009 Branch Claims — claims on the caller's branch policies (API permission claims.view). */
final class BranchClaimsPage extends BranchScreen
{
    public const PERMISSION = 'claims.view';

    public const KEY = 'claims';

    protected static ?string $slug = 'branch/claims';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-file-warning';

    protected static ?int $navigationSort = 14;

    public function kpis(): array
    {
        $c = $this->scoped('claims');

        return [
            self::kpi('claims_open', (clone $c)->whereNull('closed_at')->count()),
            self::kpi('claims_month', (clone $c)->where('created_at', '>=', now()->startOfMonth())->count()),
            self::kpi('claims_fraud', (clone $c)->where('fraud_flag', true)->count(), 'danger'),
        ];
    }

    protected function rows(): array
    {
        return $this->scoped('claims', 'c')->leftJoin('policies as x', 'x.id', '=', 'c.policy_id')->leftJoin('parties as p', 'p.id', '=', 'x.party_id')
            ->orderByDesc('c.created_at')->limit(self::LIMIT)
            ->get(['c.id', 'c.claim_number', 'x.policy_number', 'p.display_name as customer', 'c.status', 'c.loss_occurred_at', 'c.estimated_loss_minor', 'c.closed_at'])->all();
    }

    protected function columns(): array
    {
        return [
            self::col('claim_number', 'number'), self::col('policy_number', 'policy'), self::col('customer'),
            Columns::status('status', __('branch_screens.columns.status')), Columns::date('loss_occurred_at', false, __('branch_screens.columns.loss_date')),
            self::money('estimated_loss_minor', 'estimate'), Columns::date('closed_at', false, __('branch_screens.columns.closed')),
        ];
    }
}
