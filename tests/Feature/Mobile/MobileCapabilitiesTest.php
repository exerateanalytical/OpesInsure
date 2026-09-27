<?php

declare(strict_types=1);

/*
 | Mobile audit A1 (ARCH-004/005): GET /mobile/capabilities and allowed_actions on detail resources, derived from the
 | same permission gates and state rules the action endpoints enforce. Plus top-level latitude / longitude on the
 | customer address and the quote risk answers.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{InsuranceLine, Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function capMember(Tenant $t, string $role, array $membership = []): User
{
    $u = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::forceCreate(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE'] + $membership);
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

function capCustomer(): array
{
    $f = makeMobileCustomerFixture('+237671117001');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-CAP-1', 'status' => 'ACTIVE']);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    Passport::actingAs($f['user']);

    return $f + ['policy' => $policy, 'claim' => $claim, 'h' => tenantHeaderFor($f['tenant'])];
}

it('customer capabilities: own-book modules on, staff modules off', function () {
    $c = capCustomer();
    $m = $this->getJson('/api/v1/mobile/capabilities', $c['h'])->assertOk()->json('data.modules');

    expect($m['policies']['view'])->toBeTrue()->and($m['policies']['actions'])->toContain('file_claim', 'renew')
        ->and($m['claims']['actions'])->toContain('create', 'withdraw')->not->toContain('file_for_client')
        ->and($m['payments']['actions'])->toContain('retry', 'refund')
        ->and($m['referrals'])->toBe(['view' => false, 'actions' => []])
        ->and($m['staff']['view'])->toBeFalse()->and($m['settlements']['view'])->toBeFalse();
});

it('carrier roles: modules follow permissions and the carrier scope', function () {
    $t = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'CAP '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $carrier = makeMobileFinanceProposalChain($t)['carrier']->id;
    $h = ['X-Tenant-Id' => $t->id];

    Passport::actingAs(capMember($t, 'FINANCE_OFFICER', ['carrier_id' => $carrier]));
    $m = $this->getJson('/api/v1/mobile/capabilities', $h)->assertOk()->json('data.modules');
    expect($m['settlements']['view'])->toBeTrue()->and($m['referrals']['view'])->toBeFalse()->and($m['claims']['view'])->toBeFalse();

    Passport::actingAs(capMember($t, 'CARRIER_ADMIN', ['carrier_id' => $carrier]));
    $m = $this->getJson('/api/v1/mobile/capabilities', $h)->assertOk()->json('data');
    expect($m['data_scope'])->toBe('CARRIER_RELATIONSHIP')->and($m['modules']['referrals'])->toBe(['view' => true, 'actions' => ['decide']])
        ->and($m['modules']['staff']['actions'])->toBe(['view_security', 'suspend_access', 'force_reauth']);

    // An insurer admin not linked to a carrier in a shared tenant is refused by CarrierScopeResolver: no carrier module.
    Passport::actingAs(capMember($t, 'CARRIER_ADMIN'));
    $m = $this->getJson('/api/v1/mobile/capabilities', $h)->assertOk()->json('data.modules');
    expect($m['referrals']['view'])->toBeFalse()->and($m['settlements']['view'])->toBeFalse();
});

it('claim allowed_actions follow ClaimMachine and match the withdraw endpoint', function () {
    $c = capCustomer();
    $url = "/api/v1/mobile/claims/{$c['claim']->id}";

    expect($this->getJson($url, $c['h'])->assertOk()->json('data.allowed_actions'))->toContain('withdraw', 'add_evidence')->not->toContain('appeal', 'decide_settlement');

    $c['claim']->forceFill(['status' => 'DECLINED'])->save();
    $actions = $this->getJson($url, $c['h'])->json('data.allowed_actions');
    expect($actions)->toContain('appeal')->not->toContain('withdraw');
    $this->postJson("{$url}/withdraw", ['reason' => 'changed my mind please'], $c['h'])->assertStatus(422);
});

it('policy allowed_actions: file_claim only while the FNOL rule accepts the policy', function () {
    $c = capCustomer();
    $url = "/api/v1/mobile/wallet/policies/{$c['policy']->id}";
    expect($this->getJson($url, $c['h'])->assertOk()->json('data.allowed_actions'))->toContain('file_claim', 'request_service');

    $c['policy']->forceFill(['status' => 'CANCELLED'])->save();
    expect($this->getJson($url, $c['h'])->json('data.allowed_actions'))->not->toContain('file_claim');
});

it('payment allowed_actions: retry on FAILED, refund and receipt on SUCCEEDED', function () {
    $c = capCustomer();
    $failed = makeMobileTestPayment($c['proposal'], $c['tenant'], ['status' => 'FAILED']);
    $paid = makeMobileTestPayment($c['proposal'], $c['tenant']);

    expect($this->getJson("/api/v1/mobile/payments/{$failed->id}", $c['h'])->assertOk()->json('data.allowed_actions'))->toBe(['retry'])
        ->and($this->getJson("/api/v1/mobile/payments/{$paid->id}", $c['h'])->json('data.allowed_actions'))->toBe(['refund', 'receipt']);
});

it('quote and proposal allowed_actions come from QuoteService and the proposal machine', function () {
    $c = capCustomer();
    $quote = $this->getJson("/api/v1/quotes/{$c['quote']->id}", $c['h'])->assertOk()->json('data.allowed_actions');
    expect($quote)->toBeArray();
    $c['quote']->forceFill(['lifecycle_state' => 'CANCELLED', 'status' => 'CANCELLED'])->save();
    expect($this->getJson("/api/v1/mobile/quotes/{$c['quote']->id}", $c['h'])->assertOk()->json('data.allowed_actions'))->toBe([]);

    $c['proposal']->forceFill(['status' => 'COUNTEROFFERED'])->save();
    $actions = $this->getJson("/api/v1/proposals/{$c['proposal']->id}", $c['h'])->assertOk()->json('data.allowed_actions');
    expect($actions)->toContain('accept_counteroffer', 'decline_counteroffer', 'withdraw')->not->toContain('decide', 'submit');
});

it('customer profile: latitude / longitude are validated, stored on the address and returned', function () {
    $c = capCustomer();
    $url = '/api/v1/mobile/account/customer-profile';

    $this->patchJson($url, ['latitude' => 95, 'longitude' => 9.7], $c['h'])->assertStatus(422)->assertJsonValidationErrors('latitude');
    $this->patchJson($url, ['latitude' => 4.0511, 'longitude' => -181], $c['h'])->assertStatus(422)->assertJsonValidationErrors('longitude');
    $r = $this->patchJson($url, ['city' => 'Douala', 'latitude' => 4.0511, 'longitude' => 9.7679], $c['h'])->assertOk();
    expect($r->json('data.latitude'))->toBe(4.0511)->and($r->json('data.longitude'))->toBe(9.7679)
        ->and($r->json('data.allowed_actions'))->toContain('update_profile')
        ->and((float) DB::table('party_addresses')->where('party_id', $c['party']->id)->value('latitude'))->toBe(4.0511);
});

it('quote risk answers: top-level latitude / longitude are validated and stored with the risk facts', function () {
    $c = capCustomer();
    $customer = makeMobileTestTenantCustomer($c['tenant'], $c['party']);
    InsuranceLine::firstOrCreate(['code' => 'HOME'], ['name' => ['en' => 'Home'], 'description' => ['en' => 'Home'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $h = $c['h'] + ['Idempotency-Key' => (string) Str::uuid()];
    $body = ['customer_id' => $customer->id, 'line_code' => 'HOME', 'channel' => 'B2C', 'risk_facts' => ['construction' => 'CONCRETE']];

    $this->postJson('/api/v1/quotes', $body + ['latitude' => -91, 'longitude' => 9.7], $h)->assertStatus(422)->assertJsonValidationErrors('latitude');
    $this->postJson('/api/v1/quotes', ['risk_facts' => ['construction' => 'CONCRETE', 'longitude' => 200]] + $body, $h + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
    $q = $this->postJson('/api/v1/quotes', $body + ['latitude' => 4.0511, 'longitude' => 9.7679], $h + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(202)->json('data');
    expect($q['risk_facts']['latitude'])->toBe(4.0511)->and($q['risk_facts']['longitude'])->toBe(9.7679)->and($q['risk_facts']['construction'])->toBe('CONCRETE');
});
