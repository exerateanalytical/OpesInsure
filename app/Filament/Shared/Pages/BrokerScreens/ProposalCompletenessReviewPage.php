<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\{InsuranceCheckActions, ProposalActions};
use App\Models\Proposal;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-039 Proposal Completeness Review (WF-016, WF-019): proposals of the book that are not yet complete — disclosures
 * or documents pending, or a document attached but not yet verified / rejected. Actions are the proposal API's:
 * answer disclosures, attest, attach a document (POST proposals/{p}/documents), review a document
 * (POST proposals/{p}/documents/{d}/review, documents.review) and submit; the completeness check is the rule engine's
 * (InsuranceCheckActions::checkCompleteness, rules.evaluate).
 */
final class ProposalCompletenessReviewPage extends ProposalScreen
{
    protected static string $key = 'proposal_completeness';

    protected static ?string $slug = 'proposal-completeness-review';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-clipboard-list';

    protected static ?int $navigationSort = 46;

    protected function query(): Builder
    {
        $docs = DB::table('proposal_documents')->whereNotIn('status', ['VERIFIED'])->select('proposal_id');

        return $this->proposals()->where(fn ($w) => $w->whereIn('proposals.status', ['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING'])
            ->orWhere(fn ($x) => $x->whereIn('proposals.id', $docs)->whereNotIn('proposals.status', ['DECLINED', 'WITHDRAWN', 'APPROVED', 'PAYMENT_PENDING'])));
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('documents')->label(self::col('documents'))->state(function (Proposal $record): string {
                $rows = DB::table('proposal_documents')->where('proposal_id', $record->id)->pluck('status');

                return $rows->isEmpty() ? '—' : __('broker_screens_b.proposal_completeness.docs', [
                    'verified' => $rows->filter(fn ($s) => $s === 'VERIFIED')->count(), 'total' => $rows->count()]);
            }),
        ];
    }

    protected function headerActions(): array
    {
        return [InsuranceCheckActions::checkCompleteness()];
    }

    protected function recordActions(): array
    {
        return [ActionGroup::make([
            ProposalActions::disclosureAnswers(), ProposalActions::attest(), ProposalActions::attachDocument(),
            ProposalActions::reviewDocument(), ProposalActions::submit(),
        ])->label(__('broker_screens_b.actions'))->icon('lucide-zap')->button()];
    }
}
