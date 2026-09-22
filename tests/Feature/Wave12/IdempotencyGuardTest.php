<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

// Exercised against a real guarded route (POST /mobile/uploads, guarded by
// ->middleware('idempotency:mobile.uploads.start')) rather than a synthetic
// test route, so these tests double as end-to-end proof the middleware alias
// wiring in bootstrap/app.php actually works.
function uploadStartPayload(array $overrides = []): array
{
    return array_merge([
        'resource_type' => 'CLAIM_EVIDENCE',
        'mime_type' => 'image/jpeg',
        'total_chunks' => 2,
        'total_size_bytes' => 20,
    ], $overrides);
}

it('executes normally the first time it sees an idempotency key', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => 'key-1']);
    $response = $this->postJson('/api/v1/mobile/uploads', uploadStartPayload(), $headers);

    $response->assertStatus(201);
    $response->assertHeaderMissing('X-Idempotent-Replay');
    expect(UploadSession::count())->toBe(1);
    expect(IdempotencyKey::count())->toBe(1);
    expect(IdempotencyKey::first()->response_status)->toBe(201);
});

it('replays the stored response verbatim for a repeated key and identical body, without re-executing the handler', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => 'key-2']);
    $payload = uploadStartPayload();

    $first = $this->postJson('/api/v1/mobile/uploads', $payload, $headers);
    $first->assertStatus(201);

    $second = $this->postJson('/api/v1/mobile/uploads', $payload, $headers);

    $second->assertStatus(201);
    $second->assertHeader('X-Idempotent-Replay', 'true');
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    // The handler did not run again: still exactly one resource.
    expect(UploadSession::count())->toBe(1);
});

it('rejects a key reused with a different request body with 409, and does not execute the handler', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => 'key-3']);

    $this->postJson('/api/v1/mobile/uploads', uploadStartPayload(), $headers)->assertStatus(201);

    $response = $this->postJson('/api/v1/mobile/uploads', uploadStartPayload(['total_chunks' => 5]), $headers);

    $response->assertStatus(409);
    expect($response->json('errors.idempotency_key'))->not->toBeNull();
    expect(UploadSession::count())->toBe(1);
});

it('rejects a request with no Idempotency-Key header at all', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/uploads', uploadStartPayload(), tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
    expect($response->json('errors.idempotency_key'))->not->toBeNull();
    expect(UploadSession::count())->toBe(0);
});

it('treats a key past its expiry as unseen and executes fresh, overwriting the stale record', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    IdempotencyKey::create([
        'tenant_id' => $fixture['tenant']->id,
        'user_id' => $fixture['user']->id,
        'key' => 'key-4',
        'operation' => 'mobile.uploads.start',
        'request_hash' => 'stale-hash-from-a-previous-ttl-window',
        'response_status' => 201,
        'response_body' => ['data' => ['id' => 'stale-id-that-must-not-be-replayed']],
        'expires_at' => now()->subDay(),
    ]);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => 'key-4']);
    $response = $this->postJson('/api/v1/mobile/uploads', uploadStartPayload(), $headers);

    $response->assertStatus(201);
    expect($response->json('data.id'))->not->toBe('stale-id-that-must-not-be-replayed');
    expect(UploadSession::count())->toBe(1);
    expect(IdempotencyKey::where('key', 'key-4')->count())->toBe(1);
    expect(IdempotencyKey::where('key', 'key-4')->first()->expires_at->isFuture())->toBeTrue();
});

it('rejects a concurrent duplicate while the first request under that key is still in flight', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    // Simulates the race directly: a claim row with no response yet is
    // exactly the state a concurrent in-flight request would have left.
    IdempotencyKey::create([
        'tenant_id' => $fixture['tenant']->id,
        'user_id' => $fixture['user']->id,
        'key' => 'key-5',
        'operation' => 'mobile.uploads.start',
        'request_hash' => 'irrelevant-the-in-flight-check-runs-first',
        'response_status' => null,
        'response_body' => null,
        'expires_at' => now()->addHour(),
    ]);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => 'key-5']);
    $response = $this->postJson('/api/v1/mobile/uploads', uploadStartPayload(), $headers);

    $response->assertStatus(409);
    expect(UploadSession::count())->toBe(0);
});

it('scopes keys by tenant and user, so the same literal key for a different customer is independent', function () {
    $fixture = makeMobileCustomerFixture();
    // A second, unrelated customer in their own tenant — each fixture gets
    // its own Tenant, so this also proves tenant_id (not just user_id) is
    // part of the uniqueness key.
    $otherFixture = makeMobileCustomerFixture('+237670000096');

    Passport::actingAs($fixture['user']);
    $this->postJson('/api/v1/mobile/uploads', uploadStartPayload(), array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => 'shared-literal-key']))->assertStatus(201);

    Passport::actingAs($otherFixture['user']);
    $this->postJson('/api/v1/mobile/uploads', uploadStartPayload(), array_merge(tenantHeaderFor($otherFixture['tenant']), ['Idempotency-Key' => 'shared-literal-key']))->assertStatus(201);

    expect(UploadSession::count())->toBe(2);
});
