<?php

declare(strict_types=1);

use App\Application\CarrierOperations\QuoteRequests\Models\CarrierQuoteRequest;
use App\Application\CarrierOperations\QuoteRequests\QuoteRequestService;
use App\Application\Finance\Clearing\ClearingBatch;
use App\Application\Finance\PremiumStatus\PremiumComponentService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\ClearingBatches\ClearingBatchResource;
use App\Filament\Admin\Resources\Refunds\RefundResource;
use App\Filament\Shared\Actions\ClearingActions;
use App\Filament\Shared\Actions\PaymentActions;
use App\Filament\Shared\Actions\QuoteActions;
use App\Filament\Shared\Actions\RefundActions;
use App\Models\Carrier;
use App\Models\Chargeback;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Quote;
use App\Models\Refund;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** Batch 4 UI coverage: payment, refund, clearing and quote web actions (same permission + service as the API). */
function pqUser(string $tenantId, array $permissions, string $roleCode = 'FINANCE_OFFICER', ?string $carrierId = null): User
{
    $u = User::create(['full_name' => 'PQ '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => $roleCode.'-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function pqAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

/** @param  list<Closure>  $actions */
function pqHarness(array $actions, ?object $record = null)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    $this->payment = makeMobileTestPayment($this->f['proposal'], $this->f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now(), 'provider' => 'mtn_momo']);
    app(TenantContext::class)->set($this->tenant);
});

it('allocates a payment to the policy premium and reverses the run; both hidden without their permission', function () {
    $policy = makeMobileTestPolicy($this->f['proposal'], $this->f['tenant'], $this->f['carrier']->id, $this->f['party']->id, ['policy_number' => 'POL-PQ-'.Str::random(5),
        'coverage_starts_at' => now()->subDays(5), 'terms_snapshot' => ['premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000]]);
    app(PremiumComponentService::class)->captureFromTerms($policy, null);

    pqAs(pqUser($this->tenant, ['payments.allocations.read']), $this->tenant);
    pqHarness([fn () => PaymentActions::allocate(), fn () => PaymentActions::reverseAllocation()], $this->payment)
        ->assertActionHidden('paymentAllocate')->assertActionHidden('paymentReverseAllocation');

    pqAs(pqUser($this->tenant, ['payments.allocations.manage']), $this->tenant);
    pqHarness([fn () => PaymentActions::allocate()], $this->payment)
        ->callAction('paymentAllocate', ['policy_id' => $policy->id])->assertNotified(__('workflow_actions.paymentAllocate.done'));
    $run = DB::table('payment_allocation_runs')->where('payment_intent_id', $this->payment->id)->sole();
    expect($run->status)->toBe('APPLIED')->and((int) $run->allocated_minor)->toBe(100000);

    pqAs(pqUser($this->tenant, ['payments.allocations.reverse']), $this->tenant);
    pqHarness([fn () => PaymentActions::reverseAllocation()], $this->payment)
        ->callAction('paymentReverseAllocation', ['run_id' => $run->id, 'reason_code' => 'ALLOCATION_ERROR', 'reason' => 'Wrong policy picked']);
    expect(DB::table('payment_allocation_runs')->where('id', $run->id)->value('status'))->toBe('REVERSED');
});

it('opens a chargeback and requests a refund on a payment, and refuses a refund above the balance', function () {
    pqAs(pqUser($this->tenant, ['chargeback.manage', 'refund.request']), $this->tenant);
    pqHarness([fn () => PaymentActions::openChargeback()], $this->payment)
        ->callAction('paymentOpenChargeback', ['provider_case_reference' => 'MTN-CB-1', 'amount_minor' => 5000, 'reason_code' => 'FRAUD']);
    expect(Chargeback::where('payment_intent_id', $this->payment->id)->value('status'))->toBe('OPEN');

    pqHarness([fn () => PaymentActions::requestRefund()], $this->payment)
        ->callAction('paymentRequestRefund', ['amount_minor' => 30000, 'reason_code' => 'CUSTOMER_REQUEST']);
    expect(Refund::where('payment_intent_id', $this->payment->id)->where('status', 'REQUESTED')->value('amount_minor'))->toBe(30000);

    pqHarness([fn () => PaymentActions::requestRefund()], $this->payment)
        ->callAction('paymentRequestRefund', ['amount_minor' => 90000, 'reason_code' => 'CUSTOMER_REQUEST'])->assertNotified(__('workflow_actions.failed'));
    expect(Refund::where('payment_intent_id', $this->payment->id)->count())->toBe(1);
});

it('retries a failed payment through PaymentRetryService and hides retry on a successful one', function () {
    $failed = makeMobileTestPayment($this->f['proposal'], $this->f['tenant'], ['status' => 'FAILED']);
    pqAs(pqUser($this->tenant, ['payments.allocations.read']), $this->tenant);
    pqHarness([fn () => PaymentActions::retry()], $this->payment)->assertActionHidden('paymentRetry');
    pqHarness([fn () => PaymentActions::retry()], $failed)->callAction('paymentRetry');
    expect(DB::table('payment_attempts')->where('payment_intent_id', $failed->id)->count())->toBeGreaterThanOrEqual(1);
});

it('drives a refund candidate through calculate, review, approve, pay and reconcile with maker-checker', function () {
    $maker = pqUser($this->tenant, ['refund.request', 'refund.review']);
    pqAs($maker, $this->tenant);
    pqHarness([fn () => PaymentActions::refundCandidate()], $this->payment)
        ->callAction('paymentRefundCandidate', ['reason_code' => 'DUPLICATE_PAYMENT', 'amount_minor' => 40000]);
    $refund = Refund::where('payment_intent_id', $this->payment->id)->sole();
    expect($refund->status)->toBe('CANDIDATE');

    pqHarness([fn () => RefundActions::calculate()], $refund)
        ->callAction('refundCalculate', ['deductions' => [['code' => 'POLICY_FEE', 'amount_minor' => 5000]]]);
    expect($refund->refresh()->status)->toBe('CALCULATED')->and($refund->amount_minor)->toBe(95000);
    // The calculator cannot review their own calculation (service refusal surfaces as a notification).
    pqHarness([fn () => RefundActions::review()], $refund)->callAction('refundReview')->assertNotified(__('workflow_actions.failed'));

    pqAs(pqUser($this->tenant, ['refund.review']), $this->tenant);
    pqHarness([fn () => RefundActions::review()], $refund)->callAction('refundReview');
    expect($refund->refresh()->status)->toBe('REQUESTED');

    pqAs(pqUser($this->tenant, ['refund.view']), $this->tenant);
    pqHarness([fn () => RefundActions::approve()], $refund)->assertActionHidden('refundApprove');
    pqAs(pqUser($this->tenant, ['refund.approve']), $this->tenant);
    pqHarness([fn () => RefundActions::approve()], $refund)->callAction('refundApprove');
    expect($refund->refresh()->status)->toBe('APPROVED');

    pqAs(pqUser($this->tenant, ['refund.pay']), $this->tenant);
    pqHarness([fn () => RefundActions::pay()], $refund)->callAction('refundPay', ['payout_method' => 'MOBILE_MONEY', 'provider_reference' => 'MOMO-RFD-9']);
    expect($refund->refresh()->status)->toBe('PAID');

    pqAs(pqUser($this->tenant, ['refund.reconcile']), $this->tenant);
    pqHarness([fn () => RefundActions::reconcile()], $refund)->callAction('refundReconcile', ['bank_reference' => 'BNK-9']);
    expect($refund->refresh()->status)->toBe('RECONCILED');
});

it('rejects a requested refund with a reason', function () {
    $refund = app(\App\Application\Finance\Refunds\RefundEngine::class)->candidate($this->payment, 'manual', null, 'GOODWILL', pqUser($this->tenant, []), 1000);
    pqAs(pqUser($this->tenant, ['refund.approve']), $this->tenant);
    pqHarness([fn () => RefundActions::reject()], $refund)->callAction('refundReject', ['reason' => 'Not a duplicate']);
    expect($refund->refresh()->status)->toBe('REJECTED');
});

it('opens a clearing batch, attaches payments, settles and reconciles it (settler ≠ reconciler)', function () {
    $ops = pqUser($this->tenant, ['clearing.view', 'clearing.manage', 'clearing.reconcile']);
    pqAs($ops, $this->tenant);
    pqHarness([fn () => ClearingActions::open()])->callAction('clearingOpen', ['provider' => 'mtn_momo', 'settlement_reference' => 'MTN-PQ-1', 'settlement_date' => '2026-10-14', 'currency' => 'XAF']);
    $batch = ClearingBatch::where('settlement_reference', 'MTN-PQ-1')->sole();

    pqHarness([fn () => ClearingActions::attach()], $batch)->callAction('clearingAttach', ['payment_ids' => [$this->payment->id]]);
    expect($batch->refresh()->expected_minor)->toBe(100000);

    pqHarness([fn () => ClearingActions::settle()], $batch)->callAction('clearingSettle', ['settled_minor' => 98000, 'fee_minor' => 2000, 'bank_reference' => 'BNK-PQ']);
    expect($batch->refresh()->status)->toBe('SETTLED');
    pqHarness([fn () => ClearingActions::reconcile()], $batch)->callAction('clearingReconcile')->assertNotified(__('workflow_actions.failed'));

    pqAs(pqUser($this->tenant, ['clearing.view']), $this->tenant);
    pqHarness([fn () => ClearingActions::reconcile()], $batch)->assertActionHidden('clearingReconcile');
    pqAs(pqUser($this->tenant, ['clearing.view', 'clearing.reconcile']), $this->tenant);
    pqHarness([fn () => ClearingActions::reconcile()], $batch)->callAction('clearingReconcile', ['notes' => 'ok']);
    expect($batch->refresh()->status)->toBe('RECONCILED');
});

it('gates the refund and clearing screens by refund.view / clearing.view and renders them', function () {
    pqAs(pqUser($this->tenant, ['payments.allocations.read']), $this->tenant);
    expect(RefundResource::canViewAny())->toBeFalse()->and(ClearingBatchResource::canViewAny())->toBeFalse();

    $u = pqUser($this->tenant, ['refund.view', 'clearing.view', 'refund.approve']);
    pqAs($u, $this->tenant);
    expect(RefundResource::canViewAny())->toBeTrue()->and(ClearingBatchResource::canViewAny())->toBeTrue();
    $refund = app(\App\Application\Finance\Refunds\RefundEngine::class)->candidate($this->payment, 'manual', null, 'GOODWILL', pqUser($this->tenant, []), 1000);
    Livewire::test(\App\Filament\Admin\Resources\Refunds\Pages\ViewRefund::class, ['record' => $refund->id])->assertOk()->assertSee($refund->refund_number);
    Livewire::test(\App\Filament\Admin\Resources\Refunds\Pages\ListRefunds::class)->assertOk()->assertCanSeeTableRecords([$refund]);
    Livewire::test(\App\Filament\Admin\Resources\PaymentRequests\Pages\ViewPaymentRequest::class, ['record' => $this->payment->id])->assertOk()
        ->assertSee(__('workflow_actions.payment_detail.allocations'))->assertSee($refund->refund_number);
});

// ---- quotes ------------------------------------------------------------------------------------------------------

/** An open, priced quote with one offer (a fresh tariff version per extra offer). */
function pqQuote(array $f, int $offers = 1): Quote
{
    $q = makeMobileTestQuote($f["tenant"], $f["party"], ["status" => "RATED", "lifecycle_state" => "CALCULATED"]);
    for ($i = 0; $i < $offers; $i++) {
        $t = $i === 0 ? $f["tariff"] : \App\Models\TariffVersion::create(["insurance_product_id" => $f["product"]->id, "version" => $i + 1, "effective_from" => now()->toDateString(), "status" => "APPROVED", "input_schema" => [], "rules" => [], "rules_hash" => Str::random(64)]);
        makeMobileTestQuoteOffer($q, $f["carrier"]->id, $f["product"]->id, $t->id, ["total_minor" => 100000 + $i]);
    }

    return $q;
}

it('cancels, declines and generates a quote with quotes.manage; hidden without it', function () {
    $quote = pqQuote($this->f);
    pqAs(pqUser($this->tenant, ['quotes.read'], 'SALES_OFFICER'), $this->tenant);
    pqHarness([fn () => QuoteActions::cancel(), fn () => QuoteActions::decline(), fn () => QuoteActions::generate()], $quote)
        ->assertActionHidden('quoteCancel')->assertActionHidden('quoteDecline')->assertActionHidden('quoteGenerate');

    pqAs(pqUser($this->tenant, ['quotes.manage'], 'SALES_OFFICER'), $this->tenant);
    pqHarness([fn () => QuoteActions::generate()], $quote)->callAction('quoteGenerate');
    expect($quote->refresh()->quote_number)->not->toBeNull();

    pqHarness([fn () => QuoteActions::decline()], $quote)->callAction('quoteDecline', ['reason_code' => 'PRICE_TOO_HIGH', 'note' => 'Too expensive']);
    expect($quote->refresh()->decline_reason_code)->toBe('PRICE_TOO_HIGH');

    $other = pqQuote($this->f);
    pqHarness([fn () => QuoteActions::cancel()], $other)->callAction('quoteCancel');
    expect($other->refresh()->cancelled_at)->not->toBeNull()->and($other->offers()->value('status'))->toBe('WITHDRAWN');
});

it('saves an offer comparison for staff with quotes.read', function () {
    $quote = pqQuote($this->f, 2);
    pqAs(pqUser($this->tenant, ['quotes.read'], 'SALES_OFFICER'), $this->tenant);
    pqHarness([fn () => QuoteActions::saveComparison()], $quote)->callAction('quoteSaveComparison', ['offer_ids' => $quote->offers()->pluck('id')->all()])
        ->assertNotified(__('workflow_actions.quoteSaveComparison.done'));
    expect(DB::table('saved_comparisons')->where('quote_request_id', $quote->id)->count())->toBe(1);
});

it('applies an approved premium override to the offer', function () {
    $quote = pqQuote($this->f);
    $offer = $quote->offers()->first();
    $maker = pqUser($this->tenant, ['quotes.premium_override.request'], 'SALES_OFFICER');
    pqAs($maker, $this->tenant);
    pqHarness([fn () => QuoteActions::requestOverride()], $quote)->callAction('quoteRequestOverride',
        ['offer_id' => $offer->id, 'premium_minor' => 80000, 'reason_code' => \App\Application\Quotes\QuotePremiumOverrideService::REASONS[0], 'justification' => 'Loyal client with no claims.']);
    $checker = pqUser($this->tenant, ['quotes.premium_override.approve'], 'CARRIER_ADMIN');
    pqAs($checker, $this->tenant);
    pqHarness([fn () => QuoteActions::decideOverride()], $quote)->callAction('quoteDecideOverride', ['offer_id' => $offer->id, 'decision' => 'APPROVED']);
    pqHarness([fn () => QuoteActions::applyOverride()], $quote)->callAction('quoteApplyOverride', ['offer_id' => $offer->id])
        ->assertNotified(__('workflow_actions.quoteApplyOverride.done'));
    expect($offer->refresh()->premium_minor)->toBe(80000)->and($offer->original_premium_minor)->toBe(100000);

    pqAs($maker, $this->tenant);
    pqHarness([fn () => QuoteActions::applyOverride()], $quote)->assertActionHidden('quoteApplyOverride');
});

/** A quote with an open MANUAL insurer request (as ManualQuotationTest). */
function pqCarrierRequest(array $f): CarrierQuoteRequest
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'PQ Insurer '.Str::random(4), 'status' => 'ACTIVE']);
    $carrier = Carrier::create(['party_id' => $party->id, 'cima_code' => 'PQ-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE']);
    $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'AUTO', 'code' => 'AUTO-'.Str::upper(Str::random(6)), 'name' => 'PQ Motor', 'version' => 1,
        'effective_from' => now()->subYear()->toDateString(), 'status' => 'ACTIVE']);
    $quote = Quote::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'line_code' => 'AUTO', 'status' => 'REFERRED', 'currency' => 'XAF',
        'risk_facts' => [], 'submitted_at' => now(), 'expires_at' => now()->addDays(7), 'version' => 1]);

    return app(QuoteRequestService::class)->open($quote, $carrier->id, $product->id, null);
}

it('insurer staff start and decline a quote request from the register, scoped to their own carrier', function () {
    $req = pqCarrierRequest($this->f);
    $other = pqCarrierRequest($this->f);

    pqAs(pqUser($this->tenant, ['carrier.quote_requests.view'], 'CARRIER_STAFF', $req->carrier_id), $this->tenant);
    pqHarness([fn () => QuoteActions::carrierStart()], $req)->assertActionHidden('carrierQuoteStart');

    pqAs(pqUser($this->tenant, ['carrier.quote_requests.view', 'carrier.quote_requests.respond'], 'CARRIER_STAFF', $req->carrier_id), $this->tenant);
    pqHarness([fn () => QuoteActions::carrierStart()], $req)->callAction('carrierQuoteStart');
    expect($req->refresh()->status)->toBe('IN_PROGRESS');
    pqHarness([fn () => QuoteActions::carrierDecline()], $req)->callAction('carrierQuoteDecline', ['decline_reason_code' => 'OUT_OF_APPETITE', 'notes' => 'Not our risk']);
    expect($req->refresh()->status)->toBe('DECLINED');

    // Another insurer's request is not reachable (404 → nothing changes).
    expect(fn () => pqHarness([fn () => QuoteActions::carrierStart()], $other)->callAction('carrierQuoteStart'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($other->refresh()->status)->toBe('REQUESTED');
});

it('broker staff cancel an insurer request and a supervisor records a decline on behalf with evidence', function () {
    $req = pqCarrierRequest($this->f);
    $quote = Quote::findOrFail($req->quote_id);
    pqAs(pqUser($this->tenant, ['quotes.carrier_requests.create'], 'SALES_OFFICER'), $this->tenant);
    pqHarness([fn () => QuoteActions::cancelCarrierRequest()], $quote)->callAction('quoteCancelCarrierRequest', ['request_id' => $req->id, 'reason' => 'Client withdrew']);
    expect($req->refresh()->status)->toBe('CANCELLED');

    $req2 = pqCarrierRequest($this->f);
    $quote2 = Quote::findOrFail($req2->quote_id);
    $doc = (string) Str::uuid();
    DB::table('documents')->insert(['id' => $doc, 'tenant_id' => $this->tenant, 'category' => 'CARRIER_OFFER', 'storage_key' => 'pq/'.$doc, 'mime_type' => 'application/pdf',
        'size_bytes' => 100, 'sha256' => str_repeat('c', 64), 'scan_status' => 'CLEAN', 'created_at' => now(), 'updated_at' => now()]);
    pqAs(pqUser($this->tenant, ['quotes.carrier_requests.record_on_behalf'], 'SALES_OFFICER'), $this->tenant);
    pqHarness([fn () => QuoteActions::declineCarrierOnBehalf()], $quote2)
        ->callAction('quoteDeclineCarrierOnBehalf', ['request_id' => $req2->id, 'decline_reason_code' => 'OUT_OF_APPETITE', 'evidence_document_id' => $doc]);
    expect($req2->refresh()->status)->toBe('DECLINED');
});
