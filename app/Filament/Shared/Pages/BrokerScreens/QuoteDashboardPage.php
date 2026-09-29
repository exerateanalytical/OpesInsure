<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\QuoteActions;
use BackedEnum;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * BRK-031 Quote Dashboard (WF-010): KPI tiles over the caller's book of quotes (open, referred, expiring within 7 days,
 * created in the last 30 days, accepted) and the open quotes to work, with the shared quote workflow actions
 * (QuoteActions::group, each gated by the quote API permission and the own-book check).
 */
final class QuoteDashboardPage extends QuoteScreen
{
    protected static string $key = 'quote_dashboard';

    protected static ?string $slug = 'quote-dashboard';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-layout-dashboard';

    protected static ?int $navigationSort = 42;

    public const CLOSED = ['ACCEPTED', 'DECLINED', 'CANCELLED', 'EXPIRED', 'CONVERTED'];

    protected function query(): Builder
    {
        return $this->quotes()->whereNotIn('quotes.status', self::CLOSED);
    }

    protected function filters(): array
    {
        return [SelectFilter::make('status')->label(self::col('status'))
            ->options(fn () => $this->quotes()->reorder()->select('quotes.status')->distinct()->pluck('quotes.status', 'quotes.status')->all())];
    }

    protected function recordActions(): array
    {
        return [QuoteActions::group()];
    }

    public function kpis(): array
    {
        $all = fn () => $this->book(\App\Models\Quote::class, 'quotes');

        return [
            self::kpi('quotes_open', $all()->whereNotIn('quotes.status', self::CLOSED)->count(), 'primary'),
            self::kpi('quotes_referred', $all()->where('quotes.status', 'REFERRED')->count(), 'warning'),
            self::kpi('quotes_expiring', $all()->whereNotIn('quotes.status', self::CLOSED)->whereBetween('quotes.expires_at', [now(), now()->addDays(7)])->count(), 'warning'),
            self::kpi('quotes_30d', $all()->where('quotes.created_at', '>=', now()->subDays(30))->count()),
            self::kpi('quotes_accepted', $all()->where('quotes.status', 'ACCEPTED')->count(), 'success'),
        ];
    }
}
