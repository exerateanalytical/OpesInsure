<?php

declare(strict_types=1);

use App\Models\CustomerAttribution;
use App\Models\InsuranceLine;
use App\Models\Partner;
use App\Models\Party;
use App\Models\TenantCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/*
 | Owner decision: "Partners should quote for their own clients and new clients."
 | Agents / broker staff may only quote (and continue the quote → offer → proposal → payment flow)
 | for parties origin-locked to their own Partner, or for new clients they onboard themselves.
 */

function pbHeaders($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

function pbAttribute(Party $party, Partner $partner, $user): void
{
    CustomerAttribution::create(['party_id' => $party->id, 'partner_id' => $partner->id, 'origin_type' => $partner->type, 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $user->id]);
}

/** A customer (with quote/offer/proposal) plus two agents and a broker in the same tenant. */
function pbWorld(): array
{
    $c = makeMobileCustomerFixture('+237670009100');
    $tenant = $c['tenant'];
    $c['customer'] = makeMobileTestTenantCustomer($tenant, $c['party']);
    $c['offer'] = $c['quote']->offers()->first();
    InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => ['en' => 'Motor'], 'description' => ['en' => 'Motor'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $agent = makeMobileAgentFixtureInTenant($tenant, '+237680009101');
    $rival = makeMobileAgentFixtureInTenant($tenant, '+237680009102');

    return ['c' => $c, 'tenant' => $tenant, 'agent' => $agent, 'rival' => $rival];
}

function pbQuote($test, $tenant, string $customerId, string $channel = 'AGENT')
{
    return $test->postJson('/api/v1/quotes', ['customer_id' => $customerId, 'line_code' => 'MOTOR', 'channel' => $channel, 'risk_facts' => ['make' => 'Toyota', 'model' => 'Corolla']], pbHeaders($tenant));
}

it('lets an agent quote for a client in their own book', function () {
    $w = pbWorld();
    pbAttribute($w['c']['party'], $w['agent']['partner'], $w['agent']['user']);
    Passport::actingAs($w['agent']['user']);

    pbQuote($this, $w['tenant'], $w['c']['customer']->id)->assertStatus(202)->assertJsonPath('data.party_id', $w['c']['party']->id);
});

it('403s an agent quoting for another agent\'s client or an unattributed party', function () {
    $w = pbWorld();
    Passport::actingAs($w['agent']['user']);

    // Unattributed.
    pbQuote($this, $w['tenant'], $w['c']['customer']->id)->assertStatus(403)->assertJsonPath('message', __('quotes.partner_outside_book'));

    // Someone else's book.
    pbAttribute($w['c']['party'], $w['rival']['partner'], $w['rival']['user']);
    pbQuote($this, $w['tenant'], $w['c']['customer']->id)->assertStatus(403);
    expect(App\Models\Quote::where('party_id', $w['c']['party']->id)->count())->toBe(1); // only the fixture's
});

it('lets an agent onboard a new client and quote for them straight away', function () {
    $w = pbWorld();
    Passport::actingAs($w['agent']['user']);

    $id = $this->postJson('/api/v1/mobile/agent/clients', agentClientIntakePayload(['phone_e164' => '+237671119901', 'display_name' => 'Fresh Client']), pbHeaders($w['tenant']))
        ->assertStatus(201)->json('data.id');

    pbQuote($this, $w['tenant'], $id)->assertStatus(202);
    // The rival agent still cannot quote for them.
    Passport::actingAs($w['rival']['user']);
    pbQuote($this, $w['tenant'], $id)->assertStatus(403);
});

it('scopes broker staff to their broker\'s clients and lets them onboard new ones', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670009200');
    $tenant = $broker['tenant'];
    InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => ['en' => 'Motor'], 'description' => ['en' => 'Motor'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $other = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rival Broker', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    $mine = makeMobileFinanceProposalChain($tenant);
    $theirs = makeMobileFinanceProposalChain($tenant);
    pbAttribute($mine['party'], $broker['partner'], $broker['user']);
    pbAttribute($theirs['party'], $other, $broker['user']);
    $mineC = makeMobileTestTenantCustomer($tenant, $mine['party']);
    $theirsC = makeMobileTestTenantCustomer($tenant, $theirs['party']);
    Passport::actingAs($broker['user']);

    pbQuote($this, $tenant, $mineC->id, 'BROKER')->assertStatus(202);
    pbQuote($this, $tenant, $theirsC->id, 'BROKER')->assertStatus(403);

    $res = $this->postJson('/api/v1/mobile/broker/clients', ['full_name' => 'Broker New Client', 'phone_e164' => '671119902', 'city' => 'Yaoundé', 'consent_reference' => 'WEB-1'], pbHeaders($tenant))->assertStatus(201);
    $customer = TenantCustomer::findOrFail($res->json('data.id'));
    $attribution = CustomerAttribution::where('party_id', $customer->party_id)->where('status', 'ACTIVE')->firstOrFail();
    expect($attribution->partner_id)->toBe($broker['partner']->id)->and($attribution->origin_type)->toBe('BROKER');
    expect(collect($this->getJson('/api/v1/mobile/broker/clients', pbHeaders($tenant))->json('data'))->pluck('id'))->toContain($customer->id);

    pbQuote($this, $tenant, $customer->id, 'BROKER')->assertStatus(202);
});

it('blocks the follow-on steps (accept, proposal, payment) for a party outside the partner\'s book', function () {
    $w = pbWorld();
    $c = $w['c'];
    Passport::actingAs($w['agent']['user']);
    $h = pbHeaders($w['tenant']);

    $this->postJson("/api/v1/quotes/{$c['quote']->id}/offers/{$c['offer']->id}/accept", [], $h)->assertStatus(403);
    $this->postJson('/api/v1/proposals', ['quote_offer_id' => $c['offer']->id, 'party_id' => $c['party']->id], $h)->assertStatus(403);
    $this->postJson("/api/v1/proposals/{$c['proposal']->id}/submit", [], $h)->assertStatus(403);
    $this->postJson("/api/v1/proposals/{$c['proposal']->id}/disclosures/attest", [], $h)->assertStatus(403);
    $this->postJson('/api/v1/payments', ['proposal_id' => $c['proposal']->id, 'provider' => 'fake', 'payer_phone_e164' => '+237670009100', 'idempotency_key' => (string) Str::uuid()], $h)->assertStatus(403);
    $this->postJson("/api/v1/quotes/{$c['quote']->id}/cancel", ['reason' => 'x'], $h)->assertStatus(403);

    // Once the client is in the agent's book the same calls are no longer refused by the book check.
    pbAttribute($c['party'], $w['agent']['partner'], $w['agent']['user']);
    expect($this->postJson("/api/v1/quotes/{$c['quote']->id}/offers/{$c['offer']->id}/accept", [], pbHeaders($w['tenant']))->status())->not->toBe(403);
    expect($this->postJson('/api/v1/proposals', ['quote_offer_id' => $c['offer']->id, 'party_id' => $c['party']->id], pbHeaders($w['tenant']))->status())->not->toBe(403);
});

it('leaves back-office staff quoting unchanged', function () {
    $w = pbWorld();
    $staff = makeMobileTenantStaffUser($w['tenant'], '+237670009300', 'BRANCH_MANAGER');
    Passport::actingAs($staff);

    pbQuote($this, $w['tenant'], $w['c']['customer']->id, 'B2C')->assertStatus(202);
});

it('treats a partner who also holds a staff role as staff', function () {
    $w = pbWorld();
    App\Models\TenantMembership::create(['tenant_id' => $w['tenant']->id, 'user_id' => $w['agent']['user']->id, 'role_code' => 'BRANCH_MANAGER', 'status' => 'ACTIVE']);
    Passport::actingAs($w['agent']['user']);

    pbQuote($this, $w['tenant'], $w['c']['customer']->id)->assertStatus(202);
});

it('keeps the customer own-party rule', function () {
    $w = pbWorld();
    $other = makeMobileFinanceProposalChain($w['tenant']);
    $otherC = makeMobileTestTenantCustomer($w['tenant'], $other['party']);
    Passport::actingAs($w['c']['user']);

    pbQuote($this, $w['tenant'], $otherC->id, 'B2C')->assertStatus(404);
    pbQuote($this, $w['tenant'], $w['c']['customer']->id, 'B2C')->assertStatus(202);
});
