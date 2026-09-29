<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Branch;

use App\Filament\Shared\Columns;

/** BRM-006 Branch Quotes — quotes of the caller's branch (API permission quotes.read). */
final class BranchQuotesPage extends BranchScreen
{
    public const PERMISSION = 'quotes.read';

    public const KEY = 'quotes';

    protected static ?string $slug = 'branch/quotes';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-file-text';

    protected static ?int $navigationSort = 11;

    public function kpis(): array
    {
        $q = $this->scoped('quotes');

        return [
            self::kpi('quotes_month', (clone $q)->where('created_at', '>=', now()->startOfMonth())->count()),
            self::kpi('quotes_open', (clone $q)->whereNotIn('lifecycle_state', ['ACCEPTED', 'DECLINED', 'EXPIRED', 'CANCELLED', 'CONVERTED'])->count()),
            self::kpi('quotes_accepted', (clone $q)->whereNotNull('accepted_at')->count()),
            self::kpi('quotes_expired', (clone $q)->whereNull('accepted_at')->whereNotNull('expires_at')->where('expires_at', '<', now())->count(), 'warning'),
        ];
    }

    protected function rows(): array
    {
        return $this->scoped('quotes', 'q')->leftJoin('parties as p', 'p.id', '=', 'q.party_id')->orderByDesc('q.created_at')->limit(self::LIMIT)
            ->get(['q.id', 'q.quote_number', 'p.display_name as customer', 'q.line_code', 'q.channel', 'q.lifecycle_state as status', 'q.created_at', 'q.expires_at'])->all();
    }

    protected function columns(): array
    {
        return [
            self::col('quote_number', 'number'), self::col('customer'), self::col('line_code', 'line'), self::col('channel'),
            Columns::status('status', __('branch_screens.columns.status')),
            Columns::date('created_at', false, __('branch_screens.columns.created')), Columns::date('expires_at', false, __('branch_screens.columns.expires')),
        ];
    }
}
