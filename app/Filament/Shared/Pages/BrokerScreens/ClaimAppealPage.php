<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\{ClaimActions, ClaimCaseActions};
use App\Models\Claim;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-087 Claim Appeal (WF-062): claims of the book under dispute (claim_disputes OPEN, status DISPUTED) and declined /
 * partially approved claims that can still be appealed. Lodge the appeal (ClaimCaseActions::appeal), open or resolve the
 * dispute (ClaimActions::openDispute / resolveDispute, claims/{id}/disputes[...]) — the claim API's services and permissions.
 */
final class ClaimAppealPage extends ClaimScreen
{
    protected static string $key = 'claim_appeal';

    protected static ?string $slug = 'claim-appeals';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-megaphone';

    protected static ?int $navigationSort = 96;

    protected function query(): Builder
    {
        return $this->claims()->where(fn ($w) => $w->whereIn('claims.status', ['DISPUTED', 'DECLINED', 'PARTIALLY_APPROVED'])
            ->orWhereIn('claims.id', DB::table('claim_disputes')->where('status', 'OPEN')->select('claim_id')));
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('dispute')->label(self::col('dispute'))->wrap()->placeholder('—')->state(function (Claim $record): ?string {
                $d = DB::table('claim_disputes')->where(['claim_id' => $record->id, 'status' => 'OPEN'])->orderByDesc('created_at')->first(['reference', 'reason_code']);

                return $d === null ? null : trim(($d->reference ?? '').' · '.$d->reason_code, ' ·');
            }),
        ];
    }

    protected function recordActions(): array
    {
        return [self::group([ClaimCaseActions::appeal(), ClaimActions::openDispute(), ClaimActions::resolveDispute()])];
    }
}
