<?php

declare(strict_types=1);

/**
 * Launch 2026-10-02, Q4 — commercial agent servicing screens AGT-037..056 on /account and their API
 * (routes/agent_servicing.php, PartnerAgentServicingController over AgentServicingQuery + existing services).
 * Every read and write is bounded by the agent's own book: another agent's client, policy, vehicle or claim is a 404.
 */

use App\Models\{CustomerAttribution, Partner, RiskAsset, TenantCustomer};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function asbHeaders($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/** One client per agent (mine / theirs), each with an ACTIVE policy and a claim. */
function asbBook($tenant, Partner $mine, Partner $theirs, $recorder): array
{
    $out = [];
    foreach (['mine' => $mine, 'theirs' => $theirs] as $key => $partner) {
        $chain = makeMobileFinanceProposalChain($tenant);
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => $partner->type, 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $recorder->id]);
        $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-B-'.strtoupper($key), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear()]);
        $claim = makeMobileTestClaim($tenant, $policy, $chain['party'], ['claim_number' => 'CLM-B-'.strtoupper($key)]);
        $customer = TenantCustomer::where(['tenant_id' => $tenant->id, 'party_id' => $chain['party']->id])->first() ?? makeMobileTestTenantCustomer($tenant, $chain['party']);
        $out[$key] = ['chain' => $chain, 'policy' => $policy, 'claim' => $claim, 'customer' => $customer];
    }

    return $out;
}

function asbSetup(string $phone): array
{
    $c = makeMobileCustomerFixture('+2376700'.$phone.'0');
    $tenant = $c['tenant'];
    $agent = makeMobileAgentFixtureInTenant($tenant, '+2376800'.$phone.'1');
    $rival = makeMobileAgentFixtureInTenant($tenant, '+2376800'.$phone.'2');
    // The real AGENT role (RoleCatalogue::AGENT_PERMISSIONS) also carries these; the shared test fixture does not.
    $agent['role']->update(['permissions' => array_values(array_unique([...($agent['role']->permissions ?? []), 'policies.cancellation.request', 'stickers.view', 'stickers.assign', 'stickers.handover']))]);

    return ['tenant' => $tenant, 'customer' => $c, 'agent' => $agent, 'book' => asbBook($tenant, $agent['partner'], $rival['partner'], $agent['user'])];
}

it('shows an agent the detail of their own book policy and claim, never another agent\'s', function () {
    ['tenant' => $t, 'agent' => $agent, 'book' => $w] = asbSetup('9401');
    Passport::actingAs($agent['user']);
    $h = asbHeaders($t);

    $this->getJson('/api/v1/mobile/partner/agent/policies/'.$w['mine']['policy']->id, $h)->assertOk()
        ->assertJsonPath('data.policy_number', 'POL-B-MINE')->assertJsonPath('data.customer_id', $w['mine']['customer']->id)->assertJsonPath('data.transactions', []);
    $this->getJson('/api/v1/mobile/partner/agent/policies/'.$w['theirs']['policy']->id, $h)->assertNotFound();

    $this->getJson('/api/v1/mobile/partner/agent/claims/'.$w['mine']['claim']->id, $h)->assertOk()
        ->assertJsonPath('data.claim_number', 'CLM-B-MINE')->assertJsonStructure(['data' => ['timeline', 'evidence', 'customer_id']]);
    $this->getJson('/api/v1/mobile/partner/agent/claims/'.$w['theirs']['claim']->id, $h)->assertNotFound();
});

it('lets an agent raise and track a change request on a book policy only (AGT-042 / AGT-043)', function () {
    ['tenant' => $t, 'agent' => $agent, 'book' => $w] = asbSetup('9402');
    Passport::actingAs($agent['user']);

    $this->postJson('/api/v1/mobile/partner/agent/policies/'.$w['theirs']['policy']->id.'/service-requests', ['type' => 'ADDRESS_CHANGE', 'reason' => 'Client moved to Bonapriso'], asbHeaders($t))->assertNotFound();
    $this->postJson('/api/v1/mobile/partner/agent/policies/'.$w['mine']['policy']->id.'/service-requests', ['type' => 'CANCELLATION_REVIEW', 'reason' => 'Not through this form'], asbHeaders($t))->assertStatus(422);

    $id = $this->postJson('/api/v1/mobile/partner/agent/policies/'.$w['mine']['policy']->id.'/service-requests', ['type' => 'ADDRESS_CHANGE', 'reason' => 'Client moved to Bonapriso'], asbHeaders($t))
        ->assertStatus(201)->assertJsonPath('data.status', 'REQUESTED')->assertJsonPath('data.channel', 'AGENT')->json('data.id');
    expect(DB::table('policy_transactions')->where('id', $id)->value('requested_by'))->toBe($agent['user']->id);

    expect(collect($this->getJson('/api/v1/mobile/partner/agent/service-requests', asbHeaders($t))->assertOk()->json('data'))->pluck('id')->all())->toBe([$id]);
    $this->getJson('/api/v1/mobile/partner/agent/service-requests/'.$id, asbHeaders($t))->assertOk()->assertJsonPath('data.timeline.0.to_status', 'REQUESTED');

    // The rival agent cannot see it.
    Passport::actingAs(makeMobileAgentFixtureInTenant($t, '+237680094029')['user']);
    $this->getJson('/api/v1/mobile/partner/agent/service-requests/'.$id, asbHeaders($t))->assertNotFound();
});

it('keeps cancellation requests and sticker assignment to the agent\'s own book (AGT-046 / AGT-049)', function () {
    ['tenant' => $t, 'agent' => $agent, 'book' => $w] = asbSetup('9403');
    Passport::actingAs($agent['user']);

    $this->postJson('/api/v1/mobile/partner/agent/policies/'.$w['theirs']['policy']->id.'/cancellations/preview', ['effective_at' => now()->addDay()->toDateString(), 'initiated_by' => 'INSURED'], asbHeaders($t))->assertNotFound();
    $this->postJson('/api/v1/mobile/partner/agent/policies/'.$w['theirs']['policy']->id.'/cancellations', ['effective_at' => now()->addDay()->toDateString(), 'initiated_by' => 'INSURED', 'reason_code' => 'CLIENT_REQUEST'], asbHeaders($t))->assertNotFound();
    // An agent may not act as the insurer.
    $this->postJson('/api/v1/mobile/partner/agent/policies/'.$w['mine']['policy']->id.'/cancellations', ['effective_at' => now()->addDay()->toDateString(), 'initiated_by' => 'INSURER', 'reason_code' => 'X'], asbHeaders($t))->assertStatus(422);
    $this->postJson('/api/v1/mobile/partner/agent/policies/'.$w['theirs']['policy']->id.'/sticker', ['serial_number' => 'STK-0001'], asbHeaders($t))->assertNotFound();

    $this->getJson('/api/v1/mobile/partner/agent/stickers', asbHeaders($t))->assertOk()->assertJsonPath('data.stock', [])->assertJsonPath('data.handovers', []);
});

it('lists book payments and a client\'s payment history, and registers vehicles for book clients only (AGT-037 / 038 / 047 / 048)', function () {
    ['tenant' => $t, 'agent' => $agent, 'book' => $w] = asbSetup('9404');
    Passport::actingAs($agent['user']);
    $h = asbHeaders($t);

    $this->getJson('/api/v1/mobile/partner/agent/payments', $h)->assertOk();
    $this->getJson('/api/v1/mobile/partner/agent/clients/'.$w['mine']['customer']->id.'/payments', $h)->assertOk();
    $this->getJson('/api/v1/mobile/partner/agent/clients/'.$w['theirs']['customer']->id.'/payments', $h)->assertNotFound();

    $body = ['display_name' => 'Toyota Corolla · LT 777 AB', 'external_reference' => 'LT 777 AB', 'facts' => ['make' => 'Toyota', 'model' => 'Corolla', 'registration_number' => 'LT 777 AB', 'usage_type' => 'PRIVATE', 'year' => 2019]];
    $this->postJson('/api/v1/mobile/partner/agent/clients/'.$w['theirs']['customer']->id.'/vehicles', $body, asbHeaders($t))->assertNotFound();
    $id = $this->postJson('/api/v1/mobile/partner/agent/clients/'.$w['mine']['customer']->id.'/vehicles', $body, asbHeaders($t))->assertStatus(201)
        ->assertJsonPath('data.facts.registration_number', 'LT 777 AB')->json('data.id');
    expect(RiskAsset::find($id)->party_id)->toBe($w['mine']['chain']['party']->id);

    expect(collect($this->getJson('/api/v1/mobile/partner/agent/clients/'.$w['mine']['customer']->id.'/vehicles', $h)->assertOk()->json('data'))->pluck('id')->all())->toBe([$id]);
    $this->getJson('/api/v1/mobile/partner/agent/vehicles/'.$id, $h)->assertOk()->assertJsonPath('data.customer_id', $w['mine']['customer']->id);
    $this->getJson('/api/v1/mobile/partner/agent/clients/'.$w['theirs']['customer']->id.'/vehicles', $h)->assertNotFound();
});

it('refuses the agent servicing API to a customer', function () {
    ['tenant' => $t, 'customer' => $c, 'book' => $w] = asbSetup('9405');
    Passport::actingAs($c['user']);

    $this->getJson('/api/v1/mobile/partner/agent/policies/'.$w['mine']['policy']->id, asbHeaders($t))->assertForbidden();
    $this->getJson('/api/v1/mobile/partner/agent/payments', asbHeaders($t))->assertForbidden();
    $this->getJson('/api/v1/mobile/partner/agent/stickers', asbHeaders($t))->assertForbidden();
});

it('renders every agent servicing page with its API wiring, in EN and FR', function (string $url, string $en, string $fr, string $wiring) {
    $this->get($url)->assertOk()->assertSee($en)->assertSee($wiring, false)->assertSee('/landing/portal/agent-servicing.js', false)->assertSee('window.AGENT_B_T', false);
    $this->get($url.'?lang=fr')->assertOk()->assertSee($fr, false);
})->with([
    'AGT-037 payment assistance' => ['/account/book/payments', 'Payment assistance', 'Assistance au paiement', '/payment-request'],
    'AGT-038 payment history' => ['/account/customers/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/payments', 'Payment history', 'Historique des paiements', 'S.clientPayments'],
    'AGT-040 policy details' => ['/account/book/policies/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01', 'Policy details', 'Détails de la police', 'S.policy('],
    'AGT-041 policy documents' => ['/account/book/policies/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/documents', 'Policy documents', 'Documents de la police', 'A.clientDocuments'],
    'AGT-042 endorsement request' => ['/account/book/policies/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/endorsement', 'Request a change', 'Demander une modification', '/service-requests'],
    'AGT-043 endorsement tracking' => ['/account/book/requests', 'Change requests', 'Demandes de modification', 'S.requests'],
    'AGT-043 request detail' => ['/account/book/requests/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01', 'Request tracking', 'Suivi de la demande', 'S.request('],
    'AGT-045 renewal details' => ['/account/book/renewals/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01', 'Renewal details', 'Détails du renouvellement', 'A.renewals'],
    'AGT-046 cancellation' => ['/account/book/policies/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/cancel', 'Cancellation', 'Résiliation', '/cancellations'],
    'AGT-047/048 client vehicles' => ['/account/customers/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/vehicles', 'Client vehicles', 'Véhicules du client', '/vehicles'],
    'AGT-047 vehicle profile' => ['/account/book/vehicles/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01', 'Vehicle profile', 'Fiche véhicule', 'S.vehicle('],
    'AGT-049/050 stickers' => ['/account/stickers', 'Stickers', 'Vignettes', '/sticker-handovers/'],
    'AGT-051 claims portfolio' => ['/account/book/claims', 'Claims portfolio', 'Portefeuille sinistres', 'A.claims'],
    'AGT-053 claim details' => ['/account/book/claims/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01', 'Claim details', 'Détails du sinistre', 'S.claim('],
    'AGT-054 evidence assistance' => ['/account/book/claims/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/evidence', 'Evidence assistance', 'Aide aux pièces justificatives', 'evidence_by_line'],
    'AGT-056 tasks' => ['/account/tasks', 'Tasks & follow-ups', 'Tâches et relances', '/mobile/partner/agent/leads'],
]);

it('links the agent book and client pages to the servicing screens', function () {
    $this->get('/account/book')->assertOk()->assertSee('/account/book/policies/', false)->assertSee('/account/book/claims/', false)->assertSee('Change requests');
    $this->get('/account/customers/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01')->assertOk()->assertSee("/payments'", false)->assertSee("/vehicles'", false);
    $this->get('/account')->assertOk()->assertSee('href="/account/tasks"', false)->assertSee('href="/account/stickers"', false);
});

it('keeps the EN and FR launch_agent_b copy in sync', function () {
    $keys = function (array $a, string $p = '') use (&$keys): array {
        $out = [];
        foreach ($a as $k => $v) {
            $out[] = $p.$k;
            if (is_array($v) && ! array_is_list($v)) {
                $out = array_merge($out, $keys($v, $p.$k.'.'));
            }
        }

        return $out;
    };
    $en = $keys(require lang_path('en/launch_agent_b.php'));
    $fr = $keys(require lang_path('fr/launch_agent_b.php'));
    expect(array_values(array_diff($en, $fr)))->toBe([])->and(array_values(array_diff($fr, $en)))->toBe([]);
});
