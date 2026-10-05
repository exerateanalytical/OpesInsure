<?php

declare(strict_types=1);

/**
 * Intermediary desk (2026-09-30, MobileIntermediaryDeskController):
 *  - brokers quote and sell on the agent assisted-sale rails (AssistedSaleService, channel BROKER), own book only;
 *  - broker/agent renewal actions: re-quote (RenewalService::createQuote → the seller's assisted sale), client decline;
 *  - broker claimable policies: searchable and paged, own book only.
 */

use App\Models\{CustomerAttribution, InsuranceLine, Partner, Party, Policy, Proposal, Quote, QuoteOffer, RenewalCase, TenantCustomer};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function deskH($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/** A broker (BROKER_STAFF, whole book via ORGANIZATION default) and a rival broker, each with one client holding an expiring policy. */
function deskBook($test): array
{
    $test->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $broker = makeMobilePartnerFixture('BROKER', '+237670052000');
    $tenant = $broker['tenant'];
    $rival = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rival Broker', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    $book = [];
    foreach (['mine' => $broker['partner'], 'theirs' => $rival] as $key => $partner) {
        $chain = makeMobileFinanceProposalChain($tenant);
        $chain['party']->update(['display_name' => $key === 'mine' ? 'Alice Mbarga' : 'Zoe Rival']);
        $customer = TenantCustomer::create(['tenant_id' => $tenant->id, 'party_id' => $chain['party']->id, 'customer_number' => 'C-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE']);
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $broker['user']->id]);
        $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.strtoupper($key), 'premium_minor' => 100000, 'coverage_ends_at' => now()->addDays(20)]);
        $book[$key] = compact('chain', 'customer', 'policy', 'partner');
    }

    return ['broker' => $broker, 'tenant' => $tenant, 'book' => $book];
}

it('lets a broker price a book client on the BROKER channel and run the sale; other books are 404', function () {
    $x = deskBook($this);
    Passport::actingAs($x['broker']['user']);
    $h = fn () => deskH($x['tenant']);
    $facts = ['registration_number' => 'LT410AB', 'fiscal_power' => 7, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON'];

    // A client outside the broker's book cannot be sold to.
    $this->postJson('/api/v1/mobile/broker/sales', ['customer_id' => $x['book']['theirs']['customer']->id, 'product' => 'AUTO', 'payment_phone_e164' => '+237677052001', 'risk_facts' => $facts], $h())->assertStatus(404);
    $this->postJson('/api/v1/mobile/broker/sales', ['customer_id' => $x['book']['mine']['customer']->id, 'product' => 'AUTO', 'payment_phone_e164' => '+237677052001'], $h())
        ->assertStatus(422)->assertJsonValidationErrors('risk_facts');

    $sale = $this->postJson('/api/v1/mobile/broker/sales', ['customer_id' => $x['book']['mine']['customer']->id, 'product' => 'AUTO', 'payment_phone_e164' => '+237677052001', 'risk_facts' => $facts], $h())
        ->assertStatus(201)->json('data');
    $quote = Quote::findOrFail($sale['id']);
    expect($quote->channel)->toBe('BROKER')->and($quote->partner_id)->toBe($x['broker']['partner']->id)
        ->and($quote->party_id)->toBe($x['book']['mine']['chain']['party']->id)
        ->and($quote->comparison_context['seller_partner_id'])->toBe($x['broker']['partner']->id)
        ->and($quote->comparison_context['payment_provider'])->toBe('mtn_momo');

    // Price the sale with a real offer, then "Send to client" opens the client's application (PROPOSAL question set).
    DB::table('disclosure_schema_versions')->insert(['id' => (string) Str::uuid(), 'insurance_line_id' => InsuranceLine::where('code', 'AUTO')->value('id'), 'version' => 1, 'status' => 'APPROVED',
        'questions' => json_encode([['code' => 'prior_claims', 'label' => ['en' => 'Claims in the last 3 years?', 'fr' => 'Sinistres ?'], 'type' => 'boolean', 'required' => true]]),
        'schema_hash' => str_repeat('e', 64), 'effective_from' => '2026-01-01', 'created_by' => $x['broker']['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    $src = QuoteOffer::findOrFail($x['book']['mine']['chain']['proposal']->quote_offer_id);
    $offer = makeMobileTestQuoteOffer($quote, $src->carrier_id, $src->product_id, $src->tariff_version_id, ['total_minor' => 150000, 'premium_minor' => 140000, 'comparison_rank' => 1]);
    Quote::whereKey($quote->id)->update(['lifecycle_state' => 'CALCULATED', 'status' => 'RATED']);
    $this->getJson("/api/v1/mobile/broker/sales/{$quote->id}", $h())->assertOk()->assertJsonPath('data.next_action', 'SEND_TO_CLIENT')
        ->assertJsonPath('data.premium_minor', 150000)->assertJsonPath('data.commission_basis', 'NOT_CONFIGURED');
    $after = $this->postJson("/api/v1/mobile/broker/sales/{$quote->id}/payment-request", ['offer_id' => $offer->id], $h())->assertOk()->json('data');
    expect($after['proposal_id'])->not->toBeNull()->and($after['next_action'])->toBe('AWAIT_CLIENT')
        ->and(Proposal::findOrFail($after['proposal_id'])->party_id)->toBe($x['book']['mine']['chain']['party']->id);

    // The agent sale endpoints never expose a broker sale, and a rival broker's staff cannot open it.
    $rivalUser = App\Models\User::create(['full_name' => 'Rival Staff', 'phone_e164' => '+237670052999', 'party_id' => $x['book']['theirs']['partner']->party_id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = App\Models\TenantMembership::create(['tenant_id' => $x['tenant']->id, 'user_id' => $rivalUser->id, 'role_code' => 'BROKER_STAFF', 'status' => 'ACTIVE']);
    $m->roles()->attach(App\Models\Role::where(['tenant_id' => $x['tenant']->id, 'code' => 'BROKER_STAFF'])->value('id'));
    Passport::actingAs($rivalUser);
    $this->getJson("/api/v1/mobile/broker/sales/{$quote->id}", $h())->assertStatus(404);
    $this->postJson("/api/v1/mobile/broker/sales/{$quote->id}/payment-request", [], $h())->assertStatus(404);
});

it('re-quotes an expiring book policy into the broker\'s sale and records a client decline', function () {
    $x = deskBook($this);
    Passport::actingAs($x['broker']['user']);
    $h = fn () => deskH($x['tenant']);
    $mine = $x['book']['mine']['policy'];

    $this->getJson("/api/v1/mobile/broker/renewals/{$x['book']['theirs']['policy']->id}", $h())->assertStatus(404);
    $r = $this->getJson("/api/v1/mobile/broker/renewals/{$mine->id}", $h())->assertOk()->json('data');
    expect($r['status'])->toBe('NOT_OPENED')->and($r['allowed_actions'])->toBe(['REQUOTE', 'DECLINE'])->and($r['sale_id'])->toBeNull();

    $r = $this->postJson("/api/v1/mobile/broker/renewals/{$mine->id}/requote", [], $h())->assertOk()->json('data');
    $case = RenewalCase::where('policy_id', $mine->id)->sole();
    expect($r['status'])->toBe('QUOTED')->and($case->status)->toBe('QUOTED')->and($r['sale_id'])->toBe($case->renewal_quote_id)
        ->and($r['allowed_actions'])->toBe(['DECLINE']);
    expect(collect($r['events'])->pluck('action')->all())->toContain('OPENED', 'QUOTED');
    // The renewal quote is the broker's assisted sale (same sale screen), for the policyholder.
    $this->getJson("/api/v1/mobile/broker/sales/{$r['sale_id']}", $h())->assertOk()->assertJsonPath('data.customer_id', $x['book']['mine']['customer']->id);
    // Idempotent: a second tap adopts the same quote, never re-rates.
    $this->postJson("/api/v1/mobile/broker/renewals/{$mine->id}/requote", [], $h())->assertOk()->assertJsonPath('data.sale_id', $r['sale_id']);
    expect(Quote::where('party_id', $mine->party_id)->count())->toBe(2); // the original + one renewal quote

    // The client does not renew: reason required, case DECLINED, no more actions.
    $this->postJson("/api/v1/mobile/broker/renewals/{$mine->id}/decline", [], $h())->assertStatus(422)->assertJsonValidationErrors('reason');
    $r = $this->postJson("/api/v1/mobile/broker/renewals/{$mine->id}/decline", ['reason' => 'Client sold the vehicle'], $h())->assertOk()->json('data');
    expect($r['status'])->toBe('DECLINED')->and($r['closed_reason'])->toBe('CUSTOMER_DECLINED')->and($r['allowed_actions'])->toBe([]);
    expect(DB::table('renewal_work_items')->where('policy_id', $mine->id)->value('outcome'))->toBe('DECLINED');
    $this->postJson("/api/v1/mobile/broker/renewals/{$mine->id}/decline", ['reason' => 'again'], $h())->assertStatus(422);
});

it('lets an agent open and decline renewals of the agent\'s own book only', function () {
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $a = makeMobileAgentFixture('+237680052001');
    $t = $a['tenant'];
    $mine = makeMobileFinanceProposalChain($t);
    $other = makeMobileFinanceProposalChain($t);
    foreach ([$mine, $other] as $c) {
        TenantCustomer::create(['tenant_id' => $t->id, 'party_id' => $c['party']->id, 'customer_number' => 'C-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE']);
    }
    CustomerAttribution::create(['party_id' => $mine['party']->id, 'partner_id' => $a['partner']->id, 'origin_type' => 'AGENT', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $a['user']->id]);
    $p1 = makeMobileTestPolicy($mine['proposal'], $t, $mine['carrier']->id, $mine['party']->id, ['policy_number' => 'POL-AG1', 'coverage_ends_at' => now()->addDays(10)]);
    $p2 = makeMobileTestPolicy($other['proposal'], $t, $other['carrier']->id, $other['party']->id, ['policy_number' => 'POL-AG2', 'coverage_ends_at' => now()->addDays(10)]);
    Passport::actingAs($a['user']);

    $this->getJson("/api/v1/mobile/agent/renewals/{$p2->id}", deskH($t))->assertStatus(404);
    $this->getJson("/api/v1/mobile/agent/renewals/{$p1->id}", deskH($t))->assertOk()->assertJsonPath('data.status', 'NOT_OPENED')->assertJsonPath('data.policy_number', 'POL-AG1');
    $this->postJson("/api/v1/mobile/agent/renewals/{$p1->id}/decline", ['reason' => 'Moving abroad'], deskH($t))->assertOk()->assertJsonPath('data.status', 'DECLINED');
    // No quotes.rate on this agent role: re-quote is refused by the route permission.
    $this->postJson("/api/v1/mobile/agent/renewals/{$p1->id}/requote", [], deskH($t))->assertStatus(403);
});

it('lists the broker\'s claimable policies, searchable and paged, own book only', function () {
    $x = deskBook($this);
    Passport::actingAs($x['broker']['user']);
    $h = deskH($x['tenant']);

    $all = $this->getJson('/api/v1/mobile/broker/claimable-policies', $h)->assertOk();
    expect(collect($all->json('data'))->pluck('policy_number')->all())->toBe(['POL-MINE'])
        ->and($all->json('data.0.party_id'))->toBe($x['book']['mine']['chain']['party']->id)
        ->and($all->json('data.0.customer_id'))->toBe($x['book']['mine']['customer']->id)
        ->and($all->json('meta.total'))->toBe(1);
    expect($this->getJson('/api/v1/mobile/broker/claimable-policies?q=alice', $h)->assertOk()->json('meta.total'))->toBe(1);
    expect($this->getJson('/api/v1/mobile/broker/claimable-policies?q=rival', $h)->assertOk()->json('meta.total'))->toBe(0);
    expect($this->getJson('/api/v1/mobile/broker/claimable-policies?q=POL-THEIRS', $h)->assertOk()->json('meta.total'))->toBe(0);
    expect($this->getJson('/api/v1/mobile/broker/claimable-policies?customer_id='.$x['book']['mine']['customer']->id, $h)->assertOk()->json('meta.total'))->toBe(1);

    // Paging: 3 more policies for the same client, 2 per page.
    foreach ([1, 2, 3] as $i) {
        makeMobileTestPolicy($x['book']['mine']['chain']['proposal'], $x['tenant'], $x['book']['mine']['chain']['carrier']->id, $x['book']['mine']['chain']['party']->id, ['policy_number' => "POL-M{$i}"]);
    }
    $p2 = $this->getJson('/api/v1/mobile/broker/claimable-policies?per_page=2&page=2', $h)->assertOk();
    expect($p2->json('meta.total'))->toBe(4)->and($p2->json('meta.last_page'))->toBe(2)->and(count($p2->json('data')))->toBe(2);
});
