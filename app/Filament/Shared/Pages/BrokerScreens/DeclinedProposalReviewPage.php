<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Models\Proposal;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-044 Declined Proposal Review (WF-021): proposals of the book the insurer declined (or that were withdrawn), with
 * the underwriting decision reason, so the broker can follow up with the customer (new quote from the customers page).
 * Read-only: a declined proposal is terminal in the proposal machine.
 */
final class DeclinedProposalReviewPage extends ProposalScreen
{
    protected static string $key = 'declined_proposals';

    protected static ?string $slug = 'declined-proposal-review';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-x';

    protected static ?int $navigationSort = 49;

    protected function query(): Builder
    {
        return $this->proposals(['DECLINED', 'WITHDRAWN']);
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            self::date('decided_at', 'decided_at'),
            TextColumn::make('reason')->label(self::col('reason'))->wrap()->state(function (Proposal $record): string {
                $d = DB::table('underwriting_decisions as d')->join('underwriting_cases as c', 'c.id', '=', 'd.underwriting_case_id')
                    ->where('c.proposal_id', $record->id)->orderByDesc('d.created_at')->first();

                return $d === null ? '—' : trim(($d->reason_code ?? '').' '.\Illuminate\Support\Str::limit((string) ($d->rationale ?? $d->notes ?? ''), 160));
            }),
        ];
    }

    protected function filters(): array
    {
        return [SelectFilter::make('status')->label(self::col('status'))->options(['DECLINED' => __('broker_screens_b.statuses.DECLINED'), 'WITHDRAWN' => __('broker_screens_b.statuses.WITHDRAWN')])];
    }
}
