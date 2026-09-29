<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\ClaimActions;
use App\Models\Claim;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-088 Claim Reopening (WF-060): claims of the book closed in the last 12 months and claims with a reopen request
 * pending approval. Request / decide the reopening run the claim closure service (ClaimActions::requestReopen /
 * decideReopen), with the same permissions as the claim reopen-request API.
 */
final class ClaimReopeningPage extends ClaimScreen
{
    protected static string $key = 'claim_reopening';

    protected static ?string $slug = 'claim-reopening';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-lock-open';

    protected static ?int $navigationSort = 97;

    protected function query(): Builder
    {
        return $this->claims()->where(fn ($w) => $w
            ->where(fn ($x) => $x->where('claims.status', 'CLOSED')->where(fn ($y) => $y->whereNull('claims.closed_at')->orWhere('claims.closed_at', '>=', now()->subYear())))
            ->orWhereIn('claims.id', DB::table('claim_reopen_requests')->where('status', 'PENDING_APPROVAL')->select('claim_id')));
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            self::date('closed_at', 'closed_at'),
            TextColumn::make('reopen')->label(self::col('reopen_request'))->badge()->placeholder('—')
                ->state(fn (Claim $record) => ($s = DB::table('claim_reopen_requests')->where('claim_id', $record->id)->orderByDesc('created_at')->value('status')) ? \App\Filament\Shared\Columns::humanise($s) : null),
        ];
    }

    protected function recordActions(): array
    {
        return [self::group([ClaimActions::requestReopen(), ClaimActions::decideReopen()])];
    }
}
