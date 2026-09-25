<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use App\Models\InsuranceProduct;
use App\Models\Policy;

/**
 * Engine rule: insurance class → product → lifecycle trigger → requirement →
 * template → generated document.
 *
 * Packs and requirement levels come from the single catalogue
 * (CatalogueSource: document_packs / document_pack_items; insurer
 * MATRIX_OVERRIDE rows of product_document_requirements). This class only
 * maps an engine class + lifecycle trigger to catalogue pack codes and
 * decides per item: GENERATE (insurer/broker/platform-issued, REQUIRED or
 * explicitly included), CONDITIONAL (not triggered), or LINK (customer /
 * third-party input, internal).
 *
 * Trigger-level sets the catalogue does not model (claim decision
 * sub-events, cancellation, reinstatement, payment) are listed here as
 * catalogue document codes only.
 *
 * Per-subject: FLEET_VEHICLE / group MEMBER / cargo SHIPMENT packs expand per
 * subject; motor attestation + certificate are always per vehicle.
 */
final class DocumentPackResolver
{
    public const TRIGGERS = ['QUOTE_GENERATED', 'POLICY_ISSUED', 'PAYMENT_RECONCILED', 'RENEWAL_ISSUED', 'ENDORSEMENT_ISSUED', 'CANCELLATION_ISSUED', 'REINSTATEMENT_ISSUED', 'CLAIM_REGISTERED', 'CLAIM_APPROVED', 'CLAIM_PARTIALLY_APPROVED', 'CLAIM_DECLINED', 'PREAUTH_APPROVED', 'PREAUTH_PARTIALLY_APPROVED', 'PREAUTH_DECLINED', 'PREAUTH_EXTENSION_APPROVED'];

    /** REQ-HLT-002 health preauthorization triggers (ctx['preauth']; one document set per preauthorization subject). */
    public const PREAUTH_TRIGGERS = ['PREAUTH_APPROVED', 'PREAUTH_PARTIALLY_APPROVED', 'PREAUTH_DECLINED', 'PREAUTH_EXTENSION_APPROVED'];

    public const CLASSES = ['MOTOR', 'FLEET', 'HEALTH_INDIVIDUAL', 'CORPORATE_HEALTH', 'LIFE', 'GROUP_LIFE', 'PROPERTY', 'BUSINESS', 'TRAVEL', 'ACCIDENT', 'MARINE_CARGO', 'PROFESSIONAL_LIABILITY', 'GENERAL'];

    /** Engine class → [new-business packs (code => per-subject), catalogue prefix for renewal/endorsement/claim packs]. */
    private const CLASS_PACKS = [
        'MOTOR' => [['MOTOR_NEW_BUSINESS_PACK' => null], 'MOTOR'],
        'FLEET' => [['FLEET_MASTER_PACK' => null, 'FLEET_VEHICLE_PACK' => 'VEHICLE'], 'FLEET'],
        'HEALTH_INDIVIDUAL' => [['HEALTH_INDIVIDUAL_NEW_BUSINESS_PACK' => null], 'HEALTH_INDIVIDUAL'],
        'CORPORATE_HEALTH' => [['HEALTH_GROUP_MASTER_PACK' => null, 'HEALTH_GROUP_MEMBER_PACK' => 'MEMBER'], 'HEALTH_GROUP'],
        'LIFE' => [['LIFE_NEW_BUSINESS_PACK' => null], 'LIFE'],
        'GROUP_LIFE' => [['GROUP_LIFE_MASTER_PACK' => null, 'GROUP_LIFE_MEMBER_PACK' => 'MEMBER'], 'GROUP_LIFE'],
        'PROPERTY' => [['PROPERTY_NEW_BUSINESS_PACK' => null], 'PROPERTY'],
        'BUSINESS' => [['BUSINESS_MULTIRISK_NEW_BUSINESS_PACK' => null], 'BUSINESS_MULTIRISK'],
        'TRAVEL' => [['TRAVEL_NEW_BUSINESS_PACK' => null], 'TRAVEL'],
        'ACCIDENT' => [['PA_NEW_BUSINESS_PACK' => null], 'PA'],
        'MARINE_CARGO' => [['MARINE_CARGO_NEW_BUSINESS_PACK' => null, 'MARINE_CARGO_SHIPMENT_PACK' => 'SHIPMENT'], 'MARINE_CARGO'],
        'PROFESSIONAL_LIABILITY' => [['PROFESSIONAL_LIABILITY_NEW_BUSINESS_PACK' => null], 'PROFESSIONAL_LIABILITY'],
    ];

    /** Documents a trigger itself makes required (catalogue codes). */
    private const TRIGGER_DOCUMENTS = [
        'QUOTE_GENERATED' => ['INSURANCE_QUOTE', 'PRODUCT_INFORMATION_SHEET'],
        'PAYMENT_RECONCILED' => ['PREMIUM_RECEIPT'],
        'CANCELLATION_ISSUED' => ['CANCELLATION_TERMINATION_NOTICE'],
        'REINSTATEMENT_ISSUED' => ['REINSTATEMENT_NOTICE'],
        'CLAIM_REGISTERED' => ['CLAIM_NOTIFICATION_FORM', 'CLAIM_ACKNOWLEDGEMENT', 'CLAIM_REFERENCE_CONFIRMATION', 'CLAIM_REQUIREMENTS_LIST'],
        'CLAIM_APPROVED' => ['CLAIM_DECISION', 'SETTLEMENT_OFFER'],
        'CLAIM_PARTIALLY_APPROVED' => ['PARTIAL_APPROVAL_NOTICE', 'SETTLEMENT_OFFER'],
        'CLAIM_DECLINED' => ['CLAIM_REJECTION'],
        'PREAUTH_APPROVED' => ['PREAUTHORIZATION_APPROVAL', 'GUARANTEE_OF_PAYMENT'],
        'PREAUTH_PARTIALLY_APPROVED' => ['PARTIAL_PREAUTHORIZATION_APPROVAL', 'GUARANTEE_OF_PAYMENT'],
        'PREAUTH_DECLINED' => ['PREAUTHORIZATION_REJECTION'],
        'PREAUTH_EXTENSION_APPROVED' => ['HOSPITAL_STAY_EXTENSION_AUTHORIZATION', 'GUARANTEE_OF_PAYMENT'],
    ];

    /** Conditional trigger documents, generated only when the event includes them (ctx['include']). */
    private const TRIGGER_CONDITIONAL = [
        'PREAUTH_APPROVED' => ['HOSPITAL_ADMISSION_AUTHORIZATION'],
        'PREAUTH_PARTIALLY_APPROVED' => ['HOSPITAL_ADMISSION_AUTHORIZATION'],
    ];

    /** Pack documents that belong to an earlier step of the same event (linked, not re-generated). */
    private const LINK_AT_TRIGGER = [
        'RENEWAL_ISSUED' => ['RENEWAL_NOTICE', 'RENEWAL_QUOTE', 'NON_RENEWAL_NOTICE', 'CHANGE_OF_RISK_DECLARATION'],
        'POLICY_ISSUED' => ['INSURANCE_QUOTE', 'PRODUCT_INFORMATION_SHEET'],
    ];

    private const VEHICLE_CODES = ['MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE', 'PROVISIONAL_MOTOR_ATTESTATION', 'MOTOR_CERTIFICATE_REPLACEMENT', 'TERRITORIAL_EXTENSION_CERTIFICATE', 'VEHICLE_SCHEDULE'];

    /** Universal policy documents (catalogue spec) for classes without a catalogue pack. */
    private const UNIVERSAL_POLICY = ['INSURANCE_PROPOSAL', 'INSURANCE_POLICY', 'POLICY_SCHEDULE', 'GENERAL_CONDITIONS', 'CERTIFICATE_OF_INSURANCE', 'PREMIUM_RECEIPT'];

    private const TRIGGER_STAGE = ['POLICY_ISSUED' => 'ISSUANCE', 'RENEWAL_ISSUED' => 'RENEWAL', 'ENDORSEMENT_ISSUED' => 'SERVICING', 'CANCELLATION_ISSUED' => 'SERVICING', 'REINSTATEMENT_ISSUED' => 'SERVICING', 'QUOTE_GENERATED' => 'PRE_CONTRACT', 'CLAIM_REGISTERED' => 'CLAIM', 'CLAIM_APPROVED' => 'CLAIM', 'CLAIM_PARTIALLY_APPROVED' => 'CLAIM', 'CLAIM_DECLINED' => 'CLAIM', 'PREAUTH_APPROVED' => 'CLAIM', 'PREAUTH_PARTIALLY_APPROVED' => 'CLAIM', 'PREAUTH_DECLINED' => 'CLAIM', 'PREAUTH_EXTENSION_APPROVED' => 'CLAIM'];

    public function __construct(private DocumentRegister $register, private CatalogueSource $catalogue) {}

    public function classFor(Policy $policy): string
    {
        $policy->loadMissing('proposal.offer.product', 'proposal.offer.quote');
        $line = strtoupper((string) ($policy->proposal?->offer?->product?->line_code ?? $policy->proposal?->offer?->quote?->line_code ?? $policy->terms_snapshot['line_code'] ?? ''));
        $facts = $this->facts($policy);
        $class = self::classForLine($line);

        return match ($class) {
            'MOTOR' => count($this->rawSubjects($facts, 'VEHICLE')) > 1 ? 'FLEET' : 'MOTOR',
            'HEALTH_INDIVIDUAL' => count($this->rawSubjects($facts, 'MEMBER')) > 0 ? 'CORPORATE_HEALTH' : 'HEALTH_INDIVIDUAL',
            'LIFE' => count($this->rawSubjects($facts, 'MEMBER')) > 0 ? 'GROUP_LIFE' : 'LIFE',
            default => $class,
        };
    }

    public static function classForLine(string $line): string
    {
        $line = strtoupper($line);

        return match (true) {
            in_array($line, ['AUTO', 'MOTOR'], true) => 'MOTOR',
            in_array($line, ['FLEET', 'MOTOR_FLEET'], true) => 'FLEET',
            $line === 'HEALTH' => 'HEALTH_INDIVIDUAL',
            in_array($line, ['GROUP_HEALTH', 'CORPORATE_HEALTH', 'HEALTH_GROUP_HEALTH'], true) => 'CORPORATE_HEALTH',
            in_array($line, ['LIFE', 'TERM_LIFE', 'FUNERAL', 'CREDIT_LIFE', 'SAVINGS', 'RETIREMENT', 'EDUCATION'], true) => 'LIFE',
            $line === 'GROUP_LIFE' => 'GROUP_LIFE',
            in_array($line, ['HOME', 'PROPERTY', 'FIRE', 'HOME_MULTIRISK'], true) => 'PROPERTY',
            in_array($line, ['BUSINESS', 'MULTIRISK', 'BUSINESS_MULTIRISK'], true) => 'BUSINESS',
            $line === 'TRAVEL' => 'TRAVEL',
            in_array($line, ['ACCIDENT', 'PERSONAL_ACCIDENT'], true) => 'ACCIDENT',
            in_array($line, ['MARINE', 'CARGO', 'MARINE_CARGO', 'TRANSPORT', 'INLAND_TRANSIT'], true) => 'MARINE_CARGO',
            in_array($line, ['PROFESSIONAL_LIABILITY', 'RC_PRO', 'LIABILITY'], true) => 'PROFESSIONAL_LIABILITY',
            default => 'GENERAL',
        };
    }

    /**
     * @param array<int, string> $include codes the event explicitly requires (conditions met)
     * @return array{pack_code: string, insurance_class: string, items: array<int, array{document_type_code: string, required_level: string, per_subject: ?string, mode: string}>}
     */
    public function resolve(Policy $policy, string $trigger, array $include = []): array
    {
        return $this->build($this->classFor($policy), $trigger, $policy->proposal?->offer?->product_id, $include);
    }

    /** Product-level resolution (acceptance gate / admin screens): class from the product line. */
    public function resolveForProduct(InsuranceProduct $product, string $trigger): array
    {
        return $this->build(self::classForLine((string) $product->line_code), $trigger, $product->id, []);
    }

    /** @return array<int, array{key: string, label: string}> */
    public function subjects(Policy $policy, string $type): array
    {
        $subjects = [];
        foreach ($this->rawSubjects($this->facts($policy), $type) as $i => $row) {
            $row = (array) $row;
            $key = match ($type) {
                'VEHICLE' => (string) ($row['registration_number'] ?? $row['plate'] ?? $row['vin'] ?? 'VEH-'.($i + 1)),
                'MEMBER' => (string) ($row['member_id'] ?? $row['employee_number'] ?? $row['id'] ?? 'MBR-'.($i + 1)),
                default => (string) ($row['shipment_reference'] ?? $row['reference'] ?? $row['bill_of_lading'] ?? 'SHP-'.($i + 1)),
            };
            $label = match ($type) {
                'VEHICLE' => trim(implode(' ', array_filter([$row['make'] ?? null, $row['model'] ?? null, $key]))),
                'MEMBER' => trim((string) ($row['full_name'] ?? $row['name'] ?? $key)),
                default => trim(implode(' ', array_filter([$key, isset($row['voyage']) ? '('.$row['voyage'].')' : null]))),
            };
            $subjects[] = ['key' => mb_substr($key, 0, 120), 'label' => mb_substr($label, 0, 160)];
        }

        return $subjects;
    }

    /** @return array{pack_code: string, insurance_class: string, items: array<int, array<string, mixed>>} */
    private function build(string $class, string $trigger, ?string $productId, array $include): array
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException('Unknown document trigger '.$trigger);
        }
        [$nbPacks, $prefix] = self::CLASS_PACKS[$class] ?? [[], null];
        $packs = match ($trigger) {
            'POLICY_ISSUED' => $nbPacks,
            'RENEWAL_ISSUED' => $prefix ? [$prefix.'_RENEWAL_PACK' => null] : [],
            'ENDORSEMENT_ISSUED' => $prefix ? [$prefix.'_ENDORSEMENT_PACK' => null] : [],
            default => [],
        };
        $packs = array_filter($packs, fn ($per, $code) => $this->catalogue->hasPack($code), ARRAY_FILTER_USE_BOTH);
        $packCode = array_key_first($packs) ?? match ($trigger) {
            'POLICY_ISSUED' => $class.'_NEW_BUSINESS_PACK',
            'CLAIM_REGISTERED', 'CLAIM_APPROVED', 'CLAIM_PARTIALLY_APPROVED', 'CLAIM_DECLINED' => ($prefix ?? $class).'_CLAIM_PACK',
            'PREAUTH_APPROVED', 'PREAUTH_PARTIALLY_APPROVED', 'PREAUTH_DECLINED', 'PREAUTH_EXTENSION_APPROVED' => ($prefix ?? $class).'_PREAUTH_PACK',
            default => $class.'_'.str_replace('_ISSUED', '', $trigger).'_PACK',
        };

        $items = [];
        $add = function (string $code, string $requirement, ?string $per) use (&$items, $class): void {
            if (! $per && in_array($class, ['MOTOR', 'FLEET'], true) && in_array($code, self::VEHICLE_CODES, true)) {
                $per = 'VEHICLE';
            }
            $items[$code.'|'.$per] ??= ['document_type_code' => $code, 'requirement' => $requirement, 'per_subject' => $per];
        };
        foreach ($packs as $code => $per) {
            foreach ($this->catalogue->packItems($code) as $i) {
                $add($i['canonical_code'], $i['requirement'], $per);
            }
        }
        if ($trigger === 'POLICY_ISSUED' && $packs === []) {
            foreach (self::UNIVERSAL_POLICY as $code) {
                $add($code, 'REQUIRED', null);
            }
        }
        foreach (self::TRIGGER_DOCUMENTS[$trigger] ?? [] as $code) {
            $add($code, 'REQUIRED', null);
        }
        foreach (self::TRIGGER_CONDITIONAL[$trigger] ?? [] as $code) {
            $add($code, 'CONDITIONAL', null);
        }
        if ($trigger === 'CANCELLATION_ISSUED' && in_array($class, ['MOTOR', 'FLEET'], true)) {
            $add('MOTOR_INSURANCE_CANCELLATION_CERTIFICATE', 'REQUIRED', null);
        }

        // Requirement matrix of the product (selected product type + approved insurer overrides),
        // via DocumentCatalogueService::requirementsFor. Levels apply to pack items; mandatory
        // documents missing from the pack are added only at the matrix stage this trigger issues.
        $stage = self::TRIGGER_STAGE[$trigger] ?? null;
        $product = $productId ? InsuranceProduct::find($productId) : null;
        $addsAllowed = in_array($trigger, ['POLICY_ISSUED', 'RENEWAL_ISSUED', 'ENDORSEMENT_ISSUED'], true);
        $triggerOwned = self::TRIGGER_DOCUMENTS[$trigger] ?? [];
        foreach ($product && $stage ? $this->catalogue->requirementsFor($product, $stage) : [] as $r) {
            $level = ['M' => 'REQUIRED', 'C' => 'CONDITIONAL', 'O' => 'OPTIONAL', 'I' => 'INTERNAL', 'T' => 'THIRD_PARTY'][$r['level']] ?? null;
            if (! $level || in_array($r['canonical_code'], $triggerOwned, true)) {
                continue; // a trigger-owned document: the event itself meets the condition
            }
            $existing = array_keys(array_filter($items, fn ($i) => $i['document_type_code'] === $r['canonical_code']));
            foreach ($existing as $k) {
                $items[$k]['requirement'] = $level;
            }
            if (! $existing && $addsAllowed && $level === 'REQUIRED') {
                $add($r['canonical_code'], $level, null);
            }
        }

        $linkOnly = self::LINK_AT_TRIGGER[$trigger] ?? [];
        $result = [];
        foreach ($items as $i) {
            $t = $this->register->describe($i['document_type_code']);
            $required = $i['requirement'] === 'REQUIRED' || in_array($i['document_type_code'], $include, true);
            $mode = match (true) {
                $t['input_document'], in_array($i['requirement'], ['THIRD_PARTY', 'INTERNAL'], true), in_array($i['document_type_code'], $linkOnly, true) => 'LINK',
                $required => 'GENERATE',
                default => 'CONDITIONAL',
            };
            $result[] = ['document_type_code' => $i['document_type_code'], 'required_level' => $required ? 'REQUIRED' : $i['requirement'], 'per_subject' => $i['per_subject'], 'mode' => $mode];
        }

        return ['pack_code' => $packCode, 'insurance_class' => $class, 'items' => $result];
    }

    /** @return array<string, mixed> */
    private function facts(Policy $policy): array
    {
        return array_merge((array) ($policy->proposal?->offer?->quote?->risk_facts ?? []), (array) ($policy->terms_snapshot['subjects'] ?? []));
    }

    /** @return array<int, mixed> */
    private function rawSubjects(array $facts, string $type): array
    {
        return match ($type) {
            'VEHICLE' => (array) ($facts['vehicles'] ?? (isset($facts['registration_number']) ? [['registration_number' => $facts['registration_number'], 'make' => $facts['make'] ?? $facts['vehicle_make'] ?? null, 'model' => $facts['model'] ?? $facts['vehicle_model'] ?? null]] : [])),
            'MEMBER' => (array) ($facts['members'] ?? []),
            default => (array) ($facts['shipments'] ?? []),
        };
    }
}
