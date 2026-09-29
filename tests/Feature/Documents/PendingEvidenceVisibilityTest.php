<?php

declare(strict_types=1);

/**
 * S4: files still in the malware scan (PENDING_SCAN / SCAN_UNAVAILABLE) or quarantined (INFECTED) are visible
 * wherever evidence / documents are listed — "Security check in progress" with filename, upload time and status —
 * through additive `pending` arrays that never break the existing response shapes, and are never downloadable.
 */

use App\Application\Claims\Adjusters\AdjusterWorkbench;
use App\Application\Documents\Adapters\FailClosedMalwareScanAdapter;
use App\Application\Documents\Adapters\MalwareScanAdapter;
use App\Application\Documents\Adapters\ScanResult;
use App\Application\Documents\Scanning\DocumentScanQueue;
use App\Application\Documents\Scanning\MalwareScannerHealth;
use App\Application\Documents\Scanning\PendingDocuments;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const S4_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function s4Scanner(?string $verdict): void
{
    app()->bind(MalwareScanAdapter::class, fn () => $verdict === null ? new FailClosedMalwareScanAdapter : new class($verdict) implements MalwareScanAdapter
    {
        public function __construct(private string $verdict) {}

        public function scan(string $absolutePath, string $declaredMimeType): ScanResult
        {
            return $this->verdict === 'CLEAN' ? ScanResult::clean() : ScanResult::infected('stream: Eicar-Test-Signature FOUND');
        }
    });
    app()->instance(MalwareScannerHealth::class, new class($verdict !== null) extends MalwareScannerHealth
    {
        public function __construct(private bool $up) {}

        public function status(): array
        {
            return ['configured' => $this->up, 'reachable' => $this->up, 'endpoint' => null, 'version' => null, 'error' => $this->up ? null : 'down'];
        }
    });
}

/** @return array{0: array, 1: Document, 2: object, 3: array} fixture, document, claim, headers */
function s4UploadAndAttach($test): array
{
    Storage::fake('local');
    $f = makeMobileCustomerFixture('+2376710'.random_int(10000, 99999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    $upload = $test->postJson('/api/v1/mobile/documents', ['category' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/png', 'file_base64' => S4_PNG], $h)->assertStatus(201);
    $test->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", ['document_id' => $upload->json('data.id'), 'evidence_type' => 'DAMAGE_PHOTO', 'purpose' => 'CLAIM_EVIDENCE'], $h)->assertStatus(202);

    return [$f, Document::findOrFail($upload->json('data.id')), $claim, $h];
}

it('lists a held claim upload as pending in the evidence API, additively and not downloadable', function () {
    s4Scanner(null);
    [$f, $doc, $claim, $h] = s4UploadAndAttach($this);

    $r = $this->getJson("/api/v1/mobile/claims/{$claim->id}/evidence", $h)->assertOk();
    // Existing paginator shape is untouched.
    $r->assertJsonPath('data.data', [])->assertJsonPath('data.current_page', 1)->assertJsonStructure(['data' => ['data', 'current_page', 'per_page', 'total', 'pending']]);
    $r->assertJsonCount(1, 'data.pending')
        ->assertJsonPath('data.pending.0.document_id', $doc->id)
        ->assertJsonPath('data.pending.0.status', DocumentScanQueue::SCAN_UNAVAILABLE)
        ->assertJsonPath('data.pending.0.evidence_type', 'DAMAGE_PHOTO')
        ->assertJsonPath('data.pending.0.downloadable', false);
    expect($r->json('data.pending.0.filename'))->not->toBe('')
        ->and($r->json('data.pending.0.uploaded_at'))->not->toBeNull()
        ->and($r->json('data.pending.0.message'))->toBe(__('scan_queue.pending.in_progress'));

    // Adjuster workbench, insurer claim tab and agent claim detail read the same rows.
    expect(app(AdjusterWorkbench::class)->evidence($claim)['pending'])->toHaveCount(1)
        ->and(app(PendingDocuments::class)->forClaim($claim->id)[0]['status'])->toBe(DocumentScanQueue::SCAN_UNAVAILABLE);
});

it('shows a quarantined upload as INFECTED and drops the pending row once the file is attached clean', function () {
    s4Scanner(null);
    [$f, $doc, $claim, $h] = s4UploadAndAttach($this);

    s4Scanner('INFECTED');
    app(DocumentScanQueue::class)->rescanPending(ignoreBackoff: true);
    $this->getJson("/api/v1/mobile/claims/{$claim->id}/evidence", $h)->assertOk()
        ->assertJsonPath('data.pending.0.status', DocumentScanQueue::INFECTED)
        ->assertJsonPath('data.pending.0.message', __('scan_queue.pending.quarantined'))
        ->assertJsonPath('data.pending.0.downloadable', false);

    // A second, clean upload: pending while held, then listed as evidence and no longer pending.
    s4Scanner(null);
    $up = $this->postJson('/api/v1/mobile/documents', ['category' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/png', 'file_base64' => base64_encode(base64_decode(S4_PNG).'x')], $h)->assertStatus(201);
    $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", ['document_id' => $up->json('data.id'), 'evidence_type' => 'DAMAGE_PHOTO', 'purpose' => 'CLAIM_EVIDENCE'], $h);
    s4Scanner('CLEAN');
    app(DocumentScanQueue::class)->rescanPending(ignoreBackoff: true);

    $r = $this->getJson("/api/v1/mobile/claims/{$claim->id}/evidence", $h)->assertOk();
    expect(collect($r->json('data.pending'))->pluck('document_id')->all())->toBe([$doc->id])
        ->and(collect($r->json('data.data'))->pluck('document_id')->all())->toContain($up->json('data.id'));
});

it('returns only held or infected documents for KYC / proposal lists, with FR labels', function () {
    s4Scanner(null);
    [$f, $doc] = s4UploadAndAttach($this);
    $clean = Document::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'category' => 'KYC_IDENTITY', 'storage_key' => 'x/clean.png',
        'mime_type' => 'image/png', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'scan_status' => 'CLEAN', 'verification_status' => 'UNVERIFIED', 'ocr_data' => []]);

    app()->setLocale('fr');
    $rows = app(PendingDocuments::class)->forDocumentIds([$doc->id, $clean->id, null]);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['document_id'])->toBe($doc->id)
        ->and($rows[0]['status_label'])->toBe('Contrôle de sécurité retardé — nouvelle tentative automatique')
        ->and($rows[0]['message'])->toBe('Contrôle de sécurité en cours');
});

it('has every pending key in EN and FR', function () {
    $en = require base_path('resources/lang/en/scan_queue.php');
    $fr = require base_path('resources/lang/fr/scan_queue.php');
    expect(array_keys($fr['pending']))->toBe(array_keys($en['pending']))
        ->and(array_keys($fr['pending']['status']))->toBe(['PENDING_SCAN', 'SCAN_UNAVAILABLE', 'INFECTED']);
});
