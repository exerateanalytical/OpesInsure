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

    w16AcceptedAssessment($claim, $w['staff']);
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

    w16AcceptedAssessment($claim, $w['admin']);
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

/** REQ-CLM-010 (C10): a claim reaches DECISION_PENDING only with an ACCEPTED assessment on file. */
function w16AcceptedAssessment(Claim $claim, User $assessor): void
{
    DB::table('claim_assessments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id,
        'assessment_number' => 'ASM-'.Str::upper(Str::random(10)), 'status' => 'ACCEPTED', 'heads' => '[]', 'recommended_total_minor' => 40000,
        'currency' => 'XAF', 'rationale' => 'Fixture assessment', 'assessor_user_id' => $assessor->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

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

it('REQ-SET-006: accepts policy_number from carrier app 1.3.0, allocates the number server-side and keeps the sent one as carrier reference', function () {
    $w = w16CarrierWorld();
    $req = w16IssuanceRequest($w, 'mine');
    Passport::actingAs($w['staff']);

    $res = $this->postJson("/api/v1/mobile/partner/carrier/issuance/{$req->id}/approve", ['policy_number' => 'CARRIER-OWN-42'], w16CarrierHeaders($w['tenant']))->assertStatus(200);
    $policy = Policy::where('issuance_request_id', $req->id)->firstOrFail();
    expect($policy->policy_number)->not->toBe('CARRIER-OWN-42')->toStartWith('POL-')
        ->and($res->json('data.policy_number'))->toBe($policy->policy_number)
        ->and($policy->issuance_reference)->toBe('CARRIER-OWN-42');
});

// ------------------------------------------------ claim evidence (own carrier)

function w16ClaimEvidence(array $w, Claim $claim, string $scan = 'CLEAN', string $mime = 'image/jpeg'): App\Models\Document
{
    $doc = App\Models\Document::create(['tenant_id' => $w['tenant']->id, 'party_id' => $claim->claimant_party_id, 'category' => 'CLAIM_EVIDENCE', 'storage_key' => 'claims/'.Str::random(10),
        'mime_type' => $mime, 'size_bytes' => 2048, 'sha256' => hash('sha256', Str::random(20)), 'scan_status' => $scan, 'verification_status' => 'UNVERIFIED', 'ocr_data' => []]);
    DB::table('claim_documents')->insert(['id' => (string) Str::uuid(), 'claim_id' => $claim->id, 'document_id' => $doc->id, 'evidence_type' => 'DAMAGE_PHOTO',
        'evidence_hash' => $doc->sha256, 'status' => 'SUBMITTED', 'submitted_by' => $w['staff']->id, 'submitted_at' => now()]);

    return $doc;
}

it('lets insurer staff list and open the evidence of a claim on its own carrier, with a logged signed URL', function () {
    $w = w16CarrierWorld();
    $h = w16CarrierHeaders($w['tenant']);
    $claim = makeMobileTestClaim($w['tenant'], $w['mine']['policy'], $w['mine']['party']);
    $clean = w16ClaimEvidence($w, $claim);
    $pending = w16ClaimEvidence($w, $claim, 'PENDING', 'application/pdf');

    Passport::actingAs($w['staff']);
    $rows = collect($this->getJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/evidence", $h)->assertStatus(200)->json('data'));
    expect($rows->pluck('document_id')->sort()->values()->all())->toBe(collect([$clean->id, $pending->id])->sort()->values()->all())
        ->and($rows->firstWhere('document_id', $clean->id))->toMatchArray(['evidence_type' => 'DAMAGE_PHOTO', 'is_image' => true, 'downloadable' => true])
        ->and($rows->firstWhere('document_id', $pending->id)['downloadable'])->toBeFalse()
        ->and($rows->first())->not->toHaveKey('storage_key');

    $url = $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/evidence/{$clean->id}/access", [], w16CarrierHeaders($w['tenant']))->assertStatus(200)->json('data.url');
    expect($url)->toContain('signature=')
        ->and(DB::table('document_access_log')->where(['document_id' => $clean->id, 'actor_id' => $w['staff']->id, 'purpose' => 'CARRIER_CLAIM_REVIEW'])->exists())->toBeTrue();
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}/evidence/{$pending->id}/access", [], w16CarrierHeaders($w['tenant']))->assertStatus(422);
});

it('404s evidence of another insurer\'s claim and of a document not linked to the claim', function () {
    $w = w16CarrierWorld();
    $theirs = makeMobileTestClaim($w['tenant'], $w['theirs']['policy'], $w['theirs']['party']);
    $theirDoc = w16ClaimEvidence($w, $theirs);
    $mine = makeMobileTestClaim($w['tenant'], $w['mine']['policy'], $w['mine']['party']);

    Passport::actingAs($w['staff']);
    $this->getJson("/api/v1/mobile/partner/carrier/claims/{$theirs->id}/evidence", w16CarrierHeaders($w['tenant']))->assertStatus(404);
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$theirs->id}/evidence/{$theirDoc->id}/access", [], w16CarrierHeaders($w['tenant']))->assertStatus(404);
    // Own claim id + another claim's document: still 404 (no cross-claim document reads).
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$mine->id}/evidence/{$theirDoc->id}/access", [], w16CarrierHeaders($w['tenant']))->assertStatus(404);
    expect(DB::table('document_access_log')->where('document_id', $theirDoc->id)->exists())->toBeFalse();
});

it('403s a customer on the carrier claim evidence endpoints', function () {
    $fixture = makeMobileCustomerFixture('+237670003901');
    Passport::actingAs($fixture['user']);
    $z = '00000000-0000-0000-0000-000000000000';
    $this->getJson("/api/v1/mobile/partner/carrier/claims/{$z}/evidence", w16CarrierHeaders($fixture['tenant']))->assertStatus(403);
    $this->postJson("/api/v1/mobile/partner/carrier/claims/{$z}/evidence/{$z}/access", [], w16CarrierHeaders($fixture['tenant']))->assertStatus(403);
});

it('adds the insured vehicle and the coverage deductibles to the carrier claim detail', function () {
    $w = w16CarrierWorld();
    $quoteId = (string) Str::uuid();
    DB::table('quotes')->insert(['id' => $quoteId, 'tenant_id' => $w['tenant']->id, 'party_id' => $w['mine']['party']->id, 'line_code' => 'MOTOR', 'status' => 'ACCEPTED', 'currency' => 'XAF',
        'risk_facts' => json_encode(['registration_number' => 'LT 123 AB', 'make' => 'Toyota', 'model' => 'Corolla', 'year' => '2021', 'driver_phone' => '+237600000000']), 'channel' => 'B2C', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $w['mine']['policy']->update(['terms_snapshot' => ['quote_id' => $quoteId, 'coverage_snapshot' => ['coverages' => [
        ['code' => 'OWN_DAMAGE', 'name' => ['en' => 'Own damage'], 'limit_minor' => 500000000, 'deductible_minor' => 2500000],
        ['code' => 'THIRD_PARTY', 'name' => ['en' => 'Third party'], 'limit_minor' => 500000000, 'deductible_minor' => 0],
    ]]]]);
    $claim = makeMobileTestClaim($w['tenant'], $w['mine']['policy'], $w['mine']['party']);
    $claim->update(['loss_details' => ['coverage_code' => 'OWN_DAMAGE', 'description' => 'Rear-ended']]);

    Passport::actingAs($w['staff']);
    $d = $this->getJson("/api/v1/mobile/partner/carrier/claims/{$claim->id}", w16CarrierHeaders($w['tenant']))->assertStatus(200)->json('data');
    expect($d['insured_item'])->toMatchArray(['line_code' => 'MOTOR', 'registration_number' => 'LT 123 AB', 'make' => 'Toyota', 'model' => 'Corolla'])
        ->and($d['insured_item'])->not->toHaveKey('driver_phone')
        ->and($d['deductible_minor'])->toBe(2500000)
        ->and(collect($d['deductibles'])->pluck('code')->all())->toBe(['OWN_DAMAGE', 'THIRD_PARTY']);
});
