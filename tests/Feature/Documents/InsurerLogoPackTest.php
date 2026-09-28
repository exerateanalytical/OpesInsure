<?php

declare(strict_types=1);

use App\Application\Documents\Letterhead\InsurerLogoPack;
use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Letterhead\LetterheadService;
use App\Models\Carrier;
use App\Models\Letterhead\LetterheadAsset;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/document_engine_helpers.php';

/*
 | Owner-supplied logo pack of the 29 official insurers (resources/insurer-logos) imported as public-display
 | letterhead logos: explicit mapping to register carriers, idempotent, never overwrites an insurer's own logo,
 | WebP served as-is publicly and converted to PNG for PDFs.
 */

beforeEach(function () {
    $root = storage_path('framework/testing/disks/lp-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->packDir = storage_path('framework/testing/pack-'.Str::random(10));
    File::ensureDirectoryExists($this->packDir);
    $this->beforeApplicationDestroyed(function () use ($root) {
        File::deleteDirectory($root);
        File::deleteDirectory($this->packDir);
    });
});

function lpWebp(int $w = 160, int $h = 80, array $rgb = [10, 60, 120]): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    ob_start();
    imagewebp($im, null, 80);

    return (string) ob_get_clean();
}

/** Writes a pack (subset of the real manifest contract) and returns the manifest rows. */
function lpPack(string $dir, array $numbers, array $rgb = [10, 60, 120]): array
{
    $rows = [];
    foreach ($numbers as $n) {
        [, , $branch, $company] = InsurerLogoPack::MAP[$n];
        $file = sprintf('%02d_%s_%s.webp', $n, $branch, str_replace(' ', '_', $company));
        $bytes = lpWebp(160, 80, $rgb);
        file_put_contents($dir.'/'.$file, $bytes);
        $rows[] = ['number' => $n, 'branch' => $branch, 'company' => $company, 'file' => $file, 'source' => 'https://example.test/'.$n.'.png',
            'asset_status' => 'company image', 'width' => 160, 'height' => 80, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
    file_put_contents($dir.'/manifest.json', json_encode($rows));

    return $rows;
}

function lpCarrier(string $canonicalId): Carrier
{
    return Carrier::where('canonical_id', $canonicalId)->firstOrFail();
}

function lpActions(array $rows): array
{
    return collect($rows)->mapWithKeys(fn ($r) => [$r['number'] => $r['action']])->all();
}

it('maps the 29 pack entries explicitly onto 29 distinct register carriers, life and non-life apart', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);
    $ids = collect(InsurerLogoPack::MAP)->map(fn ($m) => Carrier::where('canonical_id', $m[0])->where('insurer_code', $m[1])->value('id'));
    expect($ids->filter()->count())->toBe(29)->and($ids->unique()->count())->toBe(29);
    foreach (InsurerLogoPack::MAP as [$canonical, , $branch]) {
        expect(lpCarrier($canonical)->licence_branch)->toBe($branch === 'life' ? 'LIFE' : 'IARD');
    }
    // Pack order differs from the register sequence for SAAR / SanlamAllianz.
    expect(lpCarrier(InsurerLogoPack::MAP[15][0])->short_name)->toBe('SAAR')
        ->and(lpCarrier(InsurerLogoPack::MAP[16][0])->short_name)->toBe('SANLAMALLIANZ');
});

it('imports each logo as an ACTIVE public WebP letterhead version with the owner authorization', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);
    $rows = lpPack($this->packDir, [1, 15, 20]);

    $dry = app(InsurerLogoPack::class)->import($this->packDir, dryRun: true);
    expect(array_filter(lpActions($dry), fn ($a) => $a !== 'missing'))->toBe([1 => 'would_import', 15 => 'would_import', 20 => 'would_import'])
        ->and(LetterheadAsset::count())->toBe(0);

    $this->artisan('opesinsure:import-insurer-logos', ['--path' => $this->packDir])->assertSuccessful();

    foreach ([1 => 'CM-INS-IARD-001', 15 => 'CM-INS-IARD-016', 20 => 'CM-INS-LIFE-002'] as $n => $canonical) {
        $a = LetterheadResolver::current('CARRIER', lpCarrier($canonical)->id);
        $manifest = collect($rows)->firstWhere('number', $n);
        expect($a->status)->toBe('ACTIVE')->and($a->public_display)->toBeTrue()->and($a->logo_mime)->toBe('image/webp')
            ->and($a->logo_sha256)->toBe($manifest['sha256'])->and($a->logo_path)->toEndWith('.webp')
            ->and($a->authorized_by)->toBe(InsurerLogoPack::AUTHORIZED_BY)->and($a->authorization_source)->toBe(InsurerLogoPack::BASIS)
            ->and($a->authorization_note)->toContain($manifest['source'])
            ->and(LetterheadResolver::carrierLogoUrl($a->carrier_id))->toContain('/api/v1/public/letterheads/'.$a->id.'/logo');
    }
    // ACTIVA Assurances and ACTIVA Vie are separate carriers with separate versions; nothing else got a logo.
    expect(LetterheadAsset::count())->toBe(3)
        ->and(LetterheadResolver::carrierLogoUrl(lpCarrier('CM-INS-IARD-015')->id))->toBeNull();
    expect(\Illuminate\Support\Facades\DB::table('audit_log')->where('action', 'letterhead.version.created')->count())->toBe(3);
});

it('is idempotent: an unchanged artwork is never re-published; a new artwork makes a new version', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);
    lpPack($this->packDir, [8, 23]);
    $pack = app(InsurerLogoPack::class);
    $pack->import($this->packDir);
    $again = $pack->import($this->packDir);
    expect(lpActions($again)[8])->toBe('unchanged')->and(lpActions($again)[23])->toBe('unchanged')->and(LetterheadAsset::count())->toBe(2);

    lpPack($this->packDir, [8], [200, 20, 20]);
    $third = $pack->import($this->packDir);
    expect(lpActions($third)[8])->toBe('imported')
        ->and(LetterheadResolver::current('CARRIER', lpCarrier('CM-INS-IARD-008')->id)->version)->toBe(2)
        ->and(LetterheadAsset::where('status', 'SUPERSEDED')->count())->toBe(1);
});

it('never overwrites a logo the insurer uploaded itself unless forced, and keeps its letterhead text', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);
    lpPack($this->packDir, [12, 24]);
    $nsia = lpCarrier('CM-INS-IARD-012');
    $own = app(LetterheadService::class)->publish('CARRIER', $nsia->id, ['authorized_by' => 'NSIA Communication', 'authorized_on' => now()->subDay()->toDateString(),
        'authorization_source' => 'Letter NSIA-2026-7', 'public_display' => true, 'rccm' => 'RC/DLA/NSIA'], lpWebp(200, 100, [0, 120, 0]), null, null);

    $rows = app(InsurerLogoPack::class)->import($this->packDir);
    expect(lpActions($rows)[12])->toBe('skipped_owned')->and(lpActions($rows)[24])->toBe('imported')
        ->and(LetterheadResolver::current('CARRIER', $nsia->id)->id)->toBe($own->id);
    $this->artisan('opesinsure:import-insurer-logos', ['--path' => $this->packDir])->assertSuccessful()->expectsOutputToContain('skipped_owned');

    $forced = app(InsurerLogoPack::class)->import($this->packDir, force: true);
    $cur = LetterheadResolver::current('CARRIER', $nsia->id);
    expect(lpActions($forced)[12])->toBe('imported')->and($cur->authorization_source)->toBe(InsurerLogoPack::BASIS)
        ->and($cur->rccm)->toBe('RC/DLA/NSIA')->and($own->fresh()->status)->toBe('SUPERSEDED');
});

it('flags pack entries that do not match the explicit mapping instead of guessing', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);
    $rows = lpPack($this->packDir, [1]);
    $rows[0]['company'] = 'ACTIVA Vie';
    file_put_contents($this->packDir.'/manifest.json', json_encode($rows));
    expect(lpActions(app(InsurerLogoPack::class)->import($this->packDir))[1])->toBe('unmatched')->and(LetterheadAsset::count())->toBe(0);
    $this->artisan('opesinsure:import-insurer-logos', ['--path' => $this->packDir])->assertFailed();
});

it('serves an imported logo as image/webp and exposes it as logo_url on the public directory', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);
    lpPack($this->packDir, [17]);
    app(InsurerLogoPack::class)->import($this->packDir);
    $sunu = lpCarrier('CM-INS-IARD-017');
    $a = LetterheadResolver::current('CARRIER', $sunu->id);

    $url = $this->getJson('/api/v1/public/institutions/'.$sunu->id)->assertOk()->json('data.logo_url');
    expect($url)->toContain('/api/v1/public/letterheads/'.$a->id.'/logo');
    $row = collect($this->getJson('/api/v1/public/institutions?type=insurer')->assertOk()->json('data'))->firstWhere('id', $sunu->id);
    expect($row['logo_url'])->toBe($url);

    $res = $this->get('/api/v1/public/letterheads/'.$a->id.'/logo')->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect(hash('sha256', $res->getContent()))->toBe($a->logo_sha256);
});

it('renders a WebP letterhead logo into PDFs as PNG, and falls back to the wordmark when it cannot decode', function () {
    $f = docPolicy();
    $v = app(LetterheadService::class)->publish('CARRIER', $f['carrier']->id, ['authorized_by' => 'Test', 'authorized_on' => now()->subDay()->toDateString(),
        'authorization_source' => 'Test ref'], lpWebp(), null, null);
    expect($v->logo_mime)->toBe('image/webp');

    $lh = LetterheadResolver::forPolicy($f['policy']);
    expect($lh['issuer']['logo'])->toStartWith('data:image/png;base64,');
    $png = base64_decode(substr($lh['issuer']['logo'], strlen('data:image/png;base64,')));
    expect(getimagesizefromstring($png)[0])->toBe(160);

    $spec = \App\Application\Policies\PolicyDocumentService::shellSpec($f['policy'], new \App\Models\PolicyCertificate(['serial_number' => 'S-1', 'issued_at' => now()]),
        \App\Application\Policies\PolicyDocumentService::CERTIFICATE, null, 'https://v', $lh);
    $renderer = app(\App\Application\Documents\Engine\SecureShellRenderer::class);
    $pdf = $renderer->render($spec);
    $spec['letterhead']['issuer']['logo'] = null;
    $spec['letterhead']['cobrand'] = null;
    $plain = $renderer->render($spec);
    // The logo is embedded as a raster image XObject (one more than the same document without it).
    expect($pdf)->toStartWith('%PDF')->and(substr_count($pdf, '/Subtype /Image'))->toBeGreaterThan(substr_count($plain, '/Subtype /Image'));
    expect(strlen(app(\App\Application\Quotes\QuoteDocumentRenderer::class)->pdf($f['quote'])))->toBeGreaterThan(500);

    // Undecodable WebP bytes: no broken image, the text wordmark is used.
    expect(LetterheadResolver::embedUri('RIFF0000WEBPjunk', 'image/webp'))->toBeNull();
});

it('the shipped pack maps all 29 entries with valid files (dry run)', function () {
    if (! is_file(InsurerLogoPack::defaultPath().'/manifest.json')) {
        $this->markTestSkipped('resources/insurer-logos not present.');
    }
    $this->seed(CameroonInsuranceRegisterSeeder::class);
    $rows = app(InsurerLogoPack::class)->import(dryRun: true);
    expect(count($rows))->toBe(29)->and(array_count_values(array_column($rows, 'action')))->toBe(['would_import' => 29]);
    $this->artisan('opesinsure:import-insurer-logos', ['--dry-run' => true])->assertSuccessful();
    expect(LetterheadAsset::count())->toBe(0);
});
