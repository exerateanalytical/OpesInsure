<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use App\Models\Policy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only source for the shared financial-breakdown component (canonical
 * handoff: gross premium, platform fee, processing fee, commission and
 * carrier settlement as DISTINCT labelled values). Only persisted figures are
 * shown; a line with no recorded source is omitted rather than estimated
 * (nothing is computed or invented here). Rating, fees, commission and
 * settlement logic stay in their own modules.
 */
final class FinancialBreakdownQuery
{
    /** @return array{lines: array<string, int>, currency: string, total: ?int}|null */
    public function for(Model $record): ?array
    {
        if (! $record instanceof Policy) {
            return null;
        }
        $currency = (string) ($record->currency ?: 'XAF');
        $lines = [];
        if ($record->premium_minor !== null) {
            $lines['gross_premium'] = (int) $record->premium_minor;
        }
        if (Schema::hasTable('commission_accruals')) {
            $commission = DB::table('commission_accruals')->where('policy_id', $record->getKey())->sum('amount_minor');
            if (DB::table('commission_accruals')->where('policy_id', $record->getKey())->exists()) {
                $lines['commission'] = (int) $commission;
            }
        }
        if (Schema::hasTable('settlement_items')) {
            $q = DB::table('settlement_items')->where('policy_id', $record->getKey());
            if ($q->exists()) {
                $lines['carrier_settlement'] = (int) $q->sum('net_due_minor');
            }
        }

        return $lines === [] ? null : ['lines' => $lines, 'currency' => $currency, 'total' => null];
    }
}
