<?php

declare(strict_types=1);

namespace App\Application\Finance\ReferenceMasters;

use App\Application\DataReadiness\DataStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent GP6 — vocabulary + idempotent seed of gap closure pack 06 (database/data/gap_closure_2026/06_banking_payments_accounting_gl.json).
 * Only VERIFIED_PUBLIC_SOURCE / PLATFORM_NORMALIZED values are production values. Historical BEAC bank names are seeded with
 * PENDING_OFFICIAL_IMPORT (the current official list is a gate); no bank code, BIC, account or GL rule is invented.
 */
final class FinanceReferenceCatalogue
{
    public const FILE = 'database/data/gap_closure_2026/06_banking_payments_accounting_gl.json';

    public const SOURCE = 'GAP_CLOSURE_06_BANKING_PAYMENTS_GL';

    public const GAP_STATUSES = ['VERIFIED_PUBLIC_SOURCE', 'PLATFORM_NORMALIZED', 'CONFIG_REQUIRED', 'PENDING_PRIVATE_SOURCE', 'PENDING_OFFICIAL_IMPORT', 'PENDING_VERIFICATION', 'RETIRED'];

    /** Gap-pack status => DataStatus vocabulary (only the first two are production values). */
    public const STATUS_MAP = [
        'VERIFIED_PUBLIC_SOURCE' => DataStatus::VERIFIED, 'PLATFORM_NORMALIZED' => DataStatus::PLATFORM_NORMALIZED, 'CONFIG_REQUIRED' => DataStatus::CONFIG_REQUIRED,
        'PENDING_PRIVATE_SOURCE' => DataStatus::PENDING_SOURCE, 'PENDING_OFFICIAL_IMPORT' => DataStatus::PENDING_SOURCE, 'PENDING_VERIFICATION' => DataStatus::UNVERIFIED,
        'RETIRED' => DataStatus::RETIRED,
    ];

    public const PRODUCTION_STATUSES = ['VERIFIED_PUBLIC_SOURCE', 'PLATFORM_NORMALIZED'];

    public const INSTITUTION_TYPES = ['BANK', 'PAYMENT_INSTITUTION', 'MICROFINANCE'];

    /** payment_institutions.provider_profiles => existing payment method (PaymentMethodReference) and adapters allowed. */
    public const PROVIDER_TYPES = ['MTN_MOMO', 'ORANGE_MONEY', 'BANK_TRANSFER', 'CARD_GATEWAY', 'CASH', 'CHEQUE', 'DIRECT_DEBIT'];

    public const PROVIDER_METHOD = ['MTN_MOMO' => 'MTN_MOMO', 'ORANGE_MONEY' => 'ORANGE_MONEY', 'BANK_TRANSFER' => 'BANK_TRANSFER', 'CARD_GATEWAY' => 'CARD',
        'CASH' => 'CASH', 'CHEQUE' => 'CHEQUE', 'DIRECT_DEBIT' => 'DIRECT_DEBIT'];

    /** Electronic provider types that need a merchant + settlement configuration before they can be activated. */
    public const ELECTRONIC = ['MTN_MOMO', 'ORANGE_MONEY', 'BANK_TRANSFER', 'CARD_GATEWAY', 'DIRECT_DEBIT'];

    public const SETTLEMENT_CYCLES = ['REAL_TIME', 'T_PLUS_0', 'T_PLUS_1', 'T_PLUS_2', 'WEEKLY', 'MONTHLY', 'MANUAL'];

    /** Keys refused anywhere in a profile's JSON (secrets_policy: credentials live in the secrets manager). */
    public const SECRET_KEYS = ['secret', 'password', 'api_key', 'apikey', 'token', 'private_key', 'client_secret', 'credential'];

    /**
     * chart_of_accounts_templates.control_accounts => platform baseline code in DefaultChartOfAccounts (the chart the sub-ledger
     * already uses). null = the default chart has no account for it: CONFIG_REQUIRED, the tenant must map it.
     */
    public const CONTROL_ACCOUNTS = [
        'CASH_AND_BANK' => '521000', 'CUSTOMER_RECEIVABLES' => '411000', 'BROKER_RECEIVABLES' => '411100', 'INSURER_PAYABLES' => '401100',
        'COMMISSION_RECEIVABLES' => '412000', 'COMMISSION_PAYABLES' => '421000', 'PREMIUM_INCOME' => '702000', 'UNEARNED_PREMIUM' => null,
        'CLAIMS_EXPENSE' => '601000', 'CLAIMS_PAYABLE' => '481000', 'CLAIM_RESERVES' => '481500', 'REINSURANCE_PAYABLE' => '401200',
        'REINSURANCE_RECEIVABLE' => '416000', 'PROVIDER_PAYABLE' => '481000', 'TAX_PAYABLE' => '443000', 'LEVY_PAYABLE' => '445000',
        'REFUND_PAYABLE' => '419000', 'SUSPENSE' => '471000', 'SETTLEMENT_CLEARING' => '581000',
    ];

    /**
     * event_to_gl_mapping.events => catalogued accounting event (accounting_events / AccountingEventMappingService). The mapping
     * itself stays in accounting_event_mappings (no parallel table); null = no accounting event yet (CONFIG_REQUIRED).
     */
    public const GL_EVENTS = [
        'PREMIUM_BILLED' => 'finance.obligation.created', 'PREMIUM_COLLECTED' => 'payment.succeeded', 'PREMIUM_REMITTED' => 'premium.remittance.allocated',
        'COMMISSION_ACCRUED' => 'commission.accrued', 'COMMISSION_PAID' => 'commission.paid', 'COMMISSION_CLAWED_BACK' => 'commission.clawed_back',
        'CLAIM_RESERVED' => 'claim.reserve.changed', 'CLAIM_PAID' => 'claim.settlement.paid', 'REFUND_APPROVED' => 'refund.approved', 'REFUND_PAID' => 'refund.paid',
        'PROVIDER_PAYABLE_RECOGNIZED' => 'health.provider_claim.approved', 'PROVIDER_PAID' => 'health.provider_claim.paid',
        'REINSURANCE_PREMIUM_CEDED' => 'reinsurance.policy.ceded', 'REINSURANCE_RECOVERY_RECOGNIZED' => 'reinsurance.recovery.billed',
        'TAX_RECOGNIZED' => 'premium.tax.assessed', 'LEVY_RECOGNIZED' => null, 'PAYMENT_REVERSED' => null, 'CREDIT_NOTE' => null, 'DEBIT_NOTE' => null,
    ];

    public static function data(): array
    {
        return json_decode((string) file_get_contents(base_path(self::FILE)), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function toDataStatus(?string $gap): string
    {
        return self::STATUS_MAP[strtoupper((string) $gap)] ?? DataStatus::UNVERIFIED;
    }

    public static function institutionCode(string $type, string $name): string
    {
        return ($type === 'BANK' ? 'BANK_' : 'PI_').strtoupper(Str::slug(Str::ascii($name), '_'));
    }

    /** Idempotent: inserts missing rows; never overwrites an admin-edited or already VERIFIED row, never deletes. */
    public static function seed(): void
    {
        if (! Schema::hasTable('financial_institutions')) {
            return;
        }
        $d = self::data();
        $now = now();
        $version = substr((string) ($d['meta']['generated_at'] ?? ''), 0, 10);
        $rows = [];
        foreach ((array) ($d['banks']['historical_beac_reference'] ?? []) as $name) {
            $rows[] = ['BANK', $name, null, 'PENDING_OFFICIAL_IMPORT', $d['sources']['BEAC_CREDIT_INSTITUTIONS'] ?? null];
        }
        foreach ((array) ($d['payment_institutions']['verified_public_source_records'] ?? []) as $r) {
            $rows[] = ['PAYMENT_INSTITUTION', $r['name'], $r['city'] ?? null, $r['status'], $d['sources']['DGTCFM_PAYMENT_INSTITUTIONS'] ?? null];
        }
        foreach ($rows as [$type, $name, $city, $status, $url]) {
            $code = self::institutionCode($type, $name);
            $existing = DB::table('financial_institutions')->where('code', $code)->first();
            if ($existing) {
                continue; // existing rows (admin-edited, verified or imported) are never overwritten
            }
            DB::table('financial_institutions')->insert(['id' => (string) Str::uuid(), 'code' => $code, 'institution_type' => $type, 'legal_name' => $name,
                'head_office_city' => $city, 'source' => self::SOURCE, 'source_url' => $url, 'verification_status' => $status, 'dataset_version' => $version,
                'aliases' => json_encode(str_contains($name, ' / ') ? array_map('trim', explode(' / ', $name)) : []), 'created_at' => $now, 'updated_at' => $now]);
        }

        if (Schema::hasTable('gl_control_account_mappings')) {
            foreach (self::CONTROL_ACCOUNTS as $control => $code) {
                if (DB::table('gl_control_account_mappings')->whereNull('tenant_id')->where('control_code', $control)->exists()) {
                    continue;
                }
                DB::table('gl_control_account_mappings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'control_code' => $control,
                    'ledger_account_code' => $code, 'version' => 1, 'status' => 'APPROVED', 'data_status' => $code ? 'PLATFORM_NORMALIZED' : 'CONFIG_REQUIRED',
                    'source' => self::SOURCE, 'reason' => $code ? 'Platform baseline (DefaultChartOfAccounts); tenant mapping required' : 'No baseline account; tenant mapping required',
                    'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
