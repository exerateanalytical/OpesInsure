<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

it('shows a single owned delivery, and 403s for someone else\'s', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant']);

    $otherFixture = makeMobileCustomerFixture('+237670000094');
    $theirPolicy = makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id);
    $theirDelivery = makeMobileTestDelivery($theirPolicy, $fixture['tenant']);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson("/api/v1/mobile/deliveries/{$delivery->id}", tenantHeaderFor($fixture['tenant']));
    $response->assertStatus(200);
    expect($response->json('data.id'))->toBe($delivery->id);
    expect($response->json('data.status'))->toBe('CREATED');

    $this->getJson("/api/v1/mobile/deliveries/{$theirDelivery->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('updates the delivery address while still in an editable status', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], ['status' => 'READY_FOR_PICKUP']);

    Passport::actingAs($fixture['user']);

    $newAddress = ['city' => 'Yaounde', 'street' => '5 Avenue Kennedy'];
    $response = $this->putJson("/api/v1/mobile/deliveries/{$delivery->id}/address", ['address' => $newAddress], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.delivery_address'))->toBe($newAddress);

    expect(DB::table('fulfilment_events')->where('fulfilment_order_id', $delivery->id)->where('event_type', 'ADDRESS_UPDATED')->count())->toBe(1);
});

it('refuses an address update once the delivery is no longer editable', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], ['status' => 'IN_TRANSIT']);

    Passport::actingAs($fixture['user']);

    $this->putJson("/api/v1/mobile/deliveries/{$delivery->id}/address", [
        'address' => ['city' => 'Yaounde'],
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(422);

    expect($delivery->fresh()->delivery_address)->toBe(['city' => 'Douala', 'street' => '12 Rue de la Paix']);
});

it('confirms delivery with the correct OTP and records a customer-confirmed audit event', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], ['status' => 'IN_TRANSIT']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/deliveries/{$delivery->id}/confirm", ['otp' => '123456'], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe('DELIVERED');
    expect($response->json('data.delivered_at'))->not->toBeNull();

    expect(DB::table('fulfilment_events')->where('fulfilment_order_id', $delivery->id)->where('event_type', 'CUSTOMER_CONFIRMED')->count())->toBe(1);
});

it('rejects delivery confirmation with the wrong OTP and leaves the order untouched', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], ['status' => 'IN_TRANSIT']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/deliveries/{$delivery->id}/confirm", ['otp' => '000000'], tenantHeaderFor($fixture['tenant']))->assertStatus(422);

    expect($delivery->fresh()->status)->toBe('IN_TRANSIT');
});

it('403s a delivery confirmation attempt for someone else\'s order', function () {
    $fixture = makeMobileCustomerFixture();
    $otherFixture = makeMobileCustomerFixture('+237670000093');
    $theirPolicy = makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id);
    $theirDelivery = makeMobileTestDelivery($theirPolicy, $fixture['tenant'], ['status' => 'IN_TRANSIT']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/deliveries/{$theirDelivery->id}/confirm", ['otp' => '123456'], tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('rejects an unauthenticated request to view a delivery', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant']);

    $this->getJson("/api/v1/mobile/deliveries/{$delivery->id}")->assertStatus(401);
});

it('presents the delivery as the app renders it: address fields, tracking, version and a real-event timeline', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], [
        'status' => 'ASSIGNED',
        'tracking_number' => 'TRK-PRESENTER1',
        'delivery_address' => ['recipient_name' => 'Awa Nji', 'phone' => '+237670000001', 'line1' => '5 Avenue Kennedy', 'city' => 'Yaounde'],
    ]);
    DB::table('fulfilment_events')->insert([
        'id' => (string) Str::uuid(), 'fulfilment_order_id' => $delivery->id, 'from_status' => 'READY_FOR_PICKUP',
        'to_status' => 'ASSIGNED', 'event_type' => 'TRANSITION', 'evidence' => '{}', 'occurred_at' => now(),
    ]);

    Passport::actingAs($fixture['user']);

    $data = $this->getJson("/api/v1/mobile/deliveries/{$delivery->id}", tenantHeaderFor($fixture['tenant']))->assertOk()->json('data');

    expect($data['tracking_code'])->toBe('TRK-PRESENTER1')
        ->and($data['recipient_name'])->toBe('Awa Nji')
        ->and($data['phone_e164'])->toBe('+237670000001')
        ->and($data['address_line'])->toBe('5 Avenue Kennedy')
        ->and($data['city'])->toBe('Yaounde')
        ->and($data['version'])->toBe($delivery->fresh()->updated_at->toIso8601String())
        ->and($data['can_change_address'])->toBeFalse()
        ->and($data)->not->toHaveKey('delivery_otp_hash');

    $steps = collect($data['timeline'])->keyBy('status');
    expect($steps->keys()->all())->toBe(['CREATED', 'READY_FOR_PICKUP', 'ASSIGNED', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED'])
        ->and($steps['ASSIGNED']['complete'])->toBeTrue()
        ->and($steps['ASSIGNED']['occurred_at'])->not->toBeNull()
        ->and($steps['READY_FOR_PICKUP']['complete'])->toBeTrue()
        ->and($steps['PICKED_UP']['complete'])->toBeFalse();
});

it('reads the street-style address the older records carry', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant']);

    Passport::actingAs($fixture['user']);

    $data = $this->getJson("/api/v1/mobile/deliveries/{$delivery->id}", tenantHeaderFor($fixture['tenant']))->assertOk()->json('data');

    expect($data['address_line'])->toBe('12 Rue de la Paix')
        ->and($data['city'])->toBe('Douala')
        ->and($data['can_change_address'])->toBeTrue()
        ->and($data['courier'])->toBeNull();
});
