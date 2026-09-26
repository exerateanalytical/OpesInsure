<?php

declare(strict_types=1);

use App\Models\CustomerAttribution;
use App\Models\Partner;
use App\Models\Party;
use App\Models\TenantInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function w16BrokerHeaders($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/**
 * A broker plus a competing broker in the same tenant, each with one
 * attributed client holding a policy, a quote and a claim.
 */
function w16BrokerBook(): array
{
    $broker = makeMobilePartnerFixture('BROKER', '+237670002000');
    $tenant = $broker['tenant'];
    $other = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rival Broker', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    $book = [];
    foreach (['mine' => $broker['partner'], 'theirs' => $other] as $key => $partner) {
        $chain = makeMobileFinanceProposalChain($tenant);
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $broker['user']->id]);
        $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.strtoupper($key), 'premium_minor' => 100000]);
        $quote = makeMobileTestQuote($tenant, $chain['party'], ['status' => 'OFFERED']);
        $claim = makeMobileTestClaim($tenant, $policy, $chain['party'], ['claim_number' => 'CLM-'.strtoupper($key)]);
        $book[$key] = compact('chain', 'policy', 'quote', 'claim', 'partner');
    }

    return ['broker' => $broker, 'tenant' => $tenant, 'book' => $book];
}

it('lists quotes, policies and claims for the broker\'s attributed clients only', function () {
    $x = w16BrokerBook();
    Passport::actingAs($x['broker']['user']);
    $h = w16BrokerHeaders($x['tenant']);

    $quotes = $this->getJson('/api/v1/mobile/partner/broker/quotes', $h)->assertStatus(200)->json('data');
    $mineIds = App\Models\Quote::where('party_id', $x['book']['mine']['chain']['party']->id)->pluck('id')->sort()->values()->all();
    expect(collect($quotes)->pluck('id')->sort()->values()->all())->toBe($mineIds)
        ->and($mineIds)->toContain($x['book']['mine']['quote']->id);

    $policies = $this->getJson('/api/v1/mobile/partner/broker/policies', $h)->assertStatus(200)->json('data');
    expect(collect($policies)->pluck('policy_number')->all())->toBe(['POL-MINE'])
        ->and($policies[0])->toHaveKeys(['id', 'customer_name', 'carrier_name', 'premium_minor', 'status', 'coverage_ends_at']);

    $claims = $this->getJson('/api/v1/mobile/partner/broker/claims', $h)->assertStatus(200)->json('data');
    expect(collect($claims)->pluck('claim_number')->all())->toBe(['CLM-MINE'])
        ->and($claims[0]['policy_number'])->toBe('POL-MINE');
});

it('shows the broker\'s own commission accruals and non-draft statements only', function () {
    $x = w16BrokerBook();
    $preparer = App\Models\User::factory()->create();
    makeMobileTestCommissionAccrual($x['tenant'], $x['book']['mine']['partner'], $x['book']['mine']['policy'], ['amount_minor' => 7000]);
    makeMobileTestCommissionAccrual($x['tenant'], $x['book']['theirs']['partner'], $x['book']['theirs']['policy'], ['amount_minor' => 9999]);
    makeMobileTestPartnerStatement($x['tenant'], $x['book']['mine']['partner'], $preparer, ['status' => 'PUBLISHED', 'statement_number' => 'PST-MINE']);
    makeMobileTestPartnerStatement($x['tenant'], $x['book']['mine']['partner'], $preparer, ['status' => 'DRAFT', 'statement_number' => 'PST-DRAFT', 'period_start' => now()->subMonths(3)->toDateString(), 'period_end' => now()->subMonths(2)->toDateString()]);
    makeMobileTestPartnerStatement($x['tenant'], $x['book']['theirs']['partner'], $preparer, ['status' => 'PUBLISHED', 'statement_number' => 'PST-THEIRS']);

    Passport::actingAs($x['broker']['user']);
    $data = $this->getJson('/api/v1/mobile/partner/broker/commissions', w16BrokerHeaders($x['tenant']))->assertStatus(200)->json('data');

    expect(collect($data['accruals'])->pluck('amount_minor')->all())->toBe([7000])
        ->and($data['accruals'][0]['policy_number'])->toBe('POL-MINE')
        ->and(collect($data['statements'])->pluck('statement_number')->all())->toBe(['PST-MINE'])
        ->and($data['totals']['pending_minor'])->toBe(7000);
});

it('lists broker staff in the broker tenant and lets a broker admin invite BROKER_STAFF', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670002100');
    $admin = makeMobileTenantStaffUser($broker['tenant'], '+237670002101', 'BROKER_ADMIN');
    Passport::actingAs($admin);
    $h = w16BrokerHeaders($broker['tenant']);

    $staff = $this->getJson('/api/v1/mobile/partner/broker/staff', $h)->assertStatus(200)->json('data');
    expect(collect($staff['members'])->pluck('role_code')->sort()->values()->all())->toBe(['BROKER_ADMIN', 'BROKER_STAFF'])
        ->and($staff['can_invite'])->toBeTrue();

    $inv = $this->postJson('/api/v1/mobile/partner/broker/staff/invitations', ['recipient_phone_e164' => '+237670002199'], $h)->assertStatus(201)->json('data');
    expect($inv['role_code'])->toBe('BROKER_STAFF')->and($inv['status'])->toBe('PENDING');
    $row = TenantInvitation::findOrFail($inv['id']);
    expect($row->tenant_id)->toBe($broker['tenant']->id)->and($row->role_code)->toBe('BROKER_STAFF')->and($row->invited_by)->toBe($admin->id);
    // No delivery channel exists for invitations yet: the one-time code is
    // shown to the inviting admin once (same contract as POST /invitations).
    expect(strlen($inv['invite_code']))->toBe(64)->and($row->token_hash)->toBe(hash('sha256', $inv['invite_code']));

    $after = $this->getJson('/api/v1/mobile/partner/broker/staff', $h)->json('data');
    expect(collect($after['pending_invitations'])->pluck('recipient')->all())->toBe(['+237670002199']);
});

it('refuses staff invitations from broker staff (non-admin)', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670002200');
    Passport::actingAs($broker['user']);
    $h = w16BrokerHeaders($broker['tenant']);

    expect($this->getJson('/api/v1/mobile/partner/broker/staff', $h)->assertStatus(200)->json('data.can_invite'))->toBeFalse();
    $this->postJson('/api/v1/mobile/partner/broker/staff/invitations', ['recipient_phone_e164' => '+237670002299'], $h)->assertStatus(403);
    expect(TenantInvitation::count())->toBe(0);
});

it('does not list other firms\' broker staff in a shared (non-broker) tenant', function () {
    $x = makeMobileCustomerFixture('+237670002300'); // CARRIER-type tenant shared by several firms
    $tenant = $x['tenant'];
    $me = makeMobileTenantStaffUser($tenant, '+237670002301', 'BROKER_ADMIN');
    makeMobileTenantStaffUser($tenant, '+237670002302', 'BROKER_STAFF'); // someone else's staff
    Passport::actingAs($me);

    $members = $this->getJson('/api/v1/mobile/partner/broker/staff', w16BrokerHeaders($tenant))->assertStatus(200)->json('data.members');
    expect(collect($members)->pluck('user_id')->all())->toBe([$me->id]);
});

it('403s a customer on every broker workspace endpoint', function (string $method, string $uri) {
    $fixture = makeMobileCustomerFixture('+237670002400');
    Passport::actingAs($fixture['user']);

    $this->json($method, $uri, ['recipient_phone_e164' => '+237670002499'], w16BrokerHeaders($fixture['tenant']))->assertStatus(403);
})->with([
    ['GET', '/api/v1/mobile/partner/broker/quotes'],
    ['GET', '/api/v1/mobile/partner/broker/policies'],
    ['GET', '/api/v1/mobile/partner/broker/claims'],
    ['GET', '/api/v1/mobile/partner/broker/staff'],
    ['POST', '/api/v1/mobile/partner/broker/staff/invitations'],
    ['GET', '/api/v1/mobile/partner/broker/commissions'],
]);

it('lets broker staff start a quote on behalf of their own client (web buy flow)', function () {
    $x = w16BrokerBook();
    $party = $x['book']['mine']['chain']['party'];
    $customer = App\Models\TenantCustomer::firstOrCreate(['tenant_id' => $x['tenant']->id, 'party_id' => $party->id], ['status' => 'ACTIVE', 'customer_number' => 'CUS-'.Str::upper(Str::random(8))]);
    Passport::actingAs($x['broker']['user']);
    $h = w16BrokerHeaders($x['tenant']);

    \App\Models\InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => ['en' => 'Motor'], 'description' => ['en' => 'Motor'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    // The web buy flow loads the broker's clients from /mobile/broker/clients and posts the tenant customer id.
    $ids = collect($this->getJson('/api/v1/mobile/broker/clients', $h)->assertStatus(200)->json('data'))->pluck('id');
    expect($ids)->toContain($customer->id);

    $res = $this->postJson('/api/v1/quotes', ['customer_id' => $customer->id, 'line_code' => 'MOTOR', 'channel' => 'BROKER', 'risk_facts' => ['make' => 'Toyota', 'model' => 'Corolla']], w16BrokerHeaders($x['tenant']));
    expect($res->status())->toBe(202)
        ->and($res->json('data.party_id'))->toBe($party->id)
        ->and($res->json('data.channel'))->toBe('BROKER');
});
