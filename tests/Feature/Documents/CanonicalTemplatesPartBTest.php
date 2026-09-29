<?php

declare(strict_types=1);

/**
 * Canonical templates part B (DOC-056..DOC-110: health, life / savings / beneficiaries, group & corporate).
 * Every document has a PUBLISHED, owner-approved PLATFORM template; each renders through the document shell
 * (DocumentShellView + pdf.engine-shell) with all its field-data-spec fields, the letterhead and the verification
 * block, and no draft wording. Contract: docs/spec/canonical/TEMPLATE_CONTENT_CONTRACT.md.
 */

use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Documents\Engine\DocumentShellView;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Security\DocumentSecurityProfile;
use App\Models\DocumentTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\CanonicalTemplates\CanonicalTemplateSeeder;
use Database\Seeders\CanonicalTemplates\CanonicalTemplatesPartBSeeder;
use Database\Seeders\ProviderDocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Keys printed by the fixed zones (DocumentShellView::SHOWN) or owned by zones A/B/F. */
const PARTB_FIXED = ['party.name', 'policy.insurer', 'policy.product', 'policy.insurance_class', 'policy.number', 'policy.effective_from', 'policy.effective_until',
    'risk.summary', 'premium.gross', 'premium.taxes', 'payment.reference', 'payment.amount', 'payment.paid_at', 'payment.method', 'payment.status', 'coverage.lines', 'claim.number'];

function partbSpecIds(): array
{
    return array_map(fn (int $n) => sprintf('DOC-%03d', $n), range(56, 110));
}

/** Renders the published template of one document through the shell view model; returns [html, data]. */
function partbRender(DocumentTemplate $t, array $values, string $lang = 'BILINGUAL', bool $pdf = false): string
{
    $type = app(DocumentRegister::class)->describe($t->document_type_code);
    $security = app(DocumentSecurityProfile::class)->resolve($type);
    $vars = ['{policy_number}' => (string) ($values['policy.number'] ?? ''), '{insured_name}' => (string) ($values['party.name'] ?? ''), '{carrier_name}' => (string) ($values['policy.insurer'] ?? ''),
        '{product_name}' => (string) ($values['policy.product'] ?? ''), '{subject}' => '', '{coverage_start}' => '01/01/2026', '{coverage_end}' => '31/12/2026',
        '{document_number}' => 'OPS-'.$t->document_type_code.'-000001', '{event}' => $t->title_en];
    $data = DocumentShellView::data([
        'lang' => $lang, 'template' => $t, 'type' => $type, 'security' => $security, 'values' => $values, 'requirements' => [],
        'number' => 'OPS-'.$t->document_type_code.'-000001', 'issuerName' => 'OpesInsure', 'carrierName' => (string) ($values['policy.insurer'] ?? ''), 'intermediary' => null,
        'policy' => (object) ['policy_number' => $values['policy.number'] ?? null, 'version' => 1, 'currency' => 'XAF', 'proposal' => null], 'product' => null,
        'subject' => null, 'subjectFacts' => [], 'label' => $t->title_en, 'issuedAt' => now(), 'verification' => 'VRF7-K2Q9-M4TX', 'qr' => null,
        'verifyUrl' => 'https://opesinsure.com/verify', 'sections' => DocumentEngine::templateSections((array) $t->content, $lang, $vars), 'contentHash' => hash('sha256', $t->content_hash),
        'profile' => null, 'claim' => null, 'transaction' => null, 'coverages' => (array) ($values['coverage.lines'] ?? []), 'status' => 'ISSUED',
        'letterhead' => LetterheadResolver::forDocument('PLATFORM', 'OpesInsure', null, null, null, null), 'templateContent' => (array) $t->content,
    ]);

    return $pdf ? Pdf::loadView('pdf.engine-shell', $data)->setPaper('a4')->output() : view('pdf.engine-shell', $data)->render();
}

/** One recorded value per field of the template (the render must print each). */
function partbValues(DocumentTemplate $t): array
{
    $values = [];
    foreach ((array) $t->content['fields'] as $i => $f) {
        $values[$f['key']] = 'Recorded '.strtoupper(substr(md5($f['key']), 0, 8));
    }
    if (isset($values['premium.gross'])) {
        $values['premium.gross'] = 1_234_500; // minor units: printed "12 345 XAF" by the premium block
    }

    return $values;
}

/** Text the shell prints for a value (amounts in minor units are formatted). */
function partbPrinted(string $key, mixed $value): string
{
    return $key === 'premium.gross' ? '12 345 XAF' : e((string) $value);
}

beforeEach(function () {
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    // The minimal provider seeds (DOC-064..072 REVIEW) exist before part B, as in production.
    app(ProviderDocumentTemplateSeeder::class)->run();
    $this->seeder = app(CanonicalTemplatesPartBSeeder::class);
    $this->seeder->run();
});

it('publishes an owner-approved PLATFORM template for every document DOC-056..DOC-110, idempotently', function () {
    $codes = DB::table('document_types')->whereIn('canonical_spec_id', partbSpecIds())->pluck('canonical_code', 'canonical_spec_id');
    expect($codes)->toHaveCount(55);
    foreach (partbSpecIds() as $specId) {
        $code = $codes[$specId];
        foreach (['BILINGUAL', 'FR', 'EN'] as $lang) {
            $t = DocumentTemplate::where('document_type_code', $code)->where('ownership', 'PLATFORM')->where('language', $lang)->where('status', 'PUBLISHED')->latest('version')->first();
            expect($t)->not->toBeNull("{$specId} {$lang} has no published template")
                ->and($t->content['canonical_spec_id'])->toBe($specId)->and($t->content['schema'])->toBe(CanonicalTemplateSeeder::SCHEMA)
                ->and($t->created_by)->toBe(ProviderDocumentTemplateSeeder::SYSTEM_USER_ID)->and($t->approved_by)->toBe(CanonicalTemplateSeeder::OWNER_APPROVER_ID)
                ->and($t->content['fields'])->not->toBeEmpty();
            // Life documents are LIFE-scoped (life policies only accept LIFE templates).
            expect($t->insurance_class)->toBe(in_array((int) substr($specId, 4), range(76, 95), true) ? 'LIFE' : null);
        }
    }
    // DOC-064..072: the minimal REVIEW seeds are superseded, not left pending.
    expect(DocumentTemplate::whereIn('document_type_code', ['ELIGIBILITY_CONFIRMATION', 'GUARANTEE_OF_PAYMENT', 'EXPLANATION_OF_BENEFITS'])->where('status', 'REVIEW')->count())->toBe(0);

    $published = DocumentTemplate::where('status', 'PUBLISHED')->count();
    $again = app(CanonicalTemplatesPartBSeeder::class);
    $again->run();
    expect($again->report['published'])->toBe(0)->and($again->report['unchanged'])->toBe(55 * 3)
        ->and(DocumentTemplate::where('status', 'PUBLISHED')->count())->toBe($published);
});

it('maps every field of the field-data spec: detailed specs DOC-057, 060, 066, 069, 076, 082, 096 carry their keys', function () {
    $defs = $this->seeder->definitionsForContract();
    expect(array_keys($defs))->toBe(partbSpecIds());
    $expect = [
        'DOC-057' => ['medical.questions', 'medical.conditions', 'medical.treatment', 'medical.hospitalization', 'medical.disability', 'medical.lifestyle', 'consent.record', 'proposal.attested_at'],
        'DOC-060' => ['member.name', 'member.reference', 'member.network', 'member.validity', 'member.emergency_contact', 'member.card_status', 'benefit.copay'],
        'DOC-066' => ['preauth.number', 'preauth.request_reference', 'preauth.service_code', 'preauth.requested_amount', 'decision.approved_amount', 'preauth.member_amount', 'preauth.insurer_amount',
            'preauth.approved_quantity', 'preauth.validity', 'decision.conditions', 'preauth.declined_lines', 'preauth.status', 'preauth.decided_by', 'preauth.decided_at'],
        'DOC-069' => ['guarantee.number', 'guarantee.ceiling', 'preauth.valid_from', 'preauth.valid_until', 'product.claims_requirements', 'issuer.contact', 'approval.signatory'],
        'DOC-076' => ['life.guaranteed_values', 'life.projected_values', 'life.surrender_value', 'life.maturity_values', 'quote.assumptions', 'life.charges', 'illustration.date', 'intermediary.name'],
        'DOC-082' => ['beneficiary.name', 'beneficiary.relationship', 'beneficiary.allocation', 'beneficiary.type', 'beneficiary.contingent', 'beneficiary.allocation_total', 'beneficiary.witness'],
        'DOC-096' => ['group.sponsor', 'group.eligible_members', 'group.member_categories', 'group.contribution_basis', 'group.enrollment_rules', 'group.termination_rules', 'group.dependant_rules',
            'group.member_certificate_rules', 'group.corporate_contacts'],
    ];
    foreach ($expect as $specId => $keys) {
        expect(array_column($defs[$specId]['fields'], 0))->toContain(...$keys);
    }
    foreach ($defs as $specId => $def) {
        foreach ($def['fields'] as [$key, $en, $fr, $zone]) {
            expect($key)->toMatch('/^[a-z_]+(\.[a-z_]+)+$/')->and($en)->not->toBe('')->and($fr)->not->toBe('')->and($zone)->toBeIn(['C', 'D']);
        }
    }
});

it('renders every template through the shell with all its fields, the letterhead and the verification block, and no draft wording', function () {
    $codes = DB::table('document_types')->whereIn('canonical_spec_id', partbSpecIds())->pluck('canonical_code', 'canonical_spec_id');
    foreach (partbSpecIds() as $specId) {
        foreach (['BILINGUAL', 'FR', 'EN'] as $lang) {
            $t = DocumentTemplate::where('document_type_code', $codes[$specId])->where('ownership', 'PLATFORM')->where('language', $lang)->where('status', 'PUBLISHED')->latest('version')->firstOrFail();
            $values = partbValues($t);
            $html = partbRender($t, $values, $lang);
            foreach ((array) $t->content['fields'] as $f) {
                if (preg_match('/^(document|verification|template|confidentiality)\./', $f['key'])) {
                    continue; // printed by zones A/B/F from the issuance itself
                }
                expect($f['key'] === 'claim.number' || str_contains($html, partbPrinted($f['key'], $values[$f['key']])))->toBeTrue("{$specId} {$lang}: value of {$f['key']} not printed");
                if (! in_array($f['key'], PARTB_FIXED, true)) {
                    $label = match ($lang) { 'EN' => $f['label_en'], 'FR' => $f['label_fr'], default => $f['label_fr'].' / '.$f['label_en'] };
                    expect(str_contains($html, e($label)))->toBeTrue("{$specId} {$lang}: label of {$f['key']} not printed");
                }
            }
            expect($html)->toContain('lh-wordmark')->toContain('OpesInsure')                     // letterhead
                ->toContain('VRF7-K2Q9-M4TX')->toContain('opesinsure.com/verify');                   // verification block
            expect($html)->toContain(e($lang === 'FR' ? $t->title_fr : $t->title_en));
            $text = strtolower(strip_tags(preg_replace('#<(style|script)[^>]*>.*?</\1>#s', '', $html)));
            foreach (['lorem', 'sample', 'example', 'todo', 'placeholder', 'specimen'] as $bad) {
                expect(str_contains($text, $bad))->toBeFalse("{$specId} {$lang} contains '{$bad}'");
            }
            $raw = strtolower(json_encode($t->content, JSON_UNESCAPED_UNICODE));
            foreach (['lorem', 'sample', 'example', 'todo', 'placeholder', 'specimen', 'test'] as $bad) {
                expect((bool) preg_match('/\b'.$bad.'\b/u', $raw))->toBeFalse("{$specId} {$lang} template content contains '{$bad}'");
            }
        }
    }
});

it('prints "not recorded" for an absent value rather than inventing one, and renders a valid PDF', function () {
    $t = DocumentTemplate::where('document_type_code', 'GUARANTEE_OF_PAYMENT')->where('language', 'BILINGUAL')->where('status', 'PUBLISHED')->firstOrFail();
    $html = partbRender($t, []);
    expect($html)->toContain('Non renseigné / Not recorded');
    $bytes = partbRender($t, partbValues($t), 'BILINGUAL', true);
    expect(substr($bytes, 0, 5))->toBe('%PDF-');
    $dir = getenv('PARTB_SAMPLE_DIR');
    if ($dir) {
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/DOC-069-guarantee-of-payment.pdf', $bytes);
        $life = DocumentTemplate::where('document_type_code', 'BENEFICIARY_NOMINATION')->where('language', 'BILINGUAL')->where('status', 'PUBLISHED')->firstOrFail();
        file_put_contents($dir.'/DOC-082-beneficiary-nomination.pdf', partbRender($life, partbValues($life), 'BILINGUAL', true));
    }
});
