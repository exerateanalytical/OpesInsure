<?php

declare(strict_types=1);

namespace App\Application\MasterData;

/**
 * "Everything is selected, not typed" — the input contract every mobile form
 * (quote risk wizard, disclosures, profile/KYC, beneficiaries, addresses, FNOL)
 * is normalised to before it leaves the API. REQ-MDM-003, REQ-MDM-005.
 *
 * Per field, after annotate():
 *   input        picker | multi_picker | number | money | date | boolean | text | file | repeater
 *   source       {domain, list, parent, parent_code}   master-backed pickers only
 *                (parent = key of the field whose value filters the list, or null;
 *                 parent_code = fixed parent value code, e.g. LIABILITY, or null)
 *   parent_field key of the field whose value filters this list (cascading pickers)
 *   allow_other  true  -> the picker shows "Other / Not listed"; the app sends
 *                         {key: "OTHER", key_other: "<typed text>"} and files a
 *                         POST /api/v1/master-data/suggestions (quote risk facts
 *                         are filed server-side by RiskFactsProcessor)
 *                false -> closed list (rating codes, consent flags)
 *   options      inline closed enumerations (rating codes) — rendered as a picker, allow_other false
 *   free_text    {reason} only on fields that legitimately stay typed:
 *                PERSON_NAME, ORGANISATION_NAME, IDENTIFIER, NARRATIVE, LANDMARK
 *   min/max      numbers, money and dates (dates as ISO strings or "today")
 *
 * Vehicle make/model keep `source` as the endpoint string (mobile app contract
 * owned by the vehicle master) and carry the object form in `source_ref`.
 * Legacy keys (`other_allowed`, `parent_field`) are kept for older app builds.
 */
final class InputFieldContract
{
    public const VERSION = 1;

    public const FREE_TEXT_REASONS = ['PERSON_NAME', 'ORGANISATION_NAME', 'IDENTIFIER', 'NARRATIVE', 'LANDMARK'];

    private const INPUT = [
        'select_master' => 'picker', 'multi_select_master' => 'multi_picker', 'select' => 'picker', 'multi_select' => 'multi_picker',
        'vehicle_make' => 'picker', 'vehicle_model' => 'picker', 'vehicle_generation' => 'picker', 'vehicle_variant' => 'picker', 'number' => 'number', 'money' => 'money', 'date' => 'date',
        'boolean' => 'boolean', 'file' => 'file', 'repeater' => 'repeater', 'text' => 'text', 'textarea' => 'text',
    ];

    private const VEHICLE_LISTS = ['vehicle_make' => 'makes', 'vehicle_model' => 'models', 'vehicle_generation' => 'generations', 'vehicle_variant' => 'variants'];

    /** Known typed-by-design keys across schemas (see docs/audit/FREE_TEXT_FIELDS_AUDIT.md). */
    private const FREE_TEXT = [
        'full_name' => 'PERSON_NAME', 'name' => 'PERSON_NAME', 'life_assured_name' => 'PERSON_NAME', 'payer_name' => 'PERSON_NAME',
        'company_name' => 'ORGANISATION_NAME', 'organisation_name' => 'ORGANISATION_NAME', 'contractor_name' => 'ORGANISATION_NAME',
        'registration_number' => 'IDENTIFIER', 'vin' => 'IDENTIFIER', 'serial_number' => 'IDENTIFIER', 'identifier_value' => 'IDENTIFIER', 'police_reference' => 'IDENTIFIER',
        'description' => 'NARRATIVE', 'commodity_description' => 'NARRATIVE',
        'address' => 'LANDMARK', 'address_line1' => 'LANDMARK', 'site_address' => 'LANDMARK', 'incident_location' => 'LANDMARK',
        // Specialty flows (database/data/master_data/specialty_2026.json).
        'policyholder' => 'PERSON_NAME', 'life_assured' => 'PERSON_NAME', 'insured' => 'PERSON_NAME', 'child' => 'PERSON_NAME', 'borrower' => 'PERSON_NAME',
        'lender' => 'ORGANISATION_NAME', 'company' => 'ORGANISATION_NAME', 'employer' => 'ORGANISATION_NAME', 'builder' => 'ORGANISATION_NAME', 'beneficiary' => 'ORGANISATION_NAME',
        'master_policy_number' => 'IDENTIFIER', 'contract_reference' => 'IDENTIFIER', 'registration' => 'IDENTIFIER', 'permit_reference' => 'IDENTIFIER', 'imei' => 'IDENTIFIER', 'vessel_name' => 'IDENTIFIER',
        'medical_details' => 'NARRATIVE', 'bad_debt_details' => 'NARRATIVE', 'other_bond_description' => 'NARRATIVE', 'activity_description' => 'NARRATIVE',
        'model_description' => 'NARRATIVE', 'event_name' => 'NARRATIVE', 'beneficiaries' => 'NARRATIVE',
        'site_location' => 'LANDMARK', 'venue_address' => 'LANDMARK',
    ];

    /** @param array<string, mixed> $schema */
    public static function annotate(array $schema): array
    {
        $schema['fields'] = array_map([self::class, 'field'], $schema['fields'] ?? []);
        $schema['contract'] = self::VERSION;

        return $schema;
    }

    /** @param array<string, mixed> $f */
    public static function field(array $f): array
    {
        if (! empty($f['item_fields']) && is_array($f['item_fields'])) {
            $f['item_fields'] = array_map([self::class, 'field'], $f['item_fields']);
        }
        $type = strtolower((string) ($f['type'] ?? 'text'));
        $f['input'] = self::INPUT[$type] ?? 'text';
        if (($f['input'] === 'number' || $f['input'] === 'money') && ! isset($f['min'])) {
            $f['min'] = 0; // counts, amounts, ages, areas: never negative; no invented upper cap
        }

        if (isset(self::VEHICLE_LISTS[$type])) {
            $f['source_ref'] = ['domain' => VehicleMasterSource::DOMAIN, 'list' => self::VEHICLE_LISTS[$type], 'parent' => $type === 'vehicle_make' ? null : ($f['depends_on'] ?? null)];
            // make/model: "not listed" goes to the vehicle review queue; generation/variant are optional refinements (skip, never typed)
            $f['allow_other'] = in_array($type, ['vehicle_make', 'vehicle_model'], true);
        } elseif (isset($f['source']) && is_array($f['source'])) {
            $f['source'] = ['domain' => $f['source']['domain'], 'list' => $f['source']['list'], 'parent' => $f['parent_field'] ?? ($f['source']['parent'] ?? null), 'parent_code' => $f['parent'] ?? ($f['source']['parent_code'] ?? null)];
            $f['allow_other'] = (bool) ($f['allow_other'] ?? $f['other_allowed'] ?? true);
            $f['other_allowed'] = $f['allow_other'];
        } elseif (! empty($f['options']) || isset($f['endpoint'])) {
            $f['allow_other'] = (bool) ($f['allow_other'] ?? false);
        } elseif ($f['input'] === 'text') {
            $f['free_text'] = ['reason' => $f['free_text']['reason'] ?? self::FREE_TEXT[$f['key']] ?? 'UNCLASSIFIED'];
        }

        return $f;
    }

    /**
     * Every field (recursively) still rendered as free text, with its reason —
     * the audit test asserts none is UNCLASSIFIED.
     *
     * @return array<int, array{key:string, reason:string}>
     */
    public static function freeTextFields(array $schema): array
    {
        $out = [];
        $walk = function (array $fields, string $prefix) use (&$walk, &$out): void {
            foreach ($fields as $f) {
                if (isset($f['free_text'])) {
                    $out[] = ['key' => $prefix.$f['key'], 'reason' => $f['free_text']['reason']];
                }
                if (! empty($f['item_fields'])) {
                    $walk($f['item_fields'], $prefix.$f['key'].'.*.');
                }
            }
        };
        $walk($schema['fields'] ?? [], '');

        return $out;
    }
}
