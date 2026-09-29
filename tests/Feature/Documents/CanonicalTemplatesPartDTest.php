<?php

declare(strict_types=1);

/**
 * Canonical templates part D (DOC-166..DOC-220, TEMPLATE_CONTENT_CONTRACT): every document has a PUBLISHED platform
 * template per language with the complete spec field set, and renders through the secure shell (letterhead, zones,
 * template fields, verification block).
 */

use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentShellView;
use App\Application\Documents\Engine\SecureShellRenderer;
use App\Models\DocumentTemplate;
use Database\Seeders\CanonicalTemplates\CanonicalTemplateSeeder;
use Database\Seeders\CanonicalTemplates\CanonicalTemplatesPartDSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    $this->seed(CanonicalTemplatesPartDSeeder::class);
});

const TPLD_FORBIDDEN = '/lorem|sample|example|exemple|specimen|todo|placeholder|dummy|\btests?\b/i';

/** Keys printed by the shell's fixed zones or owned by zones A/B/F (TEMPLATE_CONTENT_CONTRACT §2). */
const TPLD_FIXED = ['party.name', 'policy.insurer', 'policy.product', 'policy.insurance_class', 'policy.number', 'policy.effective_from', 'policy.effective_until',
    'risk.summary', 'risk.registration_number', 'risk.vin', 'risk.make', 'risk.model', 'risk.usage', 'risk.model_year', 'premium.gross', 'premium.taxes',
    'payment.reference', 'payment.amount', 'payment.paid_at', 'payment.method', 'payment.status', 'coverage.lines', 'endorsement.changes', 'claim.number', 'endorsement.number'];

function tpldCode(string $specId): string
{
    return (string) DB::table('document_types')->where('canonical_spec_id', $specId)->orderBy('type_id')->value('canonical_code');
}

function tpldPublished(string $code, string $lang = 'BILINGUAL'): ?DocumentTemplate
{
    return DocumentTemplate::where('document_type_code', $code)->where('ownership', 'PLATFORM')->where('language', $lang)
        ->where('status', 'PUBLISHED')->orderByDesc('version')->first();
}

it('publishes a complete owner-approved template per language for each of DOC-166..DOC-220', function () {
    $docs = (new CanonicalTemplatesPartDSeeder)->contractDocuments();
    expect(array_keys($docs))->toBe(array_map(fn ($n) => sprintf('DOC-%03d', $n), range(166, 220)));
    $names = DB::table('document_canonical_specs')->whereIn('spec_id', array_keys($docs))->get()->keyBy('spec_id');

    foreach ($docs as $specId => $def) {
        $code = tpldCode($specId);
        expect($code)->not->toBe('', "{$specId} has no catalogue type");
        foreach (['BILINGUAL', 'FR', 'EN'] as $lang) {
            $t = tpldPublished($code, $lang);
            expect($t)->not->toBeNull("{$specId} {$lang} has no published template")
                ->and($t->content['schema'])->toBe(CanonicalTemplateSeeder::SCHEMA)
                ->and($t->content['canonical_spec_id'])->toBe($specId)
                ->and($t->title_en)->toBe($names[$specId]->name_en)->and($t->title_fr)->toBe($names[$specId]->name_fr)
                ->and($t->approved_by)->toBe(CanonicalTemplateSeeder::OWNER_APPROVER_ID)
                ->and($t->published_by)->toBe(CanonicalTemplateSeeder::OWNER_APPROVER_ID)
                ->and($t->created_by)->toBe(\Database\Seeders\ProviderDocumentTemplateSeeder::SYSTEM_USER_ID);
        }
        $t = tpldPublished($code);
        expect(count($t->content['fields']))->toBe(count($def['fields']))->and(count($def['fields']))->toBeGreaterThanOrEqual(5);
        foreach ($t->content['fields'] as $f) {
            expect($f['key'])->toMatch('/^[a-z_]+(\.[a-z0-9_]+)+$/')->and($f['label_en'])->not->toBe('')->and($f['label_fr'])->not->toBe('')
                ->and($f['zone'])->toBeIn(['C', 'D']);
        }
        expect(preg_match(TPLD_FORBIDDEN, json_encode($t->content, JSON_UNESCAPED_UNICODE).' '.$t->title_en.' '.$t->title_fr))->toBe(0, "{$specId} has forbidden wording");
    }
});

it('supersedes the minimal provider seeds DOC-198/215/216 and is idempotent', function () {
    foreach (['PROVIDER_SETTLEMENT_STATEMENT', 'PROVIDER_CONTRACT', 'PROVIDER_TARIFF_SCHEDULE'] as $code) {
        $all = DocumentTemplate::where('document_type_code', $code)->where('ownership', 'PLATFORM')->where('language', 'BILINGUAL')->get();
        expect($all->where('status', 'PUBLISHED'))->toHaveCount(1)
            ->and($all->whereIn('status', ['DRAFT', 'REVIEW', 'APPROVED']))->toHaveCount(0)
            ->and(tpldPublished($code)->content['schema'])->toBe(CanonicalTemplateSeeder::SCHEMA);
    }
    $before = DocumentTemplate::count();
    $seeder = new CanonicalTemplatesPartDSeeder;
    $seeder->run();
    expect(DocumentTemplate::count())->toBe($before)->and($seeder->report['published'])->toBe(0)
        ->and($seeder->report['unchanged'])->toBe(165)->and($seeder->report['skipped'])->toBe([]);
});

it('renders every template through the secure shell with all spec fields, letterhead and verification block', function () {
    $captured = null;
    View::composer('pdf.engine-shell', function ($view) use (&$captured) {
        $captured = $view->getData();
    });
    $renderer = app(SecureShellRenderer::class);
    $outDir = getenv('TPLD_PDF_DIR') ?: null;
    $L = fn (string $fr, string $en) => $fr.' / '.$en;
    $money = fn ($minor) => $minor === null ? null : number_format(((int) $minor) / 100, 0, '.', ' ').' XAF';

    foreach ((new CanonicalTemplatesPartDSeeder)->contractDocuments() as $specId => $def) {
        $t = tpldPublished(tpldCode($specId));
        $number = 'OPS-'.substr($specId, 4).'-000001';
        $sections = DocumentEngine::templateSections($t->content, 'BILINGUAL', [
            '{document_number}' => $number, '{event}' => $t->title_en, '{subject}' => '', '{policy_number}' => '', '{insured_name}' => '',
            '{carrier_name}' => 'OpesInsure', '{product_name}' => '', '{coverage_start}' => '', '{coverage_end}' => '',
        ]);
        $pdf = $renderer->render(['type_code' => $t->document_type_code, 'number' => $number, 'verification' => 'VRF'.substr($specId, 4).'KQ',
            'issuer_name' => 'OpesInsure', 'letterhead' => ['issuer' => ['name' => 'OpesInsure'], 'color' => '#0b2a4a'],
            'sections' => $sections, 'title_en' => $t->title_en, 'title_fr' => $t->title_fr, 'label' => $t->title_en, 'template_ref' => $t->code]);
        expect(substr($pdf, 0, 5))->toBe('%PDF-');

        // Template field rows exactly as the engine path builds them (DocumentEngine passes templateContent to the shell view).
        $rows = DocumentShellView::templateFieldRows($t->content['fields'], [], $L, $money);
        $data = array_merge($captured, ['templateParty' => $rows['C'], 'templateContent' => $rows['D']]);
        $html = html_entity_decode(view('pdf.engine-shell', $data)->render(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($outDir && in_array($specId, ['DOC-181', 'DOC-206'], true)) {
            file_put_contents($outDir.'/'.$specId.'.pdf', \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.engine-shell', $data)->setPaper('a4')->output());
        }

        expect($html)->toContain('class="lh"')->toContain('OpesInsure')
            ->toContain('SCANNER POUR VÉRIFIER / SCAN TO VERIFY')->toContain('VRF'.substr($specId, 4).'KQ')->toContain('SHA-256 #')
            ->toContain($t->title_en)->toContain($t->title_fr)->toContain($number)
            ->toContain($def['intro_en'])->toContain($def['intro_fr']);
        foreach ($t->content['fields'] as $f) {
            if (in_array($f['key'], TPLD_FIXED, true) || preg_match('/^(document|verification|template|confidentiality)\./', $f['key'])) {
                continue; // printed by the fixed zones with the dictionary label
            }
            expect($html)->toContain($f['label_fr'].' / '.$f['label_en']);
        }
        expect(preg_match(TPLD_FORBIDDEN, strip_tags($html)))->toBe(0, "{$specId} rendering has forbidden wording");
    }
});
