<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\QuoteActions;
use App\Models\Quote;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-035 Premium Override Approval (WF-081): quotes of the book with a premium override awaiting a decision
 * (engine_overrides REQUESTED, linked from quote_offers.premium_override_id). Approve / reject and apply the approved
 * premium run QuotePremiumOverrideService::decide / applyEffective (quotes.premium_override.approve), the same service
 * as POST quotes/{q}/offers/{o}/premium-override/decide — maker-checker is enforced by the service.
 */
final class PremiumOverrideApprovalPage extends QuoteScreen
{
    protected static string $key = 'premium_overrides';

    protected static ?string $slug = 'premium-override-approval';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-sliders-horizontal';

    protected static ?int $navigationSort = 44;

    protected static array $readPermissions = ['quotes.read', 'quotes.premium_override.approve'];

    private static function pending(): \Illuminate\Database\Query\Builder
    {
        return DB::table('quote_offers')->whereIn('premium_override_id', DB::table('engine_overrides')->where('status', 'REQUESTED')->select('id'))->select('quote_id');
    }

    protected function query(): Builder
    {
        return $this->quotes()->whereIn('quotes.id', self::pending());
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('override')->label(self::col('override'))->wrap()->state(function (Quote $record): string {
                $o = DB::table('engine_overrides as e')->join('quote_offers as q', 'q.premium_override_id', '=', 'e.id')
                    ->where('q.quote_id', $record->id)->where('e.status', 'REQUESTED')->orderByDesc('e.created_at')
                    ->first(['e.previous_value', 'e.new_value', 'e.reason_code', 'q.currency']);

                return $o === null ? '—' : trim(self::amount($o->previous_value).' → '.self::amount($o->new_value).' '.($o->currency ?? 'XAF').' · '.$o->reason_code);
            }),
        ];
    }

    private static function amount(mixed $v): string
    {
        $v = is_string($v) ? (json_decode($v, true) ?? $v) : $v;
        $n = is_array($v) ? ($v['premium_minor'] ?? $v['value'] ?? reset($v)) : $v;

        return is_numeric($n) ? number_format((int) $n, 0, ',', ' ') : (string) $n;
    }

    protected function recordActions(): array
    {
        return [QuoteActions::decideOverride(), QuoteActions::applyOverride()];
    }
}
