<?php

declare(strict_types=1);

namespace App\Application\Ledger;

use Illuminate\Support\Facades\DB;

/**
 * Journal types from the Workflow Institutional Data Master v1 ("accounting").
 *
 * journals.reference_type and financial_posting_profiles.event_type keep
 * their meaning (the business event, e.g. PREMIUM_ISSUED, JOURNAL_REVERSAL).
 * A journal type classifies those events; the book is the existing
 * master-data finance.journal_type code. Chart of accounts, GL mapping and
 * cost centres are CONFIG_REQUIRED: tenants configure ledger_accounts and
 * approved posting profiles; nothing is seeded here.
 */
final class JournalTypeReference
{
    /** Journal type => [book (finance.journal_type), accounting.event_type codes it covers]. */
    public const TYPES = [
        'PREMIUM' => ['book' => 'SALES', 'events' => ['PREMIUM_ISSUED', 'PREMIUM_CANCELLED']],
        'PAYMENT' => ['book' => 'CASH_RECEIPTS', 'events' => ['PREMIUM_RECEIVED']],
        'COMMISSION' => ['book' => 'COMMISSIONS', 'events' => ['COMMISSION_EARNED', 'COMMISSION_PAID']],
        'CLAIM_RESERVE' => ['book' => 'CLAIMS', 'events' => ['CLAIM_RESERVE_OPENED', 'CLAIM_RESERVE_ADJUSTED']],
        'CLAIM_PAYMENT' => ['book' => 'CLAIMS', 'events' => ['CLAIM_PAID']],
        'REFUND' => ['book' => 'CASH_PAYMENTS', 'events' => ['REFUND_ISSUED']],
        'PROVIDER_PAYMENT' => ['book' => 'CASH_PAYMENTS', 'events' => []],
        'REINSURANCE' => ['book' => 'REINSURANCE', 'events' => ['REINSURANCE_CEDED', 'REINSURANCE_RECOVERY']],
        'COINSURANCE' => ['book' => 'GENERAL', 'events' => []],
        'RECOVERY' => ['book' => 'CLAIMS', 'events' => ['RECOVERY_RECEIVED']],
        'TAX' => ['book' => 'GENERAL', 'events' => ['TAX_COLLECTED', 'FEE_CHARGED']],
        'ADJUSTMENT' => ['book' => 'ADJUSTMENT', 'events' => ['WRITE_OFF', 'JOURNAL_REVERSAL']],
    ];

    public const CONFIG_STATUS = [
        'chart_of_accounts' => 'CONFIG_REQUIRED',
        'gl_mapping' => 'CONFIG_REQUIRED',
        'cost_centre' => 'CONFIG_REQUIRED',
    ];

    public static function isType(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::TYPES);
    }

    /** Stored reference/event type => journal type, or null when unclassified. */
    public static function typeForEvent(string $event): ?string
    {
        foreach (self::TYPES as $type => $map) {
            if (in_array($event, $map['events'], true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * CONFIG_REQUIRED readiness: a domain is configured once the tenant (or the
     * platform) has ledger accounts / approved posting profiles. Never seeded.
     *
     * @return array<string, string> domain => CONFIGURED | CONFIG_REQUIRED
     */
    public static function readiness(?string $tenantId): array
    {
        $scope = fn ($q) => $q->where(fn ($w) => $w->whereNull('tenant_id')->when($tenantId, fn ($x) => $x->orWhere('tenant_id', $tenantId)));
        $accounts = $scope(DB::table('ledger_accounts'))->exists();
        $profiles = $scope(DB::table('financial_posting_profiles'))->where('status', 'APPROVED')->exists();

        return [
            'chart_of_accounts' => $accounts ? 'CONFIGURED' : 'CONFIG_REQUIRED',
            'gl_mapping' => $profiles ? 'CONFIGURED' : 'CONFIG_REQUIRED',
            'cost_centre' => DB::table('master_data_values')->where(['domain_code' => 'finance', 'list_code' => 'cost_centre', 'status' => 'ACTIVE'])->where('code', '<>', 'OTHER')->exists() ? 'CONFIGURED' : 'CONFIG_REQUIRED',
        ];
    }
}
