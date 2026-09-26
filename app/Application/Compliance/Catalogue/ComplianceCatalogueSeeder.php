<?php

declare(strict_types=1);

namespace App\Application\Compliance\Catalogue;

use App\Application\DataReadiness\DataStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Idempotent seeding of Gap Closure Pack files 08 and 09. Inserts missing codes only: never overwrites an admin-edited,
 * imported or VERIFIED row, never deletes. Values the pack does not carry (refresh months, requirement text, report lines,
 * country ratings, PEP/sanctions lists) are NOT seeded: placeholder rows carry their gate status instead.
 */
final class ComplianceCatalogueSeeder
{
    public const SOURCE = 'GAP_CLOSURE_PACK_2026';

    public const DIR = 'database/data/gap_closure_2026';

    public static function pack(string $file): array
    {
        $f = $file === '08' ? '08_kyc_aml_pep_sanctions_fraud.json' : '09_regulatory_reporting_aml_ict_controls.json';

        return json_decode((string) file_get_contents(base_path(self::DIR.'/'.$f)), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int> inserted per table */
    public function run(): array
    {
        if (! Schema::hasTable('compliance_controls')) {
            return [];
        }
        $p8 = self::pack('08');
        $p9 = self::pack('09');
        $now = now();
        $n = ['kyc_document_matrix' => 0, 'kyc_refresh_policies' => 0, 'fraud_indicators' => 0, 'compliance_controls' => 0];

        $matrixStatus = (string) data_get($p8, 'kyc_document_matrix.status');
        foreach ((array) data_get($p8, 'kyc_document_matrix.baseline', []) as $type => $docs) {
            foreach ($docs as $doc) {
                if (! DB::table('kyc_document_matrix')->where(['customer_type' => $type, 'document_code' => $doc])->exists()) {
                    DB::table('kyc_document_matrix')->insert(['id' => (string) Str::uuid(), 'customer_type' => $type, 'document_code' => $doc,
                        'requirement_type' => 'REQUIRED', 'data_status' => DataStatus::UNVERIFIED, 'pack_status' => $matrixStatus, 'source' => self::SOURCE,
                        'source_reference' => '08_kyc_aml_pep_sanctions_fraud.json#kyc_document_matrix', 'effective_from' => '2026-09-25',
                        'created_at' => $now, 'updated_at' => $now]);
                    $n['kyc_document_matrix']++;
                }
            }
        }

        foreach ((array) ($p8['risk_levels'] ?? []) as $level) {
            if (! DB::table('kyc_refresh_policies')->whereNull('tenant_id')->where('risk_level', $level)->exists()) {
                DB::table('kyc_refresh_policies')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'risk_level' => $level, 'refresh_months' => null,
                    'trigger_events' => '[]', 'data_status' => DataStatus::CONFIG_REQUIRED, 'source' => self::SOURCE, 'created_at' => $now, 'updated_at' => $now]);
                $n['kyc_refresh_policies']++;
            }
        }

        foreach ((array) ($p8['fraud_indicators'] ?? []) as $i) {
            if (! DB::table('fraud_indicators')->where('code', $i['code'])->exists()) {
                DB::table('fraud_indicators')->insert(['id' => (string) Str::uuid(), 'code' => $i['code'], 'category' => $i['category'], 'severity' => $i['severity'],
                    'outcome' => 'REVIEW_REQUIRED', 'is_determination' => false, 'data_status' => DataStatus::PLATFORM_NORMALIZED, 'pack_status' => 'BASELINE_POPULATED',
                    'source' => self::SOURCE, 'created_at' => $now, 'updated_at' => $now]);
                $n['fraud_indicators']++;
            }
        }

        foreach (['AML' => $p9['aml_controls'] ?? [], 'ICT' => $p9['ict_controls'] ?? []] as $fw => $codes) {
            foreach ($codes as $code) {
                if (! DB::table('compliance_controls')->where(['framework' => $fw, 'control_code' => $code])->exists()) {
                    DB::table('compliance_controls')->insert(['id' => (string) Str::uuid(), 'framework' => $fw, 'control_code' => $code, 'domain' => $code,
                        'evidence_types' => '[]', 'data_status' => DataStatus::PENDING_SOURCE, 'pack_status' => 'CONTROL_DOMAINS_POPULATED',
                        'source' => self::SOURCE, 'source_reference' => null, 'created_at' => $now, 'updated_at' => $now]);
                    $n['compliance_controls']++;
                }
            }
        }

        return $n;
    }
}
