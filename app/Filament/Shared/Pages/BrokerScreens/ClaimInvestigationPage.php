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
 * BRK-083 Claim Investigation (WF-055, WF-089): claims of the book under investigation (claim_investigations OPEN).
 * Findings, indicators and conclusion run ClaimInvestigationService (claims.investigation.manage / .conclude), as
 * POST claims/{id}/investigations[...]; an investigation is opened from the claim page.
 */
final class ClaimInvestigationPage extends ClaimScreen
{
    protected static string $key = 'claim_investigation';

    protected static ?string $slug = 'claim-investigations';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-search';

    protected static ?int $navigationSort = 94;

    protected function query(): Builder
    {
        return $this->claims()->whereIn('claims.id', DB::table('claim_investigations')->where('status', 'OPEN')->select('claim_id'));
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('investigation')->label(self::col('reason'))->wrap()->state(function (Claim $record): string {
                $i = DB::table('claim_investigations')->where(['claim_id' => $record->id, 'status' => 'OPEN'])->orderByDesc('created_at')->first(['reason_code', 'reason']);

                return $i === null ? '—' : trim($i->reason_code.' '.\Illuminate\Support\Str::limit((string) $i->reason, 120));
            }),
        ];
    }

    protected function recordActions(): array
    {
        return [self::group([ClaimCaseActions::investigationFindings(), ClaimCaseActions::investigationIndicators(), ClaimCaseActions::investigationConclude()])];
    }
}
