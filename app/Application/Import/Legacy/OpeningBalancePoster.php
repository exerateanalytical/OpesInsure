<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy;

use App\Application\Ledger\FinancialPostingService;
use App\Application\Ledger\Posting\AccountingEventMappingService;
use App\Models\FinancialPostingProfile;
use Illuminate\Support\Facades\DB;

/**
 * REQ-IMP-002 — migrated opening balances go to the ledger through FinancialPostingService, accounting event
 * migration.opening_balance. The migration / suspense clearing account is a chart-of-accounts decision owned by
 * finance: this class never provisions one. It posts only when the event is configured (an approved posting
 * profile or an active event mapping); otherwise the balance is reported CONFIG_REQUIRED and nothing is posted.
 * Idempotent per reference (FinancialPostingService dedupes on event + reference).
 */
final class OpeningBalancePoster
{
    public const EVENT = 'migration.opening_balance';

    public function __construct(private readonly FinancialPostingService $posting, private readonly AccountingEventMappingService $mappings) {}

    public function configured(string $tenantId, string $currency): bool
    {
        $profile = FinancialPostingProfile::where(['event_type' => self::EVENT, 'currency' => $currency, 'status' => 'APPROVED'])
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->exists();
        if ($profile) {
            return true;
        }
        try {
            return $this->mappings->resolve($tenantId, self::EVENT, $currency) !== null;
        } catch (\Throwable) {
            return false;   // mapping names an account missing from the chart: still a configuration gap
        }
    }

    /** @return array{status: 'POSTED'|'CONFIG_REQUIRED'|'NOTHING_TO_POST', journal_id?: string, event: string, amount_minor: int, currency: string} */
    public function post(string $tenantId, string $referenceId, int $amountMinor, string $currency, string $correlation): array
    {
        $out = ['event' => self::EVENT, 'amount_minor' => $amountMinor, 'currency' => $currency];
        if ($amountMinor <= 0) {
            return ['status' => 'NOTHING_TO_POST'] + $out;
        }
        if (! $this->configured($tenantId, $currency)) {
            return ['status' => 'CONFIG_REQUIRED'] + $out;
        }

        return ['status' => 'POSTED', 'journal_id' => $this->posting->post($tenantId, self::EVENT, $referenceId, $amountMinor, $currency, $correlation)] + $out;
    }

    public static function journalAmount(string $referenceId): ?int
    {
        $j = DB::table('journals')->where(['reference_type' => self::EVENT, 'reference_id' => $referenceId, 'status' => 'POSTED'])->value('id');

        return $j ? (int) DB::table('journal_lines')->where('journal_id', $j)->sum('debit_minor') : null;
    }
}
