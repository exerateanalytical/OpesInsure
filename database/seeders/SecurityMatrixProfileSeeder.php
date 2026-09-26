<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\DocumentCatalogue\CanonicalDocumentSpec;
use App\Application\Documents\Security\SecurityMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Security Matrix v1 §4–11 into the EXISTING document_spec_dictionary, document_canonical_specs and document_types
 * (runs after CanonicalDocumentSpecSeeder in `opesinsure:seed-document-catalogue`). Idempotent; never deletes;
 * an admin-set profile on a catalogue type is only ever extended (seals / physical profiles are unioned; a
 * watermark profile already set is kept).
 */
final class SecurityMatrixProfileSeeder extends Seeder
{
    /** @var array<string, mixed> */
    public array $report = [];

    public function __construct(private ?SecurityMatrix $matrix = null, private ?CanonicalDocumentSpec $spec = null) {}

    public function run(): void
    {
        $matrix = $this->matrix ?? new SecurityMatrix();
        $spec = $this->spec ?? new CanonicalDocumentSpec();
        if (! $matrix->available() || ! $spec->available() || ! Schema::hasColumn('document_canonical_specs', 'watermark_profile_code')) {
            $this->report = ['skipped' => 'security matrix source or columns missing'];

            return;
        }
        $p = $matrix->parse();
        $version = 'v1.0';
        $hash = $matrix->sourceHash();
        $now = now();

        DB::transaction(function () use ($p, $spec, $version, $hash, $now): void {
            $rows = [];
            $add = function (string $kind, string $code, ?string $name, array $payload, ?int $rank = null) use (&$rows, $version, $now, $hash): void {
                $rows[] = ['kind' => $kind, 'code' => $code, 'name' => $name !== null ? mb_substr($name, 0, 255) : null,
                    'payload' => json_encode($payload + ['source' => SecurityMatrix::SOURCE, 'source_hash' => $hash], JSON_UNESCAPED_UNICODE),
                    'rank' => $rank, 'spec_version' => $version, 'status' => 'ACTIVE', 'is_seeded' => true, 'updated_at' => $now];
            };
            foreach ($p['physical'] as $code => $ps) {
                $add('PHYSICAL_PROFILE', $code, $ps['name'], $ps, (int) substr($code, 3));
            }
            foreach ($p['watermarks'] as $code => $wm) {
                $add('WATERMARK_PROFILE', $code, $wm['purpose'] ?? $code, $wm + ($code === 'WM-STATUS' ? ['overlays' => SecurityMatrix::STATUS_OVERLAYS] : []));
            }
            $sealRule = $p['seals']['_rule']['text'] ?? null;
            foreach ($p['seals'] as $code => $s) {
                if ($code !== '_rule') {
                    $add('SEAL_PROFILE', $code, $s['name'], $s + ['validity_rule' => $sealRule], (int) substr($code, 5));
                }
            }
            $add('VERIFICATION_RULE', 'PUBLIC_VERIFICATION', 'Public verification rules (§7)', $p['verification'] + [
                'enforced_by' => 'DocumentVerificationPresenter::publicPayload + SecurityMatrix::sanitizePublic',
                'allowed_keys' => ['top' => SecurityMatrix::PUBLIC_TOP_KEYS, 'document' => SecurityMatrix::PUBLIC_DOCUMENT_KEYS],
                'forbidden_categories' => SecurityMatrix::FORBIDDEN_PUBLIC]);
            $add('REVOCATION_RULE', 'REVOCATION_REPLACEMENT', 'Revocation and replacement rules (§8)', $p['revocation']);
            foreach ($p['issuance_gate'] as $i => $step) {
                $add('ISSUANCE_GATE_STEP', sprintf('GATE-%02d', $i + 1), $step, ['step' => $i + 1, 'text' => $step], $i + 1);
            }
            $add('DEVELOPER_INSTRUCTION', 'SECURITY_INSTRUCTION', 'Claude / developer security instruction (§10)', ['text' => $p['instruction']]);
            foreach ($p['principles'] as $i => $text) {
                $add('SECURITY_PRINCIPLE', sprintf('SEC-PRIN-%02d', $i + 1), $text, ['text' => $text], $i + 1);
            }
            $existing = DB::table('document_spec_dictionary')->get(['id', 'kind', 'code'])->mapWithKeys(fn ($r) => [$r->kind.'|'.$r->code => $r->id]);
            foreach (array_chunk($rows, 100) as $chunk) {
                $chunk = array_map(fn ($r) => ['id' => $existing[$r['kind'].'|'.$r['code']] ?? (string) Str::uuid(), 'created_at' => $now] + $r, $chunk);
                DB::table('document_spec_dictionary')->upsert($chunk, ['kind', 'code'], ['name', 'payload', 'rank', 'spec_version', 'status', 'is_seeded', 'updated_at']);
            }
            $this->report['dictionary'] = count($rows);

            // Per-document assignment.
            $ps04 = $p['physical']['PS-04']['recommended_for'] ?? [];
            $assignments = [];
            foreach ($spec->documents() as $id => $doc) {
                $a = SecurityMatrix::assign($id, (string) $doc['category_code'], CanonicalDocumentSpec::profile($doc), $ps04);
                $assignments[$id] = $a;
                DB::table('document_canonical_specs')->where('spec_id', $id)->update([
                    'watermark_profile_code' => $a['watermark_profile_code'], 'seal_profile_codes' => json_encode($a['seal_profile_codes']),
                    'physical_profile_codes' => json_encode($a['physical_profile_codes']),
                    'security_profile_assignment' => json_encode(['method' => $a['method'], 'source' => SecurityMatrix::SOURCE, 'source_hash' => $hash, 'ps04' => $ps04[$id] ?? null]),
                ]);
            }
            $this->report['specs_profiled'] = count($assignments);

            $updated = 0;
            foreach (DB::table('document_types')->whereNotNull('canonical_spec_id')->get(['type_id', 'canonical_spec_id', 'watermark_profile_code', 'seal_profile_codes', 'physical_profile_codes']) as $t) {
                $a = $assignments[$t->canonical_spec_id] ?? null;
                if (! $a) {
                    continue;
                }
                $seals = array_values(array_unique([...(array) json_decode((string) $t->seal_profile_codes, true), ...$a['seal_profile_codes']]));
                $phys = array_values(array_unique([...(array) json_decode((string) $t->physical_profile_codes, true), ...$a['physical_profile_codes']]));
                sort($seals);
                sort($phys);
                $new = ['watermark_profile_code' => $t->watermark_profile_code ?: $a['watermark_profile_code'], 'seal_profile_codes' => json_encode($seals), 'physical_profile_codes' => json_encode($phys)];
                if ($new['watermark_profile_code'] !== $t->watermark_profile_code || $seals !== (array) json_decode((string) $t->seal_profile_codes, true) || $phys !== (array) json_decode((string) $t->physical_profile_codes, true)) {
                    DB::table('document_types')->where('type_id', $t->type_id)->update($new + ['updated_at' => $now]);
                    $updated++;
                }
            }
            $this->report['catalogue_types_updated'] = $updated;
        });
    }
}
