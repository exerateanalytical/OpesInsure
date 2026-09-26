<?php

declare(strict_types=1);

use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Letterhead\LetterheadService;
use App\Models\Document;
use App\Models\Letterhead\LetterheadAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/document_engine_helpers.php';

/*
 | REQ-DOC-LH-001..006 — document letterheads: admin-uploaded, authorized artwork per insurer (CARRIER) and
 | organisation (TENANT), versioned + audited, rendered in the master shell and legacy views with a text
 | wordmark fallback, snapshotted into issued documents, exposed as logo_url on the public directory.
 */

beforeEach(function () {
    $root = storage_path('framework/testing/disks/lh-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
});

function lhPng(int $w = 200, int $h = 80): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 60, 120));
    ob_start();
    imagepng($im);

    return (string) ob_get_clean();
}

function lhAuth(array $extra = []): array
{
    return ['authorized_by' => 'Directeur Communication, Assureur Test', 'authorized_on' => now()->subDay()->toDateString(), 'authorization_source' => 'Letter ref. COM-2026-01'] + $extra;
}

it('REQ-DOC-LH-001: versions are immutable, audited, carry the authorization, and validate the image', function () {
    $f = docPolicy();
    $svc = app(LetterheadService::class);
    $v1 = $svc->publish('CARRIER', $f['carrier']->id, lhAuth(['brand_color' => '#0a3c78', 'rccm' => 'RC/DLA/2020/B/1', 'public_display' => true]), lhPng(), null, docUser());
    expect($v1->status)->toBe('ACTIVE')->and($v1->version)->toBe(1)->and($v1->brand_color)->toBe('#0A3C78')
        ->and($v1->logo_sha256)->toBe(hash('sha256', lhPng()))->and($v1->logo_width)->toBe(200);
    // Text-only change: new version, same artwork carried forward; v1 superseded but kept.
    $v2 = $svc->publish('CARRIER', $f['carrier']->id, lhAuth(['niu' => 'M012345678901A']), null, null, docUser());
    expect($v2->version)->toBe(2)->and($v2->logo_sha256)->toBe($v1->logo_sha256)->and($v1->fresh()->status)->toBe('SUPERSEDED')
        ->and($v2->footerLines())->toBe(['NIU M012345678901A'])
        ->and(DB::table('audit_log')->where('action', 'letterhead.version.created')->count())->toBe(2);

    // Authorization is required; images are validated (type, size, dimensions, plain SVG only).
    expect(fn () => $svc->publish('CARRIER', $f['carrier']->id, [], lhPng(), null, null))->toThrow(ValidationException::class);
    expect(fn () => $svc->publish('CARRIER', $f['carrier']->id, lhAuth(), 'not an image', null, null))->toThrow(ValidationException::class);
    expect(fn () => $svc->publish('CARRIER', $f['carrier']->id, lhAuth(), lhPng(20, 10), null, null))->toThrow(ValidationException::class);
    expect(fn () => $svc->publish('CARRIER', $f['carrier']->id, lhAuth(), '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', null, null))->toThrow(ValidationException::class);
    expect(fn () => $svc->publish('CARRIER', $f['carrier']->id, lhAuth(['brand_color' => 'blue']), null, null, null))->toThrow(ValidationException::class);
    $svg = $svc->publish('CARRIER', $f['carrier']->id, lhAuth(), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80"><rect width="240" height="80" fill="#0a3c78"/></svg>', null, null);
    expect($svg->logo_mime)->toBe('image/svg+xml')->and($svg->logo_width)->toBe(240);
});

it('REQ-DOC-LH-002: optional maker-checker keeps a new version out of documents until a second admin approves', function () {
    config(['letterheads.maker_checker' => true]);
    $f = docPolicy();
    $maker = docUser();
    $svc = app(LetterheadService::class);
    $v = $svc->publish('TENANT', $f['tenant']->id, lhAuth(), lhPng(), null, $maker);
    expect($v->status)->toBe('PENDING_APPROVAL')->and(LetterheadResolver::current('TENANT', $f['tenant']->id))->toBeNull();
    expect(fn () => $svc->approve($v, $maker))->toThrow(ValidationException::class);
    $svc->approve($v, docUser());
    expect(LetterheadResolver::current('TENANT', $f['tenant']->id)?->id)->toBe($v->id);
});

it('REQ-DOC-LH-003: the letterhead partial shows the logo, the co-branding row and footer lines, and falls back to a text wordmark', function () {
    $f = docPolicy();
    $svc = app(LetterheadService::class);
    $none = LetterheadResolver::forDocument('INSURER', 'Assureur Test', $f['carrier']->id, 'Assureur Test', $f['tenant']->id, ['name' => 'Courtier Test', 'licence' => 'LIC-1']);
    $html = view('pdf._letterhead', ['letterhead' => $none])->render();
    expect($html)->toContain('Assureur Test')->not->toContain('<img')->toContain('Courtier Test');

    $svc->publish('CARRIER', $f['carrier']->id, lhAuth(['registered_address' => 'Rue 1, Douala', 'licence_reference' => 'Agrément MINFI 000']), lhPng(), null, null);
    $svc->publish('TENANT', $f['tenant']->id, lhAuth(), lhPng(120, 40), null, null);
    $lh = LetterheadResolver::forDocument('INSURER', 'Assureur Test', $f['carrier']->id, 'Assureur Test', $f['tenant']->id, ['name' => 'Courtier Test', 'licence' => null]);
    $html = view('pdf._letterhead', ['letterhead' => $lh])->render().view('pdf._letterhead_footer', ['letterhead' => $lh])->render();
    expect(substr_count($html, 'src="data:image/png;base64,'))->toBe(2)->and($html)->toContain('Rue 1, Douala')->toContain('Agrément MINFI 000');

    // A missing / tampered file never renders a broken image: back to the wordmark.
    Storage::disk('local')->put(LetterheadResolver::current('CARRIER', $f['carrier']->id)->logo_path, 'tampered');
    $lh = LetterheadResolver::forDocument('INSURER', 'Assureur Test', $f['carrier']->id, 'Assureur Test', null, null);
    expect($lh['issuer']['logo'])->toBeNull()->and(view('pdf._letterhead', ['letterhead' => $lh])->render())->not->toContain('<img');
});

it('REQ-DOC-LH-004: issued engine documents snapshot the letterhead version and hashes; later uploads do not change them', function () {
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    $f = docPolicy('AUTO', ['registration_number' => 'LT-990-LH']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION']);
    $v1 = app(LetterheadService::class)->publish('CARRIER', $f['carrier']->id, lhAuth(), lhPng(), null, null);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $doc = Document::find(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id']);
    $snapshot = json_decode(DB::table('documents')->where('id', $doc->id)->value('issuance_snapshot'), true);
    expect($snapshot['issuer']['letterhead']['issuer'])->toMatchArray(['letterhead_id' => $v1->id, 'version' => 1, 'logo_sha256' => $v1->logo_sha256])
        ->and($doc->provenance['letterhead']['issuer']['version'])->toBe(1);

    app(LetterheadService::class)->publish('CARRIER', $f['carrier']->id, lhAuth(), lhPng(300, 100), null, null);
    expect(Document::find($doc->id)->provenance['letterhead']['issuer']['logo_sha256'])->toBe($v1->logo_sha256);
});

it('REQ-DOC-LH-005: legacy certificate / schedule views and the quote PDF render with the shared partial', function () {
    $f = docPolicy();
    app(LetterheadService::class)->publish('CARRIER', $f['carrier']->id, lhAuth(['rccm' => 'RC/TEST/1']), lhPng(), null, null);
    $lh = LetterheadResolver::forPolicy($f['policy']);
    foreach ([\App\Application\Policies\PolicyDocumentService::CERTIFICATE, \App\Application\Policies\PolicyDocumentService::SCHEDULE] as $category) {
        $spec = \App\Application\Policies\PolicyDocumentService::shellSpec($f['policy'], new \App\Models\PolicyCertificate(['serial_number' => 'S-1', 'issued_at' => now()]), $category, null, 'https://v', $lh);
        $type = app(\App\Application\Documents\Engine\DocumentRegister::class)->describe($spec['type_code']);
        $security = app(\App\Application\Documents\Security\DocumentSecurityProfile::class)->resolve($type);
        $html = view('pdf._letterhead', ['letterhead' => $lh])->render().view('pdf._letterhead_footer', ['letterhead' => $lh])->render();
        expect($spec['letterhead'])->toBe($lh)->and($html)->toContain('data:image/png;base64,')->toContain('RCCM RC/TEST/1');
        expect(strlen(app(\App\Application\Documents\Engine\SecureShellRenderer::class)->render($spec)))->toBeGreaterThan(500)->and($security)->toHaveKey('controls');
    }
    expect(strlen(app(\App\Application\Quotes\QuoteDocumentRenderer::class)->pdf($f['quote'])))->toBeGreaterThan(500);
});

it('REQ-DOC-LH-006: public institutions expose logo_url only for public-display logos, and the logo route serves it', function () {
    $f = docPolicy();
    $f['carrier']->update(['status' => 'ACTIVE']);
    $svc = app(LetterheadService::class);
    $svc->publish('CARRIER', $f['carrier']->id, lhAuth(['public_display' => false]), lhPng(), null, null);
    $this->getJson('/api/v1/public/institutions/'.$f['carrier']->id)->assertOk()
        ->assertJsonPath('data.logo_url', null)->assertJsonPath('data.letterhead_available', true);
    $private = LetterheadResolver::current('CARRIER', $f['carrier']->id);
    $this->get('/api/v1/public/letterheads/'.$private->id.'/logo')->assertNotFound();

    $this->getJson('/api/v1/public/institutions/'.$f['carrier']->id)->assertJsonPath('data.legal_footer', []);
    $v = $svc->publish('CARRIER', $f['carrier']->id, lhAuth(['public_display' => true, 'rccm' => 'RC/PUB/1']), null, null, null);
    $this->getJson('/api/v1/public/institutions/'.$f['carrier']->id)->assertJsonPath('data.legal_footer', ['RCCM RC/PUB/1']);
    expect(str_starts_with((string) LetterheadResolver::publicLogoUrl($v), 'http'))->toBeTrue();
    $url = $this->getJson('/api/v1/public/institutions/'.$f['carrier']->id)->assertOk()->json('data.logo_url');
    expect($url)->toContain('/api/v1/public/letterheads/'.$v->id.'/logo');
    $row = collect($this->getJson('/api/v1/public/institutions?type=insurer')->assertOk()->json('data'))->firstWhere('id', $f['carrier']->id);
    expect($row['logo_url'])->toBe($url)->and($row['letterhead_available'])->toBeTrue();
    $res = $this->get('/api/v1/public/letterheads/'.$v->id.'/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    // The superseded (private) version stays private.
    $this->get('/api/v1/public/letterheads/'.$private->id.'/logo')->assertNotFound();
});
