<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use Illuminate\Support\Facades\Cache;

/**
 * Selection flows shipped inside master data files (domain.flow.steps[].fields[]),
 * converted to the risk-schema format used by the quote wizard, so a specialty
 * line (e.g. CYBER, EVENT, MOBILE_DEVICE) gets a controlled questionnaire as
 * soon as its data file exists. Line code = upper-cased domain code.
 *
 * Source references resolve across all files; SOURCE_REDIRECTS points generic
 * lists at the canonical core catalogue (e.g. life_insurance.occupation →
 * occupations.occupation) and aligns vehicle list names with the vehicle master.
 */
final class MasterDataFlows
{
    public const SOURCE_REDIRECTS = [
        'life_insurance.occupation' => ['occupations', 'occupation'],
        'life.occupation' => ['occupations', 'occupation'],
        'vehicle.make' => ['vehicle', 'makes'],
        'vehicle.model' => ['vehicle', 'models'],
        'fleet.vehicle_class' => ['vehicle', 'vehicle_class'],
        'fleet.usage' => ['vehicle', 'usage'],
        // Duplicate lists consolidated (see MasterDataSeeder::SUPERSEDED_LISTS); old codes are seeded aliases.
        'life_insurance.relationship' => ['persons', 'relationship'],
        'aviation.manufacturer' => ['aviation_insurance', 'manufacturer'],
    ];

    private const TYPES = [
        'SELECT_MASTER' => 'select_master', 'MULTI_SELECT_MASTER' => 'multi_select_master', 'NUMBER' => 'number', 'CURRENCY' => 'money',
        'DATE' => 'date', 'TEXT' => 'text', 'BOOLEAN' => 'boolean', 'FILE' => 'file', 'REPEATER' => 'repeater',
    ];

    /** @return array<string, mixed>|null */
    public function schemaFor(string $lineCode): ?array
    {
        return $this->all()[strtoupper($lineCode)] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $files = glob(database_path('data/master_data/*.json')) ?: [];
        $stamp = md5(implode('|', array_map(fn ($f) => $f.filemtime($f), $files)));

        return Cache::rememberForever("master-data:flows:$stamp", function () use ($files) {
            $out = [];
            foreach ($files as $file) {
                $doc = json_decode((string) file_get_contents($file), true) ?: [];
                foreach ($doc['domains'] ?? [] as $d) {
                    if (empty($d['flow']['steps'])) {
                        continue;
                    }
                    $fields = [];
                    $steps = [];
                    foreach ($d['flow']['steps'] as $s) {
                        $steps[] = ['key' => $s['key'], 'label' => $s['label_en'] ?? $s['key'], 'label_en' => $s['label_en'] ?? $s['key'], 'label_fr' => $s['label_fr'] ?? ($s['label_en'] ?? $s['key'])];
                        foreach ($s['fields'] as $f) {
                            $fields[] = ['step' => $s['key']] + $this->field($f);
                        }
                    }
                    $out[strtoupper($d['code'])] = ['version' => 2, 'steps' => $steps, 'fields' => $fields, 'required' => [], 'source_file' => basename($file)];
                }
            }

            return $out;
        });
    }

    /** @return array{0:string,1:string} */
    public static function resolveSource(string $domain, string $list): array
    {
        return self::SOURCE_REDIRECTS["$domain.$list"] ?? [$domain, $list];
    }

    private function field(array $f): array
    {
        $out = [
            'key' => $f['key'], 'label' => $f['label_en'] ?? $f['key'], 'label_en' => $f['label_en'] ?? $f['key'], 'label_fr' => $f['label_fr'] ?? ($f['label_en'] ?? $f['key']),
            'type' => self::TYPES[strtoupper($f['type'] ?? 'TEXT')] ?? 'text', 'required' => (bool) ($f['required'] ?? false),
        ];
        if (isset($f['source'])) {
            [$d, $l] = self::resolveSource($f['source']['domain'], $f['source']['list']);
            $out['source'] = ['domain' => $d, 'list' => $l];
            $out['other_allowed'] = (bool) ($f['other_allowed'] ?? false);
        }
        if (! empty($f['depends_on'])) {
            $out['parent_field'] = is_array($f['depends_on']) ? $f['depends_on'][0] : $f['depends_on'];
        }
        foreach (['visible_if', 'min', 'max', 'pattern', 'currency', 'derived_from', 'derived', 'party_ref', 'institution_ref', 'bulk_import', 'import_format'] as $k) {
            if (array_key_exists($k, $f)) {
                $out[$k] = $f[$k];
            }
        }
        if (! empty($f['item_fields'])) {
            $out['item_fields'] = array_map(fn ($x) => $this->field($x), $f['item_fields']);
            // Beneficiary shares must total 100%.
            if (collect($f['item_fields'])->contains('key', 'share_pct')) {
                $out['allocation'] = ['field' => 'share_pct', 'total' => 100];
            }
        }

        return $out;
    }
}
