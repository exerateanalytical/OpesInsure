<?php

declare(strict_types=1);

/**
 * UI audit 2026-09-27 follow-up: the /account partner workspace book pages and their APIs.
 *  - GET /mobile/partner/{agent,broker}/proposals, /mobile/partner/agent/claims: the caller's attributed book only;
 *  - GET /mobile/partner/{agent,broker}/clients/{id}/documents: book client only (404 otherwise),
 *    DocumentAccessPolicy::intermediaryMay (no medical, no insurer-confidential);
 *  - agent-assisted FNOL from the web goes through the existing book check;
 *  - the pages render (book, client claim, staff) and the staff invitation keeps its BROKER_ADMIN gate.
 */

use App\Models\{CustomerAttribution, Partner, Party, TenantCustomer, TenantInvitation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function pwbHeaders($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/** One client per partner (mine / theirs), each with a proposal, a policy, a claim and documents. */
function pwbClients($tenant, Partner $mine, Partner $theirs, $recorder): array
{
    $out = [];
    foreach (['mine' => $mine, 'theirs' => $theirs] as $key => $partner) {
        $chain = makeMobileFinanceProposalChain($tenant);
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => $partner->type, 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $recorder->id]);
        $chain['proposal']->update(['proposal_number' => 'PRP-'.strtoupper($key)]);
        $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-'.strtoupper($key), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear()]);
        $claim = makeMobileTestClaim($tenant, $policy, $chain['party'], ['claim_number' => 'CLM-'.strtoupper($key)]);
        $customer = TenantCustomer::where(['tenant_id' => $tenant->id, 'party_id' => $chain['party']->id])->first() ?? makeMobileTestTenantCustomer($tenant, $chain['party']);
        $doc = fn (string $level, string $code) => makeMobileTestDocument($tenant, $chain['party'], ['policy_id' => $policy->id, 'document_origin' => 'INSURER', 'document_type_code' => $code,
            'security_level' => $level, 'status' => 'VALID', 'document_number' => $code.'-'.strtoupper($key)]);
        $out[$key] = ['chain' => $chain, 'policy' => $policy, 'claim' => $claim, 'customer' => $customer,
            'cert' => $doc('PUBLIC_VERIFIABLE', 'CERT_TEST'), 'medical' => $doc('MEDICAL_RESTRICTED', 'MED_TEST'), 'internal' => $doc('INSURER_CONFIDENTIAL', 'INT_TEST')];
    }

    return $out;
}

it('gives an agent their book: proposals, claims and a client\'s documents, never another partner\'s', function () {
    $c = makeMobileCustomerFixture('+237670009300');
    $tenant = $c['tenant'];
    $agent = makeMobileAgentFixtureInTenant($tenant, '+237680009301');
    $rival = makeMobileAgentFixtureInTenant($tenant, '+237680009302');
    $w = pwbClients($tenant, $agent['partner'], $rival['partner'], $agent['user']);
    Passport::actingAs($agent['user']);
    $h = pwbHeaders($tenant);

    expect(collect($this->getJson('/api/v1/mobile/partner/agent/proposals', $h)->assertOk()->json('data'))->pluck('proposal_number')->all())->toBe(['PRP-MINE']);
    expect(collect($this->getJson('/api/v1/mobile/partner/agent/claims', $h)->assertOk()->json('data'))->pluck('claim_number')->all())->toBe(['CLM-MINE']);

    $docs = $this->getJson('/api/v1/mobile/partner/agent/clients/'.$w['mine']['customer']->id.'/documents', $h)->assertOk()->json('data');
    expect(collect($docs)->pluck('document_number')->all())->toBe(['CERT_TEST-MINE'])
        ->and($docs[0]['download_url'])->toContain('/mobile/policy-documents/')->and($docs[0]['policy_number'])->toBe('POL-MINE');
    $this->getJson('/api/v1/mobile/partner/agent/clients/'.$w['theirs']['customer']->id.'/documents', $h)->assertNotFound();
});

it('lets an agent file a claim from the web for their own client only (existing FNOL book check)', function () {
    $c = makeMobileCustomerFixture('+237670009310');
    $tenant = $c['tenant'];
    $agent = makeMobileAgentFixtureInTenant($tenant, '+237680009311');
    $rival = makeMobileAgentFixtureInTenant($tenant, '+237680009312');
    $w = pwbClients($tenant, $agent['partner'], $rival['partner'], $agent['user']);
    Passport::actingAs($agent['user']);
    $body = fn (array $x) => ['policy_id' => $x['policy']->id, 'claimant_party_id' => $x['chain']['party']->id, 'loss_occurred_at' => now()->subDay()->toIso8601String(),
        'loss_details' => ['description' => 'Rear-ended at a junction'], 'idempotency_key' => (string) Str::uuid().Str::random(8)];

    $this->postJson('/api/v1/mobile/partner/agent/claims', $body($w['theirs']), pwbHeaders($tenant))->assertStatus(422)->assertJsonValidationErrors('claimant_party_id');
    $this->postJson('/api/v1/mobile/partner/agent/claims', $body($w['mine']), pwbHeaders($tenant))->assertStatus(201)->assertJsonPath('data.policy_id', $w['mine']['policy']->id);
});

it('gives a broker their book proposals and client documents, and keeps the staff invitation for BROKER_ADMIN', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670009320');
    $tenant = $broker['tenant'];
    $other = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rival Broker', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    $w = pwbClients($tenant, $broker['partner'], $other, $broker['user']);
    Passport::actingAs($broker['user']);
    $h = pwbHeaders($tenant);

    expect(collect($this->getJson('/api/v1/mobile/partner/broker/proposals', $h)->assertOk()->json('data'))->pluck('proposal_number')->all())->toBe(['PRP-MINE']);
    expect(collect($this->getJson('/api/v1/mobile/partner/broker/clients/'.$w['mine']['customer']->id.'/documents', $h)->assertOk()->json('data'))->pluck('document_number')->all())->toBe(['CERT_TEST-MINE']);
    $this->getJson('/api/v1/mobile/partner/broker/clients/'.$w['theirs']['customer']->id.'/documents', $h)->assertNotFound();

    // The web invite form posts here; only a BROKER_ADMIN may invite (the API decides, the page just hides the form).
    $admin = $this->getJson('/api/v1/mobile/partner/broker/staff', $h)->assertOk()->json('data.can_invite');
    $res = $this->postJson('/api/v1/mobile/partner/broker/staff/invitations', ['recipient_phone_e164' => '+237690009329'], pwbHeaders($tenant));
    $admin ? $res->assertStatus(201) : $res->assertStatus(403);
    expect(TenantInvitation::where('recipient_phone_e164', '+237690009329')->exists())->toBe((bool) $admin);
});

it('renders the partner workspace pages with their API wiring, in EN and FR', function (string $url, string $en, string $fr, string $api) {
    $this->get($url)->assertOk()->assertSee($en)->assertSee($api, false)->assertSee('/landing/portal/agent.js', false);
    $this->get($url.'?lang=fr')->assertOk()->assertSee($fr, false);
})->with([
    'book' => ['/account/book', 'My Book', 'Mon portefeuille', 'A.proposals'],
    'client claim' => ['/account/customers/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/claim', 'File a Claim for a Client', 'Déclarer un sinistre pour un client', '/mobile/partner/agent/claims'],
    'client documents' => ['/account/customers/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01', 'Documents', 'Documents', 'A.clientDocuments'],
    'staff' => ['/account/staff', 'Staff', 'Personnel', '/mobile/partner/broker/staff/invitations'],
]);
