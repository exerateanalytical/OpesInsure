<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Claims\ClaimResource;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/**
 * The panel tenant middleware is Livewire-persistent (ScopesPanelTenant): a /livewire/update round-trip (widget poll,
 * header action, table filter) runs with the same tenant as the page. Before, the round-trip had no tenant and any
 * tenant-scoped query threw "Tenant context is missing".
 */
function rtSnapshots(string $html): array
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);

    return array_map(fn ($s) => json_decode(html_entity_decode($s, ENT_QUOTES), true), $m[1]);
}

function rtUpdate(array $snapshot)
{
    app(TenantContext::class)->clear();

    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
        '_token' => csrf_token(),
        'components' => [['snapshot' => json_encode($snapshot), 'updates' => new stdClass, 'calls' => []]],
    ]);
}

beforeEach(function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $f['tenant']->id;
    $policy = Policy::create([
        'tenant_id' => $this->tenant, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subMonth(),
    ]);
    $this->claim = Claim::create(['tenant_id' => $this->tenant, 'policy_id' => $policy->id, 'claimant_party_id' => $f['party']->id, 'claim_number' => 'CLM-RT-'.Str::random(6),
        'status' => 'ACKNOWLEDGED', 'loss_occurred_at' => now()->subDays(2), 'loss_details' => [], 'currency' => 'XAF']);
    $u = User::create(['full_name' => 'RT Manager', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $this->tenant, 'user_id' => $u->id, 'role_code' => 'CLAIMS_MANAGER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $this->tenant, 'code' => 'RT-'.Str::random(6), 'permissions' => ['claims.view', 'claims.read', 'claims.assign'], 'is_system' => false])->id);
    $this->actingAs($u);
    app(TenantContext::class)->clear();
});

it('keeps the panel tenant on a Livewire round-trip of the claim view page (header actions work)', function () {
    $page = $this->get(ClaimResource::getUrl('view', ['record' => $this->claim]))->assertOk();
    $snapshot = collect(rtSnapshots($page->getContent()))->first(fn ($s) => str_contains((string) ($s['memo']['name'] ?? ''), 'view-claim'));
    expect($snapshot)->not->toBeNull();

    rtUpdate($snapshot)->assertOk();
    expect(fn () => app(TenantContext::class)->id())->not->toThrow(LogicException::class); // still set for the component call
});

it('refreshes a dashboard widget on a Livewire round-trip with the same tenant', function () {
    $page = $this->get('/admin')->assertOk();
    $widget = collect(rtSnapshots($page->getContent()))->first(fn ($s) => str_contains((string) ($s['memo']['name'] ?? ''), 'recent-activity-widget')
        || str_contains((string) ($s['memo']['name'] ?? ''), 'my-work-widget'));
    expect($widget)->not->toBeNull();

    $res = rtUpdate($widget)->assertOk();
    expect($res->getContent())->not->toContain('data-state=\"ERROR\"');
});
