<?php

declare(strict_types=1);

namespace Database\Seeders\CanonicalTemplates;

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Models\DocumentTemplate;
use App\Models\User;
use Database\Seeders\ProviderDocumentTemplateSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared lifecycle of the canonical templates DOC-001..DOC-220 (contract: docs/spec/canonical/TEMPLATE_CONTENT_CONTRACT.md).
 *
 * Owner approval (2026-09-28): every canonical template is built, approved and published as specified. Each template is
 * authored by the disabled system account (ProviderDocumentTemplateSeeder::SYSTEM_USER_ID) and approved + published by a
 * second disabled account that records the owner's approval, so the maker-checker rule of DocumentTemplateService is kept
 * (the author never approves). Idempotent: when the lineage's PUBLISHED version already has the same content hash nothing
 * happens; otherwise a new version is created and published (the service retires the previous one). Never deletes.
 */
abstract class CanonicalTemplateSeeder extends Seeder
{
    public const OWNER_APPROVER_ID = '00000000-0000-4000-8000-00000000a220';

    public const SCHEMA = 'canonical-template/v1';

    public const SOURCE = 'OpesInsure_220_Document_Field_Data_Specification_v1';

    public const LANGUAGES = ['BILINGUAL', 'FR', 'EN'];

    /** @var array{published: int, unchanged: int, skipped: array<int, string>} */
    public array $report = ['published' => 0, 'unchanged' => 0, 'skipped' => []];

    /**
     * spec id => definition: intro_en/intro_fr (optional), fields [[key, label_en, label_fr, zone]], sections, notices,
     * languages, insurance_class (LIFE for life documents).
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function documents(): array;

    public function run(): void
    {
        if (! Schema::hasTable('document_templates') || ! Schema::hasTable('document_types')) {
            return;
        }
        $service = app(DocumentTemplateService::class);
        $author = self::systemUser(ProviderDocumentTemplateSeeder::SYSTEM_USER_ID, 'OpesInsure system (document templates)', 'system-document-templates@opesinsure.invalid', '+00000000d0c4');
        $approver = self::systemUser(self::OWNER_APPROVER_ID, 'Owner approval 2026-09-28 (canonical templates)', 'owner-approval-templates@opesinsure.invalid', '+00000000a220');
        $docs = $this->documents();
        $types = DB::table('document_types')->whereIn('canonical_spec_id', array_keys($docs))->orderBy('type_id')->get(['type_id', 'canonical_code', 'canonical_spec_id'])
            ->groupBy('canonical_spec_id')->map(fn ($g) => ($g->firstWhere('type_id', $g->first()->canonical_spec_id) ?? $g->first())->canonical_code);
        $names = Schema::hasTable('document_canonical_specs') ? DB::table('document_canonical_specs')->whereIn('spec_id', array_keys($docs))->get(['spec_id', 'name_en', 'name_fr'])->keyBy('spec_id') : collect();

        foreach ($docs as $specId => $def) {
            $code = $types[$specId] ?? null;
            if (! $code) {
                $this->report['skipped'][] = $specId.': no catalogue type';

                continue;
            }
            $content = self::content($specId, $def);
            foreach ((array) ($def['languages'] ?? self::LANGUAGES) as $lang) {
                $scope = ['document_type_code' => $code, 'ownership' => 'PLATFORM', 'language' => $lang, 'insurance_class' => $def['insurance_class'] ?? null];
                $titleEn = (string) ($names[$specId]->name_en ?? $def['title_en'] ?? $code);
                $titleFr = (string) ($names[$specId]->name_fr ?? $def['title_fr'] ?? $titleEn);
                $lineage = DocumentTemplateService::lineage($scope);
                $probe = new DocumentTemplate(['title_en' => $titleEn, 'title_fr' => $titleFr, 'content' => $content, 'language' => $lang, 'document_type_code' => $code]);
                $hash = $service->hash($probe);
                if (DocumentTemplate::where('code', $lineage)->where('status', 'PUBLISHED')->where('content_hash', $hash)->exists()) {
                    $this->report['unchanged']++;

                    continue;
                }
                // Earlier system seeds of this lineage still waiting for review are superseded by the owner-approved version.
                DocumentTemplate::where('code', $lineage)->whereIn('status', ['DRAFT', 'REVIEW', 'APPROVED'])->where('created_by', $author->id)->get()
                    ->each(fn (DocumentTemplate $old) => $service->retire($old, 'Superseded by the owner-approved canonical template (2026-09-28)', $approver));
                $t = $service->createDraft($scope + ['title_en' => $titleEn, 'title_fr' => $titleFr, 'content' => $content, 'effective_from' => '2026-01-01'], $author);
                $service->submit($t, $author);
                $service->approveAndPublishSystem($t->refresh(), $approver);
                $this->report['published']++;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    public static function content(string $specId, array $def): array
    {
        $sections = [];
        if (! empty($def['intro_en']) || ! empty($def['intro_fr'])) {
            $sections[] = ['heading_en' => '', 'heading_fr' => '', 'body_en' => (string) ($def['intro_en'] ?? ''), 'body_fr' => (string) ($def['intro_fr'] ?? '')];
        }
        foreach ((array) ($def['sections'] ?? []) as $s) {
            $sections[] = ['heading_en' => (string) ($s['heading_en'] ?? ''), 'heading_fr' => (string) ($s['heading_fr'] ?? ''), 'body_en' => (string) ($s['body_en'] ?? ''), 'body_fr' => (string) ($s['body_fr'] ?? '')];
        }
        $fields = [];
        foreach ((array) ($def['fields'] ?? []) as $f) {
            // [key, label_en, label_fr, zone?, format?, columns?] or ['key' => …, 'label_en' => …, 'label_fr' => …, 'zone' => …, 'format' => …, 'columns' => …]
            [$key, $en, $fr, $zone, $format, $columns] = isset($f['key'])
                ? [$f['key'], $f['label_en'] ?? '', $f['label_fr'] ?? '', $f['zone'] ?? 'D', $f['format'] ?? null, $f['columns'] ?? []]
                : array_values($f) + [3 => 'D', 4 => null, 5 => []];
            $field = ['key' => (string) $key, 'label_en' => (string) $en, 'label_fr' => (string) $fr, 'zone' => $zone === 'C' ? 'C' : 'D'];
            if (in_array($format, ['money', 'date', 'datetime', 'text'], true)) {
                $field['format'] = $format;
            } elseif ($format === 'table') {
                $field['format'] = 'table';
                $field['columns'] = array_values(array_map(function ($c): array {
                    [$ck, $cen, $cfr, $cfmt] = isset($c['key']) ? [$c['key'], $c['label_en'] ?? '', $c['label_fr'] ?? '', $c['format'] ?? null] : array_values($c) + [3 => null];

                    return ['key' => (string) $ck, 'label_en' => (string) $cen, 'label_fr' => (string) $cfr] + ($cfmt ? ['format' => (string) $cfmt] : []);
                }, (array) $columns));
            }
            $fields[] = $field;
        }
        $notices = array_values(array_map(fn ($n) => ['en' => (string) ($n['en'] ?? ''), 'fr' => (string) ($n['fr'] ?? '')], (array) ($def['notices'] ?? [])));

        return ['schema' => self::SCHEMA, 'canonical_spec_id' => $specId, 'system_seeded' => true, 'source' => self::SOURCE,
            'sections' => $sections, 'fields' => $fields, 'notices' => $notices];
    }

    /** Disabled account (no password, DISABLED): cannot sign in; exists only as a named actor of the template lifecycle. */
    public static function systemUser(string $id, string $name, string $email, string $phone): User
    {
        if (! User::query()->whereKey($id)->exists()) {
            DB::table('users')->insert(['id' => $id, 'full_name' => $name, 'email' => $email, 'phone_e164' => $phone, 'password' => null, 'status' => 'DISABLED',
                'created_at' => now(), 'updated_at' => now()]);
        }

        return User::query()->findOrFail($id);
    }
}
