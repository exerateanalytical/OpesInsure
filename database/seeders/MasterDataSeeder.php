<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\MasterData\MasterDataCache;
use App\Application\MasterData\MasterDataNormalizer;
use App\Application\MasterData\WorkflowDataStatuses;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Loads every database/data/master_data/*.json file (core, reference,
 * specialty, … — other agents add files without touching this code).
 *
 * Idempotent and protective:
 *  - never deletes anything (values dropped from a file simply stay);
 *  - never reactivates a value an admin deactivated;
 *  - never overwrites a value an admin edited (admin_modified_at set);
 *  - seeded rows carry is_seeded = true, which the DB trigger protects.
 *
 * Accepted shapes: domains[].{code,label_en|name_en,label_fr|name_fr,number,provenance,
 * disclaimer_en/fr,lists[]}; lists[].{code,label_en,label_fr,parent_list,structure_only,
 * selection,provenance,source_reference,version,note,values[]}; values[].{code,
 * label_en|en,label_fr|fr,parent_code|parent,description_en/fr,attributes,aliases[]}.
 * A later file may add aliases/attribute keys to an existing code (never relabel it), declare
 * allow_other on an empty placeholder list, stamp source_reference_default on the values it
 * introduces, and carry workflow_statuses[] (see WorkflowDataStatuses).
 */
final class MasterDataSeeder extends Seeder
{
    /** @var array<string, array{created:int, updated:int, aliases:int}> */
    public array $counts = [];

    /** @var array<int, string> */
    public array $warnings = [];

    private const COMMON_COUNTRIES = ['CM', 'CF', 'TD', 'CG', 'GQ', 'GA', 'NG', 'FR', 'US', 'CN', 'GB', 'BE', 'DE', 'CI', 'SN'];

    public function run(?string $directory = null): void
    {
        $directory ??= database_path('data/master_data');
        $docs = $this->load($directory);
        $domains = $this->merge($docs);

        MasterDataCache::muted(function () use ($domains): void {
            $sort = 0;
            foreach ($domains as $domain) {
                DB::transaction(fn () => $this->seedDomain($domain, $sort++));
            }
        });
        // Lists superseded by another canonical source: deactivated, never deleted.
        foreach (self::SUPERSEDED_LISTS as $old => $new) {
            [$d, $l] = explode('.', $old);
            $n = DB::table('master_data_lists')->where(['domain_code' => $d, 'code' => $l, 'status' => 'ACTIVE'])
                ->update(['status' => 'INACTIVE', 'note' => "Superseded by $new (canonical source); codes kept and resolved as legacy aliases.", 'updated_at' => now()]);
            if ($n) {
                DB::table('master_data_values')->where(['domain_code' => $d, 'list_code' => $l])->update(['status' => 'INACTIVE']);
                MasterDataCache::bump($d);
                $this->warnings[] = "List $old deactivated: superseded by $new.";
            }
        }
        foreach ($docs as $doc) {
            if (! empty($doc['workflow_statuses'])) {
                $this->warnings = [...$this->warnings, ...app(WorkflowDataStatuses::class)->seed($doc)];
            }
        }
        MasterDataCache::flushAll();
    }

    /** Old list => canonical replacement (vehicle lists are owned by the vehicle master). */
    public const SUPERSEDED_LISTS = [
        'fleet.vehicle_class' => 'vehicle.vehicle_class',
        'fleet.usage' => 'vehicle.usage',
        // 12 coarse occupations duplicated the core ISCO-08 catalogue; flows now point at occupations.occupation.
        'life_insurance.occupation' => 'occupations.occupation',
        // Life beneficiary relationships were a subset of persons.relationship (LEGAL_HEIRS is an alias of ESTATE).
        'life_insurance.relationship' => 'persons.relationship',
        // Flat brand list duplicated the category-scoped aviation_insurance.manufacturer used by the aviation flow;
        // each flat code is a seeded alias of its brand in the canonical list.
        'aviation.manufacturer' => 'aviation_insurance.manufacturer',
    ];

    /** @return array<int, array<string, mixed>> */
    private function load(string $directory): array
    {
        $files = glob(rtrim($directory, '/\\').'/*.json') ?: [];
        sort($files);
        // core_* first: it follows the owner's seed order (countries → geography → …).
        usort($files, fn ($a, $b) => [! str_starts_with(basename($a), 'core'), basename($a)] <=> [! str_starts_with(basename($b), 'core'), basename($b)]);
        $docs = [];
        foreach ($files as $file) {
            try {
                $doc = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException('Invalid master data file '.basename($file).': '.$e->getMessage(), previous: $e);
            }
            $doc['__file'] = pathinfo($file, PATHINFO_FILENAME);
            $docs[] = self::expandValuesFrom($doc);
        }

        return $docs;
    }

    /**
     * A list may take its values from a code registry instead of repeating them
     * ("values_from": {"source": "party_roles", "codes": [...]}), so there is one canonical definition.
     */
    public static function expandValuesFrom(array $doc): array
    {
        foreach ($doc['domains'] ?? [] as $di => $d) {
            foreach ($d['lists'] ?? [] as $li => $l) {
                if (($l['values_from']['source'] ?? null) !== 'party_roles') {
                    continue;
                }
                $roles = \App\Application\Customers\Roles\PartyRoleService::ROLES;
                $doc['domains'][$di]['lists'][$li]['values'] = array_map(fn (string $c) => ['code' => $c, 'label_en' => $roles[$c]['en'], 'label_fr' => $roles[$c]['fr']],
                    array_values(array_filter((array) $l['values_from']['codes'], fn ($c) => isset($roles[$c]))));
            }
        }

        return $doc;
    }

    /** Domains with the same code across files are merged (lists united; values united by code). */
    private function merge(array $docs): array
    {
        $out = [];
        foreach ($docs as $doc) {
            foreach ($doc['domains'] ?? [] as $d) {
                $code = (string) $d['code'];
                $d['__file'] = $doc['__file'];
                $d['__provenance'] = $d['provenance'] ?? $doc['provenance_default'] ?? 'PLATFORM_NORMALIZED';
                $d['__effective_from'] = $doc['effective_from'] ?? '2026-01-01';
                $d['__version'] = $doc['version'] ?? '2026.1';
                foreach ($d['lists'] ?? [] as $i => $l) {
                    $d['lists'][$i]['__provenance'] = $l['provenance'] ?? $d['__provenance'];
                    // Values keep the provenance of the file that introduced them, even when merged into another file's list.
                    if (isset($doc['source_reference_default'])) {
                        $d['lists'][$i]['source_reference'] ??= $doc['source_reference_default'];
                        foreach ($l['values'] ?? [] as $vi => $v) {
                            $d['lists'][$i]['values'][$vi]['source_reference'] ??= $doc['source_reference_default'];
                            $d['lists'][$i]['values'][$vi]['provenance'] ??= $d['lists'][$i]['__provenance'];
                            $d['lists'][$i]['values'][$vi]['effective_from'] ??= $d['__effective_from'];
                        }
                    }
                }
                if (! isset($out[$code])) {
                    $out[$code] = $d;
                    continue;
                }
                $lists = collect($out[$code]['lists'])->keyBy('code');
                foreach ($d['lists'] ?? [] as $l) {
                    if ($lists->has($l['code'])) {
                        $this->warnings[] = "List {$code}.{$l['code']} defined in several files; values merged by code.";
                        $existing = $lists[$l['code']];
                        $known = array_flip(array_column($existing['values'], 'code'));
                        foreach ($l['values'] ?? [] as $v) {
                            if (! isset($known[$v['code']])) {
                                $existing['values'][] = $v;
                                continue;
                            }
                            // Existing code: the first definition wins; later files may only add aliases and new attribute keys.
                            $at = $known[$v['code']];
                            $cur = $existing['values'][$at];
                            $cur['aliases'] = array_values(array_unique([...(array) ($cur['aliases'] ?? []), ...(array) ($v['aliases'] ?? [])]));
                            if (! empty($v['attributes'])) {
                                $cur['attributes'] = ($cur['attributes'] ?? []) + $v['attributes'];
                            }
                            $existing['values'][$at] = $cur;
                        }
                        $existing['allow_other'] = ($existing['allow_other'] ?? false) || ($l['allow_other'] ?? false);
                        $lists[$l['code']] = $existing;
                    } else {
                        $lists[$l['code']] = $l;
                    }
                }
                $out[$code]['lists'] = $lists->values()->all();
            }
        }

        return array_values($out);
    }

    private function seedDomain(array $d, int $sort): void
    {
        $code = $d['code'];
        $now = now();
        $this->counts[$code] = ['created' => 0, 'updated' => 0, 'aliases' => 0];
        $labels = [
            'label_en' => $d['label_en'] ?? $d['name_en'] ?? $code, 'label_fr' => $d['label_fr'] ?? $d['name_fr'] ?? $code,
            'number' => $d['number'] ?? null, 'description_en' => $d['description_en'] ?? null, 'description_fr' => $d['description_fr'] ?? null,
            'disclaimer_en' => $d['disclaimer_en'] ?? null, 'disclaimer_fr' => $d['disclaimer_fr'] ?? null, 'source_type' => $d['__provenance'],
        ];
        $row = DB::table('master_data_domains')->where('code', $code)->first();
        if (! $row) {
            $domainId = (string) Str::uuid();
            DB::table('master_data_domains')->insert($labels + ['id' => $domainId, 'code' => $code, 'source_file' => $d['__file'], 'catalog_version' => 1, 'status' => 'ACTIVE', 'sort_order' => $sort, 'is_seeded' => true, 'created_at' => $now, 'updated_at' => $now]);
            $this->counts[$code]['created']++;
        } else {
            $domainId = $row->id;
            if ($row->is_seeded) {
                DB::table('master_data_domains')->where('id', $domainId)->update($labels + ['sort_order' => $sort]);
            }
        }

        $listIds = [];
        foreach ($d['lists'] ?? [] as $li => $l) {
            $listIds[$l['code']] = $this->seedList($code, $domainId, $l, $li, $d);
        }
        // Pass 2: resolve hierarchies (parent_list may be "list" or "domain.list").
        foreach ($d['lists'] ?? [] as $l) {
            if (empty($l['parent_list'])) {
                continue;
            }
            [$pDomain, $pList] = str_contains($l['parent_list'], '.') ? explode('.', $l['parent_list'], 2) : [$code, $l['parent_list']];
            $parentListId = $pDomain === $code ? ($listIds[$pList] ?? null) : DB::table('master_data_lists')->where(['domain_code' => $pDomain, 'code' => $pList])->value('id');
            if (! $parentListId) {
                $this->warnings[] = "Parent list {$l['parent_list']} of {$code}.{$l['code']} not found.";
                continue;
            }
            DB::update('UPDATE master_data_values v SET parent_value_id = p.id FROM master_data_values p
                WHERE v.list_id = ? AND p.list_id = ? AND p.code = v.parent_code AND v.parent_value_id IS DISTINCT FROM p.id', [$listIds[$l['code']], $parentListId]);
        }

        $c = $this->counts[$code];
        if ($row && ($c['created'] + $c['updated'] + $c['aliases']) > 0) {
            DB::table('master_data_domains')->where('id', $domainId)->update(['catalog_version' => DB::raw('catalog_version + 1'), 'updated_at' => $now]);
        }
        if (($c['created'] + $c['updated'] + $c['aliases']) > 0) {
            DB::table('master_data_changes')->insert(['id' => (string) Str::uuid(), 'entity_type' => 'DOMAIN', 'entity_id' => $domainId, 'domain_code' => $code,
                'action' => 'SEEDED', 'after' => json_encode($c + ['file' => $d['__file']]), 'source' => 'SEEDER', 'created_at' => $now]);
        }
    }

    private function seedList(string $domain, string $domainId, array $l, int $index, array $d): string
    {
        $now = now();
        $values = $l['values'] ?? [];
        // Placeholder lists (no values yet, e.g. PENDING_SOURCE) declare allow_other so "Other / Not listed" still works.
        $hasOther = collect($values)->contains(fn ($v) => ($v['code'] ?? '') === 'OTHER') || (bool) ($l['allow_other'] ?? false);
        $meta = [
            'label_en' => $l['label_en'] ?? $l['name_en'] ?? $l['code'], 'label_fr' => $l['label_fr'] ?? $l['name_fr'] ?? $l['code'],
            'description_en' => $l['description_en'] ?? null, 'description_fr' => $l['description_fr'] ?? null, 'note' => $l['note'] ?? null,
            'parent_list_code' => $l['parent_list'] ?? null, 'selection' => strtoupper($l['selection'] ?? 'SINGLE'), 'allow_other' => $hasOther,
            'structure_only' => (bool) ($l['structure_only'] ?? false), 'source_type' => $l['__provenance'], 'source_reference' => $l['source_reference'] ?? null,
            'version' => (string) ($l['version'] ?? $d['__version']), 'effective_from' => $l['effective_from'] ?? $d['__effective_from'], 'sort_order' => $index,
        ];
        $row = DB::table('master_data_lists')->where(['domain_code' => $domain, 'code' => $l['code']])->first();
        if (! $row) {
            $listId = (string) Str::uuid();
            DB::table('master_data_lists')->insert($meta + ['id' => $listId, 'domain_id' => $domainId, 'domain_code' => $domain, 'code' => $l['code'], 'status' => 'ACTIVE', 'is_seeded' => true, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $listId = $row->id;
            if ($row->is_seeded) {
                $meta['allow_other'] = $meta['allow_other'] || (bool) $row->allow_other;
                DB::table('master_data_lists')->where('id', $listId)->update($meta);
            }
        }

        $existing = DB::table('master_data_values')->where('list_id', $listId)->get()->keyBy('code');
        foreach ($values as $i => $v) {
            $code = (string) $v['code'];
            $attrs = $v['attributes'] ?? null;
            $aliases = array_values(array_filter((array) ($v['aliases'] ?? [])));
            $fields = [
                'label_en' => $v['label_en'] ?? $v['en'] ?? $code, 'label_fr' => $v['label_fr'] ?? $v['fr'] ?? ($v['label_en'] ?? $code),
                'description_en' => $v['description_en'] ?? null, 'description_fr' => $v['description_fr'] ?? null,
                'parent_code' => $v['parent_code'] ?? $v['parent'] ?? null, 'attributes' => $attrs ? json_encode($attrs, JSON_UNESCAPED_UNICODE) : null,
                'sort_order' => $i * 10, 'is_other' => $code === 'OTHER' || (($attrs['review_queue'] ?? false) === true && str_ends_with($code, 'OTHER')),
                'is_common' => $domain === 'geography' && $l['code'] === 'country' && in_array($code, self::COMMON_COUNTRIES, true) || ($attrs['common'] ?? false) === true,
                'source_type' => $v['provenance'] ?? $l['__provenance'], 'source_reference' => $v['source_reference'] ?? $l['source_reference'] ?? null,
                'effective_from' => $v['effective_from'] ?? $l['effective_from'] ?? $d['__effective_from'],
            ];
            $fields['search_text'] = MasterDataNormalizer::searchText([$code, $fields['label_en'], $fields['label_fr'], ...$aliases]);
            $current = $existing[$code] ?? null;
            if (! $current) {
                $valueId = (string) Str::uuid();
                DB::table('master_data_values')->insert($fields + ['id' => $valueId, 'list_id' => $listId, 'domain_code' => $domain, 'list_code' => $l['code'], 'code' => $code, 'status' => 'ACTIVE', 'is_seeded' => true, 'created_at' => $now, 'updated_at' => $now]);
                $this->counts[$domain]['created']++;
            } else {
                $valueId = $current->id;
                if ($current->is_seeded && ! $current->admin_modified_at) {
                    // search_text may include admin-added aliases: keep them.
                    $adminAliases = DB::table('master_data_aliases')->where('value_id', $valueId)->pluck('alias')->all();
                    $fields['search_text'] = MasterDataNormalizer::searchText([$code, $fields['label_en'], $fields['label_fr'], ...$aliases, ...$adminAliases]);
                    $dirty = collect($fields)->filter(fn ($val, $k) => $this->differs($current->{$k}, $val))->all();
                    if ($dirty) {
                        DB::table('master_data_values')->where('id', $valueId)->update($dirty + ['updated_at' => $now]);
                        $this->counts[$domain]['updated']++;
                    }
                }
            }
            foreach ($aliases as $alias) {
                $norm = MasterDataNormalizer::normalize($alias);
                if ($norm === '' || DB::table('master_data_aliases')->where(['value_id' => $valueId, 'normalized' => $norm])->whereNull('tenant_id')->exists()) {
                    continue;
                }
                DB::table('master_data_aliases')->insert(['id' => (string) Str::uuid(), 'value_id' => $valueId, 'alias' => $alias, 'normalized' => $norm, 'alias_type' => strlen($alias) <= 5 && strtoupper($alias) === $alias ? 'ABBREVIATION' : 'ALIAS', 'is_seeded' => true, 'created_at' => $now, 'updated_at' => $now]);
                $this->counts[$domain]['aliases']++;
            }
        }

        return $listId;
    }

    private function differs(mixed $current, mixed $new): bool
    {
        if (is_bool($new)) {
            return (bool) $current !== $new;
        }
        if (is_string($new) && is_string($current) && str_starts_with($new, '{')) {
            return json_decode($current, true) != json_decode($new, true);
        }
        if ($current instanceof \DateTimeInterface) {
            $current = $current->format('Y-m-d');
        }
        if (is_string($current) && preg_match('/^\d{4}-\d{2}-\d{2}/', $current) && is_string($new) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $new)) {
            return substr($current, 0, 10) !== $new;
        }

        return (string) $current !== (string) $new;
    }
}
