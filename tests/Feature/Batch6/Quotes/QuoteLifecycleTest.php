<?php

declare(strict_types=1);

/**
 * Batch 6B — REQ-QUO-001 REQ-QUO-002 REQ-QUO-003 REQ-QUO-004 REQ-QUO-005 REQ-DST-003 REQ-DUP-007:
 * quote machine on the shared StateMachineEngine, validity/expiry, quote risks + answers, numbering,
 * generate/send/view/decline, premium override (maker-checker), comparisons, mobile adapter compatibility.
 */

use App\Application\Quotes\QuoteMachine;
use App\Application\Quotes\QuoteService;
use App\Models\Carrier;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\TenantCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const Q6B_RULES = [
    'required_facts' => ['usage'],
    'base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'vehicle_value', 'rate_ppm' => 20000],
    'rounding' => ['unit_minor' => 100, 'mode' => 'HALF_UP'],
    'branch_allocation' => [['branch_code' => 'RC_AUTO', 'basis_points' => 10000]],
];

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    $this->fx = makeMobileCustomerFixture('+237671116600');
    $this->tenant = $this->fx['tenant'];
    InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => 'Motor', 'status' => 'ACTIVE', 'risk_schema' => ['required' => []]]);
    InsuranceLine::where('code', 'MOTOR')->update(['status' => 'ACTIVE']);
    TenantCustomer::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->fx['party']->id, 'customer_number' => 'C-'.Str::random(6), 'status' => 'ACTIVE']);
    $this->maker = makeAuthTestUser($this->tenant, ['tariff.manage', 'quotes.rate', 'quotes.read', 'quotes.manage', 'quotes.send', 'quotes.premium_override.request'], 'Q6B_MAKER');
    $this->checker = makeAuthTestUser($this->tenant, ['tariff.manage', 'tariff.approve', 'tariff.publish', 'quotes.read', 'quotes.premium_override.approve'], 'Q6B_CHECKER');
    $this->products = [];
    foreach (['Alpha', 'Beta'] as $i => $name) {
        $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => "{$name} Assurances", 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
        $p = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'Q6B-'.$name, 'name' => "{$name} Motor", 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE']);
        $p->forceFill(['quote_validity_days' => $i === 0 ? 14 : 30])->save();
        $this->products[] = $p;
        q6bTariff($p->id, [...Q6B_RULES, 'base' => [...Q6B_RULES['base'], 'rate_ppm' => 20000 + $i * 5000]]);
    }
});

function q6bAs($user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/'.$uri, $body, tenantHeaderFor(test()->tenant));
}

function q6bTariff(string $productId, array $rules): void
{
    $id = q6bAs(test()->maker, 'POST', 'tariffs', ['insurance_product_id' => $productId, 'effective_from' => '2026-01-01', 'input_schema' => ['usage' => 'string'], 'rules' => $rules, 'regulatory_reference' => 'DEMO-UNVERIFIED'])->assertCreated()->json('data.id');
    q6bAs(test()->maker, 'POST', "tariffs/{$id}/submit", ['notes' => 'Ready for technical review.'])->assertOk();
    q6bAs(test()->checker, 'POST', "tariffs/{$id}/approve", ['reason' => 'Actuarial review completed and signed off.'])->assertOk();
    q6bAs(test()->checker, 'POST', "tariffs/{$id}/schedule", ['notes' => 'Publish on the effective date.'])->assertOk();
}

/** Customer submits through the existing POST /quotes, then rates through the existing route. */
function q6bRatedQuote(): Quote
{
    $id = q6bAs(test()->fx['user'], 'POST', 'quotes', ['customer_id' => test()->fx['party']->id, 'line_code' => 'MOTOR', 'channel' => 'B2C', 'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 5000000]])
        ->assertStatus(202)->json('data.id');
    q6bAs(test()->maker, 'POST', "quotes/{$id}/rate")->assertOk();

    return Quote::findOrFail($id);
}

it('REQ-QUO-001 REQ-QUO-003 submits a numbered DRAFT quote with its risk and answers, then rates it to CALCULATED on the shared engine', function () {
    $id = q6bAs($this->fx['user'], 'POST', 'quotes', ['customer_id' => $this->fx['party']->id, 'line_code' => 'MOTOR', 'channel' => 'B2C', 'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 5000000]])
        ->assertStatus(202)->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.lifecycle_state', 'DRAFT')->json('data.id');
    $quote = Quote::find($id);
    expect($quote->quote_number)->toStartWith(app(\App\Application\Documents\Engine\DocumentNumberAllocator::class)->config($this->tenant->id, app(\App\Application\Documents\Engine\DocumentNumberAllocator::class)->familyFor($this->tenant->id, 'INSURANCE_QUOTE'))['prefix']);
    expect(DB::table('quote_risks')->where('quote_id', $id)->count())->toBe(1)
        ->and(DB::table('quote_answers')->where('quote_id', $id)->value('answers_hash'))->toHaveLength(64);

    q6bAs($this->maker, 'POST', "quotes/{$id}/rate")->assertOk()->assertJsonPath('data.quote.status', 'OFFERED')->assertJsonPath('data.quote.lifecycle_state', 'CALCULATED');
    $events = DB::table('workflow_transition_history')->where(['machine' => 'quote', 'subject_id' => $id])->orderBy('occurred_at')->pluck('to_state')->all();
    expect($events)->toBe(['RATING', 'CALCULATED']);

    // REQ-QUO-002: validity per product version (14 and 30 days); the quote lives as long as its longest offer.
    $offers = QuoteOffer::where('quote_id', $id)->orderBy('comparison_rank')->get();
    expect($offers)->toHaveCount(2)
        ->and($offers->pluck('valid_until')->map->toDateString()->sort()->values()->all())->toBe(['2026-10-24', '2026-11-09'])
        ->and(Quote::find($id)->expires_at->toDateString())->toBe('2026-11-09')
        ->and($offers[0]->sellability)->toHaveKey('sellable');

    q6bAs($this->maker, 'GET', "quotes/{$id}/history")->assertOk()->assertJsonPath('data.state', 'CALCULATED');
});

it('REQ-QUO-001 refuses invalid transitions and keeps legacy rows working (status projection)', function () {
    $legacy = Quote::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->fx['party']->id, 'line_code' => 'MOTOR', 'status' => 'ACCEPTED', 'currency' => 'XAF', 'risk_facts' => []]);
    expect(QuoteMachine::stateOf($legacy))->toBe('ACCEPTED');
    q6bAs($this->maker, 'POST', "quotes/{$legacy->id}/decline", ['reason_code' => 'LOST_TO_COMPETITOR'])->assertStatus(422);
    q6bAs($this->maker, 'POST', "quotes/{$legacy->id}/generate")->assertStatus(422);
    expect(QuoteMachine::definition()->transitionFor('DRAFT', 'accept'))->toBeNull();
});

it('REQ-QUO-004 generates, sends a tracked share, records the view, and serves the PDF', function () {
    $quote = q6bRatedQuote();
    q6bAs($this->fx['user'], 'POST', "quotes/{$quote->id}/send", ['channel' => 'EMAIL'])->assertForbidden(); // customers do not send
    $sent = q6bAs($this->maker, 'POST', "quotes/{$quote->id}/send", ['channel' => 'EMAIL', 'recipient' => 'client@example.test'])->assertCreated();
    expect($sent->json('data.quote.lifecycle_state'))->toBe('SENT')->and(Quote::find($quote->id)->generated_at)->not->toBeNull();
    $token = $sent->json('data.token');
    expect(DB::table('quote_shares')->where('quote_id', $quote->id)->value('token_hash'))->toBe(hash('sha256', $token));

    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/quote-shares/{$token}")->assertOk()->assertJsonCount(2, 'data.offers')->assertJsonPath('data.state', 'VIEWED');
    expect(DB::table('quote_shares')->where('quote_id', $quote->id)->value('view_count'))->toBe(1);
    $this->getJson('/api/v1/quote-shares/'.Str::random(48))->assertNotFound();

    $pdf = q6bAs($this->fx['user'], 'GET', "quotes/{$quote->id}/document")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');
});

it('REQ-QUO-001 WF-014 declines as lost, and a customer accepts on the existing accept route (viewed first in the app)', function () {
    $lost = q6bRatedQuote();
    q6bAs($this->maker, 'POST', "quotes/{$lost->id}/decline", ['reason_code' => 'LOST_TO_COMPETITOR'])->assertOk()->assertJsonPath('data.lifecycle_state', 'DECLINED')->assertJsonPath('data.status', 'DECLINED');

    $quote = q6bRatedQuote();
    q6bAs($this->maker, 'POST', "quotes/{$quote->id}/generate")->assertOk();
    q6bAs($this->fx['user'], 'GET', "mobile/quotes/{$quote->id}")->assertOk()->assertJsonPath('data.quote.lifecycle_state', 'VIEWED')->assertJsonPath('data.quote.status', 'OFFERED');
    $offer = QuoteOffer::where('quote_id', $quote->id)->orderBy('comparison_rank')->first();
    q6bAs($this->fx['user'], 'POST', "quotes/{$quote->id}/offers/{$offer->id}/accept")->assertOk();
    expect(Quote::find($quote->id))->lifecycle_state->toBe('ACCEPTED')->status->toBe('ACCEPTED');
});

it('REQ-QUO-002 expires open quotes through quotes:expire (QUOTE_EXPIRED), including legacy rows', function () {
    $quote = q6bRatedQuote();
    $legacy = Quote::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->fx['party']->id, 'line_code' => 'MOTOR', 'status' => 'OFFERED', 'currency' => 'XAF', 'risk_facts' => [], 'expires_at' => now()->subHour()]);
    $this->travelTo(now()->addDays(31));
    Artisan::call('quotes:expire');
    expect(Quote::find($quote->id))->lifecycle_state->toBe('EXPIRED')->status->toBe('EXPIRED')
        ->and(Quote::find($legacy->id)->status)->toBe('EXPIRED')
        ->and(QuoteOffer::where('quote_id', $quote->id)->pluck('status')->unique()->all())->toBe(['EXPIRED'])
        ->and(DB::table('outbox_messages')->where(['event_name' => 'quote.expired', 'aggregate_id' => $quote->id])->exists())->toBeTrue();
    q6bAs($this->maker, 'POST', "quotes/{$quote->id}/rate")->assertStatus(422);
});

it('REQ-QUO-005 applies a premium override only after a different user approves it, with permanent audit', function () {
    $quote = q6bRatedQuote();
    $offer = QuoteOffer::where('quote_id', $quote->id)->orderBy('comparison_rank')->first();
    $rated = (int) $offer->premium_minor;
    $o = q6bAs($this->maker, 'POST', "quotes/{$quote->id}/offers/{$offer->id}/premium-overrides", ['premium_minor' => $rated - 10000, 'reason_code' => 'COMMERCIAL_DISCOUNT', 'justification' => 'Fleet customer retention discount.'])
        ->assertCreated()->json('data');
    expect($o['status'])->toBe('REQUESTED')->and((int) $offer->fresh()->premium_minor)->toBe($rated);

    // Pending override blocks acceptance; the maker (no approve permission) cannot decide.
    q6bAs($this->fx['user'], 'POST', "quotes/{$quote->id}/offers/{$offer->id}/accept")->assertStatus(422);
    q6bAs($this->maker, 'POST', "quotes/{$quote->id}/offers/{$offer->id}/premium-overrides/{$o['id']}/decision", ['decision' => 'APPROVED'])->assertForbidden();

    q6bAs($this->checker, 'POST', "quotes/{$quote->id}/offers/{$offer->id}/premium-overrides/{$o['id']}/decision", ['decision' => 'APPROVED', 'note' => 'Within commercial authority.'])
        ->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $fresh = $offer->fresh();
    expect((int) $fresh->premium_minor)->toBe($rated - 10000)->and((int) $fresh->original_premium_minor)->toBe($rated)
        ->and((int) $fresh->total_minor)->toBe((int) $offer->total_minor - 10000)
        ->and(DB::table('audit_log')->where(['action' => 'quote.premium_override.applied', 'subject_id' => $offer->id])->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where(['action' => 'override.requested', 'subject_id' => $offer->id])->exists())->toBeTrue();

    // Once applied, the customer can accept the overridden price.
    q6bAs($this->fx['user'], 'POST', "quotes/{$quote->id}/offers/{$offer->id}/accept")->assertOk();
});

it('REQ-DST-003 saves a comparison on normalized dimensions and reads it back', function () {
    $quote = q6bRatedQuote();
    $cmp = q6bAs($this->fx['user'], 'POST', 'quote-comparisons', ['quote_id' => $quote->id])->assertCreated()->json('data');
    expect($cmp['offers'])->toHaveCount(2)
        ->and($cmp['offers'][0]['difference_to_lowest_minor'])->toBe(0)
        ->and($cmp['offers'][1]['difference_to_lowest_minor'])->toBeGreaterThan(0)
        ->and($cmp['dimension_keys'])->toContain('coverages.limit_minor', 'exclusions')
        ->and($cmp['lowest_total_offer_id'])->toBe($cmp['offers'][0]['offer_id']);
    q6bAs($this->fx['user'], 'GET', "quote-comparisons/{$cmp['id']}")->assertOk()->assertJsonPath('data.offers.0.current_status', 'OFFERED');
    q6bAs($this->fx['user'], 'GET', 'quote-comparisons?quote_id='.$quote->id)->assertOk()->assertJsonCount(1, 'data');

    // Another customer can neither compare nor read it.
    $other = makeMobileCustomerFixture('+237671116601');
    Passport::actingAs($other['user']);
    $this->postJson('/api/v1/quote-comparisons', ['quote_id' => $quote->id], tenantHeaderFor($this->tenant))->assertStatus(403);
});

it('REQ-DUP-007 the mobile adapter has no service of its own and keeps its response shapes', function () {
    expect(file_exists(app_path('Application/Quotes/MobileQuoteService.php')))->toBeFalse();
    $quote = q6bRatedQuote();
    q6bAs($this->fx['user'], 'GET', 'mobile/quotes')->assertOk()->assertJsonStructure(['data' => ['data', 'current_page', 'total']]);
    q6bAs($this->fx['user'], 'POST', "mobile/quotes/{$quote->id}/resume")->assertOk()->assertJsonStructure(['data' => ['quote' => ['id', 'status', 'is_expired'], 'offers']]);
    q6bAs($this->fx['user'], 'DELETE', "mobile/quotes/{$quote->id}")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    expect(Quote::find($quote->id)->lifecycle_state)->toBe('CANCELLED');
});

it('REQ-QUO-001 REQ-QUO-006 hook: a manual offer on a REFERRED quote prices it through the machine', function () {
    $quote = Quote::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->fx['party']->id, 'line_code' => 'MOTOR', 'status' => 'REFERRED', 'lifecycle_state' => 'REFERRED', 'currency' => 'XAF', 'risk_facts' => [], 'expires_at' => now()->addDays(3)]);
    $q = app(QuoteService::class)->manualOfferRecorded($quote, $this->maker);
    expect($q->lifecycle_state)->toBe('CALCULATED')->and($q->status)->toBe('OFFERED')
        ->and(DB::table('workflow_transition_history')->where(['machine' => 'quote', 'subject_id' => $quote->id])->orderBy('occurred_at')->pluck('to_state')->all())->toBe(['RATING', 'CALCULATED']);
});
