<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function makeStaffAccessDocument(Tenant $tenant, array $overrides = []): Document
{
    return Document::create(array_merge([
        'tenant_id' => $tenant->id,
        'category' => 'POLICY_DOCUMENT',
        'storage_key' => 'documents/staff/'.Str::random(18).'.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', Str::random(32)),
        'scan_status' => 'CLEAN',
        'verification_status' => 'VERIFIED',
        'ocr_data' => [],
    ], $overrides));
}

it('returns a real short-lived signed URL instead of the old adapter stub', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['documents.review']);
    $document = makeStaffAccessDocument($tenant);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/documents/{$document->id}/access", [
        'purpose' => 'UNDERWRITING_REVIEW',
    ], tenantHeader($tenant));

    $response->assertStatus(200);

    // The defect being fixed: this endpoint used to hand back a random
    // download_ticket and a literal SIGNED_URL_ADAPTER_REQUIRED marker.
    expect($response->json('data'))->not->toHaveKey('download_ticket');
    expect(json_encode($response->json()))->not->toContain('SIGNED_URL_ADAPTER_REQUIRED');

    $url = $response->json('data.url');
    expect($url)->toBeString()->toContain('/api/v1/mobile/documents/'.$document->id.'/download');
    // A genuine temporary signed route carries both a signature and an expiry.
    expect($url)->toContain('signature=')->toContain('expires=');
    expect($response->json('data.expires_at'))->toBeString();
    expect($response->json('data.expires_in_seconds'))->toBe(300);
});

it('records the access in document_access_log with the supplied purpose', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['documents.review']);
    $document = makeStaffAccessDocument($tenant);

    Passport::actingAs($user);

    $this->postJson("/api/v1/documents/{$document->id}/access", [
        'purpose' => 'CLAIM_ASSESSMENT',
    ], tenantHeader($tenant))->assertStatus(200);

    $log = DB::table('document_access_log')->where('document_id', $document->id)->first();

    expect($log)->not->toBeNull();
    expect($log->actor_id)->toBe($user->id);
    expect($log->action)->toBe('READ');
    expect($log->purpose)->toBe('CLAIM_ASSESSMENT');
});

it('refuses to issue a URL for a document that has not passed the malware scan', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['documents.review']);

    Passport::actingAs($user);

    foreach (['PENDING', 'INFECTED', 'FAILED'] as $scanStatus) {
        $document = makeStaffAccessDocument($tenant, ['scan_status' => $scanStatus]);

        $this->postJson("/api/v1/documents/{$document->id}/access", [
            'purpose' => 'UNDERWRITING_REVIEW',
        ], tenantHeader($tenant))->assertStatus(404);

        expect(DB::table('document_access_log')->where('document_id', $document->id)->count())->toBe(0);
    }
});

it('does not expose a document belonging to another tenant', function () {
    $tenant = makeAuthTestTenant();
    $otherTenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['documents.review']);
    $theirs = makeStaffAccessDocument($otherTenant);

    Passport::actingAs($user);

    $this->postJson("/api/v1/documents/{$theirs->id}/access", [
        'purpose' => 'UNDERWRITING_REVIEW',
    ], tenantHeader($tenant))->assertStatus(404);
});

it('requires a purpose', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['documents.review']);
    $document = makeStaffAccessDocument($tenant);

    Passport::actingAs($user);

    $this->postJson("/api/v1/documents/{$document->id}/access", [], tenantHeader($tenant))
        ->assertStatus(422);
});

it('rejects an unauthenticated access request', function () {
    $tenant = makeAuthTestTenant();
    $document = makeStaffAccessDocument($tenant);

    $this->postJson("/api/v1/documents/{$document->id}/access", [
        'purpose' => 'UNDERWRITING_REVIEW',
    ])->assertStatus(401);
});
