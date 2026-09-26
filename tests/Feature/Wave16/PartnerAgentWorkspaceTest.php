<?php

declare(strict_types=1);

use App\Models\Consent;
use App\Models\CustomerAttribution;
use App\Models\Party;
use App\Models\TenantCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function w16AgentHeaders($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/** Attributes $party to $partner the way intake does (ACTIVE origin lock). */
function w16Attribute(Party $party, $partner, $user): void
{
    CustomerAttribution::create(['party_id' => $party->id, 'partner_id' => $partner->id, 'origin_type' => 'AGENT', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $user->id]);
}

// ------------------------------------------------------------------ leads

it('creates and lists leads scoped to the calling agent only', function () {
    $a = makeMobileAgentFixture('+237680001001');
    $b = makeMobileAgentFixtureInTenant($a['tenant'], '+237680001002');

    Passport::actingAs($a['user']);
    $created = $this->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Prospect Ngono', 'phone_e164' => '+237690001111', 'city' => 'Yaoundé', 'product_interest' => 'MOTOR'], w16AgentHeaders($a['tenant']))
        ->assertStatus(201)->json('data');
    expect($created['status'])->toBe('NEW')->and($created['full_name'])->toBe('Prospect Ngono');

    $this->getJson('/api/v1/mobile/partner/agent/leads', w16AgentHeaders($a['tenant']))->assertStatus(200)->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/mobile/partner/agent/leads/'.$created['id'], w16AgentHeaders($a['tenant']))->assertStatus(200)->assertJsonPath('data.phone_e164', '+237690001111');

    Passport::actingAs($b['user']);
    $this->getJson('/api/v1/mobile/partner/agent/leads', w16AgentHeaders($a['tenant']))->assertStatus(200)->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/mobile/partner/agent/leads/'.$created['id'], w16AgentHeaders($a['tenant']))->assertStatus(404);
    $this->patchJson('/api/v1/mobile/partner/agent/leads/'.$created['id'], ['status' => 'LOST'], w16AgentHeaders($a['tenant']))->assertStatus(404);
    $this->postJson('/api/v1/mobile/partner/agent/leads/'.$created['id'].'/convert', ['consent_confirmed' => true], w16AgentHeaders($a['tenant']))->assertStatus(404);
});

it('updates a lead status through the allowed pipeline', function () {
    $a = makeMobileAgentFixture('+237680001011');
    Passport::actingAs($a['user']);
    $id = $this->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Prospect Two', 'phone_e164' => '+237690001112'], w16AgentHeaders($a['tenant']))->json('data.id');

    $this->patchJson("/api/v1/mobile/partner/agent/leads/{$id}", ['status' => 'CONTACTED', 'notes' => 'Called on Monday'], w16AgentHeaders($a['tenant']))
        ->assertStatus(200)->assertJsonPath('data.status', 'CONTACTED')->assertJsonPath('data.notes', 'Called on Monday');
    // CONVERTED is only reachable through /convert (consent + intake).
    $this->patchJson("/api/v1/mobile/partner/agent/leads/{$id}", ['status' => 'CONVERTED'], w16AgentHeaders($a['tenant']))->assertStatus(422);
});

it('converts a lead to an origin-locked client only with explicit consent, with a server-issued reference', function () {
    $a = makeMobileAgentFixture('+237680001021');
    Passport::actingAs($a['user']);
    $id = $this->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Prospect Three', 'phone_e164' => '+237690001113', 'city' => 'Douala'], w16AgentHeaders($a['tenant']))->json('data.id');

    $this->postJson("/api/v1/mobile/partner/agent/leads/{$id}/convert", [], w16AgentHeaders($a['tenant']))->assertStatus(422);
    expect(TenantCustomer::count())->toBe(0);

    $res = $this->postJson("/api/v1/mobile/partner/agent/leads/{$id}/convert", ['consent_confirmed' => true], w16AgentHeaders($a['tenant']))->assertStatus(201);
    expect($res->json('data.lead.status'))->toBe('CONVERTED')
        ->and($res->json('data.client.origin_locked'))->toBeTrue()
        ->and($res->json('data.client.consent_reference'))->toStartWith('CNS-');

    $customer = TenantCustomer::findOrFail($res->json('data.client.id'));
    expect(CustomerAttribution::where('party_id', $customer->party_id)->value('partner_id'))->toBe($a['partner']->id);
    expect(Consent::where('party_id', $customer->party_id)->first()->evidence['reference'])->toBe($res->json('data.client.consent_reference'));

    // A converted lead cannot be converted twice.
    $this->postJson("/api/v1/mobile/partner/agent/leads/{$id}/convert", ['consent_confirmed' => true], w16AgentHeaders($a['tenant']))->assertStatus(422);
});

// ------------------------------------------------------ consented intake

it('registers a client with captured consent and a server-issued consent reference', function () {
    $a = makeMobileAgentFixture('+237680001031');
    Passport::actingAs($a['user']);

    $this->postJson('/api/v1/mobile/partner/agent/clients', ['full_name' => 'Client Four', 'phone_e164' => '+237690001114', 'city' => 'Douala'], w16AgentHeaders($a['tenant']))->assertStatus(422);
    $this->postJson('/api/v1/mobile/partner/agent/clients', ['full_name' => 'Client Four', 'phone_e164' => '+237690001114', 'city' => 'Douala', 'consent_confirmed' => false], w16AgentHeaders($a['tenant']))->assertStatus(422);
    expect(TenantCustomer::count())->toBe(0);

    $res = $this->postJson('/api/v1/mobile/partner/agent/clients', ['full_name' => 'Client Four', 'phone_e164' => '+237690001114', 'city' => 'Douala', 'consent_confirmed' => true], w16AgentHeaders($a['tenant']))->assertStatus(201);
    $ref = $res->json('data.consent_reference');
    expect($ref)->toStartWith('CNS-')->and($res->json('data.city'))->toBe('Douala');
    $consent = Consent::where('party_id', TenantCustomer::findOrFail($res->json('data.id'))->party_id)->firstOrFail();
    expect($consent->evidence['reference'])->toBe($ref)->and($consent->evidence['affirmed'])->toBeTrue();
});

// -------------------------------------------------------- quotes / policies

it('lists only the quotes this agent created', function () {
    $a = makeMobileAgentFixture('+237680001041');
    $b = makeMobileAgentFixtureInTenant($a['tenant'], '+237680001042');
    $party = Party::create(['type' => 'PERSON', 'display_name' => 'Quoted Client', 'status' => 'ACTIVE']);
    $mine = makeMobileTestQuote($a['tenant'], $party, ['channel' => 'AGENT', 'status' => 'OFFERED', 'comparison_context' => ['agent_user_id' => $a['user']->id]]);
    makeMobileTestQuote($a['tenant'], $party, ['channel' => 'AGENT', 'comparison_context' => ['agent_user_id' => $b['user']->id]]);
    makeMobileTestQuote($a['tenant'], $party); // a customer's own self-service quote

    Passport::actingAs($a['user']);
    $rows = $this->getJson('/api/v1/mobile/partner/agent/quotes', w16AgentHeaders($a['tenant']))->assertStatus(200)->json('data');
    expect(collect($rows)->pluck('id')->all())->toBe([$mine->id])
        ->and($rows[0]['status'])->toBe('OFFERED')
        ->and($rows[0]['customer_name'])->toBe('Quoted Client');
});

it('lists only policies of clients attributed to this agent', function () {
    $a = makeMobileAgentFixture('+237680001051');
    $b = makeMobileAgentFixtureInTenant($a['tenant'], '+237680001052');
    $mineChain = makeMobileFinanceProposalChain($a['tenant']);
    $theirChain = makeMobileFinanceProposalChain($a['tenant']);
    w16Attribute($mineChain['party'], $a['partner'], $a['user']);
    w16Attribute($theirChain['party'], $b['partner'], $b['user']);
    $mine = makeMobileTestPolicy($mineChain['proposal'], $a['tenant'], $mineChain['carrier']->id, $mineChain['party']->id, ['policy_number' => 'POL-MINE', 'premium_minor' => 250000]);
    makeMobileTestPolicy($theirChain['proposal'], $a['tenant'], $theirChain['carrier']->id, $theirChain['party']->id, ['policy_number' => 'POL-THEIRS']);

    Passport::actingAs($a['user']);
    $rows = $this->getJson('/api/v1/mobile/partner/agent/policies', w16AgentHeaders($a['tenant']))->assertStatus(200)->json('data');
    expect(collect($rows)->pluck('policy_number')->all())->toBe(['POL-MINE'])
        ->and($rows[0]['premium_minor'])->toBe(250000)
        ->and($rows[0]['id'])->toBe($mine->id)
        ->and($rows[0]['party_id'])->toBe($mineChain['party']->id)
        ->and($rows[0]['customer_name'])->not->toBeEmpty()
        ->and($rows[0]['customer_id'])->toBe(TenantCustomer::where(['tenant_id' => $a['tenant']->id, 'party_id' => $mineChain['party']->id])->value('id'));
});

// -------------------------------------------------------------- gates

it('403s a customer on every agent workspace endpoint', function (string $method, string $uri) {
    $fixture = makeMobileCustomerFixture('+237670001060');
    Passport::actingAs($fixture['user']);

    $this->json($method, $uri, ['consent_confirmed' => true, 'full_name' => 'X Y Z', 'phone_e164' => '+237690009999', 'city' => 'Douala'], w16AgentHeaders($fixture['tenant']))->assertStatus(403);
})->with([
    ['GET', '/api/v1/mobile/partner/agent/leads'],
    ['POST', '/api/v1/mobile/partner/agent/leads'],
    ['GET', '/api/v1/mobile/partner/agent/quotes'],
    ['GET', '/api/v1/mobile/partner/agent/policies'],
    ['POST', '/api/v1/mobile/partner/agent/clients'],
]);

it('403s a broker (non-agent partner) holding agent permissions', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670001070');
    $role = App\Models\Role::create(['tenant_id' => $broker['tenant']->id, 'code' => 'AGENTLIKE', 'permissions' => ['agent.clients.read'], 'is_system' => false]);
    App\Models\TenantMembership::where('user_id', $broker['user']->id)->first()->roles()->attach($role->id);
    Passport::actingAs($broker['user']);

    $this->getJson('/api/v1/mobile/partner/agent/leads', w16AgentHeaders($broker['tenant']))->assertStatus(403);
    expect(DB::table('partner_leads')->count())->toBe(0);
});
