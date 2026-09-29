<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Models\Policy;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-067 Lapsed Policies (WF-083): policies of the book that lapsed, or expired in the last 180 days without a
 * successor policy (no policy with previous_policy_id = this one) — the win-back list — with the renewal case status.
 * Read-only: a lapsed policy is won back through a new quote / renewal (Renewals, Customers pages); there is no
 * "un-lapse" API.
 */
final class LapsedPoliciesPage extends PolicyScreen
{
    protected static string $key = 'lapsed_policies';

    protected static ?string $slug = 'lapsed-policies';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-calendar-x';

    protected static ?int $navigationSort = 75;

    protected function query(): Builder
    {
        $renewed = DB::table('policies as s')->whereNotNull('s.previous_policy_id')->select('s.previous_policy_id');

        return $this->policies()->where(fn ($w) => $w->where('policies.status', 'LAPSED')
            ->orWhere(fn ($x) => $x->where('policies.status', 'EXPIRED')->where('policies.coverage_ends_at', '>=', now()->subDays(180))))
            ->whereNotIn('policies.id', $renewed);
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('renewal')->label(self::col('renewal_case'))->badge()
                ->state(fn (Policy $record) => ($s = DB::table('renewal_cases')->where('policy_id', $record->id)->orderByDesc('created_at')->value('status')) ? \App\Filament\Shared\Columns::humanise($s) : '—'),
        ];
    }
}
