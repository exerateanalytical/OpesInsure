<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Application\Underwriting\ProposalService;
use App\Filament\Shared\Actions\ProposalActions;
use App\Models\Proposal;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * BRK-043 Conditional Offer Review (WF-020): proposals of the book on which the insurer made a counter-offer
 * (COUNTEROFFERED) with the counter terms (ProposalService::counterTerms), so the broker can walk the customer through
 * them. Accepting / refusing a counter-offer is the customer's own act (POST mobile/proposals/{p}/counteroffer/{answer},
 * customer-owned route), so it is not offered here; the broker may withdraw the proposal (ProposalService::withdraw).
 */
final class ConditionalOfferReviewPage extends ProposalScreen
{
    protected static string $key = 'conditional_offers';

    protected static ?string $slug = 'conditional-offer-review';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-arrow-left-right';

    protected static ?int $navigationSort = 48;

    protected function query(): Builder
    {
        return $this->proposals(['COUNTEROFFERED']);
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            self::date('decided_at', 'decided_at'),
            TextColumn::make('counter_terms')->label(self::col('counter_terms'))->wrap()->state(function (Proposal $record): string {
                $terms = rescue(fn () => app(ProposalService::class)->counterTerms($record), null, false);

                return $terms === null ? '—' : \Illuminate\Support\Str::limit(collect($terms)->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? (string) $v : json_encode($v)))->implode(' · '), 220);
            }),
        ];
    }

    protected function recordActions(): array
    {
        return [ProposalActions::withdraw()];
    }
}
