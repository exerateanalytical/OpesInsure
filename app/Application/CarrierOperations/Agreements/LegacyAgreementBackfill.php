<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\Agreements;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-DUP-023 — delegated_authority_agreements (legacy, kept) → carrier_broker_agreements + products (distribution)
 * and authority_limits (authority). Idempotent: keyed on legacy id; re-running refreshes status/period of rows it
 * created and adds lines that appeared since. Called by the 2026_10_03_300001 migration and
 * `opesinsure:agreements:sync-legacy` (the legacy CarrierOperationsController still writes the old table).
 *
 * Premium collection was never granted by the legacy table, so backfilled lines keep can_collect_premium=false.
 */
final class LegacyAgreementBackfill
{
    /** @return array{created:int, refreshed:int} */
    public function run(): array
    {
        $created = $refreshed = 0;
        foreach (DB::table('delegated_authority_agreements')->orderBy('created_at')->get() as $da) {
            $territories = $da->territories ?: '[]';
            $id = DB::table('carrier_broker_agreements')->where('legacy_delegated_authority_agreement_id', $da->id)->value('id');
            $period = ['effective_from' => $da->effective_from, 'effective_until' => $da->effective_until, 'status' => $da->status,
                'territories' => $territories, 'approved_by' => $da->approved_by_carrier, 'approved_at' => $da->approved_at, 'updated_at' => now()];
            if ($id === null) {
                $id = (string) Str::uuid();
                DB::table('carrier_broker_agreements')->insert([...$period, 'id' => $id, 'legacy_delegated_authority_agreement_id' => $da->id,
                    'carrier_id' => $da->carrier_id, 'partner_id' => $da->partner_id, 'agreement_number' => $this->number($da->agreement_number, $da->id),
                    'channels' => '[]', 'data_origin' => 'PLATFORM_NORMALIZED', 'created_at' => $da->created_at ?? now()]);
                $created++;
            } else {
                DB::table('carrier_broker_agreements')->where('id', $id)->update($period);
                $refreshed++;
            }

            foreach ((array) json_decode((string) $da->permitted_lines, true) as $line) {
                $exists = DB::table('carrier_broker_agreement_products')->where(['agreement_id' => $id, 'line_code' => (string) $line])->whereNull('insurance_product_id')->exists();
                if (! $exists) {
                    DB::table('carrier_broker_agreement_products')->insert(['id' => (string) Str::uuid(), 'agreement_id' => $id, 'line_code' => (string) $line,
                        'can_quote' => true, 'can_bind' => true, 'can_collect_premium' => false, 'requires_carrier_approval' => false,
                        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            foreach (['POLICY_PREMIUM' => (int) $da->max_policy_premium_minor, 'CLAIM_PAYMENT' => (int) $da->max_claim_authority_minor] as $type => $amount) {
                if ($type === 'CLAIM_PAYMENT' && $amount <= 0) {
                    continue;
                }
                $values = ['carrier_broker_agreement_id' => $id, 'carrier_id' => $da->carrier_id, 'holder_type' => 'PARTNER', 'holder_id' => $da->partner_id,
                    'max_amount_minor' => $amount, 'currency' => 'XAF', 'territories' => $territories, 'effective_from' => $da->effective_from,
                    'effective_until' => $da->effective_until, 'status' => $da->status === 'ACTIVE' ? 'ACTIVE' : 'INACTIVE', 'updated_at' => now()];
                $key = ['legacy_delegated_authority_agreement_id' => $da->id, 'authority_type' => $type];
                if (DB::table('authority_limits')->where($key)->exists()) {
                    DB::table('authority_limits')->where($key)->update($values);
                } else {
                    DB::table('authority_limits')->insert([...$key, ...$values, 'id' => (string) Str::uuid(), 'created_at' => now()]);
                }
            }
        }

        return ['created' => $created, 'refreshed' => $refreshed];
    }

    /** Agreement numbers are unique in the new table too; a clash with a natively created agreement gets a LEGACY suffix. */
    private function number(string $number, string $legacyId): string
    {
        return DB::table('carrier_broker_agreements')->where('agreement_number', $number)->exists() ? $number.'-LEGACY-'.substr($legacyId, 0, 8) : $number;
    }
}
