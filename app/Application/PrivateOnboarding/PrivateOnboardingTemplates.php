<?php

declare(strict_types=1);

namespace App\Application\PrivateOnboarding;

use Illuminate\Validation\ValidationException;

/**
 * Gap Closure Pack v1 file 11 (database/data/gap_closure_2026/11_private_tenant_onboarding_templates.json): the eight
 * private datasets an insurer / broker / tenant must supply before the related workflows may go live. The pack file is the
 * catalogue (read, never copied). Rows come in through the generic ImportPipeline (target `private_onboarding`) and are
 * staged in tenant_onboarding_records with status PENDING_PRIVATE_SOURCE until reviewed; nothing here enables a workflow —
 * the owning domain (agreements, catalogue, commission, provider tariffs, ledger, reinsurance, signatures, calendars) still
 * promotes the data through its own maker-checker service.
 */
final class PrivateOnboardingTemplates
{
    public const FILE = 'database/data/gap_closure_2026/11_private_tenant_onboarding_templates.json';

    public const SOURCE = 'GAP_CLOSURE_PACK_2026';

    /**
     * dataset => [organization types it applies to, key field, canonical table (promotion target), setup checklist item codes
     * (insurer / broker) it evidences, Data Readiness domain].
     */
    public const WIRING = [
        'BROKER_INSURER_AGREEMENTS' => [['INSURER', 'BROKER'], 'agreement_id', 'carrier_broker_agreements', ['INSURER' => 'BROKER_AGREEMENTS', 'BROKER' => 'CARRIER_PORTFOLIO'], 'broker_insurer_agreements'],
        'INSURER_PRODUCT_CATALOGUE' => [['INSURER'], 'product_id', 'insurance_products', ['INSURER' => 'PRODUCTS'], 'organization_capabilities'],
        'COMMISSION_TABLES' => [['INSURER', 'BROKER'], 'rule_id', 'commission_rule_versions', ['INSURER' => 'COMMISSION', 'BROKER' => 'COMMISSION_SPLIT'], 'commission'],
        'PROVIDER_TARIFFS' => [['INSURER'], 'tariff_id', 'provider_tariff_versions', ['INSURER' => 'CLAIMS_RULES'], 'health'],
        'GL_MAPPINGS' => [['INSURER', 'BROKER', 'TENANT'], 'event_code', 'financial_posting_profiles', ['INSURER' => 'ACCOUNTING', 'BROKER' => 'ACCOUNTING'], 'accounting'],
        'TREATIES' => [['INSURER'], 'treaty_id', 'reinsurance_treaties', ['INSURER' => 'COMPLIANCE'], 'reinsurance_coinsurance'],
        'SIGNATORY_MANDATES' => [['INSURER', 'BROKER'], 'authority_id', 'signatory_authorities', ['INSURER' => 'DOCUMENTS', 'BROKER' => 'BROKER_DOCUMENTS'], 'documents'],
        'BUSINESS_HOURS' => [['INSURER', 'BROKER', 'TENANT'], 'calendar_id', 'calendar_business_hours', ['INSURER' => 'ORGANIZATION_STRUCTURE', 'BROKER' => 'BRANCHES'], 'sla_calendars'],
    ];

    public const ORGANIZATION_TYPES = ['INSURER', 'BROKER', 'TENANT'];

    private static ?array $pack = null;

    public static function pack(): array
    {
        return self::$pack ??= json_decode((string) file_get_contents(base_path(self::FILE)), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{dataset: string, owner: string, required_import_fields: list<string>, production_gate: string}> */
    public static function datasets(): array
    {
        $out = [];
        foreach ((array) (self::pack()['private_datasets'] ?? []) as $d) {
            $out[$d['dataset']] = $d;
        }

        return $out;
    }

    public static function dataset(string $code): array
    {
        $d = self::datasets()[$code] ?? null;
        if ($d === null) {
            throw ValidationException::withMessages(['dataset' => "Unknown private onboarding dataset {$code}."]);
        }

        return $d;
    }

    public static function keyField(string $dataset): string
    {
        return self::WIRING[$dataset][1] ?? self::dataset($dataset)['required_import_fields'][0];
    }

    /** Import template: the header row (the pack's required_import_fields) as CSV. */
    public static function csvTemplate(string $dataset): string
    {
        return implode(',', self::dataset($dataset)['required_import_fields'])."\n";
    }

    /** @return list<array<string, mixed>> the catalogue as served by the API */
    public static function catalogue(): array
    {
        return array_values(array_map(fn (array $d) => $d + [
            'organization_types' => self::WIRING[$d['dataset']][0] ?? self::ORGANIZATION_TYPES,
            'key_field' => self::keyField($d['dataset']),
            'promotion_table' => self::WIRING[$d['dataset']][2] ?? null,
            'checklist_items' => self::WIRING[$d['dataset']][3] ?? [],
            'readiness_domain' => self::WIRING[$d['dataset']][4] ?? null,
            'data_status' => 'PENDING_PRIVATE_SOURCE',
            'import_target' => 'private_onboarding',
        ], self::datasets()));
    }
}
