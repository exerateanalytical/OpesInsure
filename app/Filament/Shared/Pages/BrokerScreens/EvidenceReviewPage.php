<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\ClaimCaseActions;
use App\Models\Claim;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-080 Evidence Review (WF-052): claims of the book waiting for evidence (EVIDENCE_PENDING) or with submitted
 * evidence not yet verified (claim_documents SUBMITTED). Attach / verify / review evidence run ClaimEvidenceService /
 * ClaimEvidenceReviewService (claims.evidence.manage / claims.evidence.verify), as POST claims/{id}/evidence[...].
 */
final class EvidenceReviewPage extends ClaimScreen
{
    protected static string $key = 'evidence_review';

    protected static ?string $slug = 'claim-evidence-review';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-search';

    protected static ?int $navigationSort = 91;

    protected function query(): Builder
    {
        return $this->claims()->whereNotIn('claims.status', self::CLOSED)
            ->where(fn ($w) => $w->where('claims.status', 'EVIDENCE_PENDING')->orWhereIn('claims.id', DB::table('claim_documents')->where('status', 'SUBMITTED')->select('claim_id')));
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('evidence')->label(self::col('evidence'))->state(function (Claim $record): string {
                $rows = DB::table('claim_documents')->where('claim_id', $record->id)->pluck('status');

                return __('broker_screens_b.evidence_review.counts', ['submitted' => $rows->filter(fn ($s) => $s === 'SUBMITTED')->count(), 'total' => $rows->count()]);
            }),
        ];
    }

    protected function recordActions(): array
    {
        return [self::group([ClaimCaseActions::evidenceAttach(), ClaimCaseActions::evidenceVerify(), ClaimCaseActions::evidenceReview()])];
    }
}
