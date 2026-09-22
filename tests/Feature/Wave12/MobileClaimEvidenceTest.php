<?php

declare(strict_types=1);

use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

it('attaches an owned, malware-clean document as claim evidence', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [
        'document_id' => $document->id,
        'evidence_type' => 'INCIDENT_PHOTO',
        'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe('SUBMITTED');
    expect(DB::table('claim_documents')->where(['claim_id' => $claim->id, 'document_id' => $document->id])->exists())->toBeTrue();

    $list = $this->getJson("/api/v1/mobile/claims/{$claim->id}/evidence", tenantHeaderFor($fixture['tenant']));
    $list->assertStatus(200);
    expect($list->json('data.data'))->toHaveCount(1);
    expect($list->json('data.data.0.document_id'))->toBe($document->id);
});

it('refuses evidence that has not scanned CLEAN', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['scan_status' => 'PENDING']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [
        'document_id' => $document->id,
        'evidence_type' => 'INCIDENT_PHOTO',
        'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
});

it('403s attaching a document that belongs to a different customer', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000205');
    $theirDocument = makeMobileTestDocument($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [
        'document_id' => $theirDocument->id,
        'evidence_type' => 'INCIDENT_PHOTO',
        'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(403);
});

it('403s attaching evidence to a claim that belongs to a different customer', function () {
    $fixture = makeMobileCustomerFixture();
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000206');
    $theirPolicy = makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id);
    $theirClaim = makeMobileTestClaim($fixture['tenant'], $theirPolicy, $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$theirClaim->id}/evidence", [
        'document_id' => $document->id,
        'evidence_type' => 'INCIDENT_PHOTO',
        'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(403);
});

it('rejects supplying both a document_id and an upload_session_id', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    $session = makeMobileTestUploadSession($fixture['tenant'], $fixture['user'], ['status' => 'COMPLETED', 'storage_key' => 'upload-sessions/'.Str::uuid().'/assembled']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [
        'document_id' => $document->id,
        'upload_session_id' => $session->id,
        'evidence_type' => 'INCIDENT_PHOTO',
        'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
});

it('rejects supplying neither a document_id nor an upload_session_id', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [
        'evidence_type' => 'INCIDENT_PHOTO',
        'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
});

it('registers a completed resumable-upload session as evidence, without re-uploading, and fails closed with no scanner configured', function () {
    Storage::fake('local');
    config(['services.clamav.host' => null]);

    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    $storageKey = 'upload-sessions/'.Str::uuid().'/assembled';
    Storage::disk('local')->put($storageKey, 'fake jpeg bytes');
    $session = makeMobileTestUploadSession($fixture['tenant'], $fixture['user'], [
        'status' => 'COMPLETED', 'storage_key' => $storageKey, 'total_size_bytes' => strlen('fake jpeg bytes'),
    ]);

    Passport::actingAs($fixture['user']);

    // The scanner fails closed (no CLAMAV_HOST) so the Document is
    // registered but cannot yet be attached as usable evidence — the same
    // fail-closed guarantee MobileDocumentTest exercises for the base64
    // upload path.
    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [
        'upload_session_id' => $session->id,
        'evidence_type' => 'INCIDENT_PHOTO',
        'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);

    $document = \App\Models\Document::where('storage_key', $storageKey)->first();
    expect($document)->not->toBeNull();
    expect($document->scan_status)->toBe('FAILED');
    expect($document->category)->toBe('CLAIM_EVIDENCE');
    expect($document->party_id)->toBe($fixture['party']->id);
});

it('403s registering evidence from another customer\'s upload session, and 422s one that is not COMPLETED', function () {
    Storage::fake('local');

    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000207');
    $theirSession = makeMobileTestUploadSession($fixture['tenant'], $otherFixture['user'], ['status' => 'COMPLETED', 'storage_key' => 'upload-sessions/x/assembled']);

    $myPendingSession = makeMobileTestUploadSession($fixture['tenant'], $fixture['user']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [
        'upload_session_id' => $theirSession->id, 'evidence_type' => 'INCIDENT_PHOTO', 'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(403);

    $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [
        'upload_session_id' => $myPendingSession->id, 'evidence_type' => 'INCIDENT_PHOTO', 'purpose' => 'CLAIM_EVIDENCE',
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('rejects an unauthenticated request to view or attach evidence', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    $this->getJson("/api/v1/mobile/claims/{$claim->id}/evidence")->assertStatus(401);
    $this->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", [])->assertStatus(401);
});
