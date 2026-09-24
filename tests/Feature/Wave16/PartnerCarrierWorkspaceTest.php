<?php

declare(strict_types=1);

use App\Application\Shared\CanonicalJson;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\CustomerAttribution;
use App\Models\InsuranceProduct;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function w16CarrierHeaders($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

function w16Insurer($tenant, string $carrierId, string $phone, string $role = 'CARRIER_STAFF'): User
{
    $u = makeMobileTenantStaffUser($tenant, $phone, $role);
    TenantMembership::where('user_id', $u->id)->update(['carrier_id' => $carrierId]);

    return $u;
}

/** Two insurers' books in one tenant: 'mine' (fixture carrier) and 'theirs'. */
function w16CarrierWorld(): array
{
    $a = makeMobileCustomerFixture('+237670003000');
    $tenant = $a['tenant'];
    $b = makeMobileFinanceProposalChain($tenant);
    $theirProduct = App\Models\QuoteOffer::find(Proposal::find($b['proposal']->id)->quote_offer_id)->product;
    $world = ['tenant' => $tenant, 'fixture' => $a];
    foreach (['mine' => [$a['proposal'], $a['carrier'], $a['party'], $a['product']], 'theirs' => [$b['proposal'], $b['carrier'], $b['party'], $theirProduct]] as $key => [$proposal, $carrier, $party, $product]) {
        $policy = makeMobileTestPolicy($proposal, $tenant, $carrier->id, $party->id, ['policy_number' => 'POL-'.strtoupper($key), 'premium_minor' => 100000]);
        $payment = makeMobileTestPayment($proposal, $tenant, ['amount_minor' => 100000]);
        $world[$key] = compact('proposal', 'carrier', 'party', 'product', 'policy', 'payment');
    }
    $world['staff'] = w16Insurer($tenant, $a['carrier']->id, '+237670003001');
    $world['admin'] = w16Insurer($tenant, $a['carrier']->id, '+237670003002', 'CARRIER_ADMIN');

    return $world;
}

// ---------------------------------------------------------------- reads

it('lists products, proposals, policies and payments for the caller\'s carrier only', function () {
    $w = w16CarrierWorld();
    Passport::actingAs($w['staff']);
    $h = w16CarrierHeaders($w['tenant']);

    $products = $this->getJson('/api/v1/mobile/partner/carrier/products', $h)->assertStatus(200)->json('data');
    expect(collect($products)->pluck('id')->all())->toBe([$w['mine']['product']->id])
        ->and($products[0])->toHaveKeys(['name', 'line_code', 'status', 'version', 'tariffs', 'can_toggle'])
        ->and($products[0]['can_toggle'])->toBeFalse();

    $proposals = $this->getJson('/api/v1/mobile/partner/carrier/proposals', $h)->assertStatus(200)->json('data');
    expect(collect($proposals)->pluck('id')->all())->toBe([$w['mine']['proposal']->id])
        ->and(collect($proposals)->pluck('carrier_id')->unique()->all())->toBe([$w['mine']['carrier']->id]);

    $policies = $this->getJson('/api/v1/mobile/partner/carrier/policies', $h)->assertStatus(200)->json('data');
    expect(collect($policies)->pluck('policy_number')->all())->toBe(['POL-MINE']);

    $payments = $this->getJson('/api/v1/mobile/partner/carrier/payments', $h)->assertStatus(200)->json('data');
    expect(collect($payments['items'])->pluck('id')->all())->toBe([$w['mine']['payment']->id])
        ->and($payments['items'][0]['reconciliation_status'])->toBe('UNRECONCILED')
        ->and($payments['summary']['succeeded_minor'])->toBe(100000);
});

it('lists distribution partners that sell the caller\'s products only', function () {
    $w = w16CarrierWorld();
    $mkPartner = fn (string $name, string $type) => Partner::create(['tenant_id' => $w['tenant']->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE'])->id, 'type' => $type, 'status' => 'ACTIVE', 'compliance' => []]);
    $seller = $mkPartner('Selling Broker', 'BROKER');
    $rival = $mkPartner('Rival Agent', 'AGENT');
    foreach ([[$seller, 'mine'], [$rival, 'theirs']] as [$p, $key]) {
        CustomerAttribution::create(['party_id' => $w[$key]['party']->id, 'partner_id' => $p->id, 'origin_type' => $p->type, 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $w['staff']->id]);
    }

    Passport::actingAs($w['staff']);
    $rows = $this->getJson('/api/v1/mobile/partner/carrier/partners', w16CarrierHeaders($w['tenant']))->assertStatus(200)->json('data');
    expect(collect($rows)->pluck('name')->all())->toBe(['Selling Broker'])
        ->and($rows[0]['type'])->toBe('BROKER')
        ->and($rows[0]['policies'])->toBe(1);
});

// ------------------------------------------------------------ products

it('lets an insurer admin deactivate and reactivate its own product, not staff and not another insurer\'s', function () {
    $w = w16CarrierWorld();
    $h = w16CarrierHeaders($w['tenant']);
    $id = $w['mine']['product']->id;

    Passport::actingAs($w['staff']);
    $this->postJson("/api/v1/mobile/partner/carrier/products/{$id}/status", ['active' => false, 'reason' => 'Pausing sales'], $h)->assertStatus(403);

    Passport::actingAs($w['admin']);
    expect($this->getJson('/api/v1/mobile/partner/carrier/products', $h)->json('data.0.can_toggle'))->toBeTrue();
    $this->postJson("/api/v1/mobile/partner/carrier/products/{$id}/status", ['active' => false, 'reason' => 'Pausing sales'], $h)->assertStatus(200)->assertJsonPath('data.status', 'RETIRED');
    expect(DB::table('product_status_history')->where('insurance_product_id', $id)->where('to_status', 'RETIRED')->exists())->toBeTrue();
    $this->postJson("/api/v1/mobile/partner/carrier/products/{$id}/status", ['active' => true, 'reason' => 'Resuming sales'], $h)->assertStatus(200)->assertJsonPath('data.status', 'ACTIVE');

    $this->postJson('/api/v1/mobile/partner/carrier/products/'.$w['theirs']['product']->id.'/status', ['active' => false, 'reason' => 'Not mine'], $h)->assertStatus(404);
    expect(InsuranceProduct::find($w['theirs']['product']->id)->status)->toBe('ACTIVE');
});

// ---------------------------------------------------------------- claims

it('runs the claim workflow: acknowledge, request information, propose then approve a decision (maker-checker)', function () {
    $w = w16CarrierWorld();
    $h = w16CarrierHeaders($w['tenant']);
    $claim = makeMobileTestClaim($w['tenant'], $w['mine']['policy'], $w['mine']['party']);

    Passport::actingAs($w['staff']);
    $this->getJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}", $h)->assertStatus(200)->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.actions', ['acknowledge', 'request_information']);
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/acknowledge", [], $h)->assertStatus(200)->assertJsonPath('data.status', 'ACKNOWLEDGED');
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/request-information", ['note' => 'Please upload the police report.'], $h)->assertStatus(200)->assertJsonPath('data.status', 'EVIDENCE_PENDING');

    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/decisions", ['decision' => 'PARTIAL', 'approved_amount_minor' => 40000, 'reason_code' => 'PARTIAL_COVER', 'rationale' => 'Only the bumper is covered.'], $h)
        ->assertStatus(201)->assertJsonPath('data.status', 'CARRIER_REVIEW')->assertJsonPath('data.pending_decision.decision', 'PARTIAL');
    $decision = ClaimDecision::where('claim_id', $claim->id)->firstOrFail();

    // The proposer cannot approve their own decision.
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/decisions/{$decision->id}/approve", [], $h)->assertStatus(403); // staff lacks carrier.authority.approve
    Passport::actingAs($w['admin']);
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/decisions/{$decision->id}/approve", [], $h)->assertStatus(200)->assertJsonPath('data.status', 'PARTIALLY_APPROVED');
    expect(Claim::find($claim->id)->approved_amount_minor)->toBe(40000)
        ->and(ClaimDecision::find($decision->id)->approved_by)->toBe($w['admin']->id);
    expect(DB::table('claim_events')->where('claim_id', $claim->id)->pluck('to_status')->all())->toContain('ACKNOWLEDGED', 'EVIDENCE_PENDING', 'ASSESSMENT', 'CARRIER_REVIEW', 'PARTIALLY_APPROVED');
});

it('refuses self-approval of a claim decision even for an admin', function () {
    $w = w16CarrierWorld();
    $h = w16CarrierHeaders($w['tenant']);
    $claim = makeMobileTestClaim($w['tenant'], $w['mine']['policy'], $w['mine']['party'], ['status' => 'ASSESSMENT']);

    Passport::actingAs($w['admin']);
    $id = $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/decisions", ['decision' => 'DECLINE', 'approved_amount_minor' => 0, 'reason_code' => 'EXCLUDED', 'rationale' => 'Racing is excluded.'], $h)->assertStatus(201)->json('data.pending_decision.id');
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/decisions/{$id}/approve", [], $h)->assertStatus(422);
    expect(Claim::find($claim->id)->status)->toBe('CARRIER_REVIEW');
});

it('404s every claim action on another insurer\'s claim', function (string $suffix) {
    $w = w16CarrierWorld();
    $claim = makeMobileTestClaim($w['tenant'], $w['theirs']['policy'], $w['theirs']['party']);
    Passport::actingAs($w['admin']);

    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}{$suffix}", ['note' => 'Please send documents', 'decision' => 'APPROVE', 'approved_amount_minor' => 1, 'reason_code' => 'OK', 'rationale' => 'Looks fine.'], w16CarrierHeaders($w['tenant']))->assertStatus(404);
    expect(Claim::find($claim->id)->status)->toBe('SUBMITTED');
})->with(['/acknowledge', '/request-information', '/decisions']);

it('404s a claim detail for another insurer', function () {
    $w = w16CarrierWorld();
    $claim = makeMobileTestClaim($w['tenant'], $w['theirs']['policy'], $w['theirs']['party']);
    Passport::actingAs($w['staff']);
    $this->getJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}", w16CarrierHeaders($w['tenant']))->assertStatus(404);
});

// -------------------------------------------------------------- issuance

function w16IssuanceRequest(array $w, string $key): PolicyIssuanceRequest
{
    $proposal = $w[$key]['proposal'];
    $proposal->update(['terms_snapshot' => ['currency' => 'XAF', 'total_minor' => 100000]]);
    $requester = User::factory()->create();

    return PolicyIssuanceRequest::create([
        'tenant_id' => $w['tenant']->id, 'proposal_id' => $proposal->id, 'payment_intent_id' => $w[$key]['payment']->id, 'carrier_id' => $w[$key]['carrier']->id,
        'status' => 'REQUESTED', 'authority_snapshot' => [], 'terms_hash' => app(CanonicalJson::class)->hash($proposal->refresh()->terms_snapshot),
        'coverage_starts_at' => now(), 'coverage_ends_at' => now()->addYear(), 'requested_by' => $requester->id,
    ]);
}

it('approves an issuance request through PolicyIssuanceService, creating the policy', function () {
    $w = w16CarrierWorld();
    $req = w16IssuanceRequest($w, 'mine');
    Passport::actingAs($w['staff']);

    $res = $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$req->id}/approve", ['carrier_reference' => 'INS-REF-1'], w16CarrierHeaders($w['tenant']))->assertStatus(200);
    expect($res->json('data.status'))->toBe('APPROVED')->and($res->json('data.policy_number'))->not->toBeEmpty();
    $policy = Policy::where('issuance_request_id', $req->id)->firstOrFail();
    expect($policy->carrier_id)->toBe($w['mine']['carrier']->id)->and($policy->issuance_reference)->toBe('INS-REF-1');
});

it('rejects an issuance request with a reason and 404s another insurer\'s request', function () {
    $w = w16CarrierWorld();
    $mine = w16IssuanceRequest($w, 'mine');
    $theirs = w16IssuanceRequest($w, 'theirs');
    Passport::actingAs($w['staff']);
    $h = w16CarrierHeaders($w['tenant']);

    $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$theirs->id}/approve", [], $h)->assertStatus(404);
    $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$theirs->id}/reject", ['reason' => 'Not ours at all'], $h)->assertStatus(404);
    expect(PolicyIssuanceRequest::find($theirs->id)->status)->toBe('REQUESTED');

    $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$mine->id}/reject", ['reason' => 'Vehicle inspection failed'], $h)->assertStatus(200)->assertJsonPath('data.status', 'REJECTED');
});

// ----------------------------------------------------------------- gates

it('403s a customer on every insurer workspace endpoint', function (string $method, string $uri) {
    $fixture = makeMobileCustomerFixture('+237670003900');
    Passport::actingAs($fixture['user']);

    $this->json($method, $uri, [], w16CarrierHeaders($fixture['tenant']))->assertStatus(403);
})->with([
    ['GET', '/api/v1/mobile/partner/carrier/products'],
    ['POST', '/api/v1/mobile/partner/carrier/products/00000000-0000-0000-0000-000000000000/status'],
    ['GET', '/api/v1/mobile/partner/carrier/proposals'],
    ['GET', '/api/v1/mobile/partner/carrier/policies'],
    ['GET', '/api/v1/mobile/partner/carrier/claims/00000000-0000-0000-0000-000000000000'],
    ['POST', '/api/v1/mobile/partner/carrier/claims/00000000-0000-0000-0000-000000000000/acknowledge'],
    ['POST', '/api/v1/mobile/partner/carrier/claims/00000000-0000-0000-0000-000000000000/decisions'],
    ['POST', '/api/v1/mobile/partner/carrier/issuance/00000000-0000-0000-0000-000000000000/approve'],
    ['GET', '/api/v1/mobile/partner/carrier/payments'],
    ['GET', '/api/v1/mobile/partner/carrier/partners'],
]);

it('refuses an unlinked insurer role outside a carrier tenant', function () {
    $tenant = App\Models\Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'Platform W16', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    Passport::actingAs(makeMobileTenantStaffUser($tenant, '+237670003950', 'CARRIER_STAFF'));

    $this->getJson('/api/v1/mobile/partner/carrier/policies', w16CarrierHeaders($tenant))->assertStatus(403);
});
