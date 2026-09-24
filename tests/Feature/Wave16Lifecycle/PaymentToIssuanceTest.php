<?php

declare(strict_types=1);

use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Document;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\RenewalCase;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function lifecyclePaidFixture(array $paymentOverrides = []): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], array_merge(['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id], $paymentOverrides));

    return $f;
}

function lifecycleWebhook(PaymentIntentRecord $payment, string $status, ?string $event = null): void
{
    app(WebhookProcessingService::class)->process('fake', $event ?? 'evt-'.Str::uuid(), [
        'payment_reference' => $payment->provider_reference, 'amount_minor' => $payment->amount_minor,
        'currency' => $payment->currency, 'status' => $status,
    ], 'sig');
}

function lifecycleApprover(): User
{
    return User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('reconciles a webhook-confirmed payment and opens the carrier issuance request automatically', function () {
    $f = lifecyclePaidFixture();

    lifecycleWebhook($f['payment'], 'SUCCEEDED');

    $payment = $f['payment']->refresh();
    expect($payment->status)->toBe('SUCCEEDED')->and($payment->reconciled_at)->not->toBeNull();

    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->first();
    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('CARRIER_REVIEW')
        ->and($request->payment_intent_id)->toBe($payment->id)
        ->and(Policy::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse(); // carrier approval stays authoritative

    expect(UserNotification::where('user_id', $f['user']->id)->where('title', 'Payment received — issuance in progress')->count())->toBe(1);

    // Replays (same or a new event id) never open a second request.
    lifecycleWebhook($f['payment'], 'SUCCEEDED');
    expect(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->count())->toBe(1);

    Passport::actingAs($f['user']);
    $status = $this->getJson("/api/v1/mobile/purchases/{$f['proposal']->id}/status", tenantHeaderFor($f['tenant']))->assertOk();
    expect($status->json('data.status'))->toBe('ISSUANCE_PENDING')
        ->and($status->json('data.policy_status'))->toBe('PAID_PENDING_ISSUANCE')
        ->and($status->json('data.issuance.status'))->toBe('CARRIER_REVIEW')
        ->and($status->json('data.carrier_name'))->toBe('Mobile Wallet Test Carrier Org')
        ->and($status->json('data.product_name'))->toBe('Test Plan')
        ->and($status->json('data.coverage_starts_at'))->not->toBeNull()
        ->and($status->json('data.coverage_ends_at'))->not->toBeNull();
});

it('does not open an issuance request for a failed payment and tells the customer', function () {
    $f = lifecyclePaidFixture();

    lifecycleWebhook($f['payment'], 'FAILED');

    expect(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse()
        ->and($f['payment']->refresh()->reconciled_at)->toBeNull()
        ->and(UserNotification::where('user_id', $f['user']->id)->where('type', 'PAYMENT')->where('severity', 'ERROR')->count())->toBe(1);
});

it('issues certificate, schedule and certificate PDFs with signed download links on carrier approval, and tells the customer they are covered', function () {
    $f = lifecyclePaidFixture();
    lifecycleWebhook($f['payment'], 'SUCCEEDED');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();

    $policy = app(PolicyIssuanceService::class)->approve($request, ['policy_number' => 'POL-T-'.Str::random(6), 'carrier_reference' => 'CR-1'], lifecycleApprover());

    $certificate = $policy->certificates()->first();
    expect($certificate)->not->toBeNull()
        ->and($certificate->status)->toBe('VALID')
        ->and($policy->refresh()->certificate_number)->toBe($certificate->serial_number);

    $docs = Document::where('policy_id', $policy->id)->get();
    expect($docs->pluck('category')->sort()->values()->all())->toBe(['POLICY_CERTIFICATE', 'POLICY_SCHEDULE']);
    foreach ($docs as $doc) {
        expect(str_starts_with(Storage::disk('local')->get($doc->storage_key), '%PDF'))->toBeTrue();
    }

    expect(UserNotification::where('user_id', $f['user']->id)->where('title', "You're covered")->count())->toBe(1);

    Passport::actingAs($f['user']);
    $cert = $this->getJson("/api/v1/policies/{$policy->id}/certificate", tenantHeaderFor($f['tenant']))->assertOk();
    expect($cert->json('data.serial_number'))->toBe($certificate->serial_number)
        ->and($cert->json('data.download_url'))->toContain('signature=')
        ->and($cert->json('data.verification_url'))->toStartWith('https://insurance.opesdatacenter.tech/verify?ref='.rawurlencode($certificate->serial_number).'&t=');

    $detail = $this->getJson("/api/v1/mobile/wallet/policies/{$policy->id}", tenantHeaderFor($f['tenant']))->assertOk();
    expect($detail->json('data.carrier_name'))->toBe('Mobile Wallet Test Carrier Org')
        ->and($detail->json('data.product_name'))->toBe('Test Plan')
        ->and($detail->json('data.documents'))->toHaveCount(2)
        ->and($detail->json('data.delivery'))->toBeNull()
        ->and($detail->json('data.policy_number'))->toBe($policy->policy_number);

    // The signed link works without a bearer token; a tampered one does not.
    $url = $cert->json('data.download_url');
    $this->app['auth']->forgetGuards();
    $download = $this->get($url);
    $download->assertOk();
    expect($download->headers->get('Content-Type'))->toContain('application/pdf');
    $this->get($url.'x')->assertForbidden();

    // Public verification by the QR reference.
    expect($this->postJson('/api/v1/public/insurance/verify', ['reference' => $certificate->serial_number])->assertOk()->json('data.result'))->toBe('valid');
});

it('links a renewal to the policy it renews and completes the renewal case', function () {
    $f = lifecyclePaidFixture();
    $oldOffer = makeMobileTestQuoteOffer(makeMobileTestQuote($f['tenant'], $f['party']), $f['carrier']->id, $f['product']->id, $f['tariff']->id);
    $oldProposal = App\Models\Proposal::create(['tenant_id' => $f['tenant']->id, 'quote_offer_id' => $oldOffer->id, 'party_id' => $f['party']->id, 'status' => 'APPROVED']);
    $old = makeMobileTestPolicy($oldProposal, $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-OLD-1', 'coverage_ends_at' => now()->addDays(10)]);
    // The renewal quote is the fixture's quote; the paid proposal is for it.
    $case = RenewalCase::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $old->id, 'due_on' => now()->addDays(10)->toDateString(), 'status' => 'QUOTED', 'renewal_quote_id' => $f['quote']->id]);

    lifecycleWebhook($f['payment'], 'SUCCEEDED');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    expect($request->coverage_starts_at->toDateString())->toBe($old->coverage_ends_at->toDateString());

    $policy = app(PolicyIssuanceService::class)->approve($request, ['policy_number' => 'POL-NEW-1', 'carrier_reference' => 'CR-2'], lifecycleApprover());

    expect($policy->previous_policy_id)->toBe($old->id)
        ->and($case->refresh()->status)->toBe('RENEWED')
        ->and($case->successor_policy_id)->toBe($policy->id);
});

it('returns receipt_number, issued_at and a signed PDF link on the receipt', function () {
    $f = lifecyclePaidFixture();
    lifecycleWebhook($f['payment'], 'SUCCEEDED');
    Passport::actingAs($f['user']);

    $r = $this->getJson("/api/v1/mobile/payments/{$f['payment']->id}/receipt", tenantHeaderFor($f['tenant']))->assertOk();
    expect($r->json('data.receipt_number'))->toStartWith('RCT-')
        ->and($r->json('data.issued_at'))->not->toBeNull()
        ->and($r->json('data.reference'))->toBe($f['payment']->provider_reference)
        ->and($r->json('data.confirmed_at'))->not->toBeNull()
        ->and($r->json('data.download_url'))->toContain('signature=');

    $pdf = $this->get($r->json('data.download_url'));
    $pdf->assertOk();
    expect(str_starts_with($pdf->getContent(), '%PDF'))->toBeTrue();
});

it('lists payments and wallet policies as a top-level data array with pagination meta', function () {
    $f = lifecyclePaidFixture(['status' => 'SUCCEEDED']);
    makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-L-1']);
    Passport::actingAs($f['user']);

    $payments = $this->getJson('/api/v1/mobile/payments?per_page=10', tenantHeaderFor($f['tenant']))->assertOk();
    expect($payments->json('data'))->toBeArray()->toHaveCount(1)
        ->and($payments->json('data.0.id'))->toBe($f['payment']->id)
        ->and($payments->json('meta'))->toMatchArray(['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 1, 'next_page' => null]);

    $wallet = $this->getJson('/api/v1/mobile/wallet', tenantHeaderFor($f['tenant']))->assertOk();
    expect($wallet->json('data'))->toHaveCount(1)
        ->and($wallet->json('data.0.carrier_name'))->toBe('Mobile Wallet Test Carrier Org')
        ->and($wallet->json('data.0.product_name'))->toBe('Test Plan')
        ->and($wallet->json('meta.total'))->toBe(1);
});

it('accepts a refund request carrying only {reason}, defaulting amount, reason code and idempotency', function () {
    $f = lifecyclePaidFixture(['status' => 'SUCCEEDED']);
    Passport::actingAs($f['user']);
    $stepUp = issueMobileStepUpGrant($f['user'], $f['tenant'], 'PAYMENT_REFUND_REQUEST');
    $headers = tenantHeaderFor($f['tenant']) + stepUpHeaderFor($stepUp['token']) + ['Idempotency-Key' => (string) Str::uuid()];

    $first = $this->postJson("/api/v1/mobile/payments/{$f['payment']->id}/refunds", ['reason' => 'Bought the wrong cover'], $headers)->assertStatus(201);
    expect($first->json('data.amount_minor'))->toBe(100000)
        ->and($first->json('data.reason_code'))->toBe('CUSTOMER_REQUEST')
        ->and($first->json('data.status'))->toBe('REQUESTED');

    // Same Idempotency-Key -> same refund, not a second one (fresh step-up: grants are single-use).
    $headers['X-Step-Up-Grant'] = issueMobileStepUpGrant($f['user'], $f['tenant'], 'PAYMENT_REFUND_REQUEST')['token'];
    $again = $this->postJson("/api/v1/mobile/payments/{$f['payment']->id}/refunds", ['reason' => 'Bought the wrong cover'], $headers)->assertStatus(200);
    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('refunds')->where('payment_intent_id', $f['payment']->id)->count())->toBe(1);

    // Nothing left to refund.
    $this->postJson("/api/v1/mobile/payments/{$f['payment']->id}/refunds", ['reason' => 'again'], tenantHeaderFor($f['tenant']) + stepUpHeaderFor(issueMobileStepUpGrant($f['user'], $f['tenant'], 'PAYMENT_REFUND_REQUEST')['token']) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
});

it('accepts an explicit partial refund amount and reason code', function () {
    $f = lifecyclePaidFixture(['status' => 'SUCCEEDED']);
    Passport::actingAs($f['user']);
    $stepUp = issueMobileStepUpGrant($f['user'], $f['tenant'], 'PAYMENT_REFUND_REQUEST');

    $r = $this->postJson("/api/v1/mobile/payments/{$f['payment']->id}/refunds", ['reason' => 'partial', 'amount_minor' => 25000, 'reason_code' => 'DUPLICATE_PAYMENT'],
        tenantHeaderFor($f['tenant']) + stepUpHeaderFor($stepUp['token']))->assertStatus(201);
    expect($r->json('data.amount_minor'))->toBe(25000)->and($r->json('data.reason_code'))->toBe('DUPLICATE_PAYMENT');
});
