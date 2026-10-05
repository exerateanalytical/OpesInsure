<?php

declare(strict_types=1);

use App\Models\CustomerAttribution;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/*
 | Phase-1 fix S (2026-09-30): the staff lists that stopped at limit(100) page with ?cursor= (meta.next_cursor; the
 | first page and the data shape are unchanged) and the detail screens read one record by id, bounded by the same
 | scope as the list (another insurer's / broker's record is a 404).
 */

function slpCarrierBook(Tenant $tenant, int $count): array
{
    $chain = makeMobileFinanceProposalChain($tenant);
    $policies = [];
    foreach (range(1, $count) as $i) {
        $policies[] = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.Str::random(8), 'issued_at' => now()->subMinutes($i)]);
    }

    return ['chain' => $chain, 'policies' => $policies];
}

it('pages the insurer policy list with a cursor and reads one policy by id inside the carrier', function () {
    $tenant = Tenant::create(['type' => 'BROKER', 'legal_name' => 'SLP '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $mine = slpCarrierBook($tenant, 3);
    $theirs = slpCarrierBook($tenant, 1);
    $staff = makeMobileTenantStaffUser($tenant, '+237670032001', 'CARRIER_STAFF');
    DB::table('tenant_memberships')->where('user_id', $staff->id)->update(['carrier_id' => $mine['chain']['carrier']->id]);
    Passport::actingAs($staff);
    $h = tenantHeaderFor($tenant);

    // Backward compatible first page: same array, meta.next_cursor null when everything fits.
    $all = $this->getJson('/api/v1/mobile/partner/carrier/policies', $h)->assertOk();
    expect($all->json('data'))->toHaveCount(3)->and($all->json('meta.next_cursor'))->toBeNull();

    $p1 = $this->getJson('/api/v1/mobile/partner/carrier/policies?limit=2', $h)->assertOk();
    $p2 = $this->getJson('/api/v1/mobile/partner/carrier/policies?limit=2&cursor='.$p1->json('meta.next_cursor'), $h)->assertOk();
    $ids = collect($p1->json('data'))->merge($p2->json('data'))->pluck('id');
    expect($ids->unique()->count())->toBe(3)->and($p2->json('meta.next_cursor'))->toBeNull()
        ->and($ids->all())->not->toContain($theirs['policies'][0]->id);

    $this->getJson('/api/v1/mobile/partner/carrier/policies/'.$mine['policies'][0]->id, $h)->assertOk()->assertJsonPath('data.id', $mine['policies'][0]->id);
    $this->getJson('/api/v1/mobile/partner/carrier/policies/'.$theirs['policies'][0]->id, $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/carrier/proposals/'.$mine['chain']['proposal']->id, $h)->assertOk()->assertJsonPath('data.id', $mine['chain']['proposal']->id);
    $this->getJson('/api/v1/mobile/partner/carrier/proposals/'.$theirs['chain']['proposal']->id, $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/carrier/payments/'.Str::uuid(), $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/carrier/payments', $h)->assertOk()->assertJsonStructure(['data' => ['summary', 'items'], 'meta' => ['next_cursor']]);
    $product = App\Models\InsuranceProduct::where('carrier_id', $mine['chain']['carrier']->id)->value('id');
    $this->getJson('/api/v1/mobile/partner/carrier/products/'.$product, $h)->assertOk()->assertJsonPath('data.id', $product);
    $this->getJson('/api/v1/mobile/partner/carrier/partners/'.Str::uuid(), $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/carrier/policies?cursor=not-a-cursor', $h)->assertStatus(422);
});

it('pages the insurer claims queue', function () {
    $tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'SLP C '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $book = slpCarrierBook($tenant, 3);
    foreach ($book['policies'] as $p) {
        makeMobileTestClaim($tenant, $p, $book['chain']['party']);
    }
    Passport::actingAs(makeMobileTenantStaffUser($tenant, '+237670032011', 'CARRIER_ADMIN'));
    $h = tenantHeaderFor($tenant);

    $p1 = $this->getJson('/api/v1/mobile/carrier/claims?limit=2', $h)->assertOk();
    expect($p1->json('data'))->toHaveCount(2)->and($p1->json('meta.next_cursor'))->not->toBeNull();
    $p2 = $this->getJson('/api/v1/mobile/carrier/claims?limit=2&cursor='.$p1->json('meta.next_cursor'), $h)->assertOk();
    expect($p2->json('data'))->toHaveCount(1);
});

it('pages the broker book and reads policies, claims and production items by id inside the book', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670032100');
    $tenant = $broker['tenant'];
    $rival = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rival', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    $made = [];
    foreach (['mine' => $broker['partner'], 'mine2' => $broker['partner'], 'theirs' => $rival] as $key => $partner) {
        $chain = makeMobileFinanceProposalChain($tenant);
        makeMobileTestTenantCustomer($tenant, $chain['party']);
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $broker['user']->id]);
        $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.strtoupper($key)]);
        $made[$key] = ['policy' => $policy, 'claim' => makeMobileTestClaim($tenant, $policy, $chain['party'])];
    }
    Passport::actingAs($broker['user']);
    $h = tenantHeaderFor($tenant);

    $p1 = $this->getJson('/api/v1/mobile/partner/broker/policies?limit=1', $h)->assertOk();
    $p2 = $this->getJson('/api/v1/mobile/partner/broker/policies?limit=1&cursor='.$p1->json('meta.next_cursor'), $h)->assertOk();
    expect(collect([$p1->json('data.0.policy_number'), $p2->json('data.0.policy_number')])->sort()->values()->all())->toBe(['POL-MINE', 'POL-MINE2'])
        ->and($p2->json('meta.next_cursor'))->toBeNull();

    $this->getJson('/api/v1/mobile/partner/broker/policies/'.$made['mine']['policy']->id, $h)->assertOk()->assertJsonPath('data.policy_number', 'POL-MINE');
    $this->getJson('/api/v1/mobile/partner/broker/policies/'.$made['theirs']['policy']->id, $h)->assertNotFound();
    $this->getJson('/api/v1/mobile/partner/broker/claims/'.$made['mine']['claim']->id, $h)->assertOk()->assertJsonPath('data.id', $made['mine']['claim']->id);
    $this->getJson('/api/v1/mobile/partner/broker/claims/'.$made['theirs']['claim']->id, $h)->assertNotFound();
    expect($this->getJson('/api/v1/mobile/partner/broker/claims?limit=1', $h)->assertOk()->json('meta.next_cursor'))->not->toBeNull();

    $this->getJson('/api/v1/mobile/broker/production/'.$made['mine']['policy']->id, $h)->assertOk()->assertJsonPath('data.policy_number', 'POL-MINE');
    $this->getJson('/api/v1/mobile/broker/production/'.$made['theirs']['policy']->id, $h)->assertNotFound();
    expect($this->getJson('/api/v1/mobile/broker/production?limit=1', $h)->assertOk()->json('meta.next_cursor'))->not->toBeNull();
    $this->getJson('/api/v1/mobile/broker/compliance/'.Str::uuid(), $h)->assertNotFound();
});
