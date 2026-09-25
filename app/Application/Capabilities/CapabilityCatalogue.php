<?php

declare(strict_types=1);

namespace App\Application\Capabilities;

/**
 * REQ-AOM-001 — capabilities and their modes (ADAPTIVE_OPERATING_MODEL_V1, MPS §3–5,
 * PRODUCT_RULE_ENGINE_IMPLEMENTATION_PLAN A2).
 *
 * Every capability accepts the four generic execution modes (MANUAL / CONFIGURED /
 * HYBRID / REMOTE_API, LOCK-002) plus the owner's specific modes, each of which maps to
 * one execution mode. POLICY_ISSUANCE and DOCUMENT_GENERATION are resolved from the
 * document engine's document_issuance_profiles (single source) — see CapabilityResolver.
 */
final class CapabilityCatalogue
{
    public const EXECUTION_MODES = ['MANUAL', 'CONFIGURED', 'HYBRID', 'REMOTE_API'];

    /** capability => [specific mode => execution mode] */
    public const CAPABILITIES = [
        'PRODUCT_CATALOGUE' => ['BASIC' => 'MANUAL', 'CARRIER_API' => 'REMOTE_API'],
        'QUOTATION' => [],
        'RATING' => ['MANUAL_PREMIUM' => 'MANUAL', 'UPLOADED_TARIFF' => 'CONFIGURED', 'EXCEL_IMPORT' => 'CONFIGURED', 'CONFIGURED_RULES' => 'CONFIGURED', 'CARRIER_API' => 'REMOTE_API'],
        'UNDERWRITING' => ['NOT_REQUIRED' => 'MANUAL', 'RULE_ENGINE' => 'CONFIGURED', 'REMOTE_INSURER' => 'REMOTE_API'],
        'PAYMENT' => ['BROKER_COLLECTION' => 'MANUAL', 'INSURER_COLLECTION' => 'MANUAL', 'MOBILE_MONEY' => 'CONFIGURED', 'BANK' => 'CONFIGURED', 'EXTERNAL_PROVIDER' => 'REMOTE_API'],
        'POLICY_ISSUANCE' => ['MANUAL_UPLOAD_CARRIER' => 'MANUAL', 'MANUAL_UPLOAD_BROKER' => 'MANUAL', 'OPES_GENERATED' => 'CONFIGURED', 'INSURER_API' => 'REMOTE_API'],
        'DOCUMENT_GENERATION' => ['CARRIER_ORIGINAL' => 'MANUAL', 'OPES_TEMPLATE' => 'CONFIGURED'],
        'ENDORSEMENT' => [],
        'RENEWAL' => [],
        'CLAIMS_INTAKE' => ['BROKER_ASSISTED' => 'MANUAL', 'INSURER_PORTAL' => 'MANUAL', 'MANUAL_CARRIER' => 'MANUAL', 'FULLY_DIGITAL' => 'CONFIGURED', 'API_SYNCHRONIZED' => 'REMOTE_API'],
        'CLAIMS_DECISION' => ['MANUAL_CARRIER' => 'MANUAL', 'OPES_WORKFLOW' => 'CONFIGURED', 'API_SYNCHRONIZED' => 'REMOTE_API'],
        'CLAIMS_SETTLEMENT' => ['MANUAL_CARRIER' => 'MANUAL', 'OPES_WORKFLOW' => 'CONFIGURED', 'API_SYNCHRONIZED' => 'REMOTE_API'],
        'COMMISSION' => ['MANUAL_STATEMENT' => 'MANUAL', 'IMPORTED' => 'MANUAL', 'CONFIGURED_RULES' => 'CONFIGURED'],
        'ACCOUNTING' => ['EXPORT_ONLY' => 'MANUAL', 'OPES_LEDGER' => 'CONFIGURED'],
        'PROVIDER_MANAGEMENT' => [],
        'REINSURANCE' => [],
        'REGULATORY_REPORTING' => ['CARRIER_SUPPLIED' => 'MANUAL', 'OPES_GENERATED' => 'CONFIGURED'],
        'API_INTEGRATION' => ['NONE' => 'MANUAL', 'SELECTED' => 'HYBRID', 'FULL' => 'REMOTE_API'],
    ];

    /** Derived from the document engine; an explicit row must agree with document_issuance_profiles. */
    public const DERIVED_FROM_ISSUANCE_PROFILE = ['POLICY_ISSUANCE', 'DOCUMENT_GENERATION'];

    /** document_issuance_profiles.issuance_mode => [capability => mode] */
    public const ISSUANCE_PROFILE_MAP = [
        'MANUAL_UPLOAD' => ['POLICY_ISSUANCE' => 'MANUAL_UPLOAD_CARRIER', 'DOCUMENT_GENERATION' => 'CARRIER_ORIGINAL'],
        'OPES_GENERATED' => ['POLICY_ISSUANCE' => 'OPES_GENERATED', 'DOCUMENT_GENERATION' => 'OPES_TEMPLATE'],
        'INSURER_API' => ['POLICY_ISSUANCE' => 'INSURER_API', 'DOCUMENT_GENERATION' => 'CARRIER_ORIGINAL'],
        'HYBRID' => ['POLICY_ISSUANCE' => 'HYBRID', 'DOCUMENT_GENERATION' => 'HYBRID'],
    ];

    public const MATURITY = [
        1 => 'LEVEL_1_REGISTRY', 2 => 'LEVEL_2_OPERATIONAL', 3 => 'LEVEL_3_CONFIGURED', 4 => 'LEVEL_4_CONNECTED', 5 => 'LEVEL_5_INTEGRATED',
    ];

    /** Capabilities whose modes must all be set for LEVEL_2_OPERATIONAL. */
    public const OPERATIONAL_CORE = ['QUOTATION', 'POLICY_ISSUANCE', 'RENEWAL', 'CLAIMS_INTAKE', 'COMMISSION'];

    /** Capabilities that must all be REMOTE_API for LEVEL_5_INTEGRATED. */
    public const INTEGRATED_CORE = ['QUOTATION', 'POLICY_ISSUANCE', 'CLAIMS_INTAKE', 'PAYMENT'];

    public static function has(string $capability): bool
    {
        return array_key_exists($capability, self::CAPABILITIES);
    }

    /** @return list<string> */
    public static function modes(string $capability): array
    {
        return array_values(array_unique([...self::EXECUTION_MODES, ...array_keys(self::CAPABILITIES[$capability] ?? [])]));
    }

    public static function executionMode(string $capability, string $mode): ?string
    {
        if (in_array($mode, self::EXECUTION_MODES, true)) {
            return $mode;
        }

        return self::CAPABILITIES[$capability][$mode] ?? null;
    }

    /** @return list<array{capability:string,modes:list<array{mode:string,execution_mode:string}>,derived_from_issuance_profile:bool}> */
    public static function describe(): array
    {
        $out = [];
        foreach (array_keys(self::CAPABILITIES) as $cap) {
            $out[] = [
                'capability' => $cap,
                'modes' => array_map(fn ($m) => ['mode' => $m, 'execution_mode' => self::executionMode($cap, $m)], self::modes($cap)),
                'derived_from_issuance_profile' => in_array($cap, self::DERIVED_FROM_ISSUANCE_PROFILE, true),
            ];
        }

        return $out;
    }
}
