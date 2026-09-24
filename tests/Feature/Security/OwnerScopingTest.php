<?php

declare(strict_types=1);

use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/**
 * A second customer in the SAME tenant as $a — the audit A1 shape: every
 * self-registered customer shares one tenant.
 */
function secondCustomerIn(array $a, string $phone = '+237670000555'): array
{
    $tenant = $a['tenant'];
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Customer B', 'status' => 'ACTIVE']);
    PartyContact::create(['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => $phone, 'is_primary' => true]);
    $user = User::create(['full_name' => 'Customer B', 'phone_e164' => $phone, 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'CUSTOMER', 'status' => 'ACTIVE']);
    $quote = Quote::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'line_code' => 'AUTO', 'status' => 'RATED', 'currency' => 'XAF', 'risk_facts' => []]);
    $offer = QuoteOffer::create(['quote_id' => $quote->id, 'carrier_id' => $a['carrier']->id, 'product_id' => $a['product']->id, 'tariff_version_id' => $a['tariff']->id, 'premium_minor' => 100000, 'total_minor' => 100000, 'currency' => 'XAF', 'status' => 'OFFERED', 'calculation_breakdown' => [], 'valid_until' => now()->addDays(7)]);
    $proposal = Proposal::create(['tenant_id' => $tenant->id, 'quote_offer_id' => $offer->id, 'party_id' => $party->id, 'status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['total_minor' => 100000, 'currency' => 'XAF']]);
    $customer = makeMobileTestTenantCustomer($tenant, $party);
    $policy = makeMobileTestPolicy($proposal, $tenant, $a['carrier']->id, $party->id);
    $payment = makeMobileTestPayment($proposal, $tenant, ['status' => 'PENDING_CUSTOMER', 'payer_phone_e164' => $phone]);
    $asset = makeMobileTestRiskAsset($tenant, $party);

    return compact('tenant', 'party', 'user', 'quote', 'offer', 'proposal', 'customer', 'policy', 'payment', 'asset');
}

function ownerScopingWorld(): array
{
    $a = makeMobileCustomerFixture('+237670000444');
    $a['customer'] = makeMobileTestTenantCustomer($a['tenant'], $a['party']);
    $a['policy'] = makeMobileTestPolicy($a['proposal'], $a['tenant'], $a['carrier']->id, $a['party']->id);
    $a['asset'] = makeMobileTestRiskAsset($a['tenant'], $a['party']);
    $a['payment'] = makeMobileTestPayment($a['proposal'], $a['tenant']);

    return [$a, secondCustomerIn($a)];
}

it('404s every read of another customer\'s records in the same tenant', function () {
    [$a, $b] = ownerScopingWorld();
    Passport::actingAs($a['user']);
    $h = tenantHeaderFor($a['tenant']);

    foreach ([
        "/api/v1/customers/{$b['customer']->id}",
        "/api/v1/policies/{$b['policy']->id}",
        "/api/v1/risk-assets/{$b['asset']->id}",
        "/api/v1/quotes/{$b['quote']->id}",
        "/api/v1/proposals/{$b['proposal']->id}",
        "/api/v1/payments/{$b['payment']->id}",
    ] as $url) {
        $this->getJson($url, $h)->assertStatus(404);
    }
    // Wallet-backed nested endpoint keeps its existing 403 contract for a foreign policy.
    expect($this->getJson("/api/v1/policies/{$b['policy']->id}/certificate", $h)->status())->toBeIn([403, 404]);
});

it('reads its own records', function () {
    [$a] = ownerScopingWorld();
    Passport::actingAs($a['user']);
    $h = tenantHeaderFor($a['tenant']);

    $this->getJson("/api/v1/customers/{$a['customer']->id}", $h)->assertOk();
    $this->getJson("/api/v1/policies/{$a['policy']->id}", $h)->assertOk();
    $this->getJson("/api/v1/risk-assets/{$a['asset']->id}", $h)->assertOk();
    $this->getJson("/api/v1/quotes/{$a['quote']->id}", $h)->assertOk();
    $this->getJson("/api/v1/proposals/{$a['proposal']->id}", $h)->assertOk();
    $this->getJson("/api/v1/payments/{$a['payment']->id}", $h)->assertOk();
});

it('lists only the caller\'s own rows', function () {
    [$a, $b] = ownerScopingWorld();
    Passport::actingAs($a['user']);
    $h = tenantHeaderFor($a['tenant']);

    expect(collect($this->getJson('/api/v1/customers', $h)->assertOk()->json('data.data'))->pluck('id')->all())->toBe([$a['customer']->id]);
    expect(collect($this->getJson('/api/v1/policies', $h)->assertOk()->json('data.data'))->pluck('id')->all())->toBe([$a['policy']->id]);
    expect(collect($this->getJson('/api/v1/risk-assets', $h)->assertOk()->json('data.data'))->pluck('id')->all())->toBe([$a['asset']->id]);
    // A party_id filter cannot widen the scope.
    expect($this->getJson("/api/v1/policies?party_id={$b['party']->id}", $h)->json('data.data'))->toBe([]);
    expect($this->getJson("/api/v1/risk-assets?party_id={$b['party']->id}", $h)->json('data.data'))->toBe([]);
});

it('404s writes against another customer\'s records', function () {
    [$a, $b] = ownerScopingWorld();
    Passport::actingAs($a['user']);
    $h = tenantHeaderFor($a['tenant']);

    $this->postJson("/api/v1/quotes/{$b['quote']->id}/offers/{$b['offer']->id}/accept", [], $h)->assertStatus(404);
    $this->postJson("/api/v1/proposals/{$b['proposal']->id}/submit", [], $h)->assertStatus(404);
    $this->putJson("/api/v1/proposals/{$b['proposal']->id}/disclosures", ['answers' => ['x' => 1]], $h)->assertStatus(404);
    $this->postJson("/api/v1/proposals/{$b['proposal']->id}/disclosures/attest", [], $h)->assertStatus(404);
    $this->postJson("/api/v1/payments/{$b['payment']->id}/initiate", [], $h)->assertStatus(404);
    $this->postJson('/api/v1/payments', ['proposal_id' => $b['proposal']->id, 'provider' => 'mtn_momo', 'payer_phone_e164' => '+237670000444', 'idempotency_key' => str_repeat('k', 20)], $h)->assertStatus(404);
    $this->patchJson("/api/v1/risk-assets/{$b['asset']->id}", ['version' => 1, 'display_name' => 'x'], $h)->assertStatus(404);
    $this->postJson("/api/v1/policies/{$b['policy']->id}/transactions", ['type' => 'CANCELLATION', 'effective_at' => now()->toDateString(), 'premium_delta_minor' => 0, 'reason_code' => 'X'], $h)->assertStatus(404);
    expect($this->postJson("/api/v1/policies/{$b['policy']->id}/service-requests", ['type' => 'ENDORSEMENT', 'reason' => 'change please'], $h)->status())->toBeIn([403, 404]);
    $this->postJson('/api/v1/risk-assets', ['party_id' => $b['party']->id, 'type' => 'VEHICLE', 'display_name' => 'x', 'facts' => ['a' => 1]], $h)->assertStatus(404);
    $this->postJson('/api/v1/proposals', ['quote_offer_id' => $b['offer']->id, 'party_id' => $a['party']->id], $h)->assertStatus(404);
    $this->postJson('/api/v1/quotes', ['customer_id' => $b['customer']->id, 'line_code' => 'AUTO', 'channel' => 'B2C', 'risk_facts' => ['a' => 1]], $h)->assertStatus(404);
});

it('rejects accepting an offer that belongs to a different quote', function () {
    [$a, $b] = ownerScopingWorld();
    Passport::actingAs($a['user']);

    $this->postJson("/api/v1/quotes/{$a['quote']->id}/offers/{$b['offer']->id}/accept", [], tenantHeaderFor($a['tenant']))->assertStatus(404);
});

it('keeps tenant-wide access for staff with the permission', function () {
    [$a, $b] = ownerScopingWorld();
    $staff = makeMobileTenantStaffUser($a['tenant'], '+237670000777', 'PLATFORM_ADMIN');
    Passport::actingAs($staff);
    $h = tenantHeaderFor($a['tenant']);

    expect($this->getJson('/api/v1/customers', $h)->assertOk()->json('data.data'))->toHaveCount(2);
    expect($this->getJson('/api/v1/policies', $h)->assertOk()->json('data.data'))->toHaveCount(2);
    expect($this->getJson('/api/v1/risk-assets', $h)->assertOk()->json('data.data'))->toHaveCount(2);
    $this->getJson("/api/v1/customers/{$b['customer']->id}", $h)->assertOk();
    $this->getJson("/api/v1/policies/{$b['policy']->id}", $h)->assertOk();
    $this->getJson("/api/v1/payments/{$b['payment']->id}", $h)->assertOk();
    $this->getJson("/api/v1/proposals/{$b['proposal']->id}", $h)->assertOk();
});

it('403s tenant-wide lists for non-customer roles without the read permission', function () {
    [$a] = ownerScopingWorld();
    $agent = makeMobileTenantStaffUser($a['tenant'], '+237670000778', 'AGENT');
    Passport::actingAs($agent);
    $h = tenantHeaderFor($a['tenant']);

    $this->getJson('/api/v1/customers', $h)->assertStatus(403);
    $this->getJson('/api/v1/policies', $h)->assertStatus(403);
    $this->getJson('/api/v1/risk-assets', $h)->assertStatus(403);
});

it('gates the web-experience portal dashboard by permission', function () {
    [$a] = ownerScopingWorld();
    Passport::actingAs($a['user']);
    $h = tenantHeaderFor($a['tenant']);
    foreach (['admin', 'customer', 'broker', 'carrier', 'agent'] as $portal) {
        $this->getJson("/api/v1/web-experiences/{$portal}/dashboard", $h)->assertStatus(403);
    }

    Passport::actingAs(makeMobileTenantStaffUser($a['tenant'], '+237670000779', 'PLATFORM_ADMIN'));
    $this->getJson('/api/v1/web-experiences/admin/dashboard', $h)->assertOk();
    Passport::actingAs(makeMobileTenantStaffUser($a['tenant'], '+237670000780', 'CARRIER_STAFF'));
    $this->getJson('/api/v1/web-experiences/carrier/dashboard', $h)->assertOk();
    $this->getJson('/api/v1/web-experiences/admin/dashboard', $h)->assertStatus(403);
});
