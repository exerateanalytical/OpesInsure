<?php

declare(strict_types=1);

use App\Models\SyncOperation;
use App\Models\TenantCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

function syncClientIntakeEnvelope(array $payloadOverrides = [], array $envelopeOverrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'kind' => 'MUTATION',
        'resource' => 'agent_client',
        'resource_id' => null,
        'method' => 'POST',
        'path' => 'mobile/agent/clients',
        'payload' => agentClientIntakePayload($payloadOverrides),
    ], $envelopeOverrides);
}

it('returns server time and the minimum supported client version without needing any agent.* permission', function () {
    $fixture = makeMobileAgentFixture();
    $fixture['role']->update(['permissions' => []]);
    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/sync/status', tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.minimum_client_version'))->toBe('1.0.0');
    expect($response->json('data.server_time'))->not->toBeNull();
});

it('applies an allowlisted queued client-intake operation exactly once and lets a byte-identical replay through', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $envelope = syncClientIntakeEnvelope();
    $first = $this->postJson('/api/v1/mobile/sync/operations', $envelope, agentHeaders($fixture));

    $first->assertStatus(200);
    expect($first->json('data.status'))->toBe('APPLIED');
    expect(TenantCustomer::count())->toBe(1);

    // Same operation id, same Idempotency-Key: must not re-execute the handler.
    $second = $this->postJson('/api/v1/mobile/sync/operations', $envelope, agentHeaders($fixture, (string) Str::uuid()));

    $second->assertStatus(200);
    expect($second->json('data.operation_id'))->toBe($first->json('data.operation_id'));
    expect(TenantCustomer::count())->toBe(1);
    expect(SyncOperation::where('operation_uuid', $envelope['id'])->count())->toBe(1);
});

it('rejects a method/path pair that is not on the allowlist, with an audit row and no side effects', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $envelope = syncClientIntakeEnvelope([], ['path' => 'mobile/agent/withdrawals']);
    $response = $this->postJson('/api/v1/mobile/sync/operations', $envelope, agentHeaders($fixture));

    $response->assertStatus(422);
    expect(TenantCustomer::count())->toBe(0);
    $row = SyncOperation::where('operation_uuid', $envelope['id'])->firstOrFail();
    expect($row->status)->toBe('REJECTED')->and($row->error_code)->toBe('OPERATION_NOT_ALLOWLISTED');
});

it('re-validates a queued operation\'s payload the same way the live endpoint would, and records why it was rejected', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $envelope = syncClientIntakeEnvelope(['consent' => false]);
    $response = $this->postJson('/api/v1/mobile/sync/operations', $envelope, agentHeaders($fixture));

    $response->assertStatus(422);
    expect(TenantCustomer::count())->toBe(0);
    $row = SyncOperation::where('operation_uuid', $envelope['id'])->firstOrFail();
    expect($row->status)->toBe('REJECTED');
    expect($row->response_body['errors'])->toHaveKey('consent');
});

it('lets a queued operation succeed on explicit retry once the transient reason for its rejection has cleared', function () {
    $fixture = makeMobileAgentFixture('+237680000030', ['status' => 'SUSPENDED']);
    Passport::actingAs($fixture['user']);

    $envelope = syncClientIntakeEnvelope();
    $rejected = $this->postJson('/api/v1/mobile/sync/operations', $envelope, agentHeaders($fixture));
    $rejected->assertStatus(422);
    expect(TenantCustomer::count())->toBe(0);

    $fixture['partner']->update(['status' => 'ACTIVE']);

    $retry = $this->postJson('/api/v1/mobile/agent/offline-queue/'.$envelope['id'].'/retry', [], tenantHeaderFor($fixture['tenant']));

    $retry->assertStatus(200);
    expect($retry->json('data.status'))->toBe('APPLIED');
    expect(TenantCustomer::count())->toBe(1);
    expect(SyncOperation::where('operation_uuid', $envelope['id'])->firstOrFail()->attempt_count)->toBe(2);
});

it('lists only the caller\'s own queued operations', function () {
    $agentOne = makeMobileAgentFixture('+237680000031');
    $agentTwo = makeMobileAgentFixtureInTenant($agentOne['tenant'], '+237680000032');

    Passport::actingAs($agentOne['user']);
    $this->postJson('/api/v1/mobile/sync/operations', syncClientIntakeEnvelope(['phone_e164' => '+237679993333']), agentHeaders($agentOne))->assertStatus(200);

    Passport::actingAs($agentTwo['user']);
    $queue = $this->getJson('/api/v1/mobile/agent/offline-queue', tenantHeaderFor($agentTwo['tenant']));

    $queue->assertStatus(200);
    expect($queue->json('data.data'))->toHaveCount(0);
});

it('404s a retry for an operation id that does not belong to the caller', function () {
    $agentOne = makeMobileAgentFixture('+237680000033', ['status' => 'SUSPENDED']);
    $agentTwo = makeMobileAgentFixtureInTenant($agentOne['tenant'], '+237680000034');

    Passport::actingAs($agentOne['user']);
    $envelope = syncClientIntakeEnvelope(['phone_e164' => '+237679994444']);
    $this->postJson('/api/v1/mobile/sync/operations', $envelope, agentHeaders($agentOne))->assertStatus(422);

    Passport::actingAs($agentTwo['user']);
    $this->postJson('/api/v1/mobile/agent/offline-queue/'.$envelope['id'].'/retry', [], tenantHeaderFor($agentTwo['tenant']))->assertStatus(404);
});

it('requires an Idempotency-Key header on operation dispatch', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/sync/operations', syncClientIntakeEnvelope(), tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('403s dispatch without the agent.sync.dispatch permission', function () {
    $fixture = makeMobileAgentFixture();
    $fixture['role']->update(['permissions' => ['agent.clients.manage']]);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/sync/operations', syncClientIntakeEnvelope(), agentHeaders($fixture))->assertStatus(403);
});
