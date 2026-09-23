<?php

declare(strict_types=1);

use App\Models\Consent;
use App\Models\CustomerAttribution;
use App\Models\Party;
use App\Models\TenantCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

it('registers a new client and origin-locks it to the agent\'s own partner', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), agentHeaders($fixture));

    $response->assertStatus(201);

    $attribution = CustomerAttribution::findOrFail($response->json('data.attribution_id'));
    expect($attribution->partner_id)->toBe($fixture['partner']->id)->and($attribution->origin_type)->toBe('AGENT');

    $customer = TenantCustomer::findOrFail($response->json('data.id'));
    expect($customer->tenant_id)->toBe($fixture['tenant']->id);
    expect(Consent::where('party_id', $customer->party_id)->where('status', 'GRANTED')->exists())->toBeTrue();
});

it('normalizes a local 9-digit Cameroon number to E.164 before storing it', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(['phone_e164' => '671112244']), agentHeaders($fixture));

    $response->assertStatus(201);
    $party = Party::findOrFail($response->json('data.party_id'));
    expect($party->contacts()->where('type', 'PHONE')->value('normalized_value'))->toBe('+237671112244');
});

it('rejects an unparseable phone number without creating anything', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(['phone_e164' => 'not-a-phone']), agentHeaders($fixture));

    $response->assertStatus(422);
    expect(TenantCustomer::count())->toBe(0);
});

it('403s a caller with the permission but no resolvable agent partner', function () {
    $fixture = makeMobileAgentFixture();
    $fixture['partner']->delete();
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), agentHeaders($fixture))->assertStatus(403);
});

it('403s a caller without the agent.clients.manage permission even with a valid partner', function () {
    $fixture = makeMobileAgentFixture();
    $fixture['role']->update(['permissions' => []]);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), agentHeaders($fixture))->assertStatus(403);
});

it('blocks client registration while the agent\'s own partner is suspended, without partial writes', function () {
    $fixture = makeMobileAgentFixture('+237680000010', ['status' => 'SUSPENDED']);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), agentHeaders($fixture));

    $response->assertStatus(422);
    expect(TenantCustomer::count())->toBe(0);
    expect(Party::where('display_name', 'New Client Kamga')->exists())->toBeFalse();
});

it('never lets a second agent take over a client already locked to a different agent', function () {
    $agentOne = makeMobileAgentFixture('+237680000001');
    $agentTwo = makeMobileAgentFixture('+237680000002');

    Passport::actingAs($agentOne['user']);
    $first = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(['phone_e164' => '+237679990000']), agentHeaders($agentOne));
    $first->assertStatus(201);

    Passport::actingAs($agentTwo['user']);
    $second = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(['phone_e164' => '+237679990000', 'display_name' => 'Takeover Attempt']), agentHeaders($agentTwo));

    $second->assertStatus(422);
    expect(CustomerAttribution::where('party_id', Party::where('display_name', 'New Client Kamga')->value('id'))->first()->partner_id)->toBe($agentOne['partner']->id);
});

it('is safe for the same agent to re-register their own client: one attribution, one customer', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $first = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), agentHeaders($fixture));
    $second = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), agentHeaders($fixture));

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect($second->json('data.attribution_id'))->toBe($first->json('data.attribution_id'));
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(TenantCustomer::count())->toBe(1);
    expect(CustomerAttribution::count())->toBe(1);
});

it('requires an Idempotency-Key header on intake', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('lists and shows only clients attributed to the caller\'s own agent partner', function () {
    $agentOne = makeMobileAgentFixture('+237680000003');
    $agentTwo = makeMobileAgentFixtureInTenant($agentOne['tenant'], '+237680000004');

    Passport::actingAs($agentOne['user']);
    $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(['phone_e164' => '+237679991111']), agentHeaders($agentOne))->assertStatus(201);

    Passport::actingAs($agentTwo['user']);
    $ownResponse = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(['phone_e164' => '+237679992222', 'display_name' => 'Agent Two Client']), agentHeaders($agentTwo));
    $ownResponse->assertStatus(201);

    $index = $this->getJson('/api/v1/mobile/agent/clients', tenantHeaderFor($agentTwo['tenant']));
    $index->assertStatus(200);
    expect($index->json('data.data'))->toHaveCount(1);
    expect($index->json('data.data.0.id'))->toBe($ownResponse->json('data.id'));

    $this->getJson('/api/v1/mobile/agent/clients/'.$ownResponse->json('data.id'), tenantHeaderFor($agentTwo['tenant']))->assertStatus(200);

    Passport::actingAs($agentOne['user']);
    $this->getJson('/api/v1/mobile/agent/clients/'.$ownResponse->json('data.id'), tenantHeaderFor($agentOne['tenant']))->assertStatus(403);
});

it('rejects an unauthenticated request', function () {
    $fixture = makeMobileAgentFixture();

    $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), agentHeaders($fixture))->assertStatus(401);
});
