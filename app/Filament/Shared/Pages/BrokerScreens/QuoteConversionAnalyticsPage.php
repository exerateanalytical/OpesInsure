<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Models\Quote;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-036 Quote Conversion Analytics (WF-013, WF-014): the quote -> proposal -> policy funnel of the caller's book over
 * a period (30 / 90 / 365 days): quotes created, priced (with an offer), converted to a proposal, issued as a policy,
 * and the conversion rates; below, each quote of the period with the stage it reached. Read-only; computed on the
 * same scoped quote set as the quote lists (portal tenant + PortalScope::narrowTable).
 */
final class QuoteConversionAnalyticsPage extends QuoteScreen
{
    protected static string $key = 'quote_conversion';

    protected static ?string $slug = 'quote-conversion-analytics';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-chart-line';

    protected static ?int $navigationSort = 45;

    public int $days = 90;

    private static function proposalsOf(): \Illuminate\Database\Query\Builder
    {
        return DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id');
    }

    protected function period(): Builder
    {
        return $this->book(Quote::class, 'quotes')->where('quotes.created_at', '>=', now()->subDays($this->days));
    }

    protected function query(): Builder
    {
        $proposal = self::proposalsOf()->whereColumn('quote_offers.quote_id', 'quotes.id')->selectRaw('1');
        $policy = self::proposalsOf()->join('policies', 'policies.proposal_id', '=', 'proposals.id')->whereColumn('quote_offers.quote_id', 'quotes.id')->selectRaw('1');

        return $this->period()->with('party')->withCount('offers')->select('quotes.*')
            ->selectRaw('exists('.$proposal->toSql().') as has_proposal', $proposal->getBindings())
            ->selectRaw('exists('.$policy->toSql().') as has_policy', $policy->getBindings());
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('stage')->label(self::col('stage'))->badge()
                ->state(fn (Quote $record) => __('broker_screens_b.stages.'.match (true) {
                    (bool) $record->getAttribute('has_policy') => 'policy',
                    (bool) $record->getAttribute('has_proposal') => 'proposal',
                    (int) $record->getAttribute('offers_count') > 0 => 'priced',
                    default => 'created',
                })),
        ];
    }

    protected function filters(): array
    {
        return [SelectFilter::make('line_code')->label(self::col('line'))
            ->options(fn () => $this->period()->reorder()->distinct()->pluck('quotes.line_code', 'quotes.line_code')->filter()->all())];
    }

    public function setDays(int $days): void
    {
        $this->days = in_array($days, [30, 90, 365], true) ? $days : 90;
        $this->resetTable();
    }

    public function kpis(): array
    {
        $ids = $this->period()->reorder()->select('quotes.id');
        $created = (clone $ids)->count();
        $priced = DB::table('quote_offers')->whereIn('quote_id', clone $ids)->distinct()->count('quote_id');
        $proposals = self::proposalsOf()->whereIn('quote_offers.quote_id', clone $ids)->distinct()->count('quote_offers.quote_id');
        $policies = self::proposalsOf()->join('policies', 'policies.proposal_id', '=', 'proposals.id')->whereIn('quote_offers.quote_id', clone $ids)->distinct()->count('quote_offers.quote_id');
        $rate = fn (int $n, int $d) => $d === 0 ? '—' : number_format(100 * $n / $d, 1, ',', ' ').' %';

        return [
            self::kpi('period_days', __('broker_screens_b.kpis.days', ['days' => $this->days])),
            self::kpi('quotes_created', $created),
            self::kpi('quotes_priced', $priced),
            self::kpi('quotes_to_proposal', $proposals),
            self::kpi('quotes_to_policy', $policies, 'success'),
            self::kpi('rate_proposal', $rate($proposals, $created)),
            self::kpi('rate_policy', $rate($policies, $created), 'success'),
        ];
    }

    protected function headerActions(): array
    {
        return collect([30, 90, 365])->map(fn (int $d) => \Filament\Actions\Action::make('days'.$d)
            ->label(__('broker_screens_b.kpis.days', ['days' => $d]))->color($this->days === $d ? 'primary' : 'gray')->size('sm')
            ->action(fn () => $this->setDays($d)))->all();
    }
}
