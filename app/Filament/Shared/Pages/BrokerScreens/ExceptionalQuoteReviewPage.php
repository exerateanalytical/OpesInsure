<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\QuoteActions;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-034 Exceptional Quote Review (WF-081): quotes of the book that left the straight-through path — referred quotes,
 * quotes with a referred / declined carrier offer, and quotes carrying a premium override. Actions are the quote API's:
 * request a carrier quote / record the carrier offer (manual carriers), request a premium override, decline, cancel.
 */
final class ExceptionalQuoteReviewPage extends QuoteScreen
{
    protected static string $key = 'exceptional_quotes';

    protected static ?string $slug = 'exceptional-quote-review';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-triangle-alert';

    protected static ?int $navigationSort = 43;

    protected function query(): Builder
    {
        $flagged = DB::table('quote_offers')->where(fn ($w) => $w->whereIn('status', ['REFERRED', 'DECLINED'])->orWhereNotNull('premium_override_id'))->select('quote_id');

        return $this->quotes()->whereNotIn('quotes.status', ['CANCELLED', 'EXPIRED'])
            ->where(fn ($w) => $w->where('quotes.status', 'REFERRED')->orWhereIn('quotes.id', $flagged));
    }

    protected function recordActions(): array
    {
        return [ActionGroup::make([
            QuoteActions::requestCarrierQuote(), QuoteActions::recordCarrierOffer(), QuoteActions::requestOverride(),
            QuoteActions::decline(), QuoteActions::cancel(),
        ])->label(__('workflow_actions.quote_group'))->icon('lucide-zap')->button()];
    }
}
