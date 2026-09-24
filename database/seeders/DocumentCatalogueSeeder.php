<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DocumentCatalogue\DocumentRequirementMatrixEntry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads the canonical document catalogue (database/data/document_catalogue_2026.json,
 * built on the owner's 220-type register) and the requirement matrix
 * (database/data/document_requirement_matrix_2026.json). Idempotent: rows are
 * upserted on their natural keys; seeded rows that disappear from the data file
 * are deactivated (status INACTIVE + effective_until), never deleted.
 * Query builder is used on purpose: the data files are the authority for
 * seeded rows; the application models refuse in-place edits.
 */
final class DocumentCatalogueSeeder extends Seeder
{
    public const CATALOGUE_FILE = 'data/document_catalogue_2026.json';

    public const MATRIX_FILE = 'data/document_requirement_matrix_2026.json';

    /** @var array<string,int> table => rows created in this run */
    public array $counts = [];

    public function run(): void
    {
        $cat = self::load(self::CATALOGUE_FILE);
        $mx = self::load(self::MATRIX_FILE);
        $now = now();
        $prov = fn (array $d) => ['catalogue_version' => $d['version'], 'effective_from' => $d['effective_from'], 'source_reference' => $d['source_reference'], 'is_seeded' => true, 'updated_at' => $now];

        DB::transaction(function () use ($cat, $mx, $prov) {
            $p = $prov($cat);

            $rows = array_map(fn ($t) => $p + [
                'type_id' => $t['type_id'], 'canonical_code' => $t['canonical_code'], 'namespace' => $t['namespace'], 'kind' => $t['kind'], 'parent_type_id' => $t['parent_type_id'],
                'same_as_type_id' => $t['same_as'] ?? null, 'name_en' => $t['name_en'], 'name_fr' => $t['name_fr'], 'register_group' => $t['register_group'],
                'register_group_code' => $t['register_group_code'], 'category' => $t['category'], 'document_origin' => $t['document_origin'],
                'issuer_authority' => json_encode($t['issuer_authority']), 'recipient' => $t['recipient'], 'stages' => json_encode($t['stages']), 'audience' => $t['audience'],
                'legal_reference' => $t['legal_reference'], 'verifiable' => $t['verifiable'], 'security_level' => $t['security_level'], 'scope' => $t['scope'],
                'is_evidence' => $t['is_evidence'], 'generation_triggers' => json_encode($t['generation_triggers']), 'numbering_family' => $t['numbering_family'],
                'aliases' => json_encode($t['aliases']), 'status' => 'ACTIVE', 'effective_until' => null,
            ], $cat['document_types']);
            $this->sync('document_types', ['type_id'], $rows);

            $rows = array_map(fn ($k) => $p + [
                'code' => $k['code'], 'label_en' => $k['label_en'], 'label_fr' => $k['label_fr'], 'lifecycle_stage' => $k['lifecycle_stage'], 'scope' => $k['scope'],
                'is_universal' => $k['is_universal'], 'owner_pack' => $k['owner_pack'], 'class_codes' => json_encode($k['class_codes']), 'status' => 'ACTIVE', 'effective_until' => null,
            ], $cat['document_packs']);
            $this->sync('document_packs', ['code'], $rows);

            $packIds = DB::table('document_packs')->pluck('id', 'code');
            $rows = [];
            foreach ($cat['document_packs'] as $k) {
                foreach ($k['items'] as $i) {
                    $rows[] = $p + ['document_pack_id' => $packIds[$k['code']], 'document_type_id' => $i['document_type_id'], 'requirement' => $i['requirement'],
                        'condition_note' => $i['condition_note'], 'sort_order' => $i['sort_order'], 'status' => 'ACTIVE', 'effective_until' => null];
                }
            }
            $this->sync('document_pack_items', ['document_pack_id', 'document_type_id'], $rows);

            $rows = [];
            foreach ($cat['class_applicability'] as $a) {
                foreach ($a['document_type_ids'] as $tid) {
                    $rows[] = $p + ['class_code' => $a['class_code'], 'document_type_id' => $tid, 'source' => 'PACK', 'status' => 'ACTIVE', 'effective_until' => null];
                }
            }
            $this->sync('document_type_class_applicability', ['class_code', 'document_type_id'], $rows);

            $m = $prov($mx);
            $rows = array_map(fn ($t) => $m + ['code' => $t['code'], 'label_en' => $t['label_en'], 'label_fr' => $t['label_fr'], 'class_code' => $t['class_code'],
                'extends_code' => $t['extends'], 'spec_section' => $t['spec_section'], 'status' => $t['status'], 'effective_until' => null], $mx['product_types']);
            $this->sync('document_product_types', ['code'], $rows);

            $rows = array_map(fn ($v) => $m + ['variant_code' => $v['variant_code'], 'matrix_label' => $v['matrix_label'], 'nearest_type_id' => $v['nearest_type_id'],
                'status' => 'ACTIVE', 'effective_until' => null], $mx['variants']);
            $this->sync('document_matrix_variants', ['variant_code'], $rows);

            $rows = [];
            $entry = fn (string $pt, array $e) => $m + ['product_type_code' => $pt, 'stage' => $e['stage'], 'document_type_id' => $e['document_type_id'],
                'variant_code' => $e['variant_code'] ?? '', 'matrix_label' => $e['matrix_label'], 'level' => $e['level'], 'alternate_level' => $e['alternate_level'],
                'insurer_overridable' => $e['insurer_overridable'], 'raw_level' => $e['raw_level'], 'qualifiers' => json_encode($e['qualifiers']), 'issuer' => $e['issuer'],
                'recipient' => $e['recipient'], 'trigger' => $e['trigger'], 'sort_order' => $e['sort_order'], 'status' => 'ACTIVE', 'effective_until' => null];
            foreach ($mx['baseline'] as $e) {
                $rows[] = $entry(DocumentRequirementMatrixEntry::BASELINE, $e);
            }
            foreach ($mx['product_types'] as $t) {
                foreach ($t['entries'] as $e) {
                    $rows[] = $entry($t['code'], $e);
                }
            }
            $this->sync('document_requirement_matrix', ['product_type_code', 'stage', 'document_type_id', 'variant_code'], $rows);

            // REQ-DUP-004: link legacy proposal requirement rows to the canonical type (rows kept).
            if (\Illuminate\Support\Facades\Schema::hasColumn('document_requirement_versions', 'catalogue_type_id') && DB::getDriverName() === 'pgsql') {
                DB::statement("UPDATE document_requirement_versions v SET catalogue_type_id = t.type_id FROM document_types t
                    WHERE v.catalogue_type_id IS NULL AND (t.canonical_code = upper(v.code) OR jsonb_exists(t.aliases, upper(v.code)))");
            }
        });
    }

    /** @return array<string,mixed> */
    public static function load(string $relative): array
    {
        $path = database_path($relative);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data)) {
            throw new RuntimeException("Document catalogue data file missing or invalid: $relative");
        }

        return $data;
    }

    /**
     * Upsert $rows on $keys; seeded rows no longer present are deactivated.
     *
     * @param  list<string>  $keys
     * @param  list<array<string,mixed>>  $rows
     */
    private function sync(string $table, array $keys, array $rows): void
    {
        $before = DB::table($table)->count();
        $existing = DB::table($table)->get(array_merge(['id'], $keys))
            ->mapWithKeys(fn ($r) => [implode('|', array_map(fn ($k) => (string) $r->{$k}, $keys)) => $r->id]);
        $seen = [];
        foreach (array_chunk($rows, 200) as $chunk) {
            $chunk = array_map(function ($r) use ($existing, $keys, &$seen) {
                $key = implode('|', array_map(fn ($k) => (string) $r[$k], $keys));
                $seen[$key] = true;

                return ['id' => $existing[$key] ?? (string) Str::uuid(), 'created_at' => $r['updated_at']] + $r;
            }, $chunk);
            $update = array_values(array_diff(array_keys($chunk[0]), array_merge(['id', 'created_at'], $keys)));
            DB::table($table)->upsert($chunk, $keys, $update);
        }
        $stale = $existing->reject(fn ($id, $key) => isset($seen[$key]))->values();
        if ($stale->isNotEmpty()) {
            DB::table($table)->whereIn('id', $stale)->where('is_seeded', true)->where('status', 'ACTIVE')
                ->update(['status' => 'INACTIVE', 'effective_until' => now()->toDateString(), 'updated_at' => now()]);
        }
        $this->counts[$table] = DB::table($table)->count() - $before;
    }
}
