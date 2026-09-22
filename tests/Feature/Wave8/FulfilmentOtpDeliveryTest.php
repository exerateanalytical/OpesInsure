<?php

declare(strict_types=1);

use App\Models\Courier;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/Concerns/fulfilment_helpers.php';

it('no longer returns the plaintext OTP in the response, and queues it as an SMS instead', function () {
    NotificationTemplate::create(['code' => 'fulfilment.delivery_otp', 'locale' => 'en', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS', 'body' => 'Code: {{otp}}', 'required_variables' => ['otp'], 'version' => 1, 'status' => 'ACTIVE']);

    $policy = makeFulfilmentTestPolicy('+237670000000');
    $tenant = $policy->tenant;
    $user = makeAuthTestUser($tenant, ['fulfilments.manage']);
    Passport::actingAs($user);

    $response = $this->postJson('/api/v1/fulfilments', [
        'policy_id' => $policy->id,
        'delivery_address' => ['city' => 'Douala', 'street' => '12 Rue de la Paix'],
        'sla_due_at' => now()->addDays(3)->toISOString(),
        'idempotency_key' => (string) Illuminate\Support\Str::uuid(),
    ], tenantHeader($tenant));

    $response->assertStatus(201);
    expect($response->json('data'))->not->toHaveKey('delivery_otp');
    expect($response->json('data.delivery_otp_sent'))->toBeTrue();

    $delivery = DB::table('notification_deliveries')->where('party_id', $policy->party_id)->first();
    expect($delivery)->not->toBeNull();
    expect($delivery->channel)->toBe('SMS');
    expect(json_decode($delivery->payload, true))->toHaveKey('otp');
});

it('does not queue a second OTP SMS on an idempotent replay of the same request', function () {
    NotificationTemplate::create(['code' => 'fulfilment.delivery_otp', 'locale' => 'en', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS', 'body' => 'Code: {{otp}}', 'required_variables' => ['otp'], 'version' => 1, 'status' => 'ACTIVE']);

    $policy = makeFulfilmentTestPolicy('+237670000000');
    $tenant = $policy->tenant;
    $user = makeAuthTestUser($tenant, ['fulfilments.manage']);
    Passport::actingAs($user);

    $payload = [
        'policy_id' => $policy->id,
        'delivery_address' => ['city' => 'Douala'],
        'sla_due_at' => now()->addDays(3)->toISOString(),
        'idempotency_key' => (string) Illuminate\Support\Str::uuid(),
    ];

    $this->postJson('/api/v1/fulfilments', $payload, tenantHeader($tenant))->assertStatus(201);
    $this->postJson('/api/v1/fulfilments', $payload, tenantHeader($tenant))->assertStatus(200);

    expect(DB::table('notification_deliveries')->where('party_id', $policy->party_id)->count())->toBe(1);
});

it('reports courier availability for a city with a matching ACTIVE courier', function () {
    $policy = makeFulfilmentTestPolicy();
    $tenant = $policy->tenant;
    $user = makeAuthTestUser($tenant, ['fulfilments.manage']);
    Passport::actingAs($user);

    $courier = Courier::create(['tenant_id' => $tenant->id, 'name' => 'Douala Courier', 'type' => 'INTERNAL', 'phone_hash' => 'x', 'status' => 'ACTIVE', 'service_areas' => ['Douala']]);

    $response = $this->postJson('/api/v1/fulfilments/courier-availability', [
        'delivery_address' => ['city' => 'Douala'],
    ], tenantHeader($tenant));

    $response->assertStatus(200);
    expect($response->json('data.available'))->toBeTrue();
    expect($response->json('data.couriers.0.id'))->toBe($courier->id);
});

it('reports unavailable when no courier serves the requested city', function () {
    $policy = makeFulfilmentTestPolicy();
    $tenant = $policy->tenant;
    $user = makeAuthTestUser($tenant, ['fulfilments.manage']);
    Passport::actingAs($user);

    $response = $this->postJson('/api/v1/fulfilments/courier-availability', [
        'delivery_address' => ['city' => 'Garoua'],
    ], tenantHeader($tenant));

    $response->assertStatus(200);
    expect($response->json('data.available'))->toBeFalse();
    expect($response->json('data.couriers'))->toBe([]);
});
