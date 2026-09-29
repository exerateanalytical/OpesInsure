<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\{ClaimActions, ClaimCaseActions};
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-082 Assessment Review (WF-054): claims of the book with an assessment waiting for review (claim_assessments
 * SUBMITTED) or an expert report submitted (claim_assignments REPORT_SUBMITTED). Review the assessment
 * (ClaimActions::reviewAssessment) or accept / return the expert report (ClaimCaseActions::expertReview,
 * claims.experts.review), as the claim assessment API.
 */
final class AssessmentReviewPage extends ClaimScreen
{
    protected static string $key = 'assessment_review';

    protected static ?string $slug = 'claim-assessment-review';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-clipboard-pen-line';

    protected static ?int $navigationSort = 93;

    protected function query(): Builder
    {
        return $this->claims()->where(fn ($w) => $w->whereIn('claims.id', DB::table('claim_assessments')->where('status', 'SUBMITTED')->select('claim_id'))
            ->orWhereIn('claims.id', DB::table('claim_assignments')->where(['assignment_type' => 'EXPERT', 'status' => 'REPORT_SUBMITTED'])->select('claim_id')));
    }

    protected function recordActions(): array
    {
        return [self::group([ClaimActions::reviewAssessment(), ClaimCaseActions::expertReview()])];
    }
}
