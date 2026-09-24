<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\TenantMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/Concerns/mobile_auth_helpers.php';

// --- Broker/agent side: commission accruals & statements, partner-scoped ---

it('shows the broker dashboard scoped to the caller\'s own Partner, not a tenant-mate\'s', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670000210');
    $chain = makeMobileFinanceProposalChain($broker['tenant']);
    $policy = makeMobileTestPolicy($chain['proposal'], $broker['tenant'], $chain['carrier']->id, $chain['party']->id);
    makeMobileTestCommissionAccrual($broker['tenant'], $broker['partner'], $policy, ['vested_minor' => 4000, 'paid_minor' => 1000, 'currency' => 'XAF']);
    makeMobileTestPartnerStatement($broker['tenant'], $broker['partner'], $broker['user'], ['status' => 'PUBLISHED', 'published_at' => now()]);

    // A second Partner in the SAME tenant — must not leak into the first partner's dashboard.
    $otherParty = App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other Broker Partner', 'status' => 'ACTIVE']);
    $otherPartner = App\Models\Partner::create(['tenant_id' => $broker['tenant']->id, 'party_id' => $otherParty->id, 'type' => 'AGENT', 'status' => 'ACTIVE']);
    makeMobileTestCommissionAccrual($broker['tenant'], $otherPartner, $policy, ['amount_minor' => 999999]);

    Passport::actingAs($broker['user']);
    $response = $this->getJson('/api/v1/mobile/broker/dashboard', tenantHeaderFor($broker['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.partner.id'))->toBe($broker['partner']->id);
    expect($response->json('data.commission.0.currency'))->toBe('XAF');
    expect($response->json('data.commission.0.vested_minor'))->toBe(4000);
    expect($response->json('data.commission.0.outstanding_minor'))->toBe(3000);
    expect($response->json('data.latest_statement.status'))->toBe('PUBLISHED');
});

it('lists only the caller partner\'s own commission accruals, paginated under data.data', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670000211');
    $chain = makeMobileFinanceProposalChain($broker['tenant']);
    $policy = makeMobileTestPolicy($chain['proposal'], $broker['tenant'], $chain['carrier']->id, $chain['party']->id);
    $mine = makeMobileTestCommissionAccrual($broker['tenant'], $broker['partner'], $policy);

    $otherParty = App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other Partner', 'status' => 'ACTIVE']);
    $otherPartner = App\Models\Partner::create(['tenant_id' => $broker['tenant']->id, 'party_id' => $otherParty->id, 'type' => 'AGENT', 'status' => 'ACTIVE']);
    makeMobileTestCommissionAccrual($broker['tenant'], $otherPartner, $policy);

    Passport::actingAs($broker['user']);
    $response = $this->getJson('/api/v1/mobile/broker/commission-accruals', tenantHeaderFor($broker['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.data'))->toHaveCount(1);
    expect($response->json('data.data.0.id'))->toBe($mine->id);
});

it('computes broker receivables straight from the commission ledger, per currency', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670000212');
    $chain = makeMobileFinanceProposalChain($broker['tenant']);
    $policy = makeMobileTestPolicy($chain['proposal'], $broker['tenant'], $chain['carrier']->id, $chain['party']->id);
    makeMobileTestCommissionAccrual($broker['tenant'], $broker['partner'], $policy, ['amount_minor' => 10000, 'clawed_back_minor' => 1000, 'vested_minor' => 8000, 'paid_minor' => 2000, 'currency' => 'XAF']);

    Passport::actingAs($broker['user']);
    $response = $this->getJson('/api/v1/mobile/broker/receivables', tenantHeaderFor($broker['tenant']));

    $response->assertStatus(200);
    // Strict: aggregates must come back as JSON numbers, not the strings
    // Postgres SUM()/PDO would otherwise hand back for a money field.
    // App-shaped (one row per outstanding accrual): amount = amount - paid - clawed back.
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.currency'))->toBe('XAF');
    expect($response->json('data.0.amount_minor'))->toBe(7000);
});

it('404s a nonexistent statement and 403s a tenant-mate\'s statement, but shows the caller\'s own with items', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670000213');
    $statement = makeMobileTestPartnerStatement($broker['tenant'], $broker['partner'], $broker['user']);
    App\Models\PartnerStatementItem::create(['partner_statement_id' => $statement->id, 'entry_type' => 'COMMISSION', 'reference_type' => 'commission_accrual', 'reference_id' => (string) Illuminate\Support\Str::uuid(), 'amount_minor' => 500, 'currency' => 'XAF', 'occurred_at' => now(), 'metadata' => []]);

    $otherParty = App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other Partner', 'status' => 'ACTIVE']);
    $otherPartner = App\Models\Partner::create(['tenant_id' => $broker['tenant']->id, 'party_id' => $otherParty->id, 'type' => 'AGENT', 'status' => 'ACTIVE']);
    $theirs = makeMobileTestPartnerStatement($broker['tenant'], $otherPartner, $broker['user']);

    Passport::actingAs($broker['user']);

    $this->getJson('/api/v1/mobile/broker/statements/'.Illuminate\Support\Str::uuid(), tenantHeaderFor($broker['tenant']))->assertStatus(404);
    $this->getJson("/api/v1/mobile/broker/statements/{$theirs->id}", tenantHeaderFor($broker['tenant']))->assertStatus(403);

    $response = $this->getJson("/api/v1/mobile/broker/statements/{$statement->id}", tenantHeaderFor($broker['tenant']));
    $response->assertStatus(200);
    expect($response->json('data.items'))->toHaveCount(1);
});

it('gives a broker-tenant staff member with no personal Partner link empty finance data, not an error', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670000214');
    $staff = makeMobileTenantStaffUser($broker['tenant'], '+237670000215', 'BROKER_STAFF');

    Passport::actingAs($staff);
    $response = $this->getJson('/api/v1/mobile/broker/dashboard', tenantHeaderFor($broker['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.partner'))->toBeNull();
    expect($response->json('data.commission'))->toBe([]);
});

// --- Carrier side: settlements & bordereaux, tenant-scoped ---

it('scopes carrier settlements and bordereaux strictly to the caller\'s own tenant', function () {
    $carrierA = makeMobilePartnerFixture('CARRIER', '+237670000220');
    $carrierB = makeMobilePartnerFixture('CARRIER', '+237670000221');
    $chainA = makeMobileFinanceProposalChain($carrierA['tenant']);

    $settlementA = makeMobileTestSettlementBatch($carrierA['tenant'], $chainA['carrier']->id, $carrierA['user'], ['status' => 'APPROVED', 'net_amount_minor' => 70000]);
    $bordereauA = makeMobileTestBordereau($carrierA['tenant'], $chainA['carrier']->id, $carrierA['user'], ['status' => 'SUBMITTED']);

    $chainB = makeMobileFinanceProposalChain($carrierB['tenant']);
    makeMobileTestSettlementBatch($carrierB['tenant'], $chainB['carrier']->id, $carrierB['user']);
    makeMobileTestBordereau($carrierB['tenant'], $chainB['carrier']->id, $carrierB['user']);

    Passport::actingAs($carrierA['user']);

    $dashboard = $this->getJson('/api/v1/mobile/carrier/dashboard', tenantHeaderFor($carrierA['tenant']));
    $dashboard->assertStatus(200);
    expect($dashboard->json('data.settlements_status_counts'))->toBe(['APPROVED' => 1]);
    expect($dashboard->json('data.bordereaux_status_counts'))->toBe(['SUBMITTED' => 1]);
    expect($dashboard->json('data.settlements_net_by_currency.0'))->toBe(['currency' => 'XAF', 'status' => 'APPROVED', 'net_amount_minor' => 70000]);

    $settlements = $this->getJson('/api/v1/mobile/carrier/settlements', tenantHeaderFor($carrierA['tenant']));
    // App-shaped list (MobileCarrierOpsController::settlements), not paginated.
    expect($settlements->json('data'))->toHaveCount(1);
    expect($settlements->json('data.0.id'))->toBe($settlementA->id);

    $bordereaux = $this->getJson('/api/v1/mobile/carrier/bordereaux', tenantHeaderFor($carrierA['tenant']));
    expect($bordereaux->json('data.data'))->toHaveCount(1);
    expect($bordereaux->json('data.data.0.id'))->toBe($bordereauA->id);

    // Cross-tenant show: exists, but not in this tenant -> 403, not a data leak.
    $foreignSettlementId = App\Models\SettlementBatch::where('tenant_id', $carrierB['tenant']->id)->value('id');
    $this->getJson("/api/v1/mobile/carrier/settlements/{$foreignSettlementId}", tenantHeaderFor($carrierA['tenant']))->assertStatus(403);

    $foreignBordereauId = App\Models\Bordereau::where('tenant_id', $carrierB['tenant']->id)->value('id');
    $this->getJson("/api/v1/mobile/carrier/bordereaux/{$foreignBordereauId}", tenantHeaderFor($carrierA['tenant']))->assertStatus(403);
});

it('shows a settlement with its items loaded', function () {
    $carrier = makeMobilePartnerFixture('CARRIER', '+237670000222');
    $chain = makeMobileFinanceProposalChain($carrier['tenant']);
    $policy = makeMobileTestPolicy($chain['proposal'], $carrier['tenant'], $chain['carrier']->id, $chain['party']->id);
    $payment = makeMobileTestPayment($chain['proposal'], $carrier['tenant']);
    $settlement = makeMobileTestSettlementBatch($carrier['tenant'], $chain['carrier']->id, $carrier['user']);
    App\Models\SettlementItem::create(['settlement_batch_id' => $settlement->id, 'policy_id' => $policy->id, 'payment_intent_id' => $payment->id, 'gross_premium_minor' => 10000, 'commission_minor' => 1000, 'net_due_minor' => 9000, 'currency' => 'XAF', 'status' => 'INCLUDED']);

    Passport::actingAs($carrier['user']);
    $response = $this->getJson("/api/v1/mobile/carrier/settlements/{$settlement->id}", tenantHeaderFor($carrier['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.items'))->toHaveCount(1);
    expect($response->json('data.items.0.net_due_minor'))->toBe(9000);
});

// --- Security fix: CarrierOperationsController::acknowledgeBordereau tenant isolation ---

it('no longer lets a carrier user acknowledge another tenant\'s bordereau (cross-tenant IDOR fix)', function () {
    $tenantA = makeMobilePartnerFixture('CARRIER', '+237670000230');
    $tenantB = makeMobilePartnerFixture('CARRIER', '+237670000231');
    $chainB = makeMobileFinanceProposalChain($tenantB['tenant']);
    $foreignBordereau = makeMobileTestBordereau($tenantB['tenant'], $chainB['carrier']->id, $tenantB['user'], ['status' => 'SUBMITTED']);

    $role = Role::create(['tenant_id' => $tenantA['tenant']->id, 'code' => 'CARRIER_DECIDER', 'permissions' => ['carrier.bordereaux.decide'], 'is_system' => false]);
    $membership = TenantMembership::where('tenant_id', $tenantA['tenant']->id)->where('user_id', $tenantA['user']->id)->firstOrFail();
    $membership->roles()->attach($role->id);

    Passport::actingAs($tenantA['user']);

    // Tenant A holds the right permission but the bordereau belongs to tenant B — must be rejected, not silently acknowledged.
    $response = $this->postJson("/api/v1/carrier/bordereaux/{$foreignBordereau->id}/decision", [
        'carrier_reference' => 'CARRIER-REF-1', 'decision' => 'ACKNOWLEDGED', 'notes' => 'Attempting cross-tenant acknowledgement of another tenant\'s bordereau.',
    ], tenantHeaderFor($tenantA['tenant']));

    $response->assertStatus(409);
    expect($foreignBordereau->refresh()->status)->toBe('SUBMITTED');
});

it('still lets a carrier user acknowledge their own tenant\'s submitted bordereau', function () {
    $tenant = makeMobilePartnerFixture('CARRIER', '+237670000232');
    $chain = makeMobileFinanceProposalChain($tenant['tenant']);
    $bordereau = makeMobileTestBordereau($tenant['tenant'], $chain['carrier']->id, $tenant['user'], ['status' => 'SUBMITTED']);

    $role = Role::create(['tenant_id' => $tenant['tenant']->id, 'code' => 'CARRIER_DECIDER', 'permissions' => ['carrier.bordereaux.decide'], 'is_system' => false]);
    $membership = TenantMembership::where('tenant_id', $tenant['tenant']->id)->where('user_id', $tenant['user']->id)->firstOrFail();
    $membership->roles()->attach($role->id);

    Passport::actingAs($tenant['user']);

    $response = $this->postJson("/api/v1/carrier/bordereaux/{$bordereau->id}/decision", [
        'carrier_reference' => 'CARRIER-REF-2', 'decision' => 'ACKNOWLEDGED', 'notes' => 'Acknowledging our own tenant\'s bordereau after carrier confirmation.',
    ], tenantHeaderFor($tenant['tenant']));

    $response->assertStatus(200);
    expect($bordereau->refresh()->status)->toBe('ACKNOWLEDGED');
});
