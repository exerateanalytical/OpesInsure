<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Partner;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\TenantCustomer;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';

// --- 4.2 Public institution directory ------------------------------------

function gapsCarrier(string $name, string $status = 'ACTIVE', array $capabilities = []): Carrier
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE', 'legal_identity' => ['city' => 'Douala']]);
    PartyContact::create(['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => '+2372334'.random_int(10000, 99999), 'is_primary' => true]);

    return Carrier::create(['party_id' => $party->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => $status, 'capabilities' => $capabilities]);
}

function gapsBroker(string $name, string $type = 'BROKER', string $status = 'ACTIVE'): Partner
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE', 'legal_identity' => ['city' => 'Yaoundé']]);

    return Partner::create(['party_id' => $party->id, 'type' => $type, 'status' => $status, 'licence_number' => 'LIC-'.Str::random(4), 'compliance' => []]);
}

it('lists active carriers as insurers without authentication', function () {
    $active = gapsCarrier('Chanas Assurances', 'ACTIVE', ['website' => 'https://www.chanasassurances.com']);
    gapsCarrier('Dormant Carrier', 'SUSPENDED');
    InsuranceProduct::create(['carrier_id' => $active->id, 'line_code' => 'AUTO', 'code' => 'AUTO-'.Str::random(6), 'name' => 'Chanas Auto', 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'ACTIVE']);

    $response = $this->getJson('/api/v1/public/institutions?type=insurer');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $row = $response->json('data.0');
    expect($row['id'])->toBe($active->id)
        ->and($row['type'])->toBe('insurer')
        ->and($row['name'])->toBe('Chanas Assurances')
        ->and($row['initials'])->toBe('CA')
        ->and($row['city'])->toBe('Douala')
        ->and($row['website'])->toBe('https://www.chanasassurances.com')
        ->and($row['phone'])->toStartWith('+2372334')
        ->and($row['products'])->toHaveCount(1)
        ->and($row['products'][0]['name'])->toBe('Chanas Auto')
        ->and($row['products'][0]['line_code'])->toBe('AUTO');
});

it('lists only active broker partners as brokers', function () {
    $broker = gapsBroker('Ascoma Cameroun');
    gapsBroker('Pending Broker', 'BROKER', 'PENDING');
    gapsBroker('Field Agent', 'AGENT');

    $response = $this->getJson('/api/v1/public/institutions?type=broker');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($broker->id)
        ->and($response->json('data.0.type'))->toBe('broker')
        ->and($response->json('data.0.city'))->toBe('Yaoundé')
        ->and($response->json('data.0.licence_number'))->toBe($broker->licence_number);
});

it('rejects an unknown institution type', function () {
    $this->getJson('/api/v1/public/institutions?type=bank')->assertStatus(422);
});

it('shows one institution by id and 404s inactive or unknown ones', function () {
    $carrier = gapsCarrier('AXA Assurances Cameroun');
    $broker = gapsBroker('Gras Savoye');
    $dormant = gapsCarrier('Gone Insurer', 'SUSPENDED');

    $this->getJson("/api/v1/public/institutions/{$carrier->id}")->assertOk()->assertJsonPath('data.type', 'insurer')->assertJsonPath('data.name', 'AXA Assurances Cameroun');
    $this->getJson("/api/v1/public/institutions/{$broker->id}")->assertOk()->assertJsonPath('data.type', 'broker');
    $this->getJson("/api/v1/public/institutions/{$dormant->id}")->assertNotFound();
    $this->getJson('/api/v1/public/institutions/'.Str::uuid())->assertNotFound();
});

// --- 4.3 No placeholder server values -------------------------------------

it('stores no placeholder address line for agent intake and returns null city when unknown', function () {
    $fixture = makeMobileAgentFixture('+237680000901');
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(), agentHeaders($fixture));
    $response->assertStatus(201);
    $customer = TenantCustomer::findOrFail($response->json('data.id'));
    expect(DB::table('party_addresses')->where('party_id', $customer->party_id)->value('line1'))->toBeNull();
    expect($response->json('data.city'))->toBe('Douala');

    DB::table('party_addresses')->where('party_id', $customer->party_id)->delete();
    $show = $this->getJson("/api/v1/mobile/agent/clients/{$customer->id}", tenantHeaderFor($fixture['tenant']));
    $show->assertOk();
    expect($show->json('data.city'))->toBeNull();
});

it('computes broker client origin_locked from the attribution record', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670000902');
    $party = Party::create(['type' => 'PERSON', 'display_name' => 'Locked Client', 'status' => 'ACTIVE']);
    $customer = TenantCustomer::create(['tenant_id' => $broker['tenant']->id, 'party_id' => $party->id, 'customer_number' => 'C-'.Str::random(8), 'status' => 'ACTIVE']);
    DB::table('customer_attributions')->insert([
        'id' => (string) Str::uuid(), 'party_id' => $party->id, 'partner_id' => $broker['partner']->id, 'origin_type' => 'BROKER',
        'terms_version' => 't1', 'effective_from' => now()->subDay(), 'status' => 'ACTIVE', 'evidence_reference' => 'e', 'recorded_by' => $broker['user']->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Passport::actingAs($broker['user']);

    $response = $this->getJson("/api/v1/mobile/broker/clients/{$customer->id}", tenantHeaderFor($broker['tenant']));

    $response->assertOk();
    expect($response->json('data.origin_locked'))->toBeTrue();
    expect($response->json('data.city'))->toBeNull();
});

// --- 4.4 Partner notifications --------------------------------------------

it('serves the notification inbox to agent, broker and carrier users, scoped to the user', function (string $kind) {
    $fixture = $kind === 'AGENT'
        ? makeMobileAgentFixture('+237680000903')
        : makeMobilePartnerFixture($kind, $kind === 'BROKER' ? '+237670000904' : '+237670000905');
    $other = makeMobileTestUser('+237670000906');
    $mine = UserNotification::notify($fixture['user'], 'INFO', 'Mine', 'For me', 'INFO', null, $fixture['tenant']->id);
    UserNotification::notify($other, 'INFO', 'Theirs', 'Not for me');
    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/notifications', tenantHeaderFor($fixture['tenant']));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($mine->id);
    $this->postJson("/api/v1/mobile/notifications/{$mine->id}/read", [], tenantHeaderFor($fixture['tenant']))->assertOk()->assertJsonPath('data.read', true);
})->with(['AGENT', 'BROKER', 'CARRIER']);
