<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use App\Application\Rules\QuestionSetCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs before QuoteService's required-fact check for every non-motor line:
 *
 * 1. Validates master-data fields (select_master / multi_select_master, also
 *    inside repeaters): a code must be an active value of the source list and
 *    match its parent field; anything else is rejected (422). "OTHER" is
 *    accepted only with the free text in `{key}_other`, which is filed in the
 *    review queue — the fallback never blocks the quote.
 * 2. Enforces repeater rules (min/max items, allocation shares = 100% per rank).
 * 3. Derives the legacy tariff facts the approved tariffs rate on (see LEGACY
 *    MAPPINGS below), so rating is unchanged while the stored facts keep the
 *    canonical codes.
 *
 * Only facts that are present are validated (the app enforces required
 * wizard fields; the server keeps enforcing the line's rating `required`).
 * If a source list is not installed at all, that field is not validated
 * (fail-open only for a missing catalogue, never for an unknown code).
 */
final class RiskFactsProcessor
{
    /** LEGACY MAPPINGS (new master code → existing tariff fact). */
    public const PROPERTY_TYPE = ['APARTMENT_BUILDING' => 'APARTMENT', 'VILLA' => 'VILLA'];          // else HOUSE

    public const OCCUPANCY = ['TENANTED' => 'RENTED', 'VACANT' => 'VACANT'];                          // else OWNER

    public const COVERAGE_ZONE = ['CAMEROON' => 'CAMEROON', 'CEMAC_ZONE' => 'CEMAC', 'AFRICA' => 'AFRICA', 'WORLDWIDE_EXCLUDING_USA' => 'WORLDWIDE', 'WORLDWIDE' => 'WORLDWIDE'];

    public const LIFE_PURPOSE = ['CREDIT_LIFE' => 'LOAN_COVER', 'SAVINGS' => 'SAVINGS', 'ENDOWMENT' => 'SAVINGS', 'EDUCATION' => 'SAVINGS', 'RETIREMENT' => 'SAVINGS', 'ANNUITY' => 'SAVINGS']; // else FAMILY_PROTECTION

    public function __construct(
        private readonly MasterDataCatalogue $catalogue,
        private readonly MasterDataReviewService $reviews,
        private readonly VehicleMasterSource $vehicles,
        private readonly QuestionSetCatalogue $questionSets,
    ) {}

    /** @return array<string, mixed> processed facts */
    public function process(string $lineCode, array $facts, ?string $tenantId = null, ?string $userId = null): array
    {
        $line = strtoupper($lineCode);
        $schema = $line === 'MOTOR' ? null : $this->questionSets->lineSchema($line); // question set → seed catalogue fallback
        if (! $schema) {
            return $facts;
        }
        $used = [];
        $this->validateFields($schema['fields'] ?? [], $facts, 'risk_facts', $line, $tenantId, $userId, $used);
        try {
            $this->reviews->recordUsage($tenantId, $used);
        } catch (Throwable $e) {
            Log::warning('master-data usage not recorded: '.$e->getMessage());
        }

        return $this->derive($line, $facts);
    }

    private function validateFields(array $fields, array &$facts, string $path, string $line, ?string $tenantId, ?string $userId, array &$used): void
    {
        foreach ($fields as $f) {
            $key = $f['key'];
            if (! array_key_exists($key, $facts) || $facts[$key] === null || $facts[$key] === '' || ! $this->visible($f, $facts)) {
                continue;
            }
            $type = $f['type'] ?? 'text';
            if ($type === 'select_master' || $type === 'multi_select_master') {
                $codes = $type === 'multi_select_master' ? (array) $facts[$key] : [$facts[$key]];
                foreach ($codes as $code) {
                    if (! is_string($code) || $code === '') {
                        throw ValidationException::withMessages(["$path.$key" => __('Choose a value from the list.')]);
                    }
                    $this->checkCode($f, (string) $code, $facts, "$path.$key", $line, $tenantId, $userId, $used);
                }
            } elseif ($type === 'repeater') {
                $items = $facts[$key];
                if (! is_array($items) || ! array_is_list($items)) {
                    throw ValidationException::withMessages(["$path.$key" => __('Must be a list.')]);
                }
                if (count($items) < (int) ($f['min_items'] ?? 0)) {
                    throw ValidationException::withMessages(["$path.$key" => __('Add at least :n.', ['n' => $f['min_items']])]);
                }
                if (isset($f['max_items']) && count($items) > $f['max_items']) {
                    throw ValidationException::withMessages(["$path.$key" => __('At most :n entries.', ['n' => $f['max_items']])]);
                }
                foreach ($items as $i => $item) {
                    if (! is_array($item)) {
                        throw ValidationException::withMessages(["$path.$key.$i" => __('Invalid entry.')]);
                    }
                    foreach ($f['item_fields'] ?? [] as $sub) {
                        if (($sub['required'] ?? false) && ($item[$sub['key']] ?? '') === '') {
                            throw ValidationException::withMessages(["$path.$key.$i.{$sub['key']}" => __('Required.')]);
                        }
                    }
                    $this->validateFields($f['item_fields'] ?? [], $item, "$path.$key.$i", $line, $tenantId, $userId, $used);
                    $items[$i] = $item;
                }
                if (isset($f['allocation'])) {
                    $this->checkAllocation($f['allocation'], $items, "$path.$key");
                }
                $facts[$key] = $items;
            }
        }
    }

    private function checkCode(array $f, string $code, array $facts, string $path, string $line, ?string $tenantId, ?string $userId, array &$used): void
    {
        [$domain, $list] = MasterDataFlows::resolveSource($f['source']['domain'], $f['source']['list']);
        $parent = isset($f['parent_field']) ? ($facts[$f['parent_field']] ?? null) : ($f['parent'] ?? null);
        if ($parent === 'OTHER') {
            $parent = null;
        }

        if ($code === 'OTHER') {
            if (($f['other_allowed'] ?? false) !== true) {
                throw ValidationException::withMessages([$path => __('Choose a value from the list.')]);
            }
            $key = substr($path, strrpos($path, '.') + 1);
            $text = trim((string) ($facts[$key.'_other'] ?? ''));
            if ($text === '') {
                throw ValidationException::withMessages([$path.'_other' => __('Describe the value that is not listed.')]);
            }
            if ($domain === VehicleMasterSource::DOMAIN) {
                return; // vehicle "not listed" entries belong to the vehicle master's own review queue (make + model together)
            }
            try {
                $this->reviews->submit($domain, $list, $text, ['parent_code' => $parent, 'tenant_id' => $tenantId, 'user_id' => $userId, 'line_code' => $line, 'field_key' => $key, 'screen' => 'quote.risk']);
            } catch (Throwable $e) {
                // The fallback never blocks the transaction; the raw text stays on the quote.
                Log::warning("master-data review not filed for $domain.$list: ".$e->getMessage());
            }

            return;
        }

        if ($domain === VehicleMasterSource::DOMAIN) {
            if (! $this->vehicles->exists($list, $code, $parent)) {
                throw ValidationException::withMessages([$path => __('":code" is not a known value.', ['code' => $code])]);
            }

            return;
        }
        if (! $this->catalogue->listExists($domain, $list)) {
            return; // catalogue not installed
        }
        $value = $this->catalogue->value($domain, $list, $code);
        if (! $value || ($value['is_other'] ?? false)) {
            throw ValidationException::withMessages([$path => __('":code" is not a value of :list.', ['code' => $code, 'list' => "$domain.$list"])]);
        }
        if ($parent !== null && isset($value['parent']) && $value['parent'] !== $parent
            && ! in_array($parent, (array) ($value['attributes']['also_parents'] ?? $value['attributes']['categories'] ?? []), true)) {
            throw ValidationException::withMessages([$path => __('":code" does not belong to the selected :parent.', ['code' => $code, 'parent' => $parent])]);
        }
        $used[] = $value['id'];
    }

    private function checkAllocation(array $rule, array $items, string $path): void
    {
        $field = $rule['field'];
        $groups = [];
        foreach ($items as $i => $item) {
            $share = $item[$field] ?? null;
            if (! is_numeric($share) || $share <= 0 || $share > 100) {
                throw ValidationException::withMessages(["$path.$i.$field" => __('Enter a share between 1 and 100.')]);
            }
            $group = isset($rule['group_by']) ? (string) ($item[$rule['group_by']] ?? 'PRIMARY') : '_';
            $groups[$group] = ($groups[$group] ?? 0) + (float) $share;
        }
        foreach ($groups as $group => $total) {
            if (abs($total - (float) $rule['total']) > 0.001) {
                $label = $group === '_' ? '' : ' ('.strtolower($group).')';
                throw ValidationException::withMessages([$path => __('Beneficiary shares:label must total :total% (currently :sum%).', ['label' => $label, 'total' => $rule['total'], 'sum' => round($total, 2)])]);
            }
        }
    }

    /** visible_if: {field: value | [values] | {not_empty:true} | {contains:x}} — all conditions must hold. */
    public function visible(array $f, array $facts): bool
    {
        foreach ($f['visible_if'] ?? [] as $field => $cond) {
            $v = $facts[$field] ?? null;
            $ok = match (true) {
                is_array($cond) && array_key_exists('not_empty', $cond) => ($v !== null && $v !== '' && $v !== []) === (bool) $cond['not_empty'],
                is_array($cond) && array_key_exists('contains', $cond) => in_array($cond['contains'], (array) $v, true),
                is_array($cond) => in_array($v, $cond, true),
                is_bool($cond) => $v === $cond || $v === ($cond ? 'true' : 'false'),
                default => $v === $cond,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** Legacy tariff facts from master codes (only when the master field is present). */
    private function derive(string $line, array $f): array
    {
        $age = fn (?string $dob) => $dob && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) ? CarbonImmutable::parse($dob)->age : null;
        switch ($line) {
            case 'HOME':
                if (isset($f['building_type'])) {
                    $f['property_type'] = self::PROPERTY_TYPE[$f['building_type']] ?? 'HOUSE';
                }
                if (isset($f['occupancy_status'])) {
                    $f['occupancy'] = self::OCCUPANCY[$f['occupancy_status']] ?? 'OWNER';
                }
                $values = array_filter([$f['building_value_minor'] ?? null, $f['contents_value_minor'] ?? null, $f['stock_equipment_value_minor'] ?? null], 'is_numeric');
                if ($values && ! isset($f['declared_value_minor'])) {
                    $f['declared_value_minor'] = (int) array_sum($values);
                }
                if (isset($f['security_measures'])) {
                    $f['security_features'] = array_values(array_diff((array) $f['security_measures'], ['OTHER'])) !== [];
                }
                break;
            case 'BUSINESS':
                if (isset($f['business_activity'])) {
                    $v = $this->catalogue->value('industries', 'activity', (string) $f['business_activity']);
                    $f['business_type'] = $v['attributes']['legacy_business_type'] ?? 'OFFICE';
                }
                break;
            case 'HEALTH':
                if (isset($f['geographic_zone'])) {
                    $f['coverage_zone'] = self::COVERAGE_ZONE[$f['geographic_zone']] ?? 'CAMEROON';
                }
                if (! empty($f['members']) && is_array($f['members'])) {
                    $f['beneficiary_count'] = count($f['members']);
                    $ages = array_filter(array_map(fn ($m) => $age($m['date_of_birth'] ?? null), $f['members']), fn ($a) => $a !== null);
                    if ($ages) {
                        $f['oldest_age'] = max($ages);
                    }
                }
                if (array_key_exists('medical_declaration', $f)) {
                    $f['pre_existing_conditions'] = array_values(array_diff((array) $f['medical_declaration'], ['PREGNANT'])) !== [];
                }
                break;
            case 'LIFE':
                if (isset($f['product_type'])) {
                    $f['purpose'] = self::LIFE_PURPOSE[$f['product_type']] ?? 'FAMILY_PROTECTION';
                }
                if (($a = $age($f['life_assured_date_of_birth'] ?? null)) !== null) {
                    $f['insured_age'] = $a;
                }
                if (isset($f['policy_term'])) {
                    $v = $this->catalogue->value('life_insurance', 'term_years', (string) $f['policy_term']);
                    $years = $v['attributes']['years'] ?? null;
                    if ($years === null && $f['policy_term'] === 'WHOLE_LIFE' && isset($f['insured_age'])) {
                        $years = max(1, 100 - (int) $f['insured_age']);
                    }
                    if ($years !== null) {
                        $f['term_years'] = (int) $years;
                    }
                }
                if (array_key_exists('medical_conditions', $f)) {
                    $f['smoker'] = in_array('SMOKER', (array) $f['medical_conditions'], true);
                }
                break;
            case 'TRAVEL':
                if (isset($f['destination_country']) && preg_match('/^[A-Z]{2}$/', (string) $f['destination_country'])) {
                    $v = $this->catalogue->value('geography', 'country', $f['destination_country']);
                    $f['schengen'] = (bool) ($v['attributes']['schengen'] ?? false);
                }
                break;
            case 'ACCIDENT':
                if (isset($f['occupation']) && $f['occupation'] !== 'OTHER') {
                    $v = $this->catalogue->value('occupations', 'occupation', (string) $f['occupation']);
                    $class = $v['attributes']['risk_class'] ?? 'STANDARD';
                    $legacy = $this->catalogue->value('occupations', 'risk_class', $class);
                    $f['occupation_class'] = $legacy['attributes']['legacy_accident_class'] ?? 'MANUAL';
                } elseif (($f['occupation'] ?? null) === 'OTHER' && ! isset($f['occupation_class'])) {
                    $f['occupation_class'] = 'MANUAL'; // unlisted occupation: standard class until reviewed
                }
                break;
        }

        return $f;
    }
}
