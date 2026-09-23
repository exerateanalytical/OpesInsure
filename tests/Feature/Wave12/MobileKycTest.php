<?php

declare(strict_types=1);

use App\Models\KycSubmission;
use App\Models\PartyIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/Concerns/mobile_auth_helpers.php';

it('returns an empty profile shape when the account has no linked party', function () {
    $user = makeMobileTestUser();
    [$tenant] = makeMobileTestWorkspace($user);

    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/mobile/kyc/profile', tenantHeaderFor($tenant));

    $response->assertStatus(200);
    expect($response->json('data.party_id'))->toBeNull();
    expect($response->json('data.submission'))->toBeNull();
    expect($response->json('data.identifiers'))->toBe([]);
});

it('returns the party identifiers and latest submission on the profile', function () {
    $fixture = makeMobileCustomerFixture();
    $submission = makeMobileTestKycSubmission($fixture['tenant'], $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['category' => 'ID_CARD']);
    $submission->documents()->attach($document->id, ['purpose' => 'ID_FRONT']);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/kyc/profile', tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.party_id'))->toBe($fixture['party']->id);
    expect($response->json('data.submission.id'))->toBe($submission->id);
    expect($response->json('data.submission.status'))->toBe('DRAFT');
    expect($response->json('data.submission.documents'))->toHaveCount(1);
    expect($response->json('data.submission.documents.0.purpose'))->toBe('ID_FRONT');
});

it('rejects an unauthenticated profile request', function () {
    $this->getJson('/api/v1/mobile/kyc/profile')->assertStatus(401);
});

it('adds an encrypted, masked identifier to the profile and rejects a duplicate', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $payload = ['identifier_type' => 'NATIONAL_ID', 'identifier_value' => 'CM-9988-7766', 'identifier_country' => 'CM'];

    $response = $this->patchJson('/api/v1/mobile/kyc/profile', $payload, tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.identifiers'))->toHaveCount(1);
    expect($response->json('data.identifiers.0.masked_value'))->toEndWith('7766');

    $stored = PartyIdentifier::where('party_id', $fixture['party']->id)->firstOrFail();
    expect($stored->getRawOriginal('value_encrypted'))->not->toContain('99887766');

    $this->patchJson('/api/v1/mobile/kyc/profile', $payload, tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('attaches an owned, clean document as KYC evidence and creates a draft submission', function () {
    $fixture = makeMobileCustomerFixture();
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/kyc/documents', [
        'document_id' => $document->id,
        'purpose' => 'ID_FRONT',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe('DRAFT');
    expect($response->json('data.documents.0.id'))->toBe($document->id);

    expect(KycSubmission::where('party_id', $fixture['party']->id)->count())->toBe(1);
});

it('rejects attaching a document that failed the malware scan', function () {
    $fixture = makeMobileCustomerFixture();
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['scan_status' => 'INFECTED']);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/kyc/documents', [
        'document_id' => $document->id,
        'purpose' => 'ID_FRONT',
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('rejects attaching the same document twice and rejects someone else\'s document', function () {
    $fixture = makeMobileCustomerFixture();
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    Passport::actingAs($fixture['user']);

    $payload = ['document_id' => $document->id, 'purpose' => 'ID_FRONT'];
    $this->postJson('/api/v1/mobile/kyc/documents', $payload, tenantHeaderFor($fixture['tenant']))->assertStatus(201);
    $this->postJson('/api/v1/mobile/kyc/documents', $payload, tenantHeaderFor($fixture['tenant']))->assertStatus(409);

    $otherFixture = makeMobileCustomerFixture('+237670000201');
    $theirs = makeMobileTestDocument($fixture['tenant'], $otherFixture['party']);
    $this->postJson('/api/v1/mobile/kyc/documents', [
        'document_id' => $theirs->id,
        'purpose' => 'ID_FRONT',
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('refuses to submit without any attached document', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/kyc/submission', [], array_merge(
        tenantHeaderFor($fixture['tenant']),
        ['Idempotency-Key' => (string) Str::uuid()]
    ))->assertStatus(422);
});

it('refuses to submit while an attached document has not scanned clean', function () {
    $fixture = makeMobileCustomerFixture();
    $submission = makeMobileTestKycSubmission($fixture['tenant'], $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['scan_status' => 'PENDING']);
    $submission->documents()->attach($document->id, ['purpose' => 'ID_FRONT']);

    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/kyc/submission', [], array_merge(
        tenantHeaderFor($fixture['tenant']),
        ['Idempotency-Key' => (string) Str::uuid()]
    ))->assertStatus(422);
});

it('submits a draft with clean documents and replays the same response on a retried Idempotency-Key', function () {
    $fixture = makeMobileCustomerFixture();
    $submission = makeMobileTestKycSubmission($fixture['tenant'], $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    $submission->documents()->attach($document->id, ['purpose' => 'ID_FRONT']);

    Passport::actingAs($fixture['user']);

    $key = (string) Str::uuid();
    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => $key]);

    $first = $this->postJson('/api/v1/mobile/kyc/submission', ['notes' => 'please review'], $headers);
    $first->assertStatus(201);
    expect($first->json('data.status'))->toBe('SUBMITTED');
    expect($first->json('data.id'))->toBe($submission->id);

    expect(KycSubmission::whereKey($submission->id)->value('status'))->toBe('SUBMITTED');

    $replay = $this->postJson('/api/v1/mobile/kyc/submission', ['notes' => 'please review'], $headers);
    $replay->assertStatus(201);
    $replay->assertHeader('X-Idempotent-Replay', 'true');
    expect($replay->json('data.id'))->toBe($submission->id);

    // Only ever one submission created for this party — the retry did not
    // double-process.
    expect(KycSubmission::where('party_id', $fixture['party']->id)->count())->toBe(1);
});

it('rejects an unauthenticated attach/submit', function () {
    $fixture = makeMobileCustomerFixture();
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);

    $this->postJson('/api/v1/mobile/kyc/documents', [
        'document_id' => $document->id,
        'purpose' => 'ID_FRONT',
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(401);

    $this->postJson('/api/v1/mobile/kyc/submission', [], array_merge(
        tenantHeaderFor($fixture['tenant']),
        ['Idempotency-Key' => (string) Str::uuid()]
    ))->assertStatus(401);
});
