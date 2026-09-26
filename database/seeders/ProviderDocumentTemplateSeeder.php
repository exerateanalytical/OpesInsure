<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D4 follow-up: PLATFORM-level templates for the provider documents (canonical DOC-064..072, 198, 215, 216),
 * rendered through the canonical secure shell by the document engine.
 *
 * Governance: the template lifecycle is maker-checker (DRAFT → REVIEW → APPROVED → PUBLISHED, the creator can
 * neither approve nor publish) and has no "system template" bypass, so these templates are seeded in REVIEW,
 * authored by a dedicated, disabled system account. An administrator (documents.templates.manage) activates each
 * with the one-step `approve-publish` action (DocumentTemplateService::approveAndPublishSystem).
 *
 * Content: neutral wording only — the canonical spec's document name and its "minimum additions" field list
 * (source OWNER_CANONICAL_IMPLEMENTATION_SPEC_V1); no legal clauses, no invented obligations. The event facts
 * (eligibility result, lines, amounts …) are added by the engine at issuance. Idempotent: a lineage that already
 * has any version is never touched (admin edits and published versions are preserved). Never deletes.
 */
final class ProviderDocumentTemplateSeeder extends Seeder
{
    public const SYSTEM_USER_ID = '00000000-0000-4000-8000-00000000d0c4';

    public const SOURCE = 'OWNER_CANONICAL_IMPLEMENTATION_SPEC_V1';

    /** catalogue canonical_code => canonical spec id */
    public const TYPES = [
        'ELIGIBILITY_CONFIRMATION' => 'DOC-064', 'PREAUTHORIZATION_REQUEST' => 'DOC-065', 'PREAUTHORIZATION_APPROVAL' => 'DOC-066',
        'PARTIAL_PREAUTHORIZATION_APPROVAL' => 'DOC-067', 'PREAUTHORIZATION_REJECTION' => 'DOC-068', 'GUARANTEE_OF_PAYMENT' => 'DOC-069',
        'HOSPITAL_ADMISSION_AUTHORIZATION' => 'DOC-070', 'HOSPITAL_STAY_EXTENSION_AUTHORIZATION' => 'DOC-071', 'EXPLANATION_OF_BENEFITS' => 'DOC-072',
        'PROVIDER_SETTLEMENT_STATEMENT' => 'DOC-198', 'PROVIDER_CONTRACT' => 'DOC-215', 'PROVIDER_TARIFF_SCHEDULE' => 'DOC-216',
    ];

    /** @var array<string, int> */
    public array $report = ['created' => 0, 'kept' => 0];

    public function run(): void
    {
        if (! Schema::hasTable('document_templates')) {
            return;
        }
        $author = $this->systemAuthor();
        $service = app(DocumentTemplateService::class);
        $specs = Schema::hasTable('document_canonical_specs')
            ? DB::table('document_canonical_specs')->whereIn('spec_id', array_values(self::TYPES))->get()->keyBy('spec_id') : collect();

        foreach (self::TYPES as $code => $specId) {
            $lineage = DocumentTemplateService::lineage(['document_type_code' => $code, 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL']);
            if (DocumentTemplate::where('code', $lineage)->exists()) {
                $this->report['kept']++;

                continue;
            }
            $spec = $specs[$specId] ?? null;
            $nameEn = $spec->name_en ?? ucwords(strtolower(str_replace('_', ' ', $code)));
            $nameFr = $spec->name_fr ?? $nameEn;
            $fields = $spec?->minimum_additions ? trim((string) $spec->minimum_additions) : null;
            $sections = [[
                'heading_en' => $nameEn, 'heading_fr' => $nameFr,
                'body_en' => 'Document {document_number} — {event}. Reference: {subject}.',
                'body_fr' => 'Document {document_number} — {event}. Référence : {subject}.',
            ]];
            if ($fields) {
                // Spec field list, as published (English source); no translation invented.
                $sections[] = ['heading_en' => 'Content', 'heading_fr' => 'Contenu', 'body_en' => $fields, 'body_fr' => $fields];
            }
            $sections[] = ['heading_en' => 'Verification', 'heading_fr' => 'Vérification',
                'body_en' => 'The online verification status is authoritative for this document.',
                'body_fr' => 'Le statut de vérification en ligne fait foi pour ce document.'];

            $t = $service->createDraft(['document_type_code' => $code, 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'title_en' => $nameEn, 'title_fr' => $nameFr,
                'content' => ['sections' => $sections, 'source' => self::SOURCE, 'canonical_spec_id' => $specId, 'system_seeded' => true],
                'effective_from' => '2026-01-01'], $author);
            $service->submit($t, $author);
            $this->report['created']++;
        }
    }

    /** Dedicated, disabled author (cannot sign in: no password, DISABLED) so any real administrator can be the checker. */
    private function systemAuthor(): User
    {
        $u = User::query()->find(self::SYSTEM_USER_ID);
        if ($u) {
            return $u;
        }
        DB::table('users')->insert(['id' => self::SYSTEM_USER_ID, 'full_name' => 'OpesInsure system (document templates)', 'email' => 'system-document-templates@opesinsure.invalid',
            'phone_e164' => '+00000000d0c4', 'password' => null, 'status' => 'DISABLED', 'created_at' => now(), 'updated_at' => now()]);

        return User::query()->findOrFail(self::SYSTEM_USER_ID);
    }
}
