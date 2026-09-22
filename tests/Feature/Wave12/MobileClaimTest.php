<?php

declare(strict_types=1);

use App\Models\Claim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

function mobileClaimFnolPayload(string $policyId, array $overrides = []): array
{
    return array_merge([
        'policy_id' => $policyId,
        'incident_at' => now()->subDay()->toIso8601String(),
        'incident_location' => 'Douala, Boulevard de la Liberté',
        'description' => 'Rear-ended at a traffic light while stationary.',
        'incident_type' => 'COLLISION',
        'injuries_reported' => false,
        'police_report_filed' => true,
        'police_reference' => 'PV-2026-00123',
        'estimated_loss_minor' => 150000,
    ], $overrides);
}

it('files a first notice of loss against the customer\'s own policy', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);

    Passport::actingAs($fixture['user']);
    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);

    $response = $this->postJson('/api/v1/mobile/claims', mobileClaimFnolPayload($policy->id), $headers);

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe('SUBMITTED');
    expect($response->json('data.claimant_party_id'))->toBe($fixture['party']->id);
    expect($response->json('data.policy_id'))->toBe($policy->id);

    $claim = Claim::findOrFail($response->json('data.id'));
    expect($claim->loss_details['description'])->toBe('Rear-ended at a traffic light while stationary.');
    expect($claim->loss_details['police_reference'])->toBe('PV-2026-00123');
});

it('requires an Idempotency-Key header to file a claim', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/claims', mobileClaimFnolPayload($policy->id), tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
});

it('replays the same claim instead of creating a duplicate when the FNOL request is retried with the same key', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);

    Passport::actingAs($fixture['user']);
    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $payload = mobileClaimFnolPayload($policy->id);

    $first = $this->postJson('/api/v1/mobile/claims', $payload, $headers);
    $second = $this->postJson('/api/v1/mobile/claims', $payload, $headers);

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(Claim::count())->toBe(1);
});

it('refuses a claim against a policy that does not belong to the customer', function () {
    $fixture = makeMobileCustomerFixture();
    $otherFixture = makeMobileCustomerFixture('+237670000201');
    $theirPolicy = makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);

    Passport::actingAs($fixture['user']);
    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);

    $response = $this->postJson('/api/v1/mobile/claims', mobileClaimFnolPayload($theirPolicy->id), $headers);

    $response->assertStatus(422);
    expect(Claim::count())->toBe(0);
});

it('refuses a claim whose incident date falls outside the policy coverage period', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id, [
        'coverage_starts_at' => now()->subMonths(2),
        'coverage_ends_at' => now()->subMonths(1),
    ]);

    Passport::actingAs($fixture['user']);
    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);

    $response = $this->postJson('/api/v1/mobile/claims', mobileClaimFnolPayload($policy->id, ['incident_at' => now()->subDay()->toIso8601String()]), $headers);

    $response->assertStatus(422);
});

it('lists only the authenticated customer\'s own claims', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $mine = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000202');
    $theirPolicy = makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id);
    makeMobileTestClaim($fixture['tenant'], $theirPolicy, $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/claims', tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.data'))->toHaveCount(1);
    expect($response->json('data.data.0.id'))->toBe($mine->id);
});

it('shows a single owned claim, 403s for someone else\'s, 404s for a nonexistent one', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    $mine = makeMobileTestClaim($fixture['tenant'], $policy, $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000203');
    $theirPolicy = makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id);
    $theirs = makeMobileTestClaim($fixture['tenant'], $theirPolicy, $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $this->getJson("/api/v1/mobile/claims/{$mine->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(200);
    $this->getJson("/api/v1/mobile/claims/{$theirs->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
    $this->getJson('/api/v1/mobile/claims/'.Str::uuid(), tenantHeaderFor($fixture['tenant']))->assertStatus(404);
});

it('returns the status-change timeline for an owned claim, ownership-scoped the same way', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);

    Passport::actingAs($fixture['user']);
    $headers = array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => (string) Str::uuid()]);
    $created = $this->postJson('/api/v1/mobile/claims', mobileClaimFnolPayload($policy->id), $headers);
    $claimId = $created->json('data.id');

    $timeline = $this->getJson("/api/v1/mobile/claims/{$claimId}/timeline", tenantHeaderFor($fixture['tenant']));

    $timeline->assertStatus(200);
    expect($timeline->json('data.data'))->toHaveCount(1);
    expect($timeline->json('data.data.0.type'))->toBe('FNOL_SUBMITTED');
    expect($timeline->json('data.data.0.to_status'))->toBe('SUBMITTED');

    $otherFixture = makeMobileCustomerFixture('+237670000204');
    Passport::actingAs($otherFixture['user']);
    $this->getJson("/api/v1/mobile/claims/{$claimId}/timeline", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('rejects unauthenticated requests to file, list or view claims', function () {
    $this->getJson('/api/v1/mobile/claims')->assertStatus(401);
    $this->postJson('/api/v1/mobile/claims', [])->assertStatus(401);
});
