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

beforeEach(function () {
    Storage::fake('local');
});

it('starts an upload session and reports every chunk as missing', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $start = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 3, 'total_size_bytes' => 30,
    ], $headers);

    $start->assertStatus(201);
    expect($start->json('data.status'))->toBe('IN_PROGRESS');
    $uploadId = $start->json('data.id');

    $status = $this->getJson("/api/v1/mobile/uploads/{$uploadId}/status", tenantHeaderFor($fixture['tenant']));

    $status->assertStatus(200);
    expect($status->json('data.received_chunk_indexes'))->toBe([]);
    expect($status->json('data.missing_chunk_indexes'))->toBe([0, 1, 2]);
});

it('accepts chunks out of order and lets a resumed client see exactly what is still missing', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $start = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 3, 'total_size_bytes' => 15,
    ], $headers);
    $uploadId = $start->json('data.id');

    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/2", ['data' => base64_encode('EEEEE')], tenantHeaderFor($fixture['tenant']))->assertStatus(200);

    $status = $this->getJson("/api/v1/mobile/uploads/{$uploadId}/status", tenantHeaderFor($fixture['tenant']));
    expect($status->json('data.received_chunk_indexes'))->toBe([2]);
    expect($status->json('data.missing_chunk_indexes'))->toBe([0, 1]);

    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/0", ['data' => base64_encode('AAAAA')], tenantHeaderFor($fixture['tenant']))->assertStatus(200);
    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/1", ['data' => base64_encode('BBBBB')], tenantHeaderFor($fixture['tenant']))->assertStatus(200);

    $status = $this->getJson("/api/v1/mobile/uploads/{$uploadId}/status", tenantHeaderFor($fixture['tenant']));
    expect($status->json('data.received_chunk_indexes'))->toBe([0, 1, 2]);
    expect($status->json('data.missing_chunk_indexes'))->toBe([]);
});

it('overwrites rather than duplicates when the same chunk index is re-sent (resume-safe)', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $start = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 1, 'total_size_bytes' => 5,
    ], $headers);
    $uploadId = $start->json('data.id');

    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/0", ['data' => base64_encode('AAAAA')], tenantHeaderFor($fixture['tenant']))->assertStatus(200);
    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/0", ['data' => base64_encode('ZZZZZ')], tenantHeaderFor($fixture['tenant']))->assertStatus(200);

    expect(DB::table('upload_chunks')->where('upload_session_id', $uploadId)->count())->toBe(1);

    $finalize = $this->postJson("/api/v1/mobile/uploads/{$uploadId}/finalize", [], tenantHeaderFor($fixture['tenant']));
    $finalize->assertStatus(200);
    expect(Storage::disk('local')->get($finalize->json('data.storage_key')))->toBe('ZZZZZ');
});

it('rejects a chunk index outside the declared total', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $start = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 2, 'total_size_bytes' => 10,
    ], $headers);
    $uploadId = $start->json('data.id');

    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/5", ['data' => base64_encode('AAAAA')], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('assembles the exact original bytes once every chunk has arrived, and sets a storage_key', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $content = 'Hello, resumable world!';
    $chunkA = substr($content, 0, 10);
    $chunkB = substr($content, 10);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $start = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 2, 'total_size_bytes' => strlen($content),
        'expected_sha256' => hash('sha256', $content),
    ], $headers);
    $uploadId = $start->json('data.id');

    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/0", ['data' => base64_encode($chunkA)], tenantHeaderFor($fixture['tenant']))->assertStatus(200);
    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/1", ['data' => base64_encode($chunkB)], tenantHeaderFor($fixture['tenant']))->assertStatus(200);

    $finalize = $this->postJson("/api/v1/mobile/uploads/{$uploadId}/finalize", [], tenantHeaderFor($fixture['tenant']));

    $finalize->assertStatus(200);
    expect($finalize->json('data.status'))->toBe('COMPLETED');
    $storageKey = $finalize->json('data.storage_key');
    expect(Storage::disk('local')->get($storageKey))->toBe($content);
    expect(UploadSession::find($uploadId)->status)->toBe('COMPLETED');
});

it('refuses to finalize while chunks are still missing', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $start = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 2, 'total_size_bytes' => 10,
    ], $headers);
    $uploadId = $start->json('data.id');

    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/0", ['data' => base64_encode('AAAAA')], tenantHeaderFor($fixture['tenant']))->assertStatus(200);

    $this->postJson("/api/v1/mobile/uploads/{$uploadId}/finalize", [], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
    expect(UploadSession::find($uploadId)->status)->toBe('IN_PROGRESS');
});

it('rejects an assembled upload whose checksum does not match what was declared at start, and leaves it retryable', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $start = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 1, 'total_size_bytes' => 5,
        'expected_sha256' => hash('sha256', 'WRONG'),
    ], $headers);
    $uploadId = $start->json('data.id');

    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/0", ['data' => base64_encode('AAAAA')], tenantHeaderFor($fixture['tenant']))->assertStatus(200);

    $this->postJson("/api/v1/mobile/uploads/{$uploadId}/finalize", [], tenantHeaderFor($fixture['tenant']))->assertStatus(422);

    // Left retryable, not wedged: still IN_PROGRESS, not COMPLETED.
    expect(UploadSession::find($uploadId)->status)->toBe('IN_PROGRESS');
});

it('is idempotent on finalize: calling it again after completion returns the same result without reprocessing', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $start = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 1, 'total_size_bytes' => 5,
    ], $headers);
    $uploadId = $start->json('data.id');

    $this->putJson("/api/v1/mobile/uploads/{$uploadId}/chunks/0", ['data' => base64_encode('AAAAA')], tenantHeaderFor($fixture['tenant']))->assertStatus(200);

    $first = $this->postJson("/api/v1/mobile/uploads/{$uploadId}/finalize", [], tenantHeaderFor($fixture['tenant']));
    $second = $this->postJson("/api/v1/mobile/uploads/{$uploadId}/finalize", [], tenantHeaderFor($fixture['tenant']));

    $first->assertStatus(200);
    $second->assertStatus(200);
    expect($second->json('data.storage_key'))->toBe($first->json('data.storage_key'));
});

it('rejects a mime type outside the allowlist and an oversized declared upload', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $badMime = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'application/x-msdownload', 'total_chunks' => 1, 'total_size_bytes' => 10,
    ], array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]));
    $badMime->assertStatus(422);

    $tooLarge = $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 1, 'total_size_bytes' => 999_999_999,
    ], array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]));
    $tooLarge->assertStatus(422);

    expect(UploadSession::count())->toBe(0);
});

it('403s access to another user\'s upload session in the same tenant, and 404s an unknown one', function () {
    $fixture = makeMobileCustomerFixture();
    $otherFixture = makeMobileCustomerFixture('+237670000097');
    $theirSession = makeMobileTestUploadSession($fixture['tenant'], $otherFixture['user']);

    Passport::actingAs($fixture['user']);

    $this->getJson("/api/v1/mobile/uploads/{$theirSession->id}/status", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
    $this->getJson('/api/v1/mobile/uploads/'.Str::uuid().'/status', tenantHeaderFor($fixture['tenant']))->assertStatus(404);
});

it('rejects an unauthenticated request to start an upload', function () {
    $fixture = makeMobileCustomerFixture();

    $this->postJson('/api/v1/mobile/uploads', [
        'resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/jpeg', 'total_chunks' => 1, 'total_size_bytes' => 5,
    ], array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]))->assertStatus(401);
});
