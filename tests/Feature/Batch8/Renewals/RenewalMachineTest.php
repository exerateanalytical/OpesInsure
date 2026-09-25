<?php

declare(strict_types=1);

/*
 * Batch 8 — REQ-REN-001 renewal machine (windows 90/60/30/15/7, re-rate on current version, continuity link,
 * paid-renewal issuance failure → issuance_exceptions) and REQ-DUP-010 (one seeding service, broker route is an alias).
 */

use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Application\Policies\IssuanceQueue\IssuanceQueueService;
use App\Application\Policies\PaymentIssuanceTrigger;
use App\Application\Policies\Renewals\RenewalMachine;
use App\Application\Policies\RenewalService;
use App\Models\Carrier;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\RenewalCase;
use App\Models\TenantCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const B8R_RULES = [
    'required_facts' => ['usage'],
    'base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'vehicle_value', 'rate_ppm' => 20000],
    'rounding' => ['unit_minor' => 100, 'mode' => 'HALF_UP'],
    'branch_allocation' => [['branch_code' => 'RC_AUTO', 'basis_points' => 10000]],
];

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    $this->fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->fx['tenant'];
    $this->h = tenantHeaderFor($this->tenant);
    $this->ops = makeAuthTestUser($this->tenant, ['renewals.manage', 'broker.renewals.manage', 'tariff.manage', 'quotes.rate'], 'B8R_OPS');
});

function b8rPolicy(int $daysLeft, array $overrides = []): Policy
{
    $f = test()->fx;

    return makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, $overrides + [
        'policy_number' => 'POL-B8R-'.Str::random(5), 'coverage_starts_at' => now()->subYear(), 'coverage_ends_at' => now()->addDays($daysLeft)->setTime(23, 0),
    ]);
}

function b8rTariff(string $productId, string $from, int $ratePpm): string
{
    $checker = makeAuthTestUser(test()->tenant, ['tariff.manage', 'tariff.approve', 'tariff.publish'], 'B8R_CHECKER_'.Str::random(4));
    $as = function ($u, string $uri, array $body = []) {
        Passport::actingAs($u);

        return test()->postJson('/api/v1/'.$uri, $body, test()->h);
    };
    $id = $as(test()->ops, 'tariffs', ['insurance_product_id' => $productId, 'effective_from' => $from, 'input_schema' => ['usage' => 'string'],
        'rules' => [...B8R_RULES, 'base' => [...B8R_RULES['base'], 'rate_ppm' => $ratePpm]], 'regulatory_reference' => 'DEMO-UNVERIFIED'])->assertCreated()->json('data.id');
    $as(test()->ops, "tariffs/{$id}/submit", ['notes' => 'Ready for technical review.'])->assertOk();
    $as($checker, "tariffs/{$id}/approve", ['reason' => 'Actuarial review completed and signed off.'])->assertOk();
    $as($checker, "tariffs/{$id}/schedule", ['notes' => 'Publish on the effective date.'])->assertOk();

    return $id;
}

it('REQ-REN-001 records the reminder windows 90/60/30/15/7 once each, emits renewal.due then renewal.window_reached', function () {
    expect(RenewalMachine::windowFor(120))->toBeNull()->and(RenewalMachine::windowFor(90))->toBe(90)->and(RenewalMachine::windowFor(61))->toBe(90)
        ->and(RenewalMachine::windowFor(45))->toBe(60)->and(RenewalMachine::windowFor(16))->toBe(30)->and(RenewalMachine::windowFor(8))->toBe(15)->and(RenewalMachine::windowFor(2))->toBe(7);

    $policy = b8rPolicy(85);
    Passport::actingAs($this->ops);
    $this->postJson('/api/v1/renewals/seed', [], $this->h)->assertOk()->assertJsonPath('data.created_or_found', 1)->assertJsonPath('data.windows_reached', 1);
    $case = RenewalCase::where('policy_id', $policy->id)->sole();
    expect($case->status)->toBe('DUE')->and($case->window_days)->toBe(90);

    // Same day again: nothing new.
    $this->postJson('/api/v1/renewals/seed', ['days' => 90], $this->h)->assertOk()->assertJsonPath('data.windows_reached', 0);

    foreach ([[30, 60], [30, 30], [15, 15], [5, 7]] as [$travel, $expected]) { // 55, 25, 10, 5 days left
        $this->travel($travel)->days();
        app(RenewalService::class)->seed($this->tenant, 90, null);
        expect($case->refresh()->window_days)->toBe($expected);
    }

    $windows = DB::table('renewal_case_events')->where(['renewal_case_id' => $case->id, 'action' => 'WINDOW_REACHED'])->orderBy('occurred_at')->pluck('window_days')->all();
    expect($windows)->toBe([90, 60, 30, 15, 7])
        ->and(DB::table('outbox_messages')->where('aggregate_id', $case->id)->where('event_name', 'renewal.due')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('aggregate_id', $case->id)->where('event_name', 'renewal.window_reached')->count())->toBe(5);
});

it('REQ-REN-001 lapses a case never quoted once cover has ended', function () {
    $policy = b8rPolicy(3);
    app(RenewalService::class)->seed($this->tenant, 90, null);
    $this->travel(5)->days();
    expect(app(RenewalService::class)->sweep($this->tenant, 90, null)['lapsed'])->toBe(1);
    $case = RenewalCase::where('policy_id', $policy->id)->sole();
    expect($case->status)->toBe('LAPSED')->and($case->closed_reason)->toBe('NOT_RENEWED_BY_EXPIRY');
});

it('REQ-DUP-010 broker/renewals/seed is an alias of the one renewal service', function () {
    $policy = b8rPolicy(20);
    Passport::actingAs($this->ops);
    $r = $this->postJson('/api/v1/broker/renewals/seed', ['days_ahead' => 30], $this->h)->assertOk()->assertJsonPath('data.created', 1);
    expect($r->headers->get('Deprecation'))->toBe('true')
        ->and(RenewalCase::where('policy_id', $policy->id)->sole()->window_days)->toBe(30)
        ->and(DB::table('renewal_work_items')->where('policy_id', $policy->id)->count())->toBe(1);

    // Canonical route after the alias: same case, same work item, nothing duplicated.
    $this->postJson('/api/v1/renewals/seed', ['days' => 30], $this->h)->assertOk()->assertJsonPath('data.created_or_found', 1)->assertJsonPath('data.work_items_created', 0);
    $this->postJson('/api/v1/broker/renewals/seed', ['days_ahead' => 30], $this->h)->assertOk()->assertJsonPath('data.created', 0);
    expect(RenewalCase::where('policy_id', $policy->id)->count())->toBe(1);

    $src = file_get_contents(app_path('Interfaces/Http/Controllers/Api/V1/BrokerOperations/BrokerOperationsController.php'));
    expect($src)->toContain('RenewalService')->not->toContain("insertOrIgnore(['id'=>(string)Str::uuid(),'tenant_id'=>\$tenant,'policy_id'=>\$p->id,'renewal_due_on'");
});

it('REQ-REN-001 re-rates a renewal on the policy current version and the tariff version in force', function () {
    InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => 'Motor', 'status' => 'ACTIVE', 'risk_schema' => ['required' => []]]);
    InsuranceLine::where('code', 'MOTOR')->update(['status' => 'ACTIVE']);
    TenantCustomer::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->fx['party']->id, 'customer_number' => 'C-'.Str::random(6), 'status' => 'ACTIVE']);
    $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B8R Assurances', 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
    $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'B8R-MOTOR', 'name' => 'B8R Motor', 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE']);
    $v2 = b8rTariff($product->id, '2026-01-01', 30000);

    // The expiring policy: original quote facts (vehicle 4M), then an endorsement made the current version 6M.
    $oldQuote = Quote::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->fx['party']->id, 'line_code' => 'MOTOR', 'channel' => 'B2C', 'status' => 'RATED', 'currency' => 'XAF',
        'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 4000000], 'attribution_id' => null]);
    $oldOffer = QuoteOffer::create(['quote_id' => $oldQuote->id, 'carrier_id' => $carrier->id, 'product_id' => $product->id, 'tariff_version_id' => $v2, 'premium_minor' => 80000, 'total_minor' => 80000,
        'currency' => 'XAF', 'status' => 'ACCEPTED', 'calculation_breakdown' => [], 'valid_until' => now()]);
    $oldProposal = Proposal::create(['tenant_id' => $this->tenant->id, 'quote_offer_id' => $oldOffer->id, 'party_id' => $this->fx['party']->id, 'status' => 'APPROVED']);
    $policy = makeMobileTestPolicy($oldProposal, $this->tenant, $carrier->id, $this->fx['party']->id, ['policy_number' => 'POL-B8R-RR', 'coverage_starts_at' => now()->subYear(), 'coverage_ends_at' => now()->addDays(20)]);
    $versionId = (string) Str::uuid();
    DB::table('policy_versions')->insert(['id' => $versionId, 'tenant_id' => $this->tenant->id, 'policy_id' => $policy->id, 'version_no' => 2, 'kind' => 'ENDORSEMENT', 'valid_from' => now()->subMonth(),
        'recorded_at' => now()->subMonth(), 'snapshot' => '{}', 'snapshot_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('policy_risks')->insert(['id' => (string) Str::uuid(), 'policy_id' => $policy->id, 'policy_version_id' => $versionId, 'risk_type' => 'VEHICLE',
        'facts' => json_encode(['usage' => 'PRIVATE', 'vehicle_value' => 6000000]), 'facts_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);

    app(RenewalService::class)->seed($this->tenant, 90, null);
    $case = RenewalCase::where('policy_id', $policy->id)->sole();
    Passport::actingAs($this->ops);
    $this->postJson("/api/v1/renewals/{$case->id}/quote", [], $this->h)->assertCreated()->assertJsonPath('data.status', 'QUOTED');

    $case->refresh();
    $quote = Quote::findOrFail($case->renewal_quote_id);
    $offer = QuoteOffer::where('quote_id', $quote->id)->sole();
    expect($case->rated_policy_version_id)->toBe($versionId)
        ->and((int) $quote->risk_facts['vehicle_value'])->toBe(6000000)
        ->and($offer->tariff_version_id)->toBe($v2)
        ->and((int) $offer->premium_minor)->toBe(180000) // 6M × 3% on the current tariff
        ->and(DB::table('renewal_case_events')->where(['renewal_case_id' => $case->id, 'action' => 'QUOTED'])->exists())->toBeTrue();

    // A quoted case cannot be quoted twice.
    $this->postJson("/api/v1/renewals/{$case->id}/quote", [], $this->h)->assertStatus(422);
});

it('REQ-REN-001 WF-087 a paid renewal whose issuance fails lands in the issuance exception queue and blocks nothing on recovery', function () {
    $f = $this->fx;
    $old = b8rPolicy(10, ['proposal_id' => Proposal::create(['tenant_id' => $f['tenant']->id, 'quote_offer_id' => makeMobileTestQuoteOffer(makeMobileTestQuote($f['tenant'], $f['party']), $f['carrier']->id, $f['product']->id, $f['tariff']->id)->id, 'party_id' => $f['party']->id, 'status' => 'APPROVED'])->id]);
    $case = RenewalCase::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $old->id, 'due_on' => $old->coverage_ends_at->toDateString(), 'status' => 'QUOTED', 'renewal_quote_id' => $f['quote']->id]);

    // Paid renewal proposal whose issuance request is refused (terms currency ≠ payment currency).
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'EUR']]);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now(), 'requested_by' => $f['user']->id]);
    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($payment);

    $ex = IssuanceException::where('proposal_id', $f['proposal']->id)->sole();
    $case->refresh();
    expect($ex->renewal_case_id)->toBe($case->id)
        ->and($case->status)->toBe('ISSUANCE_FAILED')->and($case->issuance_exception_id)->toBe($ex->id)
        ->and(DB::table('outbox_messages')->where(['aggregate_id' => $case->id, 'event_name' => 'renewal.issuance_failed'])->count())->toBe(1);
    $meta = json_decode((string) DB::table('renewal_case_events')->where(['renewal_case_id' => $case->id, 'action' => 'ISSUANCE_FAILED'])->value('metadata'), true);
    expect($meta['days_to_expiry'])->toBe(10);

    // A replay bumps the queue row; the case moves once.
    app(PaymentIssuanceTrigger::class)->afterPaymentSucceeded($payment);
    expect(DB::table('renewal_case_events')->where(['renewal_case_id' => $case->id, 'action' => 'ISSUANCE_FAILED'])->count())->toBe(1);

    // Ops fix the data and retry: the request opens, the case goes back to QUOTED awaiting carrier approval.
    $f['proposal']->update(['terms_snapshot' => [...$f['proposal']->terms_snapshot, 'currency' => 'XAF']]);
    app(IssuanceQueueService::class)->retry($ex->refresh(), $this->ops);
    expect($ex->refresh()->status)->toBe('RESOLVED')->and($case->refresh()->status)->toBe('QUOTED')
        ->and(DB::table('outbox_messages')->where(['aggregate_id' => $case->id, 'event_name' => 'renewal.issuance_recovered'])->count())->toBe(1);
});

it('REQ-REN-001 continuity: completion requires a successor linked by previous_policy_id', function () {
    $f = $this->fx;
    $old = b8rPolicy(10);
    $case = RenewalCase::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $old->id, 'due_on' => $old->coverage_ends_at->toDateString(), 'status' => 'QUOTED']);
    $unlinked = b8rPolicy(400);
    expect(fn () => app(RenewalService::class)->complete($case, $unlinked))->toThrow(Illuminate\Validation\ValidationException::class);

    $successor = b8rPolicy(375, ['previous_policy_id' => $old->id]);
    $done = app(RenewalService::class)->complete($case, $successor);
    expect($done->status)->toBe('RENEWED')->and($done->successor_policy_id)->toBe($successor->id);
    expect(fn () => app(RenewalService::class)->complete($case, $successor))->toThrow(Illuminate\Validation\ValidationException::class);
});
