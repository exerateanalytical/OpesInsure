<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

/**
 * Engine view of the single document catalogue (CatalogueSource: the
 * registry's document_types table, its seed JSON before seeding). Type ids
 * are the catalogue's type_id (DOC-###, CLM-##, FIN-##, EVD-###).
 *
 * The catalogue is authoritative for names, origin, evidence flag, security
 * level, numbering family and verifiability. This class only derives what the
 * engine needs on top: the app display group (tabs), and whether the engine
 * GENERATEs a type or only LINKs an input/evidence document (customer and
 * third-party documents are never insurer-issued). The owner's 220-type
 * register JSON is an import source of the catalogue only — never read here
 * (REQ-DUP-004: one definition source).
 */
final class DocumentRegister
{
    public const DISPLAY_GROUPS = ['POLICY_PACK', 'CERTIFICATES', 'SERVICING', 'CLAIMS', 'FINANCIAL'];

    public const SECURITY_LEVELS = ['PUBLIC_VERIFIABLE', 'CUSTOMER_PRIVATE', 'INSURER_CONFIDENTIAL', 'MEDICAL_RESTRICTED', 'FINANCIAL_RESTRICTED', 'INTERNAL', 'REGULATORY'];

    public const ORIGINS = ['INSURER', 'BROKER', 'CUSTOMER', 'PROVIDER', 'GARAGE', 'ADJUSTER', 'SURVEYOR', 'AUTHORITY', 'BANK', 'REGULATOR', 'REINSURER', 'SYSTEM'];

    /** Origins that are an issuer's official document (everything else is evidence / third-party input). */
    public const ISSUED_ORIGINS = ['INSURER', 'BROKER', 'SYSTEM'];

    public const STATUSES = ['DRAFT', 'GENERATED', 'PENDING_SIGNATURE', 'ISSUED', 'VALID', 'SUPERSEDED', 'REPLACED', 'REVOKED', 'EXPIRED', 'CANCELLED'];

    public const CURRENT_STATUSES = ['GENERATED', 'PENDING_SIGNATURE', 'ISSUED', 'VALID'];

    public const HISTORY_STATUSES = ['SUPERSEDED', 'REPLACED', 'REVOKED', 'EXPIRED', 'CANCELLED'];

    /** Fallback heuristic for inputs when the catalogue gives no origin. */
    private const INPUT_PATTERN = '/(QUESTIONNAIRE|DECLARATION|_FORM|CENSUS|_APPLICATION|INSURANCE_PROPOSAL|LIFE_PROPOSAL|RISK_SURVEY|INSURANCE_QUOTE|QUOTE_COMPARISON|BENEFICIARY_NOMINATION|ENDORSEMENT_REQUEST|CANCELLATION_REQUEST|SURRENDER_REQUEST|BENEFICIARY_CHANGE_REQUEST|PREAUTHORIZATION_REQUEST)$/';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $types = null;

    public function __construct(private CatalogueSource $catalogue) {}

    public static function path(): string
    {
        return database_path('data/document_catalogue_2026.json');
    }

    /** @return array<string, array<string, mixed>> keyed by canonical code */
    public function types(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }
        // REQ-DUP-004: the catalogue (tables, or its own seed file) is the only definition source.
        $types = $this->catalogue->types();

        return $this->types = array_map(fn (array $t) => $this->derive($t), $types);
    }

    /** @return array<string, mixed>|null */
    public function type(string $code): ?array
    {
        $types = $this->types();
        if (! isset($types[$code]) && ($canonical = $this->catalogue->canonicalCodeFor($code))) {
            return $types[$canonical] ?? null; // alias or type_id -> canonical (DocumentCatalogueService::typeFor)
        }

        return $types[$code] ?? null;
    }

    /** @return array<string, mixed> never null: unknown codes get a generic descriptor */
    public function describe(string $code): array
    {
        return $this->type($code) ?? $this->derive(['code' => $code, 'id' => null, 'name_en' => ucwords(strtolower(str_replace('_', ' ', $code))), 'name_fr' => ucwords(strtolower(str_replace('_', ' ', $code))), 'group_code' => 'CONTRACT', 'family' => null]);
    }

    /** @return array<string, mixed> */
    private function derive(array $t): array
    {
        $code = $t['code'];
        $group = (string) ($t['group_code'] ?? 'CONTRACT');
        $display = $this->displayGroup($code, $group, $t['category'] ?? null);
        $security = $t['security_level'] ?? ($display === 'CERTIFICATES' ? 'PUBLIC_VERIFIABLE' : ($display === 'FINANCIAL' ? 'FINANCIAL_RESTRICTED' : 'CUSTOMER_PRIVATE'));
        $origin = $t['origin'] ?? null;
        $input = ($t['is_evidence'] ?? false)
            || ($origin !== null && ! in_array($origin, self::ISSUED_ORIGINS, true))
            || ($origin === null && (bool) preg_match(self::INPUT_PATTERN, $code));

        return array_filter($t, fn ($v) => $v !== null) + [
            'display_group' => $display,
            'security_level' => $security,
            'verifiable' => $t['verifiable'] ?? ($security === 'PUBLIC_VERIFIABLE'),
            'numbering_family' => $t['numbering_family'] ?? $this->family($code, $group, $display),
            'input_document' => $input,
            'life_specific' => $group === 'LIFE',
            'family' => $t['family'] ?? null,
        ];
    }

    private function displayGroup(string $code, string $group, ?string $category): string
    {
        return match (true) {
            in_array($group, ['CLAIMS', 'CLAIM'], true) || in_array($category, ['CLAIM', 'CLAIMS'], true) || str_starts_with($code, 'CLAIM_') || in_array($code, ['SETTLEMENT_OFFER', 'SETTLEMENT_ACCEPTANCE', 'DISCHARGE', 'PARTIAL_APPROVAL_NOTICE', 'EXPERT_APPOINTMENT', 'INSPECTION_APPOINTMENT', 'REPAIR_AUTHORIZATION', 'SUBROGATION_NOTICE', 'RECOVERY_DEMAND', 'TOTAL_LOSS_DECLARATION', 'SALVAGE_DISPOSAL_AUTHORIZATION', 'APPEAL_ACKNOWLEDGEMENT', 'APPEAL_DECISION', 'ADDITIONAL_EVIDENCE_REQUEST'], true) => 'CLAIMS',
            in_array($group, ['FINANCE', 'FINANCIAL'], true) || in_array($category, ['FINANCE', 'FINANCIAL'], true) || (bool) preg_match('/(PREMIUM_SCHEDULE|PAYMENT_SCHEDULE|CONTRIBUTION_SCHEDULE|PREMIUM_STATEMENT|_RECEIPT|_INVOICE|DEBIT_NOTE|CREDIT_NOTE)$/', $code) => 'FINANCIAL',
            (bool) preg_match('/(CANCELLATION|TERMINATION|REPLACEMENT|REINSTATEMENT)/', $code) => 'SERVICING',
            (bool) preg_match('/(ATTESTATION|CERTIFICATE|_CARD|COVER_NOTE|PROOF_OF_COVER)$/', $code) => 'CERTIFICATES',
            $group === 'SERVICING_RENEWAL' || str_ends_with($code, '_ENDORSEMENT') || $code === 'POLICY_ENDORSEMENT' || (bool) preg_match('/^(RENEWAL_|REVISED_|SURRENDER|POLICY_ADVANCE|MATURITY|BENEFICIARY_CHANGE|ANNUAL_LIFE|SAVINGS_VALUE|ANNUITY)/', $code) => 'SERVICING',
            default => 'POLICY_PACK',
        };
    }

    private function family(string $code, string $group, string $display): string
    {
        return match (true) {
            in_array($code, ['INSURANCE_POLICY', 'MASTER_GROUP_POLICY', 'MARINE_CARGO_POLICY', 'OPEN_COVER_AGREEMENT', 'DUPLICATE_POLICY'], true) => 'POL',
            in_array($code, ['MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE', 'PROVISIONAL_MOTOR_ATTESTATION', 'FLEET_CERTIFICATE', 'MOTOR_CERTIFICATE_REPLACEMENT'], true) => 'ATT-MOT',
            in_array($code, ['POLICY_ENDORSEMENT', 'REVISED_POLICY_SCHEDULE'], true) || str_ends_with($code, '_ENDORSEMENT') => 'AVN',
            in_array($code, ['PREMIUM_RECEIPT', 'PAYMENT_RECEIPT'], true) => 'RCT',
            in_array($code, ['SETTLEMENT_OFFER', 'SETTLEMENT_ACCEPTANCE', 'DISCHARGE', 'CLAIM_PAYMENT_ADVICE', 'CLAIM_CLOSURE_NOTICE', 'CLAIM_SETTLEMENT_STATEMENT'], true) => 'SET',
            $display === 'CLAIMS' => 'CLM',
            $display === 'CERTIFICATES' => 'CRT',
            default => 'DOC',
        };
    }
}
