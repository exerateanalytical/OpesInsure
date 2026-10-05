<?php

declare(strict_types=1);

/**
 * Owner decision 2026-09-29 (portals writable, docs/spec/PORTAL_WRITE_RULES.md): a broker staff member takes a new
 * customer from quote to issued policy entirely in /broker — new customer, quote rated only with insurers the
 * brokerage has an ACTIVE agreement with, offers compared, proposal (disclosures, attestation, submit), premium
 * payment request (test provider), receipt, issued policy in the book. Another brokerage's customer is neither
 * visible nor actionable.
 */

use App\Application\Identity\RoleCatalogue;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Application\Rules\Models\QuestionSet;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\{PartyActions, PaymentActions, ProposalActions, QuoteActions};
use App\Filament\Shared\Pages\BrokerCustomersPage;
use App\Models\{Carrier, InsuranceLine, InsuranceProduct, Partner, Party, PaymentIntentRecord, Policy, PolicyIssuanceRequest, Proposal, Quote, Role, Tenant, TenantCustomer, TenantMembership, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Storage};
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function bpsUser(Tenant $t, string $role, ?string $partyId = null): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $partyId]);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $t->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

function bpsBrokerage(Tenant $t, string $name): Partner
{
    return Partner::create(['tenant_id' => $t->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
}

/** Acts as $user inside the /broker panel of $t (what the panel middleware sets up). */
function bpsIn(User $user, Tenant $t): void
{
    test()->actingAs($user, 'web');
    auth()->shouldUse('web');
    Filament::setCurrentPanel(Filament::getPanel('broker'));
    app(TenantContext::class)->set($t->id);
}

function bpsAct(array $actions, ?object $record = null)
{
    app(TenantContext::class)->set(test()->t->id); // an HTTP request in between clears it
    Filament::setCurrentPanel(Filament::getPanel('broker'));
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

function bpsTariff(Tenant $t, User $maker, User $checker, string $productId, int $ratePpm): void
{
    $as = function (User $u, string $method, string $uri, array $body = []) use ($t) {
        Passport::actingAs($u);

        return test()->json($method, '/api/v1/'.$uri, $body, ['X-Tenant-Id' => $t->id]);
    };
    $id = $as($maker, 'POST', 'tariffs', ['insurance_product_id' => $productId, 'effective_from' => '2026-01-01', 'input_schema' => ['usage' => 'string'], 'regulatory_reference' => 'DEMO-UNVERIFIED',
        'rules' => ['required_facts' => ['usage'], 'base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'vehicle_value', 'rate_ppm' => $ratePpm], 'rounding' => ['unit_minor' => 100, 'mode' => 'HALF_UP'],
            'branch_allocation' => [['branch_code' => 'RC_AUTO', 'basis_points' => 10000]]]])->assertCreated()->json('data.id');
    $as($maker, 'POST', "tariffs/{$id}/submit", ['notes' => 'Ready for technical review.'])->assertOk();
    $as($checker, 'POST', "tariffs/{$id}/approve", ['reason' => 'Actuarial review completed and signed off.'])->assertOk();
    $as($checker, 'POST', "tariffs/{$id}/schedule", ['notes' => 'Publish on the effective date.'])->assertOk();
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]]), 'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));

    $this->t = Tenant::create(['type' => 'BROKER', 'legal_name' => 'BPS Broker '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $this->brokerA = bpsBrokerage($this->t, 'Cabinet A');
    $this->brokerB = bpsBrokerage($this->t, 'Cabinet B');
    $this->staffA = bpsUser($this->t, 'BROKER_STAFF', $this->brokerA->party_id);
    $this->staffB = bpsUser($this->t, 'BROKER_STAFF', $this->brokerB->party_id);

    InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => 'Motor', 'status' => 'ACTIVE', 'risk_schema' => ['required' => []]]);
    InsuranceLine::where('code', 'MOTOR')->update(['status' => 'ACTIVE']);
    $maker = makeAuthTestUser($this->t, ['tariff.manage'], 'BPS_MAKER');
    $checker = makeAuthTestUser($this->t, ['tariff.manage', 'tariff.approve', 'tariff.publish'], 'BPS_CHECKER');

    // Two insurers rate the MOTOR line; only Alpha has an ACTIVE agreement with brokerage A.
    $this->products = [];
    foreach (['Alpha' => 20000, 'Beta' => 15000] as $name => $ppm) {
        $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => "{$name} Assurances", 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
        $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'BPS-'.$name, 'name' => "{$name} Motor", 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE']);
        bpsTariff($this->t, $maker, $checker, $product->id, $ppm);
        $set = QuestionSet::create(['scope_type' => 'PRODUCT_VERSION', 'insurance_product_id' => $product->id, 'line_code' => 'MOTOR', 'stage' => 'PROPOSAL', 'version' => 1,
            'status' => 'APPROVED', 'source' => 'MANUAL', 'schema_version' => 1, 'presentation' => ['steps' => [['key' => 'declarations', 'label' => 'Declarations']]],
            'schema_hash' => str_repeat('c', 64), 'effective_from' => '2026-01-01', 'approved_at' => now()]);
        $field = ['key' => 'prior_claims', 'label' => 'Claims in the last 3 years?', 'type' => 'boolean', 'required' => true, 'step' => 'declarations', 'referral_values' => [true], 'referral_code' => 'PRIOR_CLAIMS'];
        DB::table('product_questions')->insert(['id' => (string) Str::uuid(), 'question_set_id' => $set->id, 'code' => 'prior_claims', 'question_type' => 'BOOLEAN', 'input_type' => 'boolean',
            'label_en' => $field['label'], 'display_order' => 1, 'required' => true, 'validation' => '{}', 'fact_key' => 'prior_claims', 'rendered_field' => json_encode($field), 'created_at' => now(), 'updated_at' => now()]);
        $this->products[$name] = $product;
    }
    $agreement = (string) Str::uuid();
    DB::table('carrier_broker_agreements')->insert(['id' => $agreement, 'carrier_id' => $this->products['Alpha']->carrier_id, 'partner_id' => $this->brokerA->id, 'agreement_number' => 'AGR-BPS-A',
        'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'territories' => '[]', 'channels' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_broker_agreement_products')->insert(['id' => (string) Str::uuid(), 'agreement_id' => $agreement, 'line_code' => 'MOTOR', 'can_quote' => true, 'can_bind' => true,
        'can_collect_premium' => true, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
});

it('takes a new customer from quote to issued policy entirely in /broker, quoting only agreed insurers', function () {
    bpsIn($this->staffA, $this->t);
    $r = $this->get('/broker/customers');
    $r->assertOk()->assertSee(__('broker_portal_sales.customers.title'));

    // 1. New customer (POST mobile/broker/clients service), attributed to brokerage A.
    bpsAct([fn () => PartyActions::registerBrokerClient()])
        ->callAction('brokerRegisterClient', ['full_name' => 'Awa Ngono', 'phone_e164' => '+237677001122', 'consent_reference' => 'FORM-2026-001', 'consent_confirmed' => true])
        ;
    $customer = BrokerCustomersPage::customers($this->t->id)->firstOrFail();
    expect($customer->party->display_name)->toBe('Awa Ngono');

    // 2. Quote + rating: only Alpha (ACTIVE agreement) is offered, Beta (cheaper, no agreement) is not.
    bpsAct([fn () => QuoteActions::createForCustomer()], $customer)
        ->callAction('brokerNewQuote', ['line_code' => 'MOTOR', 'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => '5000000']])
        ;
    $quote = Quote::where('party_id', $customer->party_id)->firstOrFail();
    $offers = $quote->offers()->get();
    expect($quote->channel)->toBe('BROKER')->and($quote->partner_id)->toBe($this->brokerA->id)
        ->and($offers->pluck('product_id')->all())->toBe([$this->products['Alpha']->id]);
    $this->get("/broker/quotes/{$quote->id}")->assertOk()->assertSee(__('broker_portal_sales.offers.title'))->assertSee('Alpha Assurances');

    // 3. Proposal: convert the offer, answer the disclosures, attest, submit (straight through → payment pending).
    bpsAct([fn () => QuoteActions::convertToProposal()], $quote)->callAction('quoteConvert', ['offer_id' => $offers->first()->id]);
    $proposal = Proposal::where('party_id', $customer->party_id)->firstOrFail();
    bpsAct([fn () => ProposalActions::disclosureAnswers()], $proposal)->callAction('proposalDisclosureAnswers', ['answers' => ['prior_claims' => 'false']]);
    bpsAct([fn () => ProposalActions::attest()], $proposal->refresh())->callAction('proposalAttest');
    bpsAct([fn () => ProposalActions::submit()], $proposal->refresh())->callAction('proposalSubmit');
    expect($proposal->refresh()->status)->toBe('PAYMENT_PENDING');
    $this->get("/broker/proposals/{$proposal->id}")->assertOk()->assertSee(__('broker_portal_sales.brokerRequestPremium.label'));

    // 4. Premium collection needs the CUSTOMER's own terms acceptance (owner rule 2026-09-30): the first request only sends
    //    the customer the web acceptance link by SMS (to their phone on file); once they accepted, the request goes out.
    bpsAct([fn () => PaymentActions::requestPremium()], $proposal)->callAction('brokerRequestPremium', ['provider' => 'fake', 'payer_phone_e164' => '+237677001199']);
    expect(PaymentIntentRecord::where('proposal_id', $proposal->id)->exists())->toBeFalse()
        ->and(\App\Models\ProposalAcceptanceLink::where(['proposal_id' => $proposal->id, 'phone_e164' => '+237677001122'])->exists())->toBeTrue();
    $awa = User::create(['full_name' => 'Awa Ngono', 'phone_e164' => '+237677001122', 'party_id' => $customer->party_id, 'password' => 'x', 'locale' => 'fr', 'status' => 'ACTIVE']);
    app(\App\Application\Underwriting\Proposal\ProposalDeclarations::class)->accept($proposal->refresh(), 'TERMS_ACCEPTANCE', $awa, 'WEB_LINK');
    // Premium collection in test mode; the provider webhook confirms it and opens the carrier issuance request.
    bpsAct([fn () => PaymentActions::requestPremium()], $proposal)->callAction('brokerRequestPremium', ['provider' => 'fake', 'payer_phone_e164' => '+237677001122']);
    $payment = PaymentIntentRecord::where('proposal_id', $proposal->id)->firstOrFail();
    expect($payment->provider_reference)->not->toBeNull()->and($payment->amount_minor)->toBe((int) $offers->first()->total_minor);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), ['payment_reference' => $payment->provider_reference, 'amount_minor' => $payment->amount_minor,
        'currency' => $payment->currency, 'status' => 'SUCCEEDED'], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $proposal->id)->firstOrFail();

    // 5. The insurer issues (its own authority); the policy lands in the broker's book with its documents and receipt.
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $policy = app(PolicyIssuanceService::class)->approve($request, ['policy_number' => 'POL-BPS-1', 'carrier_reference' => 'CR-1'], $approver);
    bpsIn($this->staffA, $this->t);
    $this->get('/broker/policies')->assertOk()->assertSee($policy->policy_number);
    bpsIn($this->staffA, $this->t);
    expect(\App\Filament\Admin\Resources\Policies\PolicyResource::getEloquentQuery()->pluck('policy_number')->all())->toBe([$policy->policy_number]);
    $this->get("/broker/policies/{$policy->id}")->assertOk()->assertSee($policy->policy_number);
    bpsAct([fn () => PaymentActions::premiumReceipt()], $proposal->refresh())->assertActionVisible('brokerPremiumReceipt');
    expect(Policy::where('party_id', $customer->party_id)->value('status'))->toBe('ACTIVE');
});

it('keeps another brokerage\'s customers out of sight and out of reach', function () {
    // Brokerage B registers its own customer and quote.
    bpsIn($this->staffB, $this->t);
    bpsAct([fn () => PartyActions::registerBrokerClient()])
        ->callAction('brokerRegisterClient', ['full_name' => 'Client De B', 'phone_e164' => '+237677009988', 'consent_reference' => 'FORM-B-1', 'consent_confirmed' => true])
        ;
    $theirs = BrokerCustomersPage::customers($this->t->id)->firstOrFail();
    $theirQuote = Quote::create(['tenant_id' => $this->t->id, 'party_id' => $theirs->party_id, 'line_code' => 'MOTOR', 'channel' => 'BROKER', 'status' => 'SUBMITTED', 'currency' => 'XAF', 'risk_facts' => []]);

    bpsIn($this->staffA, $this->t);
    expect(BrokerCustomersPage::customers($this->t->id)->pluck('id')->all())->toBe([]);
    $this->get('/broker/customers')->assertOk()->assertDontSee('Client De B');
    $this->get("/broker/quotes/{$theirQuote->id}")->assertNotFound();

    // Quoting for their customer is refused (partner book, as the API) and creates nothing.
    bpsAct([fn () => QuoteActions::createForCustomer()], TenantCustomer::findOrFail($theirs->id))
        ->callAction('brokerNewQuote', ['line_code' => 'MOTOR', 'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => '5000000']]);
    expect(Quote::where('party_id', $theirs->party_id)->count())->toBe(1);
    // Brokerage B has no agreement: no line to quote at all.
    bpsIn($this->staffB, $this->t);
    bpsAct([fn () => QuoteActions::createForCustomer()], TenantCustomer::findOrFail($theirs->id))
        ->callAction('brokerNewQuote', ['line_code' => 'MOTOR', 'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => '5000000']]);
    expect(Quote::where('party_id', $theirs->party_id)->count())->toBe(1);
});
