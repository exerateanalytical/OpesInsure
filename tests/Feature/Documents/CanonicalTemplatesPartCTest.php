<?php

declare(strict_types=1);

// Canonical templates DOC-111..DOC-165 (owner approval 2026-09-28): published in BILINGUAL, FR and EN by the system
// author with the owner-approval checker, idempotent, and rendered in the secure shell with the letterhead, the
// verification block and every spec field, without filler wording.

use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Documents\Engine\DocumentShellView;
use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Security\DocumentFieldRequirements;
use App\Application\Documents\Security\DocumentSecurityProfile;
use App\Models\DocumentTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\CanonicalTemplates\CanonicalTemplateSeeder;
use Database\Seeders\CanonicalTemplates\CanonicalTemplatesPartCSeeder;
use Database\Seeders\ProviderDocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

require_once __DIR__.'/Concerns/document_engine_helpers.php';

/** spec id => catalogue canonical code, DOC-111..DOC-165. */
function partCSpecs(): array
{
    return DB::table('document_types')->whereBetween('canonical_spec_id', ['DOC-111', 'DOC-165'])->orderBy('canonical_spec_id')->pluck('canonical_code', 'canonical_spec_id')->all();
}

/** Render one published template for a real policy as DocumentEngine::generate composes the shell. */
function partCRender(string $code, array $f, string $lang = 'BILINGUAL'): array
{
    $policy = $f['policy']->fresh(['carrier.party', 'party', 'tenant', 'proposal.offer.product']);
    $type = app(DocumentRegister::class)->describe($code);
    $template = app(DocumentTemplateService::class)->resolve($code, ['carrier_id' => $policy->carrier_id, 'tenant_id' => $policy->tenant_id, 'broker' => false,
        'product_id' => null, 'insurance_class' => null], $lang);
    $security = app(DocumentSecurityProfile::class)->resolve($type);
    $requirements = app(DocumentFieldRequirements::class)->requiredKeys($type);
    $values = app(DocumentFieldRequirements::class)->resolve($policy, ['document.number' => 'N-1', 'issuer.legal_name' => 'Insurer'], null, (array) ($f['quote']->risk_facts ?? []), []);
    $carrier = (string) $policy->carrier->party->display_name;
    $lh = LetterheadResolver::forDocument('INSURER', $carrier, $policy->carrier_id, $carrier, $policy->tenant_id, null);
    $sections = DocumentEngine::templateSections((array) $template->content, $template->language, ['{policy_number}' => (string) $policy->policy_number, '{carrier_name}' => $carrier,
        '{event}' => 'ORIGINAL', '{document_number}' => 'N-1', '{insured_name}' => '', '{product_name}' => '', '{subject}' => '', '{coverage_start}' => '', '{coverage_end}' => '']);
    $qr = (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => true])))->render('https://verify.opesinsure.com/?code=ABCD2345EF');
    $data = DocumentShellView::data(['lang' => $template->language, 'template' => $template, 'type' => $type, 'security' => $security, 'values' => $values, 'requirements' => $requirements,
        'number' => 'N-1', 'issuerName' => $carrier, 'carrierName' => $carrier, 'intermediary' => null, 'policy' => $policy, 'product' => null,
        'subject' => null, 'subjectFacts' => [], 'label' => 'ORIGINAL', 'issuedAt' => now(), 'verification' => 'ABCD2345EF', 'qr' => $qr, 'verifyUrl' => 'https://verify.opesinsure.com',
        'sections' => $sections, 'contentHash' => hash('sha256', 'x'), 'profile' => null, 'claim' => null, 'transaction' => null, 'coverages' => (array) ($values['coverage.lines'] ?? []),
        'status' => 'ISSUED', 'letterhead' => $lh, 'templateContent' => (array) $template->content, 'demo' => false]);

    return ['html' => view('pdf.engine-shell', $data)->render(), 'template' => $template, 'security' => $security, 'data' => $data];
}

beforeEach(function () {
    $root = storage_path('framework/testing/disks/canon-c-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    app(CanonicalTemplatesPartCSeeder::class)->run();
});

it('publishes an owner-approved template for every DOC-111..DOC-165 in BILINGUAL, FR and EN, idempotently', function () {
    $specs = partCSpecs();
    expect($specs)->toHaveCount(55)->and(array_key_first($specs))->toBe('DOC-111')->and(array_key_last($specs))->toBe('DOC-165');
    foreach ($specs as $spec => $code) {
        foreach (['BILINGUAL', 'FR', 'EN'] as $lang) {
            $t = DocumentTemplate::where('document_type_code', $code)->where('ownership', 'PLATFORM')->where('language', $lang)->where('status', 'PUBLISHED')->first();
            expect($t)->not->toBeNull("{$spec} {$lang}")
                ->and($t->created_by)->toBe(ProviderDocumentTemplateSeeder::SYSTEM_USER_ID)
                ->and($t->approved_by)->toBe(CanonicalTemplateSeeder::OWNER_APPROVER_ID)
                ->and($t->published_by)->toBe(CanonicalTemplateSeeder::OWNER_APPROVER_ID)
                ->and($t->content['canonical_spec_id'])->toBe($spec)
                ->and($t->content['schema'])->toBe(CanonicalTemplateSeeder::SCHEMA)
                ->and($t->content['fields'])->not->toBeEmpty();
            foreach ($t->content['fields'] as $field) {
                expect($field['key'])->toMatch('/^[a-z_]+\.[a-z_]+$/')->and(in_array($field['zone'], ['C', 'D'], true))->toBeTrue()
                    ->and($field['label_en'])->not->toBe('')->and($field['label_fr'])->not->toBe('');
            }
        }
    }
    $count = DocumentTemplate::count();
    $again = app(CanonicalTemplatesPartCSeeder::class);
    $again->run();
    expect(DocumentTemplate::count())->toBe($count)->and($again->report['published'])->toBe(0)->and($again->report['unchanged'])->toBe(165);
});

it('renders every DOC-111..DOC-165 with letterhead, verification block, hash and all spec fields, and no filler wording', function () {
    $f = docPolicy('PROPERTY');
    foreach (partCSpecs() as $spec => $code) {
        foreach (['BILINGUAL', 'EN', 'FR'] as $lang) {
            $r = partCRender($code, $f, $lang);
            $html = $r['html'];
            $plain = mb_strtolower(strip_tags(preg_replace('/<(style|script)\b.*?<\/\1>/is', '', $html)));
            expect($html)->toContain('class="lh"')                                               // letterhead (Zone A)
                ->toContain('ABCD2345EF')->toContain('https://verify.opesinsure.com')           // verification code + URL (Zone F)
                ->toContain('SHA-256 #'.substr(hash('sha256', 'x'), 0, 16))
                ->toContain(strtok((string) $r['template']->code, '|').' v'.$r['template']->version)
                ->toContain($r['security']['tier']);
            if ($r['security']['controls']['qr']['status'] === 'APPLIED') {
                expect($html)->toContain('alt="QR"');
            }
            $shown = array_merge(array_column($r['data']['templateParty'], 'label'), array_column($r['data']['templateContent'], 'label'),
                array_column($r['data']['mappedParty'], 'label'), array_column($r['data']['mappedContent'], 'label'));
            foreach ($r['template']->content['fields'] as $field) {
                $label = match ($lang) { 'EN' => $field['label_en'], 'FR' => $field['label_fr'], default => $field['label_fr'].' / '.$field['label_en'] };
                $fixed = in_array($field['key'], ['party.name', 'policy.number', 'policy.insurer', 'policy.product', 'policy.effective_from', 'policy.effective_until', 'risk.summary',
                    'premium.gross', 'premium.taxes', 'payment.status', 'payment.reference', 'coverage.lines', 'claim.number', 'endorsement.number', 'endorsement.changes'], true);
                expect($fixed || in_array($label, $shown, true))->toBeTrue("{$spec} {$lang}: field {$field['key']} not rendered");
                if (! $fixed) {
                    expect($html)->toContain(e($label));
                }
            }
            expect(preg_match('/\{[a-z_.]+\}/', $plain))->toBe(0, "{$spec} {$lang}: unsubstituted token");
            // "test" is checked on the template itself (the fixture's party names contain it).
            expect(preg_match('/\btest\b/i', json_encode($r['template']->content, JSON_UNESCAPED_UNICODE).$r['template']->title_en.$r['template']->title_fr))->toBe(0, "{$spec}: 'test' wording");
            preg_match("/.{0,40}(lorem|sample|example|\btodo\b|placeholder|specimen|échantillon).{0,40}/u", $plain, $m); expect($m[0] ?? null)->toBeNull("{$spec} {$lang}: filler wording");
        }
    }
});

it('writes review PDFs when PARTC_PDF_DIR is set', function () {
    $dir = getenv('PARTC_PDF_DIR');
    if (! $dir) {
        expect(true)->toBeTrue();

        return;
    }
    @mkdir($dir, 0777, true);
    $f = docPolicy('PROPERTY');
    foreach (['DOC-119' => 'BILINGUAL', 'DOC-161' => 'FR'] as $spec => $lang) {
        $r = partCRender(partCSpecs()[$spec], $f, $lang);
        file_put_contents($dir.DIRECTORY_SEPARATOR.$spec.'-'.$lang.'.pdf', Pdf::loadView('pdf.engine-shell', $r['data'])->setPaper('a4')->output());
    }
    expect(file_exists($dir.DIRECTORY_SEPARATOR.'DOC-161-FR.pdf'))->toBeTrue();
});
