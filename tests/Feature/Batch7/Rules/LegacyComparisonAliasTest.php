<?php

declare(strict_types=1);

/**
 * REQ-DST-003 — legacy POST /web-experiences/marketplace/comparisons is an alias of POST /quote-comparisons:
 * one implementation (QuoteComparisonService), tenant/ownership scoped, offers validated. Fixtures mirror Batch 6B.
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

const B7C_RULES = [
    'required_facts' => ['usage'],
    'base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'vehicle_value', 'rate_ppm' => 20000],
    'rounding' => ['unit_minor' => 100, 'mode' => 'HALF_UP'],
    'branch_allocation' => [['branch_code' => 'RC_AUTO', 'basis_points' => 10000]],
];

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    $this->fx = makeMobileCustomerFixture('+237671117700');
    $this->tenant = $this->fx['tenant'];
    InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => 'Motor', 'status' => 'ACTIVE', 'risk_schema' => ['required' => []]]);
    InsuranceLine::where('code', 'MOTOR')->update(['status' => 'ACTIVE']);
    TenantCustomer::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->fx['party']->id, 'customer_number' => 'C-'.Str::random(6), 'status' => 'ACTIVE']);
    $this->maker = makeAuthTestUser($this->tenant, ['tariff.manage', 'quotes.rate', 'quotes.read', 'quotes.manage', 'quotes.send', 'quotes.premium_override.request'], 'B7C_MAKER');
    $this->checker = makeAuthTestUser($this->tenant, ['tariff.manage', 'tariff.approve', 'tariff.publish', 'quotes.read', 'quotes.premium_override.approve'], 'B7C_CHECKER');
    $this->products = [];
    foreach (['Alpha', 'Beta'] as $i => $name) {
        $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => "{$name} Assurances", 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
        $p = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'B7C-'.$name, 'name' => "{$name} Motor", 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE']);
        $p->forceFill(['quote_validity_days' => $i === 0 ? 14 : 30])->save();
        $this->products[] = $p;
        b7cTariff($p->id, [...B7C_RULES, 'base' => [...B7C_RULES['base'], 'rate_ppm' => 20000 + $i * 5000]]);
    }
});

function b7cAs($user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/'.$uri, $body, tenantHeaderFor(test()->tenant));
}

function b7cTariff(string $productId, array $rules): void
{
    $id = b7cAs(test()->maker, 'POST', 'tariffs', ['insurance_product_id' => $productId, 'effective_from' => '2026-01-01', 'input_schema' => ['usage' => 'string'], 'rules' => $rules, 'regulatory_reference' => 'DEMO-UNVERIFIED'])->assertCreated()->json('data.id');
    b7cAs(test()->maker, 'POST', "tariffs/{$id}/submit", ['notes' => 'Ready for technical review.'])->assertOk();
    b7cAs(test()->checker, 'POST', "tariffs/{$id}/approve", ['reason' => 'Actuarial review completed and signed off.'])->assertOk();
    b7cAs(test()->checker, 'POST', "tariffs/{$id}/schedule", ['notes' => 'Publish on the effective date.'])->assertOk();
}

/** Customer submits through the existing POST /quotes, then rates through the existing route. */
function b7cRatedQuote(): Quote
{
    $id = b7cAs(test()->fx['user'], 'POST', 'quotes', ['customer_id' => test()->fx['party']->id, 'line_code' => 'MOTOR', 'channel' => 'B2C', 'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 5000000]])
        ->assertStatus(202)->json('data.id');
    b7cAs(test()->maker, 'POST', "quotes/{$id}/rate")->assertOk();

    return Quote::findOrFail($id);
}

it('REQ-DST-003 the legacy marketplace comparison endpoint delegates to QuoteComparisonService', function () {
    $quote = b7cRatedQuote();
    $offers = $quote->offers()->pluck('id')->all();
    $cmp = b7cAs($this->fx['user'], 'POST', 'web-experiences/marketplace/comparisons', ['quote_request_id' => $quote->id, 'selected_offer_ids' => $offers, 'expires_at' => now()->addDay()->toIso8601String()])
        ->assertCreated()->json('data');
    expect($cmp['quote_id'])->toBe($quote->id)
        ->and($cmp['offers'])->toHaveCount(2)
        ->and($cmp['dimension_keys'])->toContain('exclusions');
    // Same store as the canonical endpoint: re-saving updates the one row.
    b7cAs($this->fx['user'], 'POST', 'quote-comparisons', ['quote_id' => $quote->id])->assertCreated()->assertJsonPath('data.id', $cmp['id']);

    // Offers not on the quote are rejected; another customer cannot compare this quote.
    b7cAs($this->fx['user'], 'POST', 'web-experiences/marketplace/comparisons', ['quote_request_id' => $quote->id, 'selected_offer_ids' => [(string) Str::uuid(), $offers[0]]])->assertStatus(422);
    $other = makeMobileCustomerFixture('+237671117701');
    Passport::actingAs($other['user']);
    $this->postJson('/api/v1/web-experiences/marketplace/comparisons', ['quote_request_id' => $quote->id, 'selected_offer_ids' => $offers], tenantHeaderFor($this->tenant))->assertStatus(403);
    expect(method_exists(\App\Application\WebExperiences\PortalWorkspaceService::class, 'saveComparison'))->toBeFalse();
});
