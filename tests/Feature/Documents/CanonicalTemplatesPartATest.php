<?php

declare(strict_types=1);

// Canonical templates DOC-001..DOC-055 (owner approval 2026-09-28): published, rendered in the secure shell with the
// letterhead, verification block, hash and tier features, every spec field, no filler wording; demo watermark only
// on demo-flagged records.

use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Documents\Engine\DocumentShellView;
use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Security\DocumentFieldRequirements;
use App\Application\Documents\Security\DocumentSecurityProfile;
use App\Models\DocumentTemplate;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Database\Seeders\CanonicalTemplates\CanonicalTemplateSeeder;
use Database\Seeders\CanonicalTemplates\CanonicalTemplatesPartASeeder;
use Database\Seeders\ProviderDocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

require_once __DIR__.'/Concerns/document_engine_helpers.php';

/** spec id => catalogue canonical code, DOC-001..DOC-055. */
function partASpecs(): array
{
    return DB::table('document_types')->whereBetween('canonical_spec_id', ['DOC-001', 'DOC-055'])->orderBy('canonical_spec_id')->pluck('canonical_code', 'canonical_spec_id')->all();
}

/** Render one published template for a real policy exactly as DocumentEngine::generate composes the shell (HTML). */
function partARender(string $code, array $f, string $lang = 'BILINGUAL', bool $demo = false): array
{
    $policy = $f['policy']->fresh(['carrier.party', 'party', 'tenant', 'proposal.offer.product']);
    $type = app(DocumentRegister::class)->describe($code);
    $template = app(DocumentTemplateService::class)->resolve($code, ['carrier_id' => $policy->carrier_id, 'tenant_id' => $policy->tenant_id, 'broker' => false,
        'product_id' => null, 'insurance_class' => 'MOTOR'], $lang);
    $security = app(DocumentSecurityProfile::class)->resolve($type);
    $requirements = app(DocumentFieldRequirements::class)->requiredKeys($type);
    $values = app(DocumentFieldRequirements::class)->resolve($policy, ['document.number' => 'N-1', 'issuer.legal_name' => 'Insurer'], null, (array) ($f['quote']->risk_facts ?? []), []);
    $lh = LetterheadResolver::forDocument('INSURER', (string) $policy->carrier->party->display_name, $policy->carrier_id, $policy->carrier->party->display_name, $policy->tenant_id, null);
    $sections = DocumentEngine::templateSections((array) $template->content, $template->language, ['{policy_number}' => (string) $policy->policy_number, '{carrier_name}' => 'Insurer',
        '{event}' => 'ORIGINAL', '{document_number}' => 'N-1', '{insured_name}' => '', '{product_name}' => '', '{subject}' => '', '{coverage_start}' => '', '{coverage_end}' => '']);
    $qr = (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => true])))->render('https://verify.test/?code=X');
    $data = DocumentShellView::data(['lang' => $template->language, 'template' => $template, 'type' => $type, 'security' => $security, 'values' => $values, 'requirements' => $requirements,
        'number' => 'N-1', 'issuerName' => (string) $policy->carrier->party->display_name, 'carrierName' => 'Insurer', 'intermediary' => null, 'policy' => $policy, 'product' => null,
        'subject' => null, 'subjectFacts' => [], 'label' => 'ORIGINAL', 'issuedAt' => now(), 'verification' => 'ABCD2345EF', 'qr' => $qr, 'verifyUrl' => 'https://verify.opesinsure.com',
        'sections' => $sections, 'contentHash' => hash('sha256', 'x'), 'profile' => null, 'claim' => null, 'transaction' => null, 'coverages' => (array) ($values['coverage.lines'] ?? []),
        'status' => 'ISSUED', 'letterhead' => $lh, 'templateContent' => (array) $template->content, 'demo' => $demo]);

    return ['html' => view('pdf.engine-shell', $data)->render(), 'template' => $template, 'security' => $security, 'data' => $data];
}

beforeEach(function () {
    $root = storage_path('framework/testing/disks/canon-a-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    app(CanonicalTemplatesPartASeeder::class)->run();
});

it('publishes an owner-approved template for every DOC-001..DOC-055 in BILINGUAL, FR and EN, idempotently', function () {
    $specs = partASpecs();
    expect($specs)->toHaveCount(55);
    foreach ($specs as $spec => $code) {
        foreach (['BILINGUAL', 'FR', 'EN'] as $lang) {
            $t = DocumentTemplate::where('document_type_code', $code)->where('ownership', 'PLATFORM')->where('language', $lang)->where('status', 'PUBLISHED')->first();
            expect($t)->not->toBeNull("{$spec} {$lang}")
                ->and($t->created_by)->toBe(ProviderDocumentTemplateSeeder::SYSTEM_USER_ID)
                ->and($t->approved_by)->toBe(CanonicalTemplateSeeder::OWNER_APPROVER_ID)
                ->and($t->published_by)->toBe(CanonicalTemplateSeeder::OWNER_APPROVER_ID)
                ->and($t->content['canonical_spec_id'])->toBe($spec)
                ->and($t->content['fields'])->not->toBeEmpty();
        }
    }
    $count = DocumentTemplate::count();
    $again = app(CanonicalTemplatesPartASeeder::class);
    $again->run();
    expect(DocumentTemplate::count())->toBe($count)->and($again->report['published'])->toBe(0)->and($again->report['unchanged'])->toBe(165);
});

it('a changed template becomes a new published version and the old one is retired', function () {
    $code = partASpecs()['DOC-024'];
    $old = DocumentTemplate::where('document_type_code', $code)->where('language', 'EN')->where('status', 'PUBLISHED')->firstOrFail();
    $seeder = new class extends CanonicalTemplateSeeder
    {
        protected function documents(): array
        {
            return ['DOC-024' => ['intro_en' => 'Summary of policy {policy_number} (revised).', 'intro_fr' => 'Résumé de la police {policy_number} (révisé).',
                'fields' => [['policy.number', 'Policy number', 'N° de police', 'D']], 'languages' => ['EN']]];
        }
    };
    $seeder->run();
    expect($seeder->report['published'])->toBe(1)->and($old->refresh()->status)->toBe('RETIRED')
        ->and(DocumentTemplate::where('code', $old->code)->where('status', 'PUBLISHED')->value('version'))->toBe($old->version + 1);
});

it('renders every DOC-001..DOC-055 with letterhead, verification, hash, tier features and all spec fields, and no filler wording', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-456-CM']);
    foreach (partASpecs() as $spec => $code) {
        $r = partARender($code, $f);
        $html = $r['html'];
        $plain = mb_strtolower(strip_tags(preg_replace('/<(style|script)\b.*?<\/\1>/is', '', $html)));
        $controls = $r['security']['controls'];
        expect($html)->toContain('class="lh"', $f['carrier']->party->display_name)                 // letterhead (Zone A)
            ->and($html)->toContain('ABCD')->toContain('https://verify.opesinsure.com')             // short code + verification URL (Zone F)
            ->and($html)->toContain('SHA-256 #'.substr(hash('sha256', 'x'), 0, 16))                 // hash fragment
            ->and($html)->toContain('class="pageno"')                                              // page x / y
            ->and($html)->toContain(strtok((string) $r['template']->code, '|').' v'.$r['template']->version)               // template code / version (Zone G)
            ->and($html)->toContain($r['security']['tier']);
        if ($controls['qr']['status'] === 'APPLIED') {
            expect($html)->toContain('alt="QR"');
        }
        if ($controls['microtext']['status'] === 'APPLIED') {
            expect($html)->toContain('class="micro"');
        }
        if ($controls['guilloche']['status'] === 'APPLIED') {
            expect($html)->toContain('class="guil"');
        }
        if ($controls['watermark']['status'] === 'APPLIED') {
            expect($html)->toContain('class="wm"');
        }
        if (in_array($r['security']['tier'], ['S3', 'S4', 'S5'], true)) {
            expect($controls['microtext']['status'])->toBe('APPLIED', $spec)->and($controls['guilloche']['status'])->toBe('APPLIED', $spec);
        }
        $shownLabels = array_merge(array_column($r['data']['templateParty'], 'label'), array_column($r['data']['templateContent'], 'label'), array_column($r['data']['templateTables'], 'label'),
            array_column($r['data']['mappedParty'], 'label'), array_column($r['data']['mappedContent'], 'label'));
        foreach ($r['template']->content['fields'] as $field) {
            $label = $field['label_fr'].' / '.$field['label_en'];
            // Printed by a fixed zone (only when that zone really printed it), else as a template row.
            $fixed = in_array($field['key'], $r['data']['printedKeys'], true);
            expect($fixed || in_array($label, $shownLabels, true))->toBeTrue("{$spec}: field {$field['key']} not rendered");
            if (! $fixed) {
                expect($html)->toContain(e($label));
            }
        }
        foreach (['lorem', 'sample', 'example', 'todo', 'placeholder', 'specimen', 'échantillon'] as $bad) {
            expect($plain)->not->toContain($bad, "{$spec} contains '{$bad}'");
        }
    }
});

it('field rows: table fields, money hints, and fixed-zone keys are skipped only when the fixed zone printed them', function () {
    $L = fn ($fr, $en) => $fr.' / '.$en;
    $money = fn ($m) => number_format($m / 100, 0, '.', ' ').' XAF';
    $fields = [
        ['key' => 'driver.schedule', 'label_en' => 'Drivers', 'label_fr' => 'Conducteurs', 'zone' => 'C', 'format' => 'table',
            'columns' => [['key' => 'name', 'label_en' => 'Name', 'label_fr' => 'Nom'], ['key' => 'since', 'label_en' => 'Since', 'label_fr' => 'Depuis', 'format' => 'date']]],
        ['key' => 'settlement.gross', 'label_en' => 'Gross', 'label_fr' => 'Brut', 'zone' => 'D'],
        ['key' => 'fleet.vehicle_count', 'label_en' => 'Vehicles', 'label_fr' => 'Véhicules', 'zone' => 'D'],
        ['key' => 'premium.taxes', 'label_en' => 'Taxes', 'label_fr' => 'Taxes', 'zone' => 'D'],
        ['key' => 'claim.number', 'label_en' => 'Claim', 'label_fr' => 'Sinistre', 'zone' => 'D'],
    ];
    $values = ['driver.schedule' => [['name' => 'A. Mbarga', 'since' => '2024-03-01'], ['name' => 'B. Nkeng', 'since' => null]], 'settlement.gross' => 1250000, 'fleet.vehicle_count' => 12, 'premium.taxes' => 5000];
    $rows = DocumentShellView::templateFieldRows($fields, $values, $L, $money, [], ['claim.number']);
    expect($rows['T'][0]['rows'])->toBe([['A. Mbarga', '01/03/2024'], ['B. Nkeng', '—']])->and($rows['T'][0]['columns'])->toBe(['Nom / Name', 'Depuis / Since']);
    expect(array_column($rows['D'], 'value', 'key'))->toBe(['settlement.gross' => '12 500 XAF', 'fleet.vehicle_count' => '12', 'premium.taxes' => '50 XAF']);
});

it('renders real A4 PDFs for S1, S3, S4 and S5 documents with a resolved page count', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-321-CM']);
    $specs = partASpecs();
    $dir = getenv('CANON_SAMPLE_DIR') ?: null;
    // Render as production does (no UAT environment band) so the page layout is the real one.
    $env = app()['env'];
    app()['env'] = 'production';
    $this->beforeApplicationDestroyed(fn () => app()['env'] = $env);
    foreach (['DOC-001' => 'S1', 'DOC-022' => 'S3', 'DOC-055' => 'S4', 'DOC-036' => 'S5'] as $spec => $tier) {
        config(['document_security.physical_issuance_enabled' => $tier === 'S5']);
        $r = partARender($specs[$spec], $f);
        expect($r['security']['tier'])->toBe($tier);
        $pdf = DocumentShellView::pdf($r['data']);
        expect(substr($pdf, 0, 5))->toBe('%PDF-')->and($pdf)->not->toContain('/ 0)');
        if ($tier === 'S5') {
            expect(preg_match_all('/\/Type\s*\/Page[^s]/', $pdf))->toBe(1); // the motor attestation is a one-page certificate
        }
        if ($dir) {
            @mkdir($dir, 0777, true);
            file_put_contents($dir.DIRECTORY_SEPARATOR.$spec.'-'.$tier.'.pdf', $pdf);
        }
    }
});

it('prints the DEMO watermark only for demo-flagged records; a real record prints clean even with demo mode on', function () {
    config(['demo.enabled' => true]);
    $f = docPolicy('AUTO', ['registration_number' => 'LT-789-CM']);
    $code = partASpecs()['DOC-036'];
    expect(partARender($code, $f, demo: false)['html'])->not->toContain('data-demo-watermark');
    expect(partARender($code, $f, demo: true)['html'])->toContain('data-demo-watermark')->toContain('DEMONSTRATION / DÉMONSTRATION — NOT VALID INSURANCE');

    // Through the engine: the policy's is_demo flag decides.
    docAuthorize($f);
    $seen = [];
    View::composer('pdf.engine-shell', function ($view) use (&$seen) {
        $seen[] = $view->getData()['demoRecord'];
    });
    $engine = app(DocumentEngine::class);
    $engine->fire('POLICY_ISSUED', $f['policy']);
    expect($seen)->not->toBeEmpty()->and(array_unique($seen))->toBe([false]);

    $g = docPolicy('AUTO', ['registration_number' => 'LT-790-CM']);
    DB::table('policies')->where('id', $g['policy']->id)->update(['is_demo' => true]);
    docAuthorize($g);
    $seen = [];
    $manifest = $engine->fire('POLICY_ISSUED', $g['policy']->refresh());
    expect($seen)->not->toBeEmpty()->and(array_unique($seen))->toBe([true])
        ->and(collect($manifest->items)->where('state', 'GENERATED')->count())->toBeGreaterThan(0);
});

it('the engine issues pack documents from the canonical templates (no fixture templates)', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-791-CM']);
    docAuthorize($f);
    $manifest = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $generated = collect($manifest->items)->where('state', 'GENERATED');
    expect($generated->pluck('document_type_code'))->toContain('INSURANCE_POLICY', 'POLICY_SCHEDULE', 'MOTOR_INSURANCE_ATTESTATION');
    foreach ($generated as $i) {
        expect(DocumentTemplate::find($i['template_id'])->created_by)->toBe(ProviderDocumentTemplateSeeder::SYSTEM_USER_ID);
    }
});
