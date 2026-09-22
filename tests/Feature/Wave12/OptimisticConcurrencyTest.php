<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

// EnforcesOptimisticConcurrency is demonstrated on
// MobileDeliveryService::updateAddress() — a customer editing a delivery
// address is a believable real race against a concurrent courier-side
// update. Existing MobileDeliveryTest coverage (no `version` field at all)
// keeps passing unchanged, proving the check is opt-in/non-breaking.

it('updates the address when the supplied version matches the delivery\'s current updated_at', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], ['status' => 'READY_FOR_PICKUP']);

    Passport::actingAs($fixture['user']);

    $response = $this->putJson("/api/v1/mobile/deliveries/{$delivery->id}/address", [
        'address' => ['city' => 'Bafoussam'],
        'version' => $delivery->updated_at->toIso8601String(),
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.delivery_address'))->toBe(['city' => 'Bafoussam']);
});

it('rejects a stale version with 409 and reports the authoritative current version, without applying the edit', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], ['status' => 'READY_FOR_PICKUP']);

    $staleVersion = $delivery->updated_at->toIso8601String();

    // Simulate a concurrent change (e.g. a courier-side update) landing
    // after the customer loaded the screen but before they submitted their
    // edit. Bypass Eloquent's own auto-touch-on-save so the row's updated_at
    // becomes exactly what we choose, deterministically.
    DB::table('fulfilment_orders')->where('id', $delivery->id)->update(['updated_at' => now()->addMinute()]);

    Passport::actingAs($fixture['user']);

    $response = $this->putJson("/api/v1/mobile/deliveries/{$delivery->id}/address", [
        'address' => ['city' => 'Bafoussam'],
        'version' => $staleVersion,
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(409);
    expect($response->json('errors.version'))->not->toBeNull();
    expect($response->json('errors.current_version'))->not->toBeNull();
    // The stale write must not have been applied.
    expect($delivery->fresh()->delivery_address)->toBe(['city' => 'Douala', 'street' => '12 Rue de la Paix']);
});

it('treats an unparseable version as stale rather than silently skipping the check', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], ['status' => 'READY_FOR_PICKUP']);

    Passport::actingAs($fixture['user']);

    $response = $this->putJson("/api/v1/mobile/deliveries/{$delivery->id}/address", [
        'address' => ['city' => 'Bafoussam'],
        'version' => 'not-a-real-timestamp',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(409);
    expect($delivery->fresh()->delivery_address)->toBe(['city' => 'Douala', 'street' => '12 Rue de la Paix']);
});

it('still updates the address when no version is supplied at all (backward compatible / opt-in)', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $delivery = makeMobileTestDelivery($policy, $fixture['tenant'], ['status' => 'READY_FOR_PICKUP']);

    DB::table('fulfilment_orders')->where('id', $delivery->id)->update(['updated_at' => now()->addMinute()]);

    Passport::actingAs($fixture['user']);

    $response = $this->putJson("/api/v1/mobile/deliveries/{$delivery->id}/address", [
        'address' => ['city' => 'Bafoussam'],
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
});
