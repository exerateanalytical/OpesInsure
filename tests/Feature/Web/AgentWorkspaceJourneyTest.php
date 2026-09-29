<?php

declare(strict_types=1);

// Agent web workspace (/account agent pages): the HTTP endpoints those pages call — leads (+ diary), convert,
// assisted quote, premium collection, commissions — scoped to the calling agent only.

use App\Models\Quote;
use App\Models\QuoteOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function awH($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

it('runs the agent journey: lead, diary, convert, quote, collect premium, commissions', function () {
    $a = makeMobileAgentFixture('+237680007001');
    $b = makeMobileAgentFixtureInTenant($a['tenant'], '+237680007002');
    $t = $a['tenant'];
    Passport::actingAs($a['user']);

    // /account/leads: create, move, log activity, convert
    $lead = $this->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Journey Prospect', 'phone_e164' => '+237690007001', 'product_interest' => 'MOTOR'], awH($t))->assertStatus(201)->json('data');
    $this->patchJson('/api/v1/mobile/partner/agent/leads/'.$lead['id'], ['status' => 'CONTACTED'], awH($t))->assertOk()->assertJsonPath('data.status', 'CONTACTED');
    $this->postJson('/api/v1/mobile/partner/agent/leads/'.$lead['id'].'/activities', ['entry_type' => 'CALL', 'body' => 'Called, wants a motor quote', 'follow_up_at' => now()->addDay()->toIso8601String()], awH($t))->assertStatus(201);
    $this->getJson('/api/v1/mobile/partner/agent/leads/'.$lead['id'].'/activities', awH($t))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.entry_type', 'CALL');
    $client = $this->postJson('/api/v1/mobile/partner/agent/leads/'.$lead['id'].'/convert', ['consent_confirmed' => true], awH($t))->assertStatus(201)->json('data.client');

    // /account/customers lists the new client
    expect(collect($this->getJson('/api/v1/mobile/agent/clients', awH($t))->assertOk()->json('data'))->pluck('id')->all())->toContain($client['id']);

    // /account/buy (agent): quote for the client — sold under the agent's partner, stamped with the agent
    \App\Models\InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $qr = $this->postJson('/api/v1/quotes', ['customer_id' => $client['id'], 'line_code' => 'AUTO', 'channel' => 'AGENT', 'risk_facts' => ['registration_number' => 'LT123AB', 'fiscal_power' => 7, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON']], awH($t)); $quoteId = $qr->assertStatus(202)->json("data.id");
    $quote = Quote::findOrFail($quoteId);
    expect($quote->partner_id)->toBe($a['partner']->id)->and($quote->comparison_context['agent_user_id'] ?? null)->toBe($a['user']->id);

    // Give it a priced offer (rating fixtures are out of scope here) and collect the premium from /account/book
    $chain = makeMobileFinanceProposalChain($t);
    $src = QuoteOffer::findOrFail($chain['proposal']->quote_offer_id);
    makeMobileTestQuoteOffer($quote, $src->carrier_id, $src->product_id, $src->tariff_version_id, ['total_minor' => 150000, 'premium_minor' => 150000, 'comparison_rank' => 1]);
    $row = collect($this->getJson('/api/v1/mobile/partner/agent/quotes', awH($t))->assertOk()->json('data'))->firstWhere('id', $quoteId);
    expect($row['assisted'])->toBeTrue()->and($row['best_premium_minor'])->toBe(150000);
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", [], awH($t))->assertOk()->assertJsonPath('data.payment_status', 'CUSTOMER_PROMPTED');
    $row = collect($this->getJson('/api/v1/mobile/partner/agent/quotes', awH($t))->json('data'))->firstWhere('id', $quoteId);
    expect($row['payment_status'])->toBe('CUSTOMER_PROMPTED');

    // /account/commissions
    $policy = makeMobileTestPolicy($chain['proposal'], $t, $chain['carrier']->id, $chain['party']->id);
    $accrual = makeMobileTestCommissionAccrual($t, $a['partner'], $policy);
    makeMobileTestCommissionAccrual($t, $b['partner'], $policy);
    expect(collect($this->getJson('/api/v1/mobile/agent/commissions', awH($t))->assertOk()->json('data'))->pluck('id')->all())->toBe([$accrual->id]);

    // Another agent sees none of it
    Passport::actingAs($b['user']);
    $this->getJson('/api/v1/mobile/partner/agent/leads', awH($t))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/mobile/partner/agent/leads/'.$lead['id'].'/activities', awH($t))->assertStatus(404);
    $this->postJson('/api/v1/mobile/partner/agent/leads/'.$lead['id'].'/activities', ['entry_type' => 'NOTE', 'body' => 'x'], awH($t))->assertStatus(404);
    expect(collect($this->getJson('/api/v1/mobile/agent/clients', awH($t))->assertOk()->json('data'))->pluck('id')->all())->not->toContain($client['id']);
    $this->getJson('/api/v1/mobile/agent/clients/'.$client['id'], awH($t))->assertForbidden();
    expect(collect($this->getJson('/api/v1/mobile/partner/agent/quotes', awH($t))->json('data'))->pluck('id')->all())->not->toContain($quoteId);
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", [], awH($t))->assertStatus(404);
});

it('renders the agent workspace controls on the /account pages in EN and FR', function () {
    $this->get('/account/leads')->assertOk()->assertSee('data-lead-form', false)->assertSee('/activities', false)->assertSee('Log activity');
    $this->get('/account/book')->assertOk()->assertSee('/payment-request', false)->assertSee('Request payment');
    $this->get('/account/book?lang=fr')->assertOk()->assertSee('Demander le paiement');
});
