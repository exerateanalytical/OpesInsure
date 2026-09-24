<?php

declare(strict_types=1);

use App\Application\Payments\Adapters\FakePaymentAdapter;
use App\Application\Payments\Adapters\MtnMomoAdapter;
use App\Application\Payments\Adapters\PaymentAdapterRegistry;
use App\Models\Policy;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const DEMO_PERSONA_PHONE = '+237600000100'; // DemoMobileAccountSeeder 'Demo Customer'

/** A PAYMENT_PENDING proposal with a 60-second-old pending payment, plus a carrier approver. */
function demoSettlementWorld(string $phone): array
{
    $f = makeMobileCustomerFixture($phone);
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['total_minor' => 100000, 'currency' => 'XAF']]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'payer_phone_e164' => $phone]);
    // Stored as app-timezone wall clock, matching how the settler reads it back.
    $f['payment']->forceFill(['created_at' => now()->subSeconds(60)])->saveQuietly();
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+237699999999', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $f['tenant']->id, 'user_id' => $approver->id, 'role_code' => 'CARRIER_STAFF', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::firstOrCreate(['tenant_id' => $f['tenant']->id, 'code' => 'CARRIER_STAFF'], ['permissions' => ['*'], 'is_system' => true])->id);

    return $f;
}

it('settles a demo persona\'s payment when the app polls GET /payments/{id} (A5)', function () {
    config(['demo.enabled' => true]);
    $f = demoSettlementWorld(DEMO_PERSONA_PHONE);
    Passport::actingAs($f['user']);

    $res = $this->getJson("/api/v1/payments/{$f['payment']->id}", tenantHeaderFor($f['tenant']))->assertOk();

    expect($res->json('data.status'))->toBe('SUCCEEDED');
});

it('never settles a real user\'s payment, even in demo mode (A2)', function () {
    config(['demo.enabled' => true]);
    $f = demoSettlementWorld('+237677123456');
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    expect($this->getJson("/api/v1/payments/{$f['payment']->id}", $h)->assertOk()->json('data.status'))->toBe('PENDING_CUSTOMER');
    $this->getJson("/api/v1/mobile/purchases/{$f['proposal']->id}/status", $h)->assertOk();

    expect($f['payment']->fresh()->status)->toBe('PENDING_CUSTOMER');
    expect(Policy::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse();
});

it('does nothing when demo mode is off, even for a persona phone', function () {
    config(['demo.enabled' => false]);
    $f = demoSettlementWorld(DEMO_PERSONA_PHONE);
    Passport::actingAs($f['user']);

    $this->getJson("/api/v1/payments/{$f['payment']->id}", tenantHeaderFor($f['tenant']))->assertOk();
    expect($f['payment']->fresh()->status)->toBe('PENDING_CUSTOMER');
});

it('hands the fake adapter only to demo personas', function () {
    config(['demo.enabled' => true]);
    $registry = new PaymentAdapterRegistry;

    expect($registry->for('mtn_momo', true))->toBeInstanceOf(FakePaymentAdapter::class);
    expect($registry->for('mtn_momo', false))->toBeInstanceOf(MtnMomoAdapter::class);

    app()['env'] = 'production';
    expect(fn () => $registry->for('fake', false))->toThrow(InvalidArgumentException::class);
    expect($registry->for('fake', true))->toBeInstanceOf(FakePaymentAdapter::class);
});

it('gives a real user a clear 422 at initiate when no live provider is configured, even in demo mode', function () {
    config(['demo.enabled' => true]);
    $f = demoSettlementWorld('+237677123457');
    $f['payment']->update(['provider' => 'mtn_momo', 'status' => 'CREATED']);
    Passport::actingAs($f['user']);

    $this->postJson("/api/v1/payments/{$f['payment']->id}/initiate", [], tenantHeaderFor($f['tenant']))
        ->assertStatus(422)->assertJsonValidationErrors('provider');
    expect($f['payment']->fresh()->status)->toBe('CREATED');
});

it('lets a demo persona initiate through the fake adapter in demo mode', function () {
    config(['demo.enabled' => true]);
    $f = demoSettlementWorld(DEMO_PERSONA_PHONE);
    $f['payment']->update(['provider' => 'mtn_momo', 'status' => 'CREATED']);
    Passport::actingAs($f['user']);

    $this->postJson("/api/v1/payments/{$f['payment']->id}/initiate", [], tenantHeaderFor($f['tenant']))->assertStatus(202);
});

it('rejects provider=fake from a real user outside local/testing', function () {
    config(['demo.enabled' => true]);
    $f = demoSettlementWorld('+237677123458');
    Passport::actingAs($f['user']);
    app()['env'] = 'production';

    $this->postJson('/api/v1/payments', ['proposal_id' => $f['proposal']->id, 'provider' => 'fake', 'payer_phone_e164' => '+237677123458', 'idempotency_key' => str_repeat('z', 20)], tenantHeaderFor($f['tenant']))
        ->assertStatus(422)->assertJsonValidationErrors('provider');
});
