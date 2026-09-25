<?php

declare(strict_types=1);

/**
 * REQ-RAT-001 REQ-RAT-002 REQ-RAT-003 REQ-RAT-004 REQ-RAT-005 — rating v2: PRE §75 tariff lifecycle, versioned
 * tax/levy/fee tables (DEMO/UNVERIFIED, OQ-9), quote rating through the Temporal engine with an immutable
 * snapshot of every version, reproduction for a past date, and the stateless /insurance/rate preview.
 */

use App\Application\Quotes\QuoteService;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\TariffVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const RAT_RULES = [
    'required_facts' => ['usage'],
    'base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'vehicle_value', 'rate_ppm' => 20000],
    'coverages' => [['code' => 'GLASS', 'method' => 'FIXED', 'amount_minor' => 5000, 'optional' => true]],
    'loadings' => [['code' => 'TAXI', 'type' => 'HIGH_RISK_USE', 'fact' => 'usage', 'operator' => 'EQUALS', 'value' => 'TAXI', 'basis_points' => 2500]],
    'rounding' => ['unit_minor' => 100, 'mode' => 'HALF_UP'],
    'branch_allocation' => [['branch_code' => 'RC_AUTO', 'basis_points' => 7000], ['branch_code' => 'DOMMAGES_AUTO', 'basis_points' => 3000]],
];

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    $this->fx = makeMobileCustomerFixture('+237671115500');
    $this->tenant = $this->fx['tenant'];
    $this->maker = makeAuthTestUser($this->tenant, ['tariff.manage', 'rating.charges.view', 'rating.charges.manage', 'rating.runs.view', 'quotes.rate'], 'RAT_MAKER');
    $this->checker = makeAuthTestUser($this->tenant, ['tariff.manage', 'tariff.approve', 'tariff.publish', 'rating.charges.view', 'rating.charges.approve', 'rating.runs.view'], 'RAT_CHECKER');
    $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rating Carrier', 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
    $this->product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'MOTOR-V2', 'name' => 'Motor v2', 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE']);
});

function ratAs($user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/'.$uri, $body, tenantHeaderFor(test()->tenant));
}

/** Tariff through the real workflow: create (maker) → submit → approve (checker) → schedule/activate. */
function ratTariff(string $from, array $rules = RAT_RULES): string
{
    $id = ratAs(test()->maker, 'POST', 'tariffs', ['insurance_product_id' => test()->product->id, 'effective_from' => $from, 'input_schema' => ['usage' => 'string'], 'rules' => $rules, 'regulatory_reference' => 'DEMO-UNVERIFIED'])->assertCreated()->json('data.id');
    ratAs(test()->maker, 'POST', "tariffs/{$id}/submit", ['notes' => 'Ready for technical review.'])->assertOk();
    ratAs(test()->checker, 'POST', "tariffs/{$id}/approve", ['reason' => 'Actuarial review completed and signed off.'])->assertOk();
    ratAs(test()->checker, 'POST', "tariffs/{$id}/schedule", ['notes' => 'Publish on the effective date.'])->assertOk();

    return $id;
}

/** Tax and fee tables through the charge-table maker-checker API. */
function ratCharges(string $from, int $taxBp): void
{
    $tax = ratAs(test()->maker, 'POST', 'rating/charge-tables/tax', ['line_code' => 'MOTOR', 'effective_from' => $from, 'rules' => ['charges' => [['code' => 'TAX', 'basis' => 'PREMIUM', 'basis_points' => $taxBp]]]])->assertCreated();
    ratAs(test()->checker, 'POST', 'rating/charge-tables/tax/'.$tax->json('data.id').'/approve')->assertOk();
    if (! DB::table('fee_schedule_versions')->where('code', 'PLATFORM_FEE')->exists()) {
        $fee = ratAs(test()->maker, 'POST', 'rating/charge-tables/fee', ['code' => 'PLATFORM_FEE', 'effective_from' => $from, 'rules' => ['charges' => [['code' => 'PLATFORM_FEE', 'basis' => 'FIXED', 'fixed_minor' => 1000]]]])->assertCreated();
        ratAs(test()->checker, 'POST', 'rating/charge-tables/fee/'.$fee->json('data.id').'/approve')->assertOk();
    }
}

function ratQuote(array $facts = ['usage' => 'TAXI', 'vehicle_value' => 5000000]): Quote
{
    return Quote::create(['tenant_id' => test()->tenant->id, 'party_id' => test()->fx['party']->id, 'line_code' => 'MOTOR', 'status' => 'SUBMITTED', 'currency' => 'XAF', 'risk_facts' => $facts]);
}

it('REQ-RAT-002 runs the PRE §75 workflow with maker-checker, scheduling, activation, expiry and immutable history', function () {
    $v1 = ratTariff('2026-01-01');
    expect(TariffVersion::find($v1)->status)->toBe('ACTIVE');

    // Maker cannot approve their own version; a rejected version stays rejected.
    $bad = ratAs($this->maker, 'POST', 'tariffs', ['insurance_product_id' => $this->product->id, 'effective_from' => '2026-12-01', 'input_schema' => ['usage' => 'string'], 'rules' => RAT_RULES, 'regulatory_reference' => 'X'])->json('data.id');
    ratAs($this->maker, 'POST', "tariffs/{$bad}/submit", ['notes' => 'Ready for technical review.'])->assertOk();
    Passport::actingAs($this->maker);
    ratAs($this->maker, 'POST', "tariffs/{$bad}/reject", ['notes' => 'Maker trying to reject.'])->assertForbidden();
    ratAs($this->checker, 'POST', "tariffs/{$bad}/reject", ['notes' => 'Loadings not justified.'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    ratAs($this->checker, 'POST', "tariffs/{$bad}/activate", ['notes' => 'Should never activate.'])->assertStatus(422);

    // Invalid v2 rules are refused at creation.
    ratAs($this->maker, 'POST', 'tariffs', ['insurance_product_id' => $this->product->id, 'effective_from' => '2027-01-01', 'input_schema' => ['usage' => 'string'], 'rules' => ['base' => ['method' => 'MAGIC']], 'regulatory_reference' => 'X'])->assertStatus(422);

    // A future version is SCHEDULED; tariffs:advance activates it on its date and closes v1 the day before.
    $v2 = ratTariff('2026-11-01', [...RAT_RULES, 'base' => ['method' => 'FIXED', 'amount_minor' => 90000]]);
    expect(TariffVersion::find($v2)->status)->toBe('SCHEDULED');
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 30));
    Artisan::call('tariffs:advance');
    $old = TariffVersion::find($v1);
    expect(TariffVersion::find($v2)->status)->toBe('ACTIVE')->and($old->status)->toBe('EXPIRED')->and($old->effective_until->toDateString())->toBe('2026-10-31');

    $history = ratAs($this->maker, 'GET', "tariffs/{$v1}")->assertOk()->json('data.history');
    expect(array_column($history, 'to_status'))->toBe(['DRAFT', 'IN_REVIEW', 'APPROVED', 'ACTIVE', 'EXPIRED']);
    expect(fn () => DB::table('tariff_versions')->where('id', $v1)->update(['status' => 'LIVE']))->toThrow(Illuminate\Database\QueryException::class);
});

it('REQ-RAT-003 keeps charge tables versioned, catalogued, maker-checked, overlap-free and DEMO/UNVERIFIED', function () {
    ratAs($this->maker, 'POST', 'rating/charge-tables/tax', ['line_code' => 'MOTOR', 'effective_from' => '2026-01-01', 'rules' => ['charges' => [['code' => 'INVENTED_LEVY', 'basis' => 'PREMIUM', 'basis_points' => 100]]]])->assertStatus(422);
    ratAs($this->maker, 'POST', 'rating/charge-tables/tax', ['line_code' => 'MOTOR', 'effective_from' => '2026-01-01', 'data_status' => 'OWNER_CONFIRMED', 'rules' => ['charges' => [['code' => 'TAX', 'basis' => 'PREMIUM', 'basis_points' => 100]]]])->assertStatus(422);
    $t = ratAs($this->maker, 'POST', 'rating/charge-tables/tax', ['line_code' => 'MOTOR', 'effective_from' => '2026-01-01', 'rules' => ['charges' => [['code' => 'TAX', 'basis' => 'PREMIUM', 'basis_points' => 1925]]]])->assertCreated();
    expect($t->json('data.data_status'))->toBe('DEMO_UNVERIFIED')->and($t->json('data.status'))->toBe('DRAFT');
    Passport::actingAs($this->maker);
    ratAs($this->maker, 'POST', 'rating/charge-tables/tax/'.$t->json('data.id').'/approve')->assertForbidden();
    ratAs($this->checker, 'POST', 'rating/charge-tables/tax/'.$t->json('data.id').'/approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $overlap = ratAs($this->maker, 'POST', 'rating/charge-tables/tax', ['line_code' => 'MOTOR', 'effective_from' => '2026-06-01', 'rules' => ['charges' => [['code' => 'TAX', 'basis' => 'PREMIUM', 'basis_points' => 1000]]]])->assertCreated();
    ratAs($this->checker, 'POST', 'rating/charge-tables/tax/'.$overlap->json('data.id').'/approve')->assertStatus(422);

    $codes = ratAs($this->maker, 'GET', 'rating/charge-codes')->assertOk()->json('data');
    expect(collect($codes)->every(fn ($c) => $c['verification_status'] === 'UNVERIFIED' && $c['legal_reference'] === null))->toBeTrue();
});

it('REQ-RAT-001 REQ-RAT-004 REQ-RAT-005 rates a quote through the Temporal engine and snapshots every version', function () {
    ratTariff('2026-01-01');
    ratCharges('2026-01-01', 1925);
    $quote = app(QuoteService::class)->rate(ratQuote(), $this->fx['user']);
    expect($quote->status)->toBe('OFFERED');

    $offer = QuoteOffer::where('quote_id', $quote->id)->sole();
    // 5 000 000 × 2% = 100 000; TAXI +25% = 125 000; tax 19.25% (DEMO) = 24 063; fee 1 000.
    expect($offer->premium_minor)->toBe(125000)->and($offer->tax_minor)->toBe(24063)->and($offer->fee_minor)->toBe(1000)->and($offer->total_minor)->toBe(150063)
        ->and(collect($offer->calculation_breakdown)->pluck('kind')->all())->toBe(['BASE', 'LOADING', 'FEE', 'TAX']);

    $run = DB::table('rating_runs')->where('id', $offer->rating_run_id)->first();
    $versions = json_decode($run->resolved_versions, true);
    expect($run->status)->toBe('SUCCEEDED')->and($run->tax_levy_version_id)->not->toBeNull()->and($run->fee_schedule_version_id)->not->toBeNull()
        ->and($versions)->toHaveKeys(['tariff', 'tax_levy:CM/MOTOR', 'fee_schedule:PLATFORM_FEE/GLOBAL'])
        ->and($run->allocation_status)->toBe('ALLOCATED')
        ->and(array_sum(array_column(json_decode($run->branch_allocation, true), 'amount_minor')))->toBe(125000);
    $engine = json_decode($run->engine_result, true);
    expect($engine['engine'])->toBe('RATING')->and($engine['warnings'])->toContain('rating.charge_rates_unverified')
        ->and(collect($engine['trace'])->pluck('rule_code')->all())->toBe(['BASE', 'TAXI', 'PLATFORM_FEE', 'TAX']);

    ratAs($this->maker, 'GET', "rating/runs/{$run->id}")->assertOk()->assertJsonPath('data.engine_result.engine', 'RATING');
});

it('REQ-RAT-004 reproduces a past rating byte-for-byte after tariffs and taxes change; the preview is stateless and date-aware', function () {
    ratTariff('2026-01-01');
    ratCharges('2026-01-01', 1925);
    $quote = app(QuoteService::class)->rate(ratQuote(), $this->fx['user']);
    $offer = QuoteOffer::where('quote_id', $quote->id)->sole();

    // New tariff and a new tax version from 2026-11-01 (the old tax is closed first).
    ratTariff('2026-11-01', [...RAT_RULES, 'base' => ['method' => 'FIXED', 'amount_minor' => 90000]]);
    DB::table('tax_levy_versions')->where('status', 'APPROVED')->update(['effective_until' => '2026-10-31']);
    ratCharges('2026-11-01', 1000);
    $this->travelTo(now()->setDate(2026, 11, 5)->setTime(9, 0));
    Artisan::call('tariffs:advance');

    $rep = ratAs($this->checker, 'POST', "rating/runs/{$offer->rating_run_id}/reproduce")->assertOk();
    expect($rep->json('data.identical'))->toBeTrue()->and($rep->json('data.temporal_resolution_matches'))->toBeTrue()
        ->and($rep->json('data.pricing.total_minor'))->toBe(150063);

    $runs = DB::table('rating_runs')->count();
    $past = ratAs($this->maker, 'POST', 'insurance/rate', ['quote_id' => $quote->id, 'reference_date' => '2026-10-10'])->assertOk();
    $today = ratAs($this->maker, 'POST', 'insurance/rate', ['quote_id' => $quote->id])->assertOk();
    expect($past->json('data.0.pricing.total_minor'))->toBe(150063)
        ->and($today->json('data.0.pricing.net_premium_minor'))->toBe(112500) // 90 000 + 25 % TAXI
        ->and($today->json('data.0.pricing.tax_minor'))->toBe(11250)
        ->and($today->json('meta.stateless'))->toBeTrue()
        ->and(DB::table('rating_runs')->count())->toBe($runs);

    // A customer cannot preview someone else's quote.
    $other = makeAuthTestUser($this->tenant, ['quotes.rate'], 'CUSTOMER');
    Passport::actingAs($other);
    ratAs($other, 'POST', 'insurance/rate', ['quote_id' => $quote->id])->assertNotFound();
});
