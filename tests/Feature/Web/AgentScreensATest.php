<?php

declare(strict_types=1);

// Q3 launch — agent web screens, first half (AGT-004/005/014/015/016/018/019/022/024/026/028/029/030/031/032/033/034):
// every /account shell renders in EN and FR, and the endpoints they call refuse another agent's client.

use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function agaH($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

$u = 'ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01';

dataset('agent screens a', [
    'AGT-004 dashboard' => ['/account/agent', 'Agent Dashboard', 'Tableau de bord agent'],
    'AGT-005 action centre' => ['/account/agent-actions', 'Action Centre', 'Centre d&#039;actions'],
    'AGT-014/015 customer KYC' => ["/account/customers/{$u}/kyc", 'Customer KYC', 'KYC client'],
    'AGT-016 customer activities' => ["/account/customers/{$u}/activities", 'Customer Activities', 'Activités client'],
    'AGT-018 product details' => ['/account/agent/products', 'Product Details', 'Détails du produit'],
    'AGT-019 needs assessment' => ['/account/agent/needs', 'Needs Assessment', 'Analyse des besoins'],
    'agent quote workspace' => ["/account/agent/quotes/{$u}", 'Quote', 'Devis'],
    'AGT-026 send quote' => ["/account/agent/quotes/{$u}/send", 'Send Quote', 'Envoyer le devis'],
    'AGT-028 lost quote' => ["/account/agent/quotes/{$u}/lost", 'Lost Quote', 'Devis perdu'],
    'AGT-029 proposal builder' => ['/account/agent/proposals/new', 'Proposal Builder', 'Création de proposition'],
    'AGT-031/032/034 proposal review' => ["/account/agent/proposals/{$u}", 'Proposal Review', 'Revue de la proposition'],
    'AGT-030 proposal documents' => ["/account/agent/proposals/{$u}/documents", 'Proposal Documents', 'Documents de la proposition'],
    'AGT-033 information request' => ["/account/agent/proposals/{$u}/information", 'Information Request', 'Demande d&#039;informations'],
]);

it('renders each agent screen in English and French', function (string $url, string $en, string $fr) {
    $this->get($url)->assertOk()->assertSee($en, false)->assertSee('/landing/portal/agent-a.js', false)->assertSee('window.LA_T', false)->assertSee('/landing/portal/agent.js', false);
    $this->get($url.'?lang=fr')->assertOk()->assertSee($fr, false);
})->with('agent screens a');

it('keeps the shared quote screens the agent uses for AGT-022/024/029 routable', function () use ($u) {
    $this->get("/account/quotes/{$u}")->assertOk();
    $this->get("/account/quotes/{$u}/customize")->assertOk();
    $this->get("/account/quotes/{$u}/review")->assertOk();
});

it('keeps the EN and FR launch_agent_a copy in sync', function () {
    $keys = function (array $a, string $p = '') use (&$keys): array {
        $out = [];
        foreach ($a as $k => $v) {
            $out[] = $p.$k;
            if (is_array($v)) {
                $out = array_merge($out, $keys($v, $p.$k.'.'));
            }
        }

        return $out;
    };
    $en = $keys(require lang_path('en/launch_agent_a.php'));
    $fr = $keys(require lang_path('fr/launch_agent_a.php'));
    expect(array_values(array_diff($en, $fr)))->toBe([])->and(array_values(array_diff($fr, $en)))->toBe([]);
});

it('serves KYC, quote and proposal data to the owning agent and refuses another agent', function () {
    $a = makeMobileAgentFixture('+237680017001');
    $b = makeMobileAgentFixtureInTenant($a['tenant'], '+237680017002');
    $t = $a['tenant'];
    $a['role']->update(['permissions' => array_merge($a['role']->permissions, ['quotes.manage', 'quotes.send'])]);
    Passport::actingAs($a['user']);

    $lead = $this->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Screens Prospect', 'phone_e164' => '+237690017001'], agaH($t))->assertStatus(201)->json('data');
    $client = $this->postJson('/api/v1/mobile/partner/agent/leads/'.$lead['id'].'/convert', ['consent_confirmed' => true], agaH($t))->assertStatus(201)->json('data.client');
    $cid = $client['id'];

    // AGT-014/015: KYC file and assisted capture (stored as the client's own document).
    $this->getJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc", agaH($t))->assertOk()->assertJsonPath('data.submission', null);
    $png = base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 64));
    $doc = $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/documents", ['category' => 'KYC', 'mime_type' => 'image/png', 'file_base64' => $png], agaH($t))->assertStatus(201)->json('data');
    expect(\App\Models\Document::find($doc['id'])->party_id)->toBe($client['party_id']);
    $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc/documents", ['document_id' => $doc['id'], 'purpose' => 'IDENTITY'], agaH($t))->assertStatus(201)->assertJsonPath('data.documents.0.id', $doc['id']);
    $this->getJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc", agaH($t))->assertOk()->assertJsonPath('data.submission.status', 'DRAFT');

    // Quote (AGT-024/026/028) and proposal (AGT-030…034) for the client.
    \App\Models\InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $quoteId = $this->postJson('/api/v1/quotes', ['customer_id' => $cid, 'line_code' => 'AUTO', 'channel' => 'AGENT', 'risk_facts' => ['registration_number' => 'LT456AB', 'fiscal_power' => 7, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON']], agaH($t))->assertStatus(202)->json('data.id');
    $chain = makeMobileFinanceProposalChain($t);
    $src = QuoteOffer::findOrFail($chain['proposal']->quote_offer_id);
    $offer = makeMobileTestQuoteOffer(Quote::findOrFail($quoteId), $src->carrier_id, $src->product_id, $src->tariff_version_id, ['total_minor' => 150000, 'premium_minor' => 150000, 'comparison_rank' => 1]);
    $proposal = Proposal::create(['tenant_id' => $t->id, 'quote_offer_id' => $offer->id, 'party_id' => $client['party_id'], 'status' => 'INFORMATION_REQUIRED',
        'information_request' => ['id' => (string) Str::uuid(), 'items' => [['description' => 'Copy of the driving licence']], 'message' => 'Please send the licence', 'requested_at' => now()->toIso8601String(), 'responded_at' => null]]);

    $this->getJson("/api/v1/quotes/{$quoteId}", agaH($t))->assertOk()->assertJsonPath('data.quote.id', $quoteId);
    $this->getJson("/api/v1/proposals/{$proposal->id}", agaH($t))->assertOk()->assertJsonPath('data.id', $proposal->id);
    $this->getJson("/api/v1/proposals/{$proposal->id}/checklist", agaH($t))->assertOk()->assertJsonPath('data.information_request.items.0.description', 'Copy of the driving licence');
    expect(collect($this->getJson('/api/v1/mobile/partner/agent/proposals', agaH($t))->assertOk()->json('data'))->pluck('id')->all())->toContain($proposal->id);

    // Another agent: every read and write on this client is refused.
    Passport::actingAs($b['user']);
    $b['role']->update(['permissions' => array_merge($b['role']->permissions, ['quotes.manage', 'quotes.send'])]);
    $this->getJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc", agaH($t))->assertForbidden();
    $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/documents", ['category' => 'KYC', 'mime_type' => 'image/png', 'file_base64' => $png], agaH($t))->assertForbidden();
    $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc/documents", ['document_id' => $doc['id'], 'purpose' => 'IDENTITY'], agaH($t))->assertForbidden();
    $this->postJson("/api/v1/mobile/partner/agent/clients/{$cid}/kyc/submit", [], agaH($t))->assertForbidden();
    $this->getJson("/api/v1/quotes/{$quoteId}", agaH($t))->assertForbidden();
    $this->postJson("/api/v1/quotes/{$quoteId}/send", ['channel' => 'LINK'], agaH($t))->assertForbidden();
    $this->postJson("/api/v1/quotes/{$quoteId}/decline", ['reason_code' => 'NO_RESPONSE'], agaH($t))->assertForbidden();
    $this->getJson("/api/v1/proposals/{$proposal->id}", agaH($t))->assertForbidden();
    $this->getJson("/api/v1/proposals/{$proposal->id}/checklist", agaH($t))->assertForbidden();
    $this->postJson("/api/v1/proposals/{$proposal->id}/resubmit", ['response' => 'x'], agaH($t))->assertForbidden();
    $this->postJson("/api/v1/proposals/{$proposal->id}/documents", ['document_id' => $doc['id'], 'requirement_code' => 'X'], agaH($t))->assertForbidden();
    expect(collect($this->getJson('/api/v1/mobile/partner/agent/proposals', agaH($t))->assertOk()->json('data'))->pluck('id')->all())->not->toContain($proposal->id);

    // Back to the owner: AGT-028 lost quote (a priced quote; rating fixtures are out of scope here).
    Passport::actingAs($a['user']);
    Quote::whereKey($quoteId)->update(['lifecycle_state' => 'CALCULATED']);
    $this->postJson("/api/v1/quotes/{$quoteId}/decline", ['reason_code' => 'PRICE_TOO_HIGH', 'note' => 'Cheaper elsewhere'], agaH($t))->assertOk();
});
