<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DocumentCatalogue\CanonicalDocumentSpec;
use App\Application\DocumentCatalogue\CanonicalFieldDictionary;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Loads the owner's canonical document specification into the EXISTING document catalogue
 * (runs after DocumentCatalogueSeeder, same `opesinsure:seed-document-catalogue` command, so it runs on
 * every deploy through `optimize`). Idempotent; never deletes; never lowers security:
 *
 *  - document_spec_dictionary: S1-S5, A4 zones, FG-01..FG-15, control legend, confidentiality classes,
 *    access profiles, shared security artifacts, master shells, manifest invariants;
 *  - document_canonical_specs: the 220 records under their stable spec ids;
 *  - document_types: the security profile of the spec record whose English or French name equals the
 *    catalogue type's name (exact match only — no fuzzy guess; unmatched spec ids stay
 *    PENDING_VERIFICATION). Tier = max(current, spec floor); a control never drops below its current
 *    requirement; security_level only moves to a MORE restrictive level.
 */
final class CanonicalDocumentSpecSeeder extends Seeder
{
    public const SOURCE = 'OWNER_CANONICAL_IMPLEMENTATION_SPEC_V1';

    private const REQUIREMENT_RANK = ['PENDING_VERIFICATION' => 0, 'NOT_REQUIRED' => 1, 'OPTIONAL' => 2, 'CONFIGURABLE' => 3, 'REQUIRED' => 4];

    /** Stored documents.security_level restrictiveness (DocumentRegister::SECURITY_LEVELS). */
    private const LEVEL_RANK = ['PUBLIC_VERIFIABLE' => 0, 'CUSTOMER_PRIVATE' => 1, 'INSURER_CONFIDENTIAL' => 2, 'INTERNAL' => 2, 'FINANCIAL_RESTRICTED' => 3, 'MEDICAL_RESTRICTED' => 4, 'REGULATORY' => 4];

    /** @var array<string, mixed> run report */
    public array $report = [];

    public function __construct(private ?CanonicalDocumentSpec $spec = null) {}

    public function run(): void
    {
        $spec = $this->spec ?? new CanonicalDocumentSpec();
        if (! $spec->available() || ! Schema::hasTable('document_canonical_specs')) {
            $this->report = ['skipped' => 'spec file or tables missing'];

            return;
        }
        $version = $spec->version();
        $hash = $spec->sourceHash();
        $now = now();
        $raw = $spec->raw();
        $system = $raw['document_system'];

        DB::transaction(function () use ($spec, $version, $hash, $now, $raw, $system): void {
            $this->seedDictionary($system, $raw, $version, $now);

            $types = DB::table('document_types')->where('kind', '!=', 'EVIDENCE')->get(['type_id', 'name_en', 'name_fr']);
            $byName = [];
            foreach ($types as $t) {
                foreach ([$t->name_en, $t->name_fr] as $n) {
                    $byName[CanonicalDocumentSpec::nameKey((string) $n)][$t->type_id] = true;
                }
            }

            $rows = [];
            $mapped = [];
            foreach ($spec->documents() as $id => $doc) {
                $profile = CanonicalDocumentSpec::profile($doc);
                $explicit = (array) ($doc['field_requirements']['field_group_refs'] ?? []);
                $groups = CanonicalFieldDictionary::groupsFor($explicit, $doc['field_requirements']['minimum_additions'] ?? null);
                $matches = array_keys(($byName[CanonicalDocumentSpec::nameKey($doc['name_en'])] ?? []) + ($byName[CanonicalDocumentSpec::nameKey($doc['name_fr'])] ?? []));
                sort($matches);
                foreach ($matches as $typeId) {
                    $mapped[$typeId][] = $id;
                }
                $rows[] = [
                    'spec_id' => $id, 'name_en' => $doc['name_en'], 'name_fr' => $doc['name_fr'], 'category_code' => $doc['category_code'], 'category_name' => $doc['category_name'],
                    'typical_security_tier' => (string) $doc['typical_security_tier'], 'tier_floor' => $profile['tier_floor'], 'tier_ceiling' => $profile['tier_ceiling'],
                    'field_group_refs' => json_encode($groups), 'minimum_additions' => $doc['field_requirements']['minimum_additions'] ?? null,
                    'detailed_field_spec' => $doc['detailed_field_spec'] ? json_encode($doc['detailed_field_spec'], JSON_UNESCAPED_UNICODE) : null,
                    'detailed_field_map' => $doc['detailed_field_spec'] ? json_encode(CanonicalDocumentSpec::detailedFieldMap($doc['detailed_field_spec']), JSON_UNESCAPED_UNICODE) : null,
                    'security_profile_raw' => json_encode($doc['security_profile'], JSON_UNESCAPED_UNICODE), 'security_controls' => json_encode($profile['controls']),
                    'confidentiality_classes' => json_encode($profile['confidentiality_classes']), 'access_profiles' => json_encode($profile['access_profiles']),
                    'master_shell_codes' => json_encode(array_values((array) $doc['master_shell_codes'])), 'catalogue_type_ids' => json_encode($matches),
                    'mapping_status' => $matches ? 'MAPPED' : 'PENDING_VERIFICATION', 'spec_version' => $version, 'source_hash' => $hash,
                    'source_reference' => self::SOURCE.' document_system.documents.'.$id, 'status' => 'ACTIVE', 'is_seeded' => true, 'updated_at' => $now,
                ];
            }
            $existing = DB::table('document_canonical_specs')->pluck('id', 'spec_id');
            foreach (array_chunk($rows, 100) as $chunk) {
                $chunk = array_map(fn ($r) => ['id' => $existing[$r['spec_id']] ?? (string) Str::uuid(), 'created_at' => $now] + $r, $chunk);
                DB::table('document_canonical_specs')->upsert($chunk, ['spec_id'], array_values(array_diff(array_keys($chunk[0]), ['id', 'created_at', 'spec_id'])));
            }

            $this->applyToCatalogue($spec, $mapped);
            $this->report['specs'] = count($rows);
            $this->report['pending_verification'] = count(array_filter($rows, fn ($r) => $r['mapping_status'] !== 'MAPPED'));
        });
    }

    /**
     * @param  array<string, array<int, string>>  $mapped  type_id => spec ids
     */
    private function applyToCatalogue(CanonicalDocumentSpec $spec, array $mapped): void
    {
        $docs = $spec->documents();
        $current = DB::table('document_types')->whereIn('type_id', array_keys($mapped))->get()->keyBy('type_id');
        $updated = 0;
        $conflicts = [];
        foreach ($mapped as $typeId => $specIds) {
            $row = $current[$typeId] ?? null;
            if (! $row) {
                continue;
            }
            // A catalogue type matched by two spec records (same name): the strictest profile wins.
            $profiles = array_map(fn ($sid) => CanonicalDocumentSpec::profile($docs[$sid]) + ['spec_id' => $sid, 'shells' => (array) $docs[$sid]['master_shell_codes']], $specIds);
            usort($profiles, fn ($a, $b) => CanonicalDocumentSpec::tierRank($b['tier_floor']) <=> CanonicalDocumentSpec::tierRank($a['tier_floor']));
            $p = $profiles[0];

            $tier = CanonicalDocumentSpec::tierRank($row->security_tier) > CanonicalDocumentSpec::tierRank($p['tier_floor']) ? $row->security_tier : $p['tier_floor'];
            $ceiling = CanonicalDocumentSpec::tierRank($row->security_tier_ceiling) > CanonicalDocumentSpec::tierRank($p['tier_ceiling']) ? $row->security_tier_ceiling : $p['tier_ceiling'];
            $controls = $p['controls'];
            foreach ((array) json_decode((string) $row->security_controls, true) as $c => $old) {
                if ((self::REQUIREMENT_RANK[$old['requirement'] ?? ''] ?? 0) > (self::REQUIREMENT_RANK[$controls[$c]['requirement'] ?? ''] ?? 0)) {
                    $controls[$c] = $old; // never weaken an existing control
                }
            }
            $class = $p['confidentiality_class'];
            if ($row->confidentiality_class && (CanonicalDocumentSpec::CONFIDENTIALITY_RANK[$row->confidentiality_class] ?? 0) > (CanonicalDocumentSpec::CONFIDENTIALITY_RANK[$class] ?? 0)) {
                $class = $row->confidentiality_class;
            }
            $level = $row->security_level;
            $specLevel = \App\Application\Documents\Engine\DocumentRegister::CONFIDENTIALITY_CLASS_MAP[$class] ?? null;
            if ($specLevel && (self::LEVEL_RANK[$specLevel] ?? 0) > (self::LEVEL_RANK[$level] ?? 0)) {
                $level = $specLevel;
            } elseif ($specLevel && $specLevel !== $level) {
                $conflicts[$typeId] = ['spec' => $p['spec_id'], 'catalogue_level' => $level, 'spec_level' => $specLevel, 'kept' => $level];
            }
            $update = [
                'canonical_spec_id' => $row->canonical_spec_id ?: $p['spec_id'], 'security_tier' => $tier, 'security_tier_ceiling' => $ceiling,
                'security_controls' => json_encode($controls), 'confidentiality_class' => $class, 'access_profiles' => json_encode($p['access_profiles']),
                'master_shell_code' => $row->master_shell_code ?: ($p['shells'][0] ?? null), 'security_level' => $level,
            ];
            $canon = new \App\Application\Shared\CanonicalJson();
            $changed = array_filter($update, fn ($v, $k) => in_array($k, ['security_controls', 'access_profiles'], true)
                ? $canon->encode((array) json_decode((string) $v, true)) !== $canon->encode((array) json_decode((string) $row->{$k}, true))
                : (string) $v !== (string) $row->{$k}, ARRAY_FILTER_USE_BOTH);
            if ($changed !== []) {
                DB::table('document_types')->where('type_id', $typeId)->update($changed + ['updated_at' => now()]);
                $updated++;
            }
        }
        $this->report['catalogue_types_mapped'] = count($mapped);
        $this->report['catalogue_types_updated'] = $updated;
        $this->report['less_restrictive_spec_levels_kept'] = $conflicts;
    }

    /** @param array<string, mixed> $system */
    private function seedDictionary(array $system, array $raw, string $version, $now): void
    {
        $rows = [];
        $add = function (string $kind, string $code, ?string $name, $payload, ?int $rank = null) use (&$rows, $version, $now): void {
            $rows[] = ['kind' => $kind, 'code' => $code, 'name' => $name !== null ? mb_substr($name, 0, 255) : null, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'rank' => $rank, 'spec_version' => $version, 'status' => 'ACTIVE', 'is_seeded' => true, 'updated_at' => $now];
        };
        foreach ($system['security_tiers'] as $code => $t) {
            $add('SECURITY_TIER', $code, $t['name'], ['required_controls' => $t['required_controls'], 'optional_controls' => $t['optional_controls'], 'source_text' => $t['source_text'] ?? null], CanonicalDocumentSpec::tierRank($code));
        }
        foreach ($system['a4_zones'] as $code => $z) {
            $add('A4_ZONE', $code, $z['name'], ['content' => $z['content'], 'bullets' => $z['bullets']]);
        }
        foreach ($system['field_groups'] as $code => $g) {
            $add('FIELD_GROUP', $code, $g['name'], ['items' => $g['items'], 'subgroups' => $g['subgroups'], 'enforced_keys' => CanonicalFieldDictionary::GROUP_KEYS[$code] ?? []]);
        }
        foreach ($system['security_control_legend'] as $code => $text) {
            $add('SECURITY_CONTROL', $code, $text, ['description' => $text, 'physical' => in_array($code, CanonicalDocumentSpec::PHYSICAL_CONTROLS, true)]);
        }
        foreach ($system['confidentiality_classes'] as $code => $text) {
            $add('CONFIDENTIALITY_CLASS', $code, $text, ['description' => $text], CanonicalDocumentSpec::CONFIDENTIALITY_RANK[$code] ?? null);
        }
        foreach ($system['access_profiles'] as $code => $text) {
            $add('ACCESS_PROFILE', $code, $text, ['description' => $text], (int) substr($code, 1));
        }
        foreach ($system['shared_security_artifacts'] as $code => $a) {
            $add('SECURITY_ARTIFACT', Str::upper(Str::snake(str_replace(' ', '', $code))), $code, $a);
        }
        foreach ($system['master_shells'] as $code => $s) {
            $add('MASTER_SHELL', $code, $s['title'], $s);
        }
        $manifestPath = (string) config('document_security.manifest_path');
        $manifest = is_file($manifestPath) ? (array) json_decode((string) file_get_contents($manifestPath), true) : [];
        foreach ((array) ($manifest['critical_invariants'] ?? []) as $i => $text) {
            $add('INVARIANT', sprintf('INV-%02d', $i + 1), $text, ['text' => $text]);
        }
        $add('POLICY', 'DOCUMENT_IMPLEMENTATION_POLICY', 'document_implementation_policy', $raw['document_implementation_policy'] ?? []);

        $existing = DB::table('document_spec_dictionary')->get(['id', 'kind', 'code'])->mapWithKeys(fn ($r) => [$r->kind.'|'.$r->code => $r->id]);
        foreach (array_chunk($rows, 100) as $chunk) {
            $chunk = array_map(fn ($r) => ['id' => $existing[$r['kind'].'|'.$r['code']] ?? (string) Str::uuid(), 'created_at' => $now] + $r, $chunk);
            DB::table('document_spec_dictionary')->upsert($chunk, ['kind', 'code'], ['name', 'payload', 'rank', 'spec_version', 'status', 'is_seeded', 'updated_at']);
        }
        $this->report['dictionary'] = count($rows);
    }
}
