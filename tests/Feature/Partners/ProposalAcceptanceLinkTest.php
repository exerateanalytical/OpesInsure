<?php

declare(strict_types=1);

/**
 * Owner fix 2026-09-30 (phase 1, W): contract terms are always the customer's own act.
 *  - agent sale for a client WITHOUT the app: the client gets a signed, expiring, single-use web link by SMS, proves the
 *    proposal's phone with an OTP, answers, attests and accepts; the acceptance is party-bound and the agent's next tap
 *    sends the payment prompt. The link never reaches the agent, and the agent cannot accept for the client;
 *  - every payment (web included, no X-App-Version) needs that acceptance; POST /proposals/{id}/terms is the customer's only.
 */

use App\Application\Notifications\Otp\SendOtpJob;
use App\Application\Underwriting\Proposal\ProposalDeclarations;
use App\Models\{InsuranceLine, PaymentIntentRecord, PaymentProviderConnection, Proposal, ProposalAcceptanceLink, ProposalDeclaration, Quote, QuoteOffer, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\{DB, Http, Queue};
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function palH($tenant): array
{
    return ['X-Tenant-Id' => $tenant->id, 'Idempotency-Key' => (string) Str::uuid()];
}

/** Agent + a client in the book WITHOUT any app account + a rated assisted-sale quote with one offer. */
function palSale($test, string $clientPhone, ?string $paymentPhone = null): array
{
    $test->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $a = makeMobileAgentFixture('+237680051001');
    $t = $a['tenant'];
    Passport::actingAs($a['user']);
    $lead = $test->postJson('/api/v1/mobile/partner/agent/leads', ['full_name' => 'Link Client', 'phone_e164' => $clientPhone, 'product_interest' => 'MOTOR'], palH($t))->assertStatus(201)->json('data');
    $client = $test->postJson("/api/v1/mobile/partner/agent/leads/{$lead['id']}/convert", ['consent_confirmed' => true], palH($t))->assertStatus(201)->json('data.client');
    $line = InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    DB::table('disclosure_schema_versions')->insert(['id' => (string) Str::uuid(), 'insurance_line_id' => $line->id, 'version' => 1, 'status' => 'APPROVED',
        'questions' => json_encode([['code' => 'prior_claims', 'label' => ['en' => 'Claims in the last 3 years?', 'fr' => 'Sinistres ?'], 'type' => 'boolean', 'required' => true]]),
        'schema_hash' => str_repeat('e', 64), 'effective_from' => '2026-01-01', 'created_by' => $a['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    $quoteId = $test->postJson('/api/v1/quotes', ['customer_id' => $client['id'], 'line_code' => 'AUTO', 'channel' => 'AGENT', 'risk_facts' => ['registration_number' => 'LT510AB', 'fiscal_power' => 7, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON']], palH($t))
        ->assertStatus(202)->json('data.id');
    $src = QuoteOffer::findOrFail(makeMobileFinanceProposalChain($t)['proposal']->quote_offer_id);
    $offer = makeMobileTestQuoteOffer(Quote::findOrFail($quoteId), $src->carrier_id, $src->product_id, $src->tariff_version_id, ['total_minor' => 150000, 'premium_minor' => 140000, 'comparison_rank' => 1]);
    $q = Quote::findOrFail($quoteId);
    $q->update(['lifecycle_state' => 'CALCULATED', 'status' => 'RATED', 'comparison_context' => array_merge($q->comparison_context ?? [], ['assisted_sale' => true, 'agent_user_id' => $a['user']->id, 'payment_phone_e164' => $paymentPhone ?? $clientPhone])]);

    return [$a, $t, $client, $quoteId, $offer];
}

function palSentLinks(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'api.twilio.com'))
        ->map(fn (HttpRequest $r) => preg_match('#(https?://[^\s&]+/account/accept/[A-Za-z0-9]+\?expires=\d+&signature=[0-9a-f]{64})#', urldecode((string) $r->body()), $m) ? $m[1] : null)->filter()->values()->all();
}

beforeEach(function () {
    config(['services.otp.delivery_mode' => 'queue']);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201), 'exp.host/*' => Http::response(['data' => []])]);
});

it('lets a client without the app accept by web link + OTP, then the agent sends the real payment prompt', function () {
    Queue::fake([SendOtpJob::class]);
    [$a, $t, $client, $quoteId, $offer] = palSale($this, '+237677051001');
    expect(User::where('party_id', $client['party_id'])->exists())->toBeFalse();

    // Tap 1: application opened; the client gets the acceptance link by SMS — the agent never sees it.
    $r = $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", ['offer_id' => $offer->id], palH($t))->assertOk();
    expect($r->json('data.step'))->toBe('APPLICATION_SENT')->and($r->json('data.next_action'))->toBe('AWAIT_CLIENT')
        ->and($r->json('data.client_acceptance.status'))->toBe('LINK_SENT')->and($r->json('data.client_acceptance.sent_now'))->toBeTrue()
        ->and($r->json('data.client_acceptance.phone_masked'))->not->toContain('051001')
        ->and($r->getContent())->not->toContain('/account/accept/');
    $pid = $r->json('data.proposal_id');
    $links = palSentLinks();
    expect($links)->toHaveCount(1);
    $url = $links[0];
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    // Tap 2 within 10 minutes: no second SMS.
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", [], palH($t))->assertOk()
        ->assertJsonPath('data.client_acceptance.sent_now', false)->assertJsonPath('data.step', 'REMINDER_RECENT');
    expect(palSentLinks())->toHaveCount(1);

    // The agent cannot accept for the client (app/web terms endpoint).
    $this->postJson("/api/v1/proposals/{$pid}/terms", ['accepted' => true], palH($t))->assertForbidden();

    // Tampered link: refused.
    $this->get(preg_replace('/signature=[0-9a-f]{8}/', 'signature=00000000', $path))->assertOk()->assertSee(__('acceptance.state.invalid_t'));

    // The client: OTP first — accepting before verifying is refused.
    $this->get($path)->assertOk()->assertSee(__('acceptance.verify_t'))->assertHeader('Referrer-Policy', 'no-referrer');
    $this->post($path, ['intent' => 'accept', 'terms' => '1', 'attest' => '1', 'answers' => ['prior_claims' => 'false']])->assertRedirect();
    expect(ProposalDeclaration::where('proposal_id', $pid)->exists())->toBeFalse();

    $this->post($path, ['intent' => 'send_code'])->assertRedirect()->assertSessionHas('acceptance_status');
    $code = Queue::pushed(SendOtpJob::class)->last()->code;
    expect(Queue::pushed(SendOtpJob::class)->last()->phoneE164)->toBe('+237677051001');
    $this->post($path, ['intent' => 'verify', 'code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    $this->post($path, ['intent' => 'verify', 'code' => $code])->assertSessionHasNoErrors();
    $this->get($path)->assertOk()->assertSee(__('acceptance.review_t'))->assertSee('Claims in the last 3 years?');

    // Terms must be ticked.
    $this->post($path, ['intent' => 'accept', 'attest' => '1', 'answers' => ['prior_claims' => 'false']])->assertSessionHasErrors('terms');
    $this->post($path, ['intent' => 'accept', 'terms' => '1', 'attest' => '1', 'answers' => ['prior_claims' => 'false']])->assertSessionHasNoErrors()->assertSessionHas('acceptance_done');

    // Recorded as the client's own (party-bound) acceptance, through the proposal service; straight-through → payable.
    $p = Proposal::findOrFail($pid);
    $customer = User::where('party_id', $client['party_id'])->sole();
    $terms = app(ProposalDeclarations::class)->acceptedByParty($p, 'TERMS_ACCEPTANCE');
    expect($p->status)->toBe('PAYMENT_PENDING')->and($customer->phone_e164)->toBe('+237677051001')->and($customer->phone_verified_at)->not->toBeNull()
        ->and($terms)->not->toBeNull()->and($terms->accepted_by)->toBe($customer->id)->and($terms->channel)->toBe('WEB_LINK')
        ->and($terms->evidence['acceptance_link_id'])->toBe(ProposalAcceptanceLink::where('proposal_id', $pid)->value('id'))
        ->and(ProposalDeclaration::where(['proposal_id' => $pid, 'code' => 'DISCLOSURE_ACCURACY', 'accepted_by' => $customer->id])->exists())->toBeTrue();

    // Single use.
    $this->get($path)->assertOk()->assertSee(__('acceptance.done_t'));
    $this->get($path)->assertOk()->assertSee(__('acceptance.state.accepted_t'));
    $this->post($path, ['intent' => 'send_code'])->assertSessionHasErrors('link');

    // The agent's next tap sends the operator prompt (MTN MoMo sandbox, faked).
    Passport::actingAs($a['user']);
    config(['payments.providers.mtn_momo' => array_merge(config('payments.providers.mtn_momo'), ['base_url' => 'https://momo.test', 'subscription_key' => 'sk', 'api_user' => 'u', 'api_key' => 'k', 'callback_token' => 'cbt'])]);
    Http::fake(['momo.test/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]), 'momo.test/collection/v1_0/requesttopay' => Http::response(null, 202)]);
    PaymentProviderConnection::create(['tenant_id' => $t->id, 'provider' => 'mtn_momo', 'environment' => 'PRODUCTION', 'status' => 'ACTIVE', 'credential_reference' => 'vault://momo', 'created_by' => $a['user']->id]);
    $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", ['provider' => 'mtn_momo'], palH($t))->assertOk()
        ->assertJsonPath('data.step', 'PAYMENT_PROMPTED')->assertJsonPath('data.client_acceptance.status', 'ACCEPTED')->assertJsonPath('data.payment_status', 'CUSTOMER_PROMPTED');
    expect(PaymentIntentRecord::where('proposal_id', $pid)->count())->toBe(1);
});

it('never sends the acceptance link to the agent\'s own phone and expires links', function () {
    [$a, $t, $client, $quoteId, $offer] = palSale($this, '+237677051002', '+237680051001'); // payment phone = the agent's OWN number

    DB::table('party_contacts')->where('party_id', $client['party_id'])->delete();
    $r = $this->postJson("/api/v1/mobile/agent/sales/{$quoteId}/payment-request", ['offer_id' => $offer->id], palH($t))->assertOk();
    expect($r->json('data.client_acceptance.status'))->toBe('NO_PHONE')->and(palSentLinks())->toBe([]);

    // An expired link shows the expired page and cannot send codes.
    $token = Str::random(48);
    $link = ProposalAcceptanceLink::create(['tenant_id' => $t->id, 'proposal_id' => $r->json('data.proposal_id'), 'party_id' => $client['party_id'], 'phone_e164' => '+237677051009',
        'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinute(), 'created_by' => $a['user']->id]);
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('public.acceptance.show', now()->addMinute(), ['token' => $token]);
    $this->travel(2)->minutes();
    $this->get($url)->assertOk()->assertSee(__('acceptance.state.expired_t'));
    expect($link->refresh()->usable())->toBeFalse();
});

it('requires the customer\'s own terms acceptance for every payment, website included', function () {
    $f = makeMobileCustomerFixture('+237672051001');
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'attested_at' => now(), 'terms_snapshot' => ['premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);
    $body = fn () => ['proposal_id' => $f['proposal']->id, 'provider' => 'mtn_momo', 'payer_phone_e164' => '+237672051001', 'idempotency_key' => 'web-'.Str::uuid()];

    // Website checkout (no X-App-Version): refused until the customer accepts.
    $this->postJson('/api/v1/payments', $body(), $h)->assertStatus(422)->assertJsonPath('code', 'TERMS_NOT_ACCEPTED');
    $this->getJson("/api/v1/proposals/{$f['proposal']->id}", $h)->assertOk()->assertJsonPath('data.terms.accepted', false);

    // Someone else's acceptance (e.g. staff) never counts.
    $staff = User::create(['full_name' => 'Staff', 'phone_e164' => '+237672051099', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    app(ProposalDeclarations::class)->accept($f['proposal'], 'TERMS_ACCEPTANCE', $staff, 'API');
    $this->postJson('/api/v1/payments', $body(), $h)->assertStatus(422)->assertJsonPath('code', 'TERMS_NOT_ACCEPTED');

    // The customer accepts on the review/checkout page (same endpoint as the app) → payment request created.
    $this->postJson("/api/v1/proposals/{$f['proposal']->id}/terms", ['accepted' => true], $h)->assertOk();
    $this->getJson("/api/v1/proposals/{$f['proposal']->id}", $h)->assertOk()->assertJsonPath('data.terms.accepted', true);
    $this->postJson('/api/v1/payments', $body(), $h)->assertStatus(201)->assertJsonPath('data.status', 'PENDING_CUSTOMER');

    // The web pages record the acceptance before paying.
    $this->get('/account/payments/new')->assertOk()->assertSee("/terms', { body: { accepted: true } }", false);
});
