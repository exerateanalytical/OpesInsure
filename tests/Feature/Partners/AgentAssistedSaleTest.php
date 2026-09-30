<?php

declare(strict_types=1);

/**
 * Launch fix D (2026-09-30): the agent's assisted sale runs on the customer purchase rails (AssistedSaleService).
 *  - no invented risk facts: risk_facts are required and validated by QuoteService like POST /quotes;
 *  - "Send payment request" opens the client's application, waits for the CLIENT's own terms acceptance, then sends
 *    ONE real mobile-money request (operator prompt) — repeated taps never create a second one;
 *  - commission comes from the configured commission rule, never a hard-coded 10%.
 */

use App\Application\Agents\AssistedSaleService;
use App\Application\Underwriting\Proposal\ProposalDeclarations;
use App\Models\{InsuranceLine, PaymentIntentRecord, PaymentProviderConnection, Proposal, Quote, QuoteOffer, User, UserNotification};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function agSaleH($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/** Agent + a client in the agent's book (lead → convert) + an AUTO line with a PROPOSAL question set. */
function agSaleSetup($test): array
{
    $test->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $a = makeMobileAgentFixture('+237680041001');
    $t = $a['tenant'];
    Passport::actingAs($a['user']);
    $lead = $test->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Assisted Sale Client', 'phone_e164' => '+237677041001', 'product_interest' => 'MOTOR'], agSaleH($t))->assertStatus(201)->json('data');
    $client = $test->postJson("/api/v1/mobile/partner/agent/leads/{$lead['id']}/convert", ['consent_confirmed' => true], agSaleH($t))->assertStatus(201)->json('data.client');
    $line = InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    DB::table('disclosure_schema_versions')->insert(['id' => (string) Str::uuid(), 'insurance_line_id' => $line->id, 'version' => 1, 'status' => 'APPROVED',
        'questions' => json_encode([['code' => 'prior_claims', 'label' => ['en' => 'Claims in the last 3 years?', 'fr' => 'Sinistres ?'], 'type' => 'boolean', 'required' => true]]),
        'schema_hash' => str_repeat('d', 64), 'effective_from' => '2026-01-01', 'created_by' => $a['user']->id, 'created_at' => now(), 'updated_at' => now()]);

    return [$a, $t, $client];
}

it('refuses an assisted sale without the client\'s real risk facts or for an unknown product', function () {
    [$a, $t, $client] = agSaleSetup($this);

    $this->postJson('/api/v1/mobile/agent/sales', ['customer_id' => $client['id'], 'product' => 'AUTO', 'payment_phone_e164' => '+237677041001'], agSaleH($t))
        ->assertStatus(422)->assertJsonValidationErrors('risk_facts');
    $this->postJson('/api/v1/mobile/agent/sales', ['customer_id' => $client['id'], 'product' => 'NOPE', 'payment_phone_e164' => '+237677041001', 'risk_facts' => ['a' => 1]], agSaleH($t))
        ->assertStatus(422)->assertJsonValidationErrors('product');
    $this->postJson('/api/v1/mobile/agent/sales', ['customer_id' => $client['id'], 'product' => 'AUTO', 'payment_phone_e164' => 'not-a-phone', 'risk_facts' => ['a' => 1]], agSaleH($t))
        ->assertStatus(422)->assertJsonValidationErrors('payment_phone_e164');
    expect(Quote::where('party_id', $client['party_id'])->exists())->toBeFalse();
});

it('opens the application, waits for the client\'s own terms acceptance, then sends one real mobile-money request', function () {
    [$a, $t, $client] = agSaleSetup($this);
    $clientUser = User::create(['full_name' => 'Assisted Sale Client', 'phone_e164' => '+237677041001', 'party_id' => $client['party_id'], 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    $quoteId = $this->postJson('/api/v1/quotes', ['customer_id' => $client['id'], 'line_code' => 'AUTO', 'channel' => 'AGENT', 'risk_facts' => ['registration_number' => 'LT410AB', 'fiscal_power' => 7, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON']], agSaleH($t))
        ->assertStatus(202)->json('data.id');
    $src = QuoteOffer::findOrFail(makeMobileFinanceProposalChain($t)['proposal']->quote_offer_id);
    $offer = makeMobileTestQuoteOffer(Quote::findOrFail($quoteId), $src->carrier_id, $src->product_id, $src->tariff_version_id, ['total_minor' => 150000, 'premium_minor' => 140000, 'comparison_rank' => 1]);
    Quote::whereKey($quoteId)->update(['lifecycle_state' => 'CALCULATED', 'status' => 'RATED']);

    // The real premium and the offer are on the sale before anything is sent; no invented 10% commission.
    $sale = $this->getJson("/api/v1/mobile/agent/sales/{$quoteId}", agSaleH($t))->assertOk()->json('data');
    expect($sale['premium_minor'])->toBe(150000)->and($sale['next_action'])->toBe('SEND_TO_CLIENT')->and($sale['offers'][0]['id'])->toBe($offer->id)
        ->and($sale['commission_basis'])->toBe('NOT_CONFIGURED')->and($sale['commission_minor'])->toBeNull();

    // Tap 1: the client's application is opened from the chosen offer and the client is asked to finish it.
    $sale = $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", ['offer_id' => $offer->id], agSaleH($t))->assertOk()->json('data');
    $pid = $sale['proposal_id'];
    expect($pid)->not->toBeNull()->and($sale['next_action'])->toBe('AWAIT_CLIENT')->and($sale['payment_status'])->toBe('NOT_REQUESTED');
    expect(Proposal::findOrFail($pid)->party_id)->toBe($client['party_id']);
    expect(UserNotification::where('user_id', $clientUser->id)->where('path', "/proposals/{$pid}")->exists())->toBeTrue();

    // Tap 2: same application, no payment while the client has not accepted the terms.
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", [], agSaleH($t))->assertOk()->assertJsonPath('data.proposal_id', $pid);
    expect(Proposal::where('quote_offer_id', $offer->id)->count())->toBe(1);

    // Approved, but the AGENT accepting the terms never stands in for the client.
    Proposal::whereKey($pid)->update(['status' => 'PAYMENT_PENDING']);
    app(ProposalDeclarations::class)->accept(Proposal::findOrFail($pid), 'TERMS_ACCEPTANCE', $a['user'], 'MOBILE');
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", [], agSaleH($t))->assertOk()->assertJsonPath('data.next_action', 'AWAIT_CLIENT');
    expect(PaymentIntentRecord::where('proposal_id', $pid)->exists())->toBeFalse();

    // The client accepts in their own app → the next tap sends the operator prompt (MTN MoMo, sandbox faked).
    app(ProposalDeclarations::class)->accept(Proposal::findOrFail($pid), 'TERMS_ACCEPTANCE', $clientUser, 'MOBILE');
    config(['payments.providers.mtn_momo' => array_merge(config('payments.providers.mtn_momo'), ['base_url' => 'https://momo.test', 'subscription_key' => 'sk', 'api_user' => 'u', 'api_key' => 'k', 'callback_token' => 'cbt'])]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201), 'exp.host/*' => Http::response(['data' => []]),
        'momo.test/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]), 'momo.test/collection/v1_0/requesttopay' => Http::response(null, 202)]);
    PaymentProviderConnection::create(['tenant_id' => $t->id, 'provider' => 'mtn_momo', 'environment' => 'PRODUCTION', 'status' => 'ACTIVE', 'credential_reference' => 'vault://momo', 'created_by' => $a['user']->id]);
    $sale = $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", ['provider' => 'mtn_momo', 'payment_phone_e164' => '+237677041001'], agSaleH($t))->assertOk()->json('data');
    expect($sale['payment_status'])->toBe('CUSTOMER_PROMPTED')->and($sale['next_action'])->toBe('AWAIT_PAYMENT')->and($sale['payment']['provider'])->toBe('mtn_momo');
    $intent = PaymentIntentRecord::where('proposal_id', $pid)->sole();
    expect($intent->amount_minor)->toBe(150000)->and($intent->provider_reference)->not->toBeNull()->and($intent->requested_by)->toBe($a['user']->id);

    // Repeated taps: still exactly one payment request, the prompt is not re-sent.
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", ['provider' => 'mtn_momo'], agSaleH($t))->assertOk()->assertJsonPath('data.payment.id', $intent->id);
    expect(PaymentIntentRecord::where('proposal_id', $pid)->count())->toBe(1)->and($intent->attempts()->count())->toBe(1);
});

it('infers the Cameroon mobile-money operator from the number', function () {
    expect(AssistedSaleService::providerForPhone('+237677000000'))->toBe('mtn_momo')
        ->and(AssistedSaleService::providerForPhone('+237650000000'))->toBe('mtn_momo')
        ->and(AssistedSaleService::providerForPhone('+237690000000'))->toBe('orange_money')
        ->and(AssistedSaleService::providerForPhone('+237655000000'))->toBe('orange_money')
        ->and(AssistedSaleService::providerForPhone('+33612345678'))->toBeNull();
});
