<?php

declare(strict_types=1);

use App\Application\Payments\PaymentRequestService;
use App\Application\Underwriting\Proposal\ProposalDeclarations;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
});

/** A PAYMENT_PENDING proposal with a priced terms snapshot. */
function payableFixture(string $phone): array
{
    $f = makeMobileCustomerFixture($phone);
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    $f['proposal']->refresh();

    return $f;
}

function payRequest(array $f, bool $requireTerms = false, ?string $key = null)
{
    return app(PaymentRequestService::class)->create($f['tenant'], $f['proposal'], [
        'provider' => 'mtn_momo', 'payer_phone_e164' => '+237670000001', 'idempotency_key' => $key ?? 'test-'.Str::uuid(),
    ], $f['user'], $requireTerms);
}

function problemCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (ApiProblemException $e) {
        return $e->errorCode.':'.$e->status;
    }

    return null;
}

it('refuses a second payment once the premium was collected (409 PAYMENT_ALREADY_MADE)', function () {
    $f = payableFixture('+237672330001');
    makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED']);

    expect(problemCode(fn () => payRequest($f)))->toBe('PAYMENT_ALREADY_MADE:409');
});

it('refuses a new payment while one is with the operator (409 PAYMENT_IN_PROGRESS) but not after it expired or failed', function () {
    $f = payableFixture('+237672330002');
    $live = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'expires_at' => now()->addMinutes(10)]);
    expect(problemCode(fn () => payRequest($f)))->toBe('PAYMENT_IN_PROGRESS:409');

    $live->update(['status' => 'PROCESSING']);
    expect(problemCode(fn () => payRequest($f)))->toBe('PAYMENT_IN_PROGRESS:409');

    $live->update(['status' => 'PENDING_CUSTOMER', 'expires_at' => now()->subMinute()]);
    expect(payRequest($f)->status)->toBe('PENDING_CUSTOMER');
});

it('lets a failed attempt be retried and still returns the same payment for the same key', function () {
    $f = payableFixture('+237672330003');
    makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'FAILED']);
    $first = payRequest($f, false, 'same-key-000000000001');
    // Same Idempotency-Key: the same payment back, never a 409 for the caller's own retry.
    expect(payRequest($f, false, 'same-key-000000000001')->id)->toBe($first->id);
});

it('requires the TERMS_ACCEPTANCE declaration when asked (native app checkout)', function () {
    $f = payableFixture('+237672330004');
    expect(problemCode(fn () => payRequest($f, true)))->toBe('TERMS_NOT_ACCEPTED:422');
    // Assisted channels (broker / web review page) are not gated.
    expect(payRequest($f, false)->status)->toBe('PENDING_CUSTOMER');

    $g = payableFixture('+237672330005');
    app(ProposalDeclarations::class)->accept($g['proposal'], 'TERMS_ACCEPTANCE', $g['user'], 'MOBILE');
    expect(payRequest($g, true)->status)->toBe('PENDING_CUSTOMER');
});

it('POST /payments from the native app answers 409 with the existing payment id', function () {
    $f = payableFixture('+237672330006');
    app(ProposalDeclarations::class)->accept($f['proposal'], 'TERMS_ACCEPTANCE', $f['user'], 'MOBILE');
    $paid = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED']);
    Passport::actingAs($f['user']);

    $this->postJson('/api/v1/payments', ['proposal_id' => $f['proposal']->id, 'provider' => 'mtn_momo', 'payer_phone_e164' => '+237670000001', 'idempotency_key' => 'pay:'.Str::uuid()],
        tenantHeaderFor($f['tenant']) + ['X-App-Version' => '1.6.0'])
        ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_ALREADY_MADE')->assertJsonPath('payment_id', $paid->id);
});

it('GET /mobile/proposals/{id} returns the revised counter-offer terms to the owner only', function () {
    $f = makeMobileCustomerFixture('+237672330007');
    $f['proposal']->update(['status' => 'COUNTEROFFERED', 'terms_snapshot' => ['premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    $case = UnderwritingCase::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'status' => 'DECIDED', 'priority' => 'NORMAL', 'referral_reasons' => []]);
    $uw = User::create(['full_name' => 'UW', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    UnderwritingDecision::create(['underwriting_case_id' => $case->id, 'decision' => 'COUNTEROFFERED', 'reason_code' => 'HIGHER_RISK', 'notes' => 'Revised premium.', 'conditions' => ['revised_premium_minor' => 120000, 'revised_total_minor' => 130000], 'decided_by' => $uw->id, 'decided_at' => now()]);
    Passport::actingAs($f['user']);

    $this->getJson("/api/v1/mobile/proposals/{$f['proposal']->id}", tenantHeaderFor($f['tenant']))->assertOk()
        ->assertJsonPath('data.status', 'COUNTEROFFERED')
        ->assertJsonPath('data.counter_offer.total_minor', 130000)
        ->assertJsonPath('data.counter_offer.premium_minor', 120000)
        ->assertJsonPath('data.counter_offer.notes', 'Revised premium.');

    $other = makeMobileCustomerFixture('+237672330008');
    Passport::actingAs($other['user']);
    $this->getJson("/api/v1/mobile/proposals/{$f['proposal']->id}", tenantHeaderFor($other['tenant']))->assertNotFound();
});
