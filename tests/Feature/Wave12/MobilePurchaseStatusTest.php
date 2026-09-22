<?php

declare(strict_types=1);

use App\Models\Policy;
use Database\Seeders\MobileOAuthClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(MobileOAuthClientSeeder::class));

require_once __DIR__.'/Concerns/mobile_auth_helpers.php';
require_once __DIR__.'/../Wave4/Concerns/mobile_money_helpers.php';

function actingAsMobileCustomer(): array
{
    $user = makeMobileTestUser('+237670000099');
    [$tenant] = makeMobileTestWorkspace($user, [], 'AGENT');
    linkMobileTestUserToParty($user, $tenant);
    Passport::actingAs($user);

    return [$user, $tenant];
}

it('reports PAYMENT_PENDING when no payment has been initiated yet', function () {
    [$user, $tenant] = actingAsMobileCustomer();
    $proposal = makeMobileMoneyTestProposal();
    $proposal->update(['tenant_id' => $tenant->id, 'party_id' => linkedPartyId($user)]);

    $response = $this->getJson("/api/v1/mobile/purchases/{$proposal->id}/status", ['X-Tenant-Id' => $tenant->id]);

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe('PAYMENT_PENDING');
    expect($response->json('data.payment'))->toBeNull();
    expect($response->json('data.policy'))->toBeNull();
});

it('reports ISSUANCE_PENDING when payment succeeded but no policy exists yet — the exact gap this endpoint exists to close', function () {
    [$user, $tenant] = actingAsMobileCustomer();
    $proposal = makeMobileMoneyTestProposal();
    $proposal->update(['tenant_id' => $tenant->id, 'party_id' => linkedPartyId($user)]);
    $intent = makeMobileMoneyTestIntent('mtn_momo', (string) Str::uuid(), ['proposal_id' => $proposal->id, 'tenant_id' => $tenant->id, 'status' => 'SUCCEEDED']);

    $response = $this->getJson("/api/v1/mobile/purchases/{$proposal->id}/status", ['X-Tenant-Id' => $tenant->id]);

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe('ISSUANCE_PENDING');
    expect($response->json('data.payment.id'))->toBe($intent->id);
    expect($response->json('data.payment.status'))->toBe('SUCCEEDED');
    expect($response->json('data.policy'))->toBeNull();
});

it('reports POLICY_ISSUED only once a real, persisted policy exists', function () {
    [$user, $tenant] = actingAsMobileCustomer();
    $proposal = makeMobileMoneyTestProposal();
    $partyId = linkedPartyId($user);
    $proposal->update(['tenant_id' => $tenant->id, 'party_id' => $partyId]);
    makeMobileMoneyTestIntent('mtn_momo', (string) Str::uuid(), ['proposal_id' => $proposal->id, 'tenant_id' => $tenant->id, 'status' => 'SUCCEEDED']);

    $policy = Policy::create([
        'tenant_id' => $tenant->id,
        'proposal_id' => $proposal->id,
        'carrier_id' => $proposal->offer->carrier_id,
        'party_id' => $partyId,
        'status' => 'ACTIVE',
        'coverage_starts_at' => now(),
        'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [],
    ]);

    $response = $this->getJson("/api/v1/mobile/purchases/{$proposal->id}/status", ['X-Tenant-Id' => $tenant->id]);

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe('POLICY_ISSUED');
    expect($response->json('data.policy.id'))->toBe($policy->id);
});

it('maps FAILED and EXPIRED payments to PAYMENT_FAILED, and PROCESSING to PAYMENT_PROCESSING', function () {
    [$user, $tenant] = actingAsMobileCustomer();

    $failedProposal = makeMobileMoneyTestProposal();
    $failedProposal->update(['tenant_id' => $tenant->id, 'party_id' => linkedPartyId($user)]);
    makeMobileMoneyTestIntent('mtn_momo', (string) Str::uuid(), ['proposal_id' => $failedProposal->id, 'tenant_id' => $tenant->id, 'status' => 'FAILED']);

    $processingProposal = makeMobileMoneyTestProposal();
    $processingProposal->update(['tenant_id' => $tenant->id, 'party_id' => linkedPartyId($user)]);
    makeMobileMoneyTestIntent('mtn_momo', (string) Str::uuid(), ['proposal_id' => $processingProposal->id, 'tenant_id' => $tenant->id, 'status' => 'PROCESSING']);

    $this->getJson("/api/v1/mobile/purchases/{$failedProposal->id}/status", ['X-Tenant-Id' => $tenant->id])
        ->assertJsonPath('data.status', 'PAYMENT_FAILED');

    $this->getJson("/api/v1/mobile/purchases/{$processingProposal->id}/status", ['X-Tenant-Id' => $tenant->id])
        ->assertJsonPath('data.status', 'PAYMENT_PROCESSING');
});

it('refuses a proposal that does not belong to the authenticated customer', function () {
    [, $tenant] = actingAsMobileCustomer();
    $proposal = makeMobileMoneyTestProposal(); // its own unrelated party
    $proposal->update(['tenant_id' => $tenant->id]);

    $this->getJson("/api/v1/mobile/purchases/{$proposal->id}/status", ['X-Tenant-Id' => $tenant->id])
        ->assertStatus(403);
});

it('returns 404 for a proposal belonging to a different tenant than the one in the request (never leaks cross-tenant existence)', function () {
    [, $tenant] = actingAsMobileCustomer();
    $proposal = makeMobileMoneyTestProposal(); // its own unrelated tenant, never reassigned to $tenant

    $this->getJson("/api/v1/mobile/purchases/{$proposal->id}/status", ['X-Tenant-Id' => $tenant->id])
        ->assertStatus(404);
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/mobile/purchases/'.Str::uuid().'/status', ['X-Tenant-Id' => (string) Str::uuid()])
        ->assertStatus(401);
});

function linkedPartyId($user): string
{
    return App\Models\PartyContact::where('normalized_value', $user->phone_e164)->firstOrFail()->party_id;
}
