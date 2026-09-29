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
 * BRK-079 Coverage Review (WF-050): open claims of the book whose coverage is not confirmed yet — no coverage check run,
 * or a check that did not confirm cover and is unresolved. Run the check / resolve it with the claim coverage service
 * (claims.coverage.check / resolve), the same service as the claim coverage-check API.
 */
final class CoverageReviewPage extends ClaimScreen
{
    protected static string $key = 'coverage_review';

    protected static ?string $slug = 'claim-coverage-review';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-shield-question';

    protected static ?int $navigationSort = 90;

    protected function query(): Builder
    {
        $confirmed = DB::table('claim_coverage_checks')->where(fn ($w) => $w->where('outcome', 'COVERAGE_CONFIRMED')->orWhereNotNull('resolution'))->select('claim_id');

        return $this->claims()->whereNotIn('claims.status', [...self::CLOSED, 'DRAFT', 'APPROVED', 'PARTIALLY_APPROVED'])->whereNotIn('claims.id', $confirmed);
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('coverage')->label(self::col('coverage_outcome'))->badge()->state(fn (Claim $record) => ($o = DB::table('claim_coverage_checks')
                ->where('claim_id', $record->id)->orderByDesc('checked_at')->value('outcome')) ? \App\Filament\Shared\Columns::humanise($o) : __('broker_screens_b.coverage_review.not_checked')),
        ];
    }

    protected function recordActions(): array
    {
        return [self::group([ClaimCaseActions::coverageCheck(), ClaimCaseActions::coverageResolve()])];
    }
}
