<?php

declare(strict_types=1);

/**
 * Agent B3 — REQ-API-007 carrier connector framework: per-carrier config, signed delivery with the
 * Batch 11 carrier keys, retries with exponential backoff, manual fallback queue, record sync conflicts.
 */

use App\Application\Claims\Execution\ClaimCarrierSignatureVerifier;
use App\Application\Integrations\Carriers\{CarrierConnectorService,CarrierMessageSigner};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\{Artisan,DB,Http};
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Integrations/Concerns/helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant'];
    $this->carrier = $this->f['carrier']->id;
    $this->h = tenantHeader($this->tenant);
    $this->admin = makeAuthTestUser($this->tenant, ['integrations.carrier_connectors.manage', 'integrations.manage']);
    $this->key = app(ClaimCarrierSignatureVerifier::class)->register($this->carrier, $this->admin);
    Passport::actingAs($this->admin, [], 'api');
});

function b3Configure(array $extra = []): void
{
    test()->putJson('/api/v1/carrier-connectors/'.test()->carrier, [
        'transport' => 'API', 'base_url' => 'https://carrier.example.test/api', 'endpoints' => ['CLAIM_SUBMISSION' => '/claims'],
        'signing_key_id' => test()->key['key_id'], 'max_attempts' => 3, 'base_backoff_seconds' => 60, ...$extra,
    ], test()->h)->assertOk();
}

function b3Message(string $type = 'CLAIM_SUBMISSION'): string
{
    $id = (string) Str::uuid();
    DB::table('carrier_exchange_messages')->insert(['id' => $id, 'carrier_id' => test()->carrier, 'direction' => 'OUTBOUND', 'message_type' => $type,
        'correlation_id' => 'corr-'.Str::random(8), 'status' => 'QUEUED', 'payload' => json_encode(['hello' => 'carrier']), 'payload_hash' => str_repeat('a', 64),
        'attempt_count' => 0, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('configures a connector, hiding the endpoint URL and requiring an active carrier signing key', function () {
    $this->putJson("/api/v1/carrier-connectors/{$this->carrier}", ['transport' => 'API', 'base_url' => 'https://x.example.test', 'signing_key_id' => 'nope'], $this->h)->assertUnprocessable();
    b3Configure();
    $this->getJson("/api/v1/carrier-connectors/{$this->carrier}", $this->h)->assertOk()
        ->assertJsonPath('data.transport', 'API')->assertJsonPath('data.has_base_url', true)->assertJsonMissingPath('data.base_url_encrypted');
    expect(DB::table('outbox_messages')->where('event_name', 'integration.carrier_connector.configured')->exists())->toBeTrue();
});

it('delivers a signed message the carrier can verify with the Batch 11 scheme', function () {
    b3Configure();
    Http::fake(['carrier.example.test/*' => Http::response(['reference' => 'CAR-123'], 200)]);
    $id = b3Message();

    $this->postJson("/api/v1/carrier-connectors/messages/{$id}/dispatch", [], $this->h)->assertOk()->assertJsonPath('data.status', 'SENT');
    expect(DB::table('carrier_exchange_messages')->find($id)->external_reference)->toBe('CAR-123');

    Http::assertSent(function (HttpRequest $r) {
        $ts = (int) $r->header(CarrierMessageSigner::TIMESTAMP_HEADER)[0];
        app(ClaimCarrierSignatureVerifier::class)->verify(test()->carrier, $r->header(CarrierMessageSigner::KEY_HEADER)[0], $ts, $r->body(), $r->header(CarrierMessageSigner::SIGNATURE_HEADER)[0]);

        return $r->url() === 'https://carrier.example.test/api/claims' && $r->hasHeader('Idempotency-Key');
    });
});

it('retries transient failures with exponential backoff then falls back to the manual queue', function () {
    b3Configure();
    Http::fake(['carrier.example.test/*' => Http::response('down', 503)]);
    $id = b3Message();
    $svc = app(CarrierConnectorService::class);

    $m = $svc->dispatch($id);
    expect($m->status)->toBe('RETRY_PENDING')->and((int) $m->attempt_count)->toBe(1);
    expect(now()->diffInSeconds($m->next_attempt_at))->toBeGreaterThan(50)->toBeLessThan(70);
    expect(CarrierConnectorService::backoffSeconds(60, 2))->toBe(120)->and(CarrierConnectorService::backoffSeconds(60, 30))->toBe(86_400);

    // not yet due: the scheduler skips it
    Artisan::call('carriers:dispatch-messages');
    expect(DB::table('carrier_exchange_messages')->find($id)->attempt_count)->toBe(1);

    $this->travel(2)->minutes();
    $m = $svc->dispatch($id);
    expect($m->status)->toBe('RETRY_PENDING')->and((int) $m->attempt_count)->toBe(2);
    $this->travel(5)->minutes();
    Artisan::call('carriers:dispatch-messages');
    $m = DB::table('carrier_exchange_messages')->find($id);
    expect($m->status)->toBe('MANUAL_FALLBACK')->and($m->fallback_reason)->toBe('retries_exhausted:http_503')->and((int) $m->attempt_count)->toBe(3);

    $this->getJson('/api/v1/carrier-connectors/fallback-queue', $this->h)->assertOk()->assertJsonPath('data.0.id', $id);
    $this->postJson("/api/v1/carrier-connectors/messages/{$id}/resolve", ['resolution' => 'SENT_MANUALLY', 'note' => 'emailed', 'external_reference' => 'MAN-1'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'SENT');
    $this->postJson("/api/v1/carrier-connectors/messages/{$id}/resolve", ['resolution' => 'CANCELLED', 'note' => 'x'], $this->h)->assertUnprocessable();
});

it('sends non-retryable errors and manual carriers straight to the fallback queue, and can requeue', function () {
    b3Configure();
    Http::fake(['carrier.example.test/*' => Http::response(['error' => 'bad'], 422)]);
    $id = b3Message();
    expect(app(CarrierConnectorService::class)->dispatch($id)->status)->toBe('MANUAL_FALLBACK');

    $unknownType = b3Message('POLICY_ISSUANCE');
    expect(app(CarrierConnectorService::class)->dispatch($unknownType)->fallback_reason)->toBe('no_endpoint_for_message_type');

    $this->postJson("/api/v1/carrier-connectors/messages/{$id}/resolve", ['resolution' => 'REQUEUED', 'note' => 'fixed'], $this->h)->assertOk()->assertJsonPath('data.status', 'RETRY_PENDING');

    b3Configure(['transport' => 'MANUAL']);
    expect(app(CarrierConnectorService::class)->dispatch($id)->fallback_reason)->toBe('manual_transport');
    expect(DB::table('outbox_messages')->where('event_name', 'integration.carrier_message.fallback_queued')->count())->toBe(3);
});

it('syncs carrier records through external_record_mappings and queues conflicts on owned records', function () {
    $client = activateTestIntegrationClient($this->admin);
    b3Configure(['integration_client_id' => $client->id]);
    $record = (string) Str::uuid();
    $sync = fn (string $v) => $this->postJson("/api/v1/carrier-connectors/{$this->carrier}/sync",
        ['record_type' => 'policy', 'external_record_id' => 'EXT-9', 'opesinsure_record_id' => $record, 'external_version' => $v, 'fields' => ['premium' => 1]], $this->h);

    $mappingId = $sync('1')->assertOk()->assertJsonPath('data.synchronization_status', 'SYNCED')->json('data.id');
    $sync('1')->assertOk()->assertJsonPath('data.synchronization_status', 'SYNCED');
    $sync('2')->assertOk()->assertJsonPath('data.synchronization_status', 'CONFLICT')->assertJsonPath('data.conflict_status', 'EXTERNAL_CHANGE_ON_OWNED_RECORD');
    expect(DB::table('external_record_mappings')->find($mappingId)->conflict_detected_at)->not->toBeNull();

    $this->postJson("/api/v1/carrier-connectors/mappings/{$mappingId}/resolve-conflict", ['resolution' => 'ACCEPT_EXTERNAL', 'note' => 'carrier correct'], $this->h)
        ->assertOk()->assertJsonPath('data.synchronization_status', 'SYNCED')->assertJsonPath('data.external_version', '2');
    $row = DB::table('external_record_mappings')->find($mappingId);
    expect($row->conflict_resolution)->toBe('ACCEPT_EXTERNAL')->and($row->conflict_resolved_by)->toBe($this->admin->id);
    $this->postJson("/api/v1/carrier-connectors/mappings/{$mappingId}/resolve-conflict", ['resolution' => 'KEEP_OPESINSURE', 'note' => 'x'], $this->h)->assertUnprocessable();
});

it('requires the carrier connector permission', function () {
    Passport::actingAs(makeAuthTestUser($this->tenant, ['integrations.manage']), [], 'api');
    $this->getJson('/api/v1/carrier-connectors/fallback-queue', $this->h)->assertForbidden();
});
