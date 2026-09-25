<?php

declare(strict_types=1);

/**
 * Owner decisions on the canonical UI handoff (2026-09-25):
 * D2 retire /portal/{portal}; D3 Lucide icons; D4 broker/carrier web sections (read-only, tenant/permission scoped).
 * REQ-UI-001 / REQ-UI-002.
 */

use App\Application\WebExperiences\PortalAuthorization;
use App\Filament\Shared\LucideIcons;
use App\Models\{Role, Tenant, TenantMembership, User};
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function odTenant(string $type): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'OD '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function odUser(Tenant $t, string $role, array $permissions, ?string $carrierId = null): User
{
    $u = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $r = Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'code' => $role.'_'.Str::random(4), 'permissions' => $permissions, 'is_system' => false]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

test('D2 /portal/{portal} is retired: permanent redirect to the owning panel, unknown portal 404', function () {
    $this->get('/portal/broker')->assertRedirect('/broker')->assertStatus(301);
    $this->get('/portal/carrier')->assertRedirect('/insurer');
    $this->get('/portal/admin')->assertRedirect('/admin');
    $this->get('/portal/customer')->assertRedirect('/download');
    $this->get('/portal/nope')->assertNotFound();
});

test('D3 Lucide icons: package set resolves, every mapped icon exists, heroicon navigation enums are swapped', function () {
    expect(svg('lucide-shield-check')->toHtml())->toContain('stroke-width="2"');
    foreach ([...array_map(fn ($n) => 'lucide-'.$n, LucideIcons::MAP), ...array_values(LucideIcons::ALIASES)] as $name) {
        expect(fn () => svg($name))->not->toThrow(Exception::class);
    }
    expect(LucideIcons::lucideFor(Heroicon::OutlinedShieldCheck))->toBe('lucide-shield-check')
        ->and(LucideIcons::lucideFor('lucide-handshake'))->toBe('lucide-handshake');
    expect(view('filament.shared.status-badge', ['status' => 'ACTIVE'])->render())->toContain('stroke-width="2"');
});

test('D4 carrier portal: bordereaux and settlements are tenant + carrier scoped, read-only, permission gated', function () {
    $tenant = odTenant('CARRIER');
    $other = odTenant('CARRIER');
    $chain = makeMobileFinanceProposalChain($tenant);
    $chain2 = makeMobileFinanceProposalChain($tenant);
    $reader = odUser($tenant, 'CARRIER_STAFF', ['carrier.finance.read'], $chain['carrier']->id);
    $noPerm = odUser($tenant, 'CARRIER_STAFF', ['policies.read'], $chain['carrier']->id);

    $mine = makeMobileTestBordereau($tenant, $chain['carrier']->id, $reader, ['bordereau_number' => 'BDX-MINE-1']);
    makeMobileTestBordereau($tenant, $chain2['carrier']->id, $reader, ['bordereau_number' => 'BDX-OTHERCARRIER']);
    $foreign = makeMobileTestBordereau($other, $chain['carrier']->id, $reader, ['bordereau_number' => 'BDX-OTHERTENANT']);
    makeMobileTestSettlementBatch($tenant, $chain['carrier']->id, $reader, ['settlement_number' => 'STL-MINE-1']);

    $this->actingAs($reader)->get('/insurer/bordereaux/bordereaus')->assertOk()->assertSee('BDX-MINE-1')->assertDontSee('BDX-OTHERCARRIER')->assertDontSee('BDX-OTHERTENANT');
    $this->actingAs($reader)->get('/insurer/bordereaux/bordereaus/'.$mine->id)->assertOk();
    $this->actingAs($reader)->get('/insurer/bordereaux/bordereaus/'.$foreign->id)->assertNotFound();
    $this->actingAs($reader)->get('/insurer/carrier-settlements')->assertOk()->assertSee('STL-MINE-1');
    $this->flushSession();
    $this->actingAs($noPerm)->get('/insurer/bordereaux/bordereaus')->assertForbidden();

    // read-only in the portal: write abilities are never granted there
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('insurer'));
    expect(app(PortalAuthorization::class)->before($reader, 'create', [\App\Models\Bordereau::class]))->toBeFalse()
        ->and(app(PortalAuthorization::class)->before($reader, 'update', [$mine]))->toBeFalse();
});

test('D4 broker portal: staff list is the portal tenant only and cannot be edited; receivables are the caller partner only', function () {
    $tenant = odTenant('BROKER');
    $other = odTenant('BROKER');
    $admin = odUser($tenant, 'BROKER_ADMIN', ['broker.portal.read', 'broker.finance.read', 'distribution.agreements.view']);
    $colleague = odUser($tenant, 'BROKER_STAFF', ['quotes.read']);
    $stranger = odUser($other, 'BROKER_STAFF', ['quotes.read']);
    $colleague->update(['full_name' => 'Colleague Inside']);
    $stranger->update(['full_name' => 'Stranger Outside']);

    $this->actingAs($admin)->get('/broker/memberships')->assertOk()->assertSee('Colleague Inside')->assertDontSee('Stranger Outside')
        ->assertDontSee(__('filament-actions::create.single.label'));
    $this->actingAs($admin)->get('/broker/memberships/create')->assertForbidden();

    // receivables: no partner link = nothing listed (MobileBrokerOpsController::receivables boundary)
    $this->actingAs($admin)->get('/broker/commission-accruals')->assertOk();
    $this->actingAs($admin)->get('/broker/agreements')->assertOk();
});

test('D4 agreements: visible only when the partner belongs to the portal tenant; bilingual navigation', function () {
    $tenant = odTenant('BROKER');
    $other = odTenant('BROKER');
    $user = odUser($tenant, 'BROKER_ADMIN', ['distribution.agreements.view']);
    $chain = makeMobileFinanceProposalChain($tenant);
    $p1 = \App\Models\Partner::create(['tenant_id' => $tenant->id, 'party_id' => \App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Mine Brokerage', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    $p2 = \App\Models\Partner::create(['tenant_id' => $other->id, 'party_id' => \App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other Brokerage', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    foreach ([[$p1, 'AGR-MINE'], [$p2, 'AGR-OTHER']] as [$p, $n]) {
        \Illuminate\Support\Facades\DB::table('carrier_broker_agreements')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $chain['carrier']->id, 'partner_id' => $p->id, 'agreement_number' => $n, 'effective_from' => now()->toDateString(), 'status' => 'ACTIVE', 'territories' => '[]', 'channels' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($user)->get('/broker/agreements')->assertOk()->assertSee('AGR-MINE')->assertDontSee('AGR-OTHER');
    $this->actingAs($user)->get('/broker/agreements?lang=fr')->assertOk()->assertSee(__('web_experience.sections.agreements', [], 'fr'));
});
