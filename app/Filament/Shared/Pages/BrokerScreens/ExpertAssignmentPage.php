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
 * BRK-081 Expert Assignment (WF-053): open claims of the book in the assessment stages with their expert (loss
 * adjuster) assignment — none yet, requested, accepted, inspected, report submitted. Assign / cancel an expert run
 * ExpertAssignmentService (claims.experts.assign), as POST claims/{id}/assignments[...].
 */
final class ExpertAssignmentPage extends ClaimScreen
{
    protected static string $key = 'expert_assignment';

    protected static ?string $slug = 'claim-expert-assignment';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-hard-hat';

    protected static ?int $navigationSort = 92;

    protected function query(): Builder
    {
        return $this->claims(['SUBMITTED', 'ACKNOWLEDGED', 'EVIDENCE_PENDING', 'ASSESSMENT', 'CARRIER_REVIEW']);
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('expert')->label(self::col('expert'))->placeholder(__('broker_screens_b.expert_assignment.none'))->state(function (Claim $record): ?string {
                $a = DB::table('claim_assignments as a')->leftJoin('users as u', 'u.id', '=', 'a.assignee_id')->where('a.claim_id', $record->id)
                    ->where('a.assignment_type', 'EXPERT')->whereNotIn('a.status', ['CANCELLED', 'DECLINED'])->orderByDesc('a.assigned_at')->first(['u.full_name', 'a.status']);

                return $a === null ? null : trim(($a->full_name ?? '').' · '.\App\Filament\Shared\Columns::humanise($a->status), ' ·');
            }),
        ];
    }

    protected function recordActions(): array
    {
        return [self::group([ClaimCaseActions::expertAssign(), ClaimCaseActions::expertCancel()])];
    }
}
