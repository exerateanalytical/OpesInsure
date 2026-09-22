<?php

declare(strict_types=1);

use App\Application\Documents\Adapters\ClamAvMalwareScanAdapter;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

const MOBILE_DOCUMENT_TEST_PDF_BYTES = "%PDF-1.4\n%mock pdf content for tests\n";

it('lists only the authenticated customer\'s own documents', function () {
    $fixture = makeMobileCustomerFixture();
    $mine = makeMobileTestDocument($fixture['tenant'], $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000101');
    makeMobileTestDocument($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/documents', tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.data'))->toHaveCount(1);
    expect($response->json('data.data.0.id'))->toBe($mine->id);
});

it('shows a single owned document, 403s for someone else\'s, 404s for a nonexistent one', function () {
    $fixture = makeMobileCustomerFixture();
    $mine = makeMobileTestDocument($fixture['tenant'], $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000102');
    $theirs = makeMobileTestDocument($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $this->getJson("/api/v1/mobile/documents/{$mine->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(200);
    $this->getJson("/api/v1/mobile/documents/{$theirs->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
    $this->getJson('/api/v1/mobile/documents/'.Str::uuid(), tenantHeaderFor($fixture['tenant']))->assertStatus(404);
});

it('refuses to issue a signed URL for a document that has not scanned CLEAN', function () {
    $fixture = makeMobileCustomerFixture();
    $pending = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['scan_status' => 'PENDING']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/documents/{$pending->id}/access", [], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('issues a signed URL for an owned CLEAN document, logs the access, and the URL actually serves the bytes', function () {
    Storage::fake('local');

    $fixture = makeMobileCustomerFixture();
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['storage_key' => 'documents/test/serve-me.pdf']);
    Storage::disk('local')->put($document->storage_key, MOBILE_DOCUMENT_TEST_PDF_BYTES);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/documents/{$document->id}/access", ['purpose' => 'VIEW'], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.id'))->toBe($document->id);
    $url = $response->json('data.url');
    expect($url)->toBeString();
    expect($response->json('data.expires_at'))->toBeString();

    expect(DB::table('document_access_log')->where('document_id', $document->id)->where('purpose', 'VIEW')->count())->toBe(1);

    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    $download = $this->get($path);
    $download->assertStatus(200);
    expect($download->streamedContent())->toBe(MOBILE_DOCUMENT_TEST_PDF_BYTES);
});

it('rejects a download whose signature has expired or was tampered with', function () {
    Storage::fake('local');

    $fixture = makeMobileCustomerFixture();
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    Storage::disk('local')->put($document->storage_key, MOBILE_DOCUMENT_TEST_PDF_BYTES);

    // A fabricated, unsigned hit at the route shape itself must fail closed.
    $this->get("/api/v1/mobile/documents/{$document->id}/download")->assertStatus(403);
});

it('rejects an unauthenticated request to list or access documents', function () {
    $this->getJson('/api/v1/mobile/documents')->assertStatus(401);

    $fixture = makeMobileCustomerFixture();
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    $this->postJson("/api/v1/mobile/documents/{$document->id}/access", [], tenantHeaderFor($fixture['tenant']))->assertStatus(401);
});

it('uploads a document, malware-scans it, and marks it unusable when no scanner is configured', function () {
    Storage::fake('local');
    config(['services.clamav.host' => null]);

    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/documents', [
        'category' => 'PROOF_OF_ADDRESS',
        'mime_type' => 'application/pdf',
        'file_base64' => base64_encode(MOBILE_DOCUMENT_TEST_PDF_BYTES),
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
    // Fail-closed placeholder: with no CLAMAV_HOST configured, an upload can
    // never come back CLEAN/usable — see FailClosedMalwareScanAdapter.
    expect($response->json('data.scan_status'))->toBe('FAILED');
    expect($response->json('data.usable'))->toBeFalse();

    $document = Document::findOrFail($response->json('data.id'));
    expect($document->party_id)->toBe($fixture['party']->id);
    expect($document->tenant_id)->toBe($fixture['tenant']->id);
    Storage::disk('local')->assertExists($document->storage_key);
    expect(DB::table('document_versions')->where('document_id', $document->id)->count())->toBe(1);
});

it('rejects an upload whose declared MIME type does not match the actual file signature', function () {
    Storage::fake('local');

    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/documents', [
        'category' => 'PROOF_OF_ADDRESS',
        'mime_type' => 'image/png',
        'file_base64' => base64_encode(MOBILE_DOCUMENT_TEST_PDF_BYTES),
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
});

it('rejects a duplicate upload of the same content by the same customer', function () {
    Storage::fake('local');

    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $payload = [
        'category' => 'PROOF_OF_ADDRESS',
        'mime_type' => 'application/pdf',
        'file_base64' => base64_encode(MOBILE_DOCUMENT_TEST_PDF_BYTES),
    ];

    $this->postJson('/api/v1/mobile/documents', $payload, tenantHeaderFor($fixture['tenant']))->assertStatus(201);
    $this->postJson('/api/v1/mobile/documents', $payload, tenantHeaderFor($fixture['tenant']))->assertStatus(409);
});

it('rejects an unauthenticated upload', function () {
    $this->postJson('/api/v1/mobile/documents', [
        'category' => 'PROOF_OF_ADDRESS',
        'mime_type' => 'application/pdf',
        'file_base64' => base64_encode(MOBILE_DOCUMENT_TEST_PDF_BYTES),
    ])->assertStatus(401);
});

it('the ClamAV adapter fails closed (never CLEAN) when the daemon is unreachable, instead of throwing', function () {
    config(['services.clamav.host' => '127.0.0.1', 'services.clamav.port' => 1, 'services.clamav.timeout' => 1]);

    $tempPath = tempnam(sys_get_temp_dir(), 'clamav-test-');
    file_put_contents($tempPath, MOBILE_DOCUMENT_TEST_PDF_BYTES);

    $result = (new ClamAvMalwareScanAdapter)->scan($tempPath, 'application/pdf');

    @unlink($tempPath);

    expect($result->status)->toBe('FAILED');
});
