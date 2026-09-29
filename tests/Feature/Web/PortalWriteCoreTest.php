<?php

declare(strict_types=1);

use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Bordereaux\Pages\ViewBordereau;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Models\{Role, Tenant, TenantMembership, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Owner decision 2026-09-29 (D4 lifted, docs/spec/PORTAL_WRITE_RULES.md): portal writes are allowed when the user holds
 * the API route's permission AND the record is their own organisation's; tenant / carrier isolation stays absolute.
 * Every user holds a governed role carrying exactly the listed permissions (no role-name shortcut).
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function pwTenant(string $type): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'PW '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function pwUser(Tenant $t, string $role, array $permissions, ?string $carrierId = null): User
{
    $u = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'code' => $role.'_'.Str::random(5), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function pwAs(User $u, Tenant $t, string $panel): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($t->id);
    Filament::setCurrentPanel(Filament::getPanel($panel));
}

it('lets an insurer acknowledge its own carrier bordereau in /insurer and refuses another carrier or tenant', function () {
    $tenant = pwTenant('CARRIER');
    $other = pwTenant('CARRIER');
    $mine = makeMobileFinanceProposalChain($tenant);
    $theirs = makeMobileFinanceProposalChain($tenant);
    $preparer = pwUser($tenant, 'CARRIER_STAFF', ['carrier.finance.read'], $mine['carrier']->id);
    $decider = pwUser($tenant, 'CARRIER_ADMIN', ['carrier.finance.read', 'carrier.bordereaux.decide'], $mine['carrier']->id);

    $own = makeMobileTestBordereau($tenant, $mine['carrier']->id, $preparer, ['status' => 'SUBMITTED']);
    $otherCarrier = makeMobileTestBordereau($tenant, $theirs['carrier']->id, $preparer, ['status' => 'SUBMITTED']);
    $otherTenant = makeMobileTestBordereau($other, $mine['carrier']->id, $preparer, ['status' => 'SUBMITTED']);

    pwAs($decider, $tenant, 'insurer');
    Livewire::test(ViewBordereau::class, ['record' => $own->id])
        ->assertActionVisible('bordereauAcknowledge')
        ->callAction('bordereauAcknowledge', ['carrier_reference' => 'CAR-REF-1'])
        ->assertNotified(__('finance_actions.bordereauAcknowledge.done'));
    expect($own->refresh()->status)->toBe('ACKNOWLEDGED');

    // Another carrier's / tenant's record: not own, the write check refuses it and the pages do not open.
    expect(PortalScope::isOwnRecord($otherCarrier))->toBeFalse()
        ->and(PortalScope::isOwnRecord($otherTenant))->toBeFalse()
        ->and(WorkflowAction::allowed('bordereaux.confirm', $otherCarrier))->toBeFalse()
        ->and(WorkflowAction::allowed('bordereaux.confirm', $own))->toBeTrue();
    $this->flushSession();
    expect($this->actingAs($decider)->get('/insurer/bordereaux/'.$otherCarrier->id)->status())->toBeIn([403, 404]);
    $this->actingAs($decider)->get('/insurer/bordereaux/'.$otherTenant->id)->assertNotFound();
    expect($otherCarrier->refresh()->status)->toBe('SUBMITTED')->and($otherTenant->refresh()->status)->toBe('SUBMITTED');
});

it('lets a broker submit its own approved bordereau in /broker and refuses another broker tenant', function () {
    $broker = pwTenant('BROKER');
    $rival = pwTenant('BROKER');
    $chain = makeMobileFinanceProposalChain($broker);
    $preparer = pwUser($broker, 'BROKER_STAFF', ['broker.finance.read', 'broker.bordereaux.manage']);
    $submitter = pwUser($broker, 'BROKER_ADMIN', ['broker.finance.read', 'broker.bordereaux.submit']);
    $rivalUser = pwUser($rival, 'BROKER_ADMIN', ['broker.finance.read', 'broker.bordereaux.submit']);

    $own = makeMobileTestBordereau($broker, $chain['carrier']->id, $preparer, ['status' => 'APPROVED']);
    $foreign = makeMobileTestBordereau($rival, $chain['carrier']->id, $rivalUser, ['status' => 'APPROVED']);

    pwAs($submitter, $broker, 'broker');
    Livewire::test(ViewBordereau::class, ['record' => $own->id])
        ->assertActionVisible('bordereauSubmit')
        ->callAction('bordereauSubmit')
        ->assertNotified(__('finance_actions.bordereauSubmit.done'));
    expect($own->refresh()->status)->toBe('SUBMITTED');

    expect(PortalScope::isOwnRecord($foreign))->toBeFalse()
        ->and(WorkflowAction::allowed('bordereaux.submit', $foreign))->toBeFalse();
    $this->flushSession();
    $this->actingAs($submitter)->get('/broker/bordereaux/'.$foreign->id)->assertNotFound();
    $this->actingAs($submitter)->get('/broker/bordereaux')->assertOk()->assertSee($own->bordereau_number)->assertDontSee($foreign->bordereau_number);
    expect($foreign->refresh()->status)->toBe('APPROVED');

    // A preparer-only user (no submit permission) does not get the submit action on the same own record.
    pwAs($preparer, $broker, 'broker');
    $own->update(['status' => 'APPROVED']);
    Livewire::test(ViewBordereau::class, ['record' => $own->id])->assertActionHidden('bordereauSubmit');
});

// Navigation is built once per application instance, so each user's sidebar is checked in its own test (own request cycle).
it('gives an insurer user without claims permissions no claims navigation and a 403 on the claims pages', function () {
    $tenant = pwTenant('CARRIER');
    $chain = makeMobileFinanceProposalChain($tenant);
    $financeOnly = pwUser($tenant, 'CARRIER_STAFF', ['carrier.finance.read'], $chain['carrier']->id);

    $this->actingAs($financeOnly)->get('/insurer/bordereaux')->assertOk()->assertDontSee('/insurer/claims', false);
    $this->actingAs($financeOnly)->get('/insurer/claims')->assertForbidden();
});

it('gives an insurer user holding claims.view the claims navigation and pages', function () {
    $tenant = pwTenant('CARRIER');
    $chain = makeMobileFinanceProposalChain($tenant);
    $claimsAndFinance = pwUser($tenant, 'CARRIER_STAFF', ['claims.view', 'carrier.finance.read'], $chain['carrier']->id);

    $this->actingAs($claimsAndFinance)->get('/insurer/bordereaux')->assertOk()->assertSee('/insurer/claims', false);
    $this->actingAs($claimsAndFinance)->get('/insurer/claims')->assertOk();
});

it('shows each insurer user only the actions their permissions allow', function () {
    $tenant = pwTenant('CARRIER');
    $chain = makeMobileFinanceProposalChain($tenant);
    $claimsAndFinance = pwUser($tenant, 'CARRIER_STAFF', ['claims.view', 'carrier.finance.read', 'carrier.bordereaux.decide'], $chain['carrier']->id);
    $financeOnly = pwUser($tenant, 'CARRIER_STAFF', ['carrier.finance.read'], $chain['carrier']->id);
    $b = makeMobileTestBordereau($tenant, $chain['carrier']->id, $financeOnly, ['status' => 'SUBMITTED']);

    pwAs($claimsAndFinance, $tenant, 'insurer');
    Livewire::test(ViewBordereau::class, ['record' => $b->id])->assertActionVisible('bordereauAcknowledge');
    pwAs($financeOnly, $tenant, 'insurer');
    Livewire::test(ViewBordereau::class, ['record' => $b->id])->assertActionHidden('bordereauAcknowledge')->assertActionHidden('bordereauReject');
});

it('treats carrier-owned global rows (products, tariffs, carrier) as own only for the caller carrier in /insurer', function () {
    $tenant = pwTenant('CARRIER');
    $mine = makeMobileFinanceProposalChain($tenant);
    $theirs = makeMobileFinanceProposalChain($tenant);
    $user = pwUser($tenant, 'CARRIER_ADMIN', ['carrier.finance.read'], $mine['carrier']->id);
    $ownProduct = \App\Models\InsuranceProduct::where('carrier_id', $mine['carrier']->id)->firstOrFail();
    $otherProduct = \App\Models\InsuranceProduct::where('carrier_id', $theirs['carrier']->id)->firstOrFail();

    pwAs($user, $tenant, 'insurer');
    expect(PortalScope::isOwnRecord($ownProduct))->toBeTrue()
        ->and(PortalScope::isOwnRecord($otherProduct))->toBeFalse()
        ->and(PortalScope::isOwnRecord(\App\Models\TariffVersion::where('insurance_product_id', $ownProduct->id)->firstOrFail()))->toBeTrue()
        ->and(PortalScope::isOwnRecord(\App\Models\TariffVersion::where('insurance_product_id', $otherProduct->id)->firstOrFail()))->toBeFalse()
        ->and(PortalScope::isOwnRecord($mine['carrier']))->toBeTrue()
        ->and(PortalScope::isOwnRecord($theirs['carrier']))->toBeFalse();

    // /broker has no carrier: carrier-owned global rows are never its own.
    $broker = pwTenant('BROKER');
    pwAs(pwUser($broker, 'BROKER_ADMIN', ['broker.finance.read']), $broker, 'broker');
    expect(PortalScope::isOwnRecord($ownProduct))->toBeFalse();
});
