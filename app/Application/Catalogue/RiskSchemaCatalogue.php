<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

/**
 * The quote wizard's question schema per insurance line: fields
 * {key,label,type,options,required,step} grouped into ordered steps.
 * PlatformCatalogueSeeder writes these onto insurance_lines.risk_schema
 * (alongside the rating "required" keys); GET
 * /mobile/catalogue/lines/{code}/risk-schema falls back to these defaults
 * for a line whose stored schema has no fields yet.
 *
 * Keys are the facts the tariffs rate on (DeterministicRatingEngine
 * factors / required_facts): numbers are numbers, selects are the exact
 * uppercase codes the factors compare against, booleans are booleans.
 */
final class RiskSchemaCatalogue
{
    /** @return array<string, array{steps: array<int, array{key:string,label:string}>, fields: array<int, array<string, mixed>>, required: array<int, string>}> */
    public static function all(): array
    {
        return [
            'MOTOR' => self::schema(
                [['vehicle', 'Vehicle'], ['usage', 'Usage'], ['owner', 'Owner & location'], ['cover', 'Cover'], ['history', 'History']],
                [
                    self::f('registration_number', 'Registration number', 'text', 'vehicle', true),
                    self::f('make', 'Make', 'text', 'vehicle', true),
                    self::f('model', 'Model', 'text', 'vehicle', true),
                    self::f('year', 'Year of manufacture', 'number', 'vehicle', true, null, ['min' => 1970, 'max' => (int) date('Y') + 1]),
                    self::f('fiscal_power', 'Fiscal power (CV)', 'number', 'vehicle', true, null, ['min' => 1, 'max' => 60]),
                    self::f('vehicle_value', 'Vehicle value (XAF)', 'number', 'vehicle', true, null, ['min' => 0]),
                    self::f('usage_type', 'Usage', 'select', 'usage', true, [['PRIVATE', 'Private'], ['COMMERCIAL', 'Commercial']]),
                    self::f('zone', 'City / zone', 'select', 'owner', true, [['DOUALA', 'Douala'], ['YAOUNDE', 'Yaoundé'], ['OTHER_URBAN', 'Other city'], ['RURAL', 'Rural area']]),
                    self::f('cover_type', 'Cover', 'select', 'cover', true, [['THIRD_PARTY', 'Third party'], ['THIRD_PARTY_FIRE_THEFT', 'Third party, fire & theft'], ['COMPREHENSIVE', 'Comprehensive']]),
                    self::f('previous_insurer', 'Previous insurer', 'text', 'history', false),
                    self::f('claims_last_3_years', 'Claims in the last 3 years', 'number', 'history', true, null, ['min' => 0, 'max' => 20]),
                ],
                ['registration_number', 'fiscal_power', 'usage_type', 'zone'],
            ),
            'HEALTH' => self::schema(
                [['plan', 'Plan'], ['members', 'Members'], ['history', 'Health history']],
                [
                    self::f('plan_type', 'Plan', 'select', 'plan', true, [['INDIVIDUAL', 'Individual'], ['FAMILY', 'Family']]),
                    self::f('coverage_zone', 'Coverage zone', 'select', 'plan', true, [['CAMEROON', 'Cameroon'], ['CEMAC', 'CEMAC'], ['AFRICA', 'Africa'], ['WORLDWIDE', 'Worldwide']]),
                    self::f('beneficiary_count', 'Number of people covered', 'number', 'members', true, null, ['min' => 1, 'max' => 20]),
                    self::f('oldest_age', 'Age of the oldest member', 'number', 'members', true, null, ['min' => 0, 'max' => 90]),
                    self::f('pre_existing_conditions', 'Any pre-existing condition?', 'boolean', 'history', false),
                ],
                ['beneficiary_count', 'oldest_age', 'coverage_zone', 'plan_type'],
            ),
            'TRAVEL' => self::schema(
                [['trip', 'Trip'], ['travellers', 'Travellers']],
                [
                    self::f('destination_country', 'Destination country', 'text', 'trip', true),
                    self::f('departure_date', 'Departure date', 'date', 'trip', true),
                    self::f('return_date', 'Return date', 'date', 'trip', true),
                    self::f('traveller_count', 'Number of travellers', 'number', 'travellers', true, null, ['min' => 1, 'max' => 20]),
                    self::f('trip_purpose', 'Purpose of trip', 'select', 'trip', false, [['LEISURE', 'Leisure'], ['BUSINESS', 'Business'], ['STUDY', 'Study'], ['PILGRIMAGE', 'Pilgrimage']]),
                ],
                ['destination_country', 'departure_date', 'return_date', 'traveller_count'],
            ),
            'HOME' => self::schema(
                [['property', 'Property'], ['occupancy', 'Occupancy'], ['value', 'Value']],
                [
                    self::f('property_type', 'Property type', 'select', 'property', true, [['HOUSE', 'House'], ['APARTMENT', 'Apartment'], ['VILLA', 'Villa']]),
                    self::f('city', 'City', 'text', 'property', true),
                    self::f('occupancy', 'Occupancy', 'select', 'occupancy', true, [['OWNER', 'Owner-occupied'], ['RENTED', 'Rented'], ['VACANT', 'Vacant']]),
                    self::f('declared_value_minor', 'Declared contents & building value (XAF)', 'number', 'value', true, null, ['min' => 0]),
                    self::f('security_features', 'Guard or alarm on site?', 'boolean', 'value', false),
                ],
                ['property_type', 'occupancy', 'city', 'declared_value_minor'],
            ),
            'LIFE' => self::schema(
                [['insured', 'Insured person'], ['cover', 'Cover']],
                [
                    self::f('insured_age', 'Age of insured', 'number', 'insured', true, null, ['min' => 18, 'max' => 75]),
                    self::f('smoker', 'Smoker?', 'boolean', 'insured', false),
                    self::f('cover_amount_minor', 'Cover amount (XAF)', 'number', 'cover', true, null, ['min' => 0]),
                    self::f('term_years', 'Term (years)', 'number', 'cover', true, null, ['min' => 1, 'max' => 40]),
                    self::f('purpose', 'Purpose', 'select', 'cover', true, [['FAMILY_PROTECTION', 'Family protection'], ['LOAN_COVER', 'Loan cover'], ['SAVINGS', 'Savings']]),
                ],
                ['insured_age', 'cover_amount_minor', 'term_years', 'purpose'],
            ),
            'BUSINESS' => self::schema(
                [['business', 'Business'], ['premises', 'Premises'], ['cover', 'Cover']],
                [
                    self::f('business_type', 'Business type', 'select', 'business', true, [['RETAIL', 'Retail / shop'], ['OFFICE', 'Office / services'], ['RESTAURANT', 'Restaurant / hospitality'], ['WORKSHOP', 'Workshop / light industry'], ['WAREHOUSE', 'Warehouse']]),
                    self::f('employee_count', 'Number of employees', 'number', 'business', true, null, ['min' => 0, 'max' => 5000]),
                    self::f('annual_turnover', 'Annual turnover (XAF)', 'number', 'business', true, null, ['min' => 0]),
                    self::f('city', 'City', 'text', 'premises', true),
                    self::f('premises_value', 'Premises & stock value (XAF)', 'number', 'premises', true, null, ['min' => 0]),
                    self::f('cover_type', 'Cover', 'select', 'cover', true, [['LIABILITY', 'Public liability'], ['MULTIRISK', 'Multirisk (fire, theft, liability)']]),
                ],
                ['business_type', 'employee_count', 'city', 'premises_value'],
            ),
            'ACCIDENT' => self::schema(
                [['insured', 'Insured people'], ['cover', 'Cover']],
                [
                    self::f('insured_count', 'Number of people covered', 'number', 'insured', true, null, ['min' => 1, 'max' => 50]),
                    self::f('oldest_age', 'Age of the oldest person', 'number', 'insured', true, null, ['min' => 0, 'max' => 80]),
                    self::f('occupation_class', 'Occupation', 'select', 'insured', true, [['OFFICE', 'Office / low risk'], ['MANUAL', 'Manual work'], ['HIGH_RISK', 'High-risk work (mining, construction, transport)']]),
                    self::f('cover_amount', 'Death / disability benefit (XAF)', 'number', 'cover', true, null, ['min' => 0]),
                ],
                ['insured_count', 'occupation_class', 'cover_amount'],
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function for(string $lineCode): ?array
    {
        return self::all()[strtoupper($lineCode)] ?? null;
    }

    private static function schema(array $steps, array $fields, array $required): array
    {
        return ['steps' => array_map(fn ($s) => ['key' => $s[0], 'label' => $s[1]], $steps), 'fields' => $fields, 'required' => $required];
    }

    private static function f(string $key, string $label, string $type, string $step, bool $required, ?array $options = null, array $extra = []): array
    {
        return [
            'key' => $key, 'label' => $label, 'type' => $type, 'step' => $step, 'required' => $required,
            'options' => $options ? array_map(fn ($o) => ['value' => $o[0], 'label' => $o[1]], $options) : null,
        ] + $extra;
    }
}
