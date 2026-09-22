<?php

declare(strict_types=1);

use App\Models\ClaimInvolvedParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

it('records the claimant themselves as an involved party without requiring consent', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/parties", [
        'role' => 'DRIVER',
        'display_name' => 'Mobile Wallet Test Party',
        'is_self' => true,
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
    expect($response->json('data.is_self'))->toBeTrue();
    expect($response->json('data.consent_given'))->toBeFalse();
});

it('refuses to store a third party\'s contact details without their consent', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/parties", [
        'role' => 'THIRD_PARTY',
        'display_name' => 'Other Driver',
        'contact_phone' => '+237670000999',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
    expect(ClaimInvolvedParty::count())->toBe(0);
});

it('stores a third party\'s contact details once consent is explicitly given', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/parties", [
        'role' => 'WITNESS',
        'display_name' => 'A Witness',
        'contact_phone' => '+237670000998',
        'consent_given' => true,
        'notes' => 'Saw the collision from across the street.',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
    expect($response->json('data.consent_given'))->toBeTrue();
    expect($response->json('data.contact_phone'))->toBe('+237670000998');
});

it('allows a party record with no contact details and no consent (e.g. an unreachable witness)', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/parties", [
        'role' => 'WITNESS',
        'display_name' => 'Unidentified passerby',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
});

it('lists only the involved parties of a claim the customer owns, and 403s another customer\'s claim', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);
    ClaimInvolvedParty::create(['claim_id' => $claim->id, 'role' => 'DRIVER', 'display_name' => 'Self', 'is_self' => true, 'added_by' => $fixture['user']->id]);

    $otherFixture = makeMobileCustomerFixture('+237670000208');
    $theirPolicy = makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id);
    $theirClaim = makeMobileTestClaim($fixture['tenant'], $theirPolicy, $otherFixture['party']);
    ClaimInvolvedParty::create(['claim_id' => $theirClaim->id, 'role' => 'DRIVER', 'display_name' => 'Someone else', 'is_self' => true, 'added_by' => $otherFixture['user']->id]);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson("/api/v1/mobile/claims/{$claim->id}/parties", tenantHeaderFor($fixture['tenant']));
    $response->assertStatus(200);
    expect($response->json('data.data'))->toHaveCount(1);

    $this->getJson("/api/v1/mobile/claims/{$theirClaim->id}/parties", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('refuses to add an involved party once the claim is closed', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party'], ['status' => 'CLOSED', 'closed_at' => now()]);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/claims/{$claim->id}/parties", [
        'role' => 'DRIVER',
        'display_name' => 'Self',
        'is_self' => true,
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
});

it('404s a nonexistent claim for both listing and adding a party', function () {
    $fixture = makeMobileCustomerFixture();
    Passport::actingAs($fixture['user']);

    $this->getJson('/api/v1/mobile/claims/'.Str::uuid().'/parties', tenantHeaderFor($fixture['tenant']))->assertStatus(404);
    $this->postJson('/api/v1/mobile/claims/'.Str::uuid().'/parties', ['role' => 'DRIVER', 'display_name' => 'Self'], tenantHeaderFor($fixture['tenant']))->assertStatus(404);
});

it('rejects an unauthenticated request to view or add involved parties', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $claim = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    $this->getJson("/api/v1/mobile/claims/{$claim->id}/parties")->assertStatus(401);
    $this->postJson("/api/v1/mobile/claims/{$claim->id}/parties", [])->assertStatus(401);
});
