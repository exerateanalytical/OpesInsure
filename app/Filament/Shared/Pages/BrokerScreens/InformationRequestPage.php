<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\ProposalActions;
use App\Models\Proposal;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * BRK-042 Information Request Management (WF-019): proposals of the book on which the underwriter asked for more
 * information (INFORMATION_REQUIRED, ProposalService::requestInformation). The broker answers the disclosures, attaches
 * the documents asked for and resubmits (ProposalService::resubmit, same as the proposal API); an underwriter holding
 * underwriting.decide can add a further request.
 */
final class InformationRequestPage extends ProposalScreen
{
    protected static string $key = 'information_requests';

    protected static ?string $slug = 'information-requests';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-circle-help';

    protected static ?int $navigationSort = 47;

    protected function query(): Builder
    {
        return $this->proposals()->where(fn ($w) => $w->where('proposals.status', 'INFORMATION_REQUIRED')
            ->orWhere(fn ($x) => $x->whereNotNull('proposals.information_request')->where('proposals.status', 'RESUBMITTED')));
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('request')->label(self::col('request'))->wrap()->state(fn (Proposal $record) => self::describe($record->information_request)),
        ];
    }

    public static function describe(mixed $request): string
    {
        $request = is_string($request) ? (json_decode($request, true) ?? $request) : $request;
        if (! is_array($request)) {
            return filled($request) ? (string) $request : '—';
        }
        $items = collect((array) ($request['items'] ?? []))->map(fn ($i) => is_array($i) ? ($i['description'] ?? $i['code'] ?? '') : (string) $i)->filter();
        $text = trim(($request['message'] ?? '').' '.$items->implode(' · '));

        return $text === '' ? '—' : \Illuminate\Support\Str::limit($text, 200);
    }

    protected function recordActions(): array
    {
        return [ActionGroup::make([
            ProposalActions::disclosureAnswers(), ProposalActions::attachDocument(), ProposalActions::answer(), ProposalActions::requestInformation(),
        ])->label(__('broker_screens_b.actions'))->icon('lucide-zap')->button()];
    }
}
