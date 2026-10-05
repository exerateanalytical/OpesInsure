<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\Demo\DemoPersonas;
use App\Application\Events\OutboxWriter;
use App\Application\Payments\MobilePaymentService;
use App\Application\Payments\PaymentInitiationService;
use App\Application\Policies\MobileWalletService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Interfaces\Http\Errors\ErrorCode;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer money screens with no own-party API before launch (2026-10-02):
 *  - GET  mobile/policies/{policy}/instalments               REQ-PAY-006 schedule of the caller's own policy;
 *  - POST mobile/policies/{policy}/instalments/{id}/pay      one instalment through the normal payment path: a payment intent
 *         on the policy's application keyed to the instalment's INSTALMENT obligation (payment_intents.financial_obligation_id),
 *         initiated with PaymentInitiationService. When it succeeds the webhook's PolicyPremiumObligations::settlePayment settles
 *         that obligation and the instalment (PolicyRecoveryService::settleInstalment) — nothing is settled here.
 *         Double-charge guard (same rule as PaymentRequestService::assertNotPaid, per instalment): a paid / waived instalment
 *         or a SUCCEEDED intent → 409 PAYMENT_ALREADY_MADE; an intent still with the operator → 409 PAYMENT_IN_PROGRESS;
 *         the same Idempotency-Key returns the same intent.
 *  - GET  mobile/payments/{payment}/refunds                  refunds raised on one of the caller's own payments;
 *  - GET  mobile/refunds/{refund}                            one own refund (notification deep link /refunds/{id} → payment).
 */
final class MobileCustomerMoneyController
{
    /** Instalment statuses that can still be paid by the customer. */
    public const PAYABLE = ['DUE', 'OVERDUE', 'GRACE', 'DEFAULTED'];

    public function __construct(
        private readonly MobileWalletService $wallet,
        private readonly MobilePaymentService $payments,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function instalments(string $policy, Request $request): JsonResponse
    {
        $p = $this->policy($policy, $request);
        $rows = DB::table('policy_premium_instalments')->where('policy_id', $p->id)->where('tenant_id', $p->tenant_id)->orderBy('sequence')->get();
        $intents = $this->intentsFor($rows->pluck('financial_obligation_id')->filter()->values()->all());

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r, $intents[$r->financial_obligation_id] ?? null))->values(), 'meta' => [
            'policy_id' => $p->id, 'policy_number' => $p->policy_number, 'currency' => $p->currency ?? 'XAF',
            'outstanding_minor' => (int) $rows->whereIn('status', self::PAYABLE)->sum(fn ($r) => max(0, (int) $r->amount_minor - (int) $r->paid_minor)),
        ]]);
    }

    public function payInstalment(string $policy, string $instalment, Request $request): JsonResponse
    {
        $d = $request->validate([
            'provider' => 'required|in:fake,maviance,campay,mtn_momo,orange_money',
            'payer_phone_e164' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        abort_if(strlen($key) < 16 || strlen($key) > 80, 422, __('customer_flows.idempotency_required'));
        $p = $this->policy($policy, $request);
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $instalment), 404);
        $user = $request->user();
        DemoPersonas::assertProviderAllowed($d['provider'], $user);
        $scopedKey = 'instalment:'.$instalment.':'.$key;

        $intent = DB::transaction(function () use ($p, $instalment, $d, $user, $scopedKey): PaymentIntentRecord {
            $row = DB::table('policy_premium_instalments')->where(['id' => $instalment, 'policy_id' => $p->id, 'tenant_id' => $p->tenant_id])->lockForUpdate()->first() ?? abort(404);
            if ($existing = PaymentIntentRecord::where(['tenant_id' => $p->tenant_id, 'idempotency_key' => $scopedKey])->first()) {
                return $existing;
            }
            $this->assertNotPaid($row);
            if (! in_array($row->status, self::PAYABLE, true) || ! $row->financial_obligation_id || ! $p->proposal_id) {
                throw new ApiProblemException('INSTALMENT_NOT_PAYABLE', 422, __('customer_flows.instalment_not_payable'));
            }
            $amount = max(0, (int) $row->amount_minor - (int) $row->paid_minor);
            abort_if($amount <= 0, 409, __('customer_flows.instalment_paid'));

            $intent = PaymentIntentRecord::create([
                'tenant_id' => $p->tenant_id, 'proposal_id' => $p->proposal_id, 'financial_obligation_id' => $row->financial_obligation_id,
                'provider' => $d['provider'], 'payer_phone_e164' => $d['payer_phone_e164'], 'amount_minor' => $amount, 'currency' => $row->currency,
                'status' => 'PENDING_CUSTOMER', 'idempotency_key' => $scopedKey, 'provider_snapshot' => [], 'requested_by' => $user->id,
                'expires_at' => now()->addMinutes(15), 'customer_prompted_at' => now(), 'request_channel' => 'MOBILE',
            ]);
            $this->audit->record('payment.requested', 'payment_intent', $intent->id, ['amount_minor' => $amount, 'provider' => $d['provider'], 'instalment_id' => $row->id, 'sequence' => (int) $row->sequence]);
            $this->outbox->record('payment.requested', 'payment_intent', $intent->id, ['payment_intent_id' => $intent->id, 'proposal_id' => $p->proposal_id, 'provider' => $d['provider'], 'instalment_id' => $row->id]);

            return $intent;
        });

        // Initiation talks to the operator: outside the lock, and only for a fresh intent (a replay returns the stored one).
        $created = $intent->wasRecentlyCreated;
        if ($created) {
            $intent = app(PaymentInitiationService::class)->initiate($intent);
        }

        return response()->json(['data' => $this->intent($intent->refresh())], $created ? 201 : 200);
    }

    public function paymentRefunds(string $payment, Request $request): JsonResponse
    {
        $intent = $this->payments->show($payment, $request->user(), app(TenantContext::class)->id());

        return response()->json(['data' => DB::table('refunds')->where('tenant_id', $intent->tenant_id)->where('payment_intent_id', $intent->id)
            ->orderByDesc('created_at')->get()->map(fn ($r) => $this->refund($r))->values()]);
    }

    public function refundShow(string $refund, Request $request): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $refund), 404);
        $tenant = app(TenantContext::class)->id();
        $row = DB::table('refunds')->where('tenant_id', $tenant)->where('id', $refund)->whereNotNull('payment_intent_id')->first() ?? abort(404);
        // Ownership is the payment's: MobilePaymentService::show 404s for anyone else's payment.
        $this->payments->show((string) $row->payment_intent_id, $request->user(), $tenant);

        return response()->json(['data' => $this->refund($row)]);
    }

    private function policy(string $id, Request $request): Policy
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id), 404);

        return $this->wallet->policy($id, $request->user(), app(TenantContext::class)->id());
    }

    /** 409 when the instalment is settled or a collection for it is still with the operator (never charge twice). */
    private function assertNotPaid(object $row): void
    {
        if (in_array($row->status, ['PAID', 'WAIVED'], true)) {
            throw new ApiProblemException(ErrorCode::PAYMENT_ALREADY_MADE, 409, __('customer_flows.instalment_paid'));
        }
        if (! $row->financial_obligation_id) {
            return;
        }
        $q = fn () => PaymentIntentRecord::where(['tenant_id' => $row->tenant_id, 'financial_obligation_id' => $row->financial_obligation_id]);
        if ($paid = $q()->where('status', 'SUCCEEDED')->latest('updated_at')->first()) {
            throw new ApiProblemException(ErrorCode::PAYMENT_ALREADY_MADE, 409, __('customer_flows.instalment_paid'), [], ['payment_id' => $paid->id]);
        }
        if ($live = $q()->where(fn ($w) => $this->live($w))->latest('created_at')->first()) {
            throw new ApiProblemException(ErrorCode::PAYMENT_IN_PROGRESS, 409, __('customer_flows.instalment_in_progress'), [], ['payment_id' => $live->id]);
        }
    }

    private function live($w)
    {
        return $w->where('status', 'PROCESSING')->orWhere(fn ($x) => $x->whereIn('status', ['CREATED', 'PENDING_CUSTOMER'])->whereNotNull('provider_reference')
            ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now())));
    }

    /** @param list<string> $obligations @return array<string, object> latest intent per obligation */
    private function intentsFor(array $obligations): array
    {
        if ($obligations === []) {
            return [];
        }

        return PaymentIntentRecord::whereIn('financial_obligation_id', $obligations)->orderBy('created_at')->get(['id', 'financial_obligation_id', 'status', 'provider_reference', 'expires_at', 'created_at'])
            ->keyBy('financial_obligation_id')->all();
    }

    private function present(object $r, ?PaymentIntentRecord $intent): array
    {
        $outstanding = max(0, (int) $r->amount_minor - (int) $r->paid_minor);
        $inFlight = $intent && ($intent->status === 'PROCESSING' || (in_array($intent->status, ['CREATED', 'PENDING_CUSTOMER'], true) && $intent->provider_reference && (! $intent->expires_at || $intent->expires_at->isFuture())));

        return [
            'id' => $r->id, 'number' => (int) $r->sequence, 'due_date' => (string) $r->due_date, 'amount_minor' => (int) $r->amount_minor,
            'paid_minor' => (int) $r->paid_minor, 'outstanding_minor' => $outstanding, 'currency' => $r->currency, 'status' => $r->status,
            'paid_at' => $r->settled_at ? \Illuminate\Support\Carbon::parse($r->settled_at)->toIso8601String() : null, 'grace_ends_on' => $r->grace_ends_on,
            'overdue' => in_array($r->status, ['OVERDUE', 'GRACE', 'DEFAULTED'], true),
            'payable' => in_array($r->status, self::PAYABLE, true) && $outstanding > 0 && $r->financial_obligation_id !== null && ! $inFlight && $intent?->status !== 'SUCCEEDED',
            'payment_id' => $intent?->id, 'payment_status' => $intent?->status, 'payment_in_progress' => (bool) $inFlight,
        ];
    }

    private function intent(PaymentIntentRecord $i): array
    {
        return ['id' => $i->id, 'status' => $i->status, 'provider' => $i->provider, 'payer_phone_e164' => $i->payer_phone_e164, 'amount_minor' => (int) $i->amount_minor,
            'currency' => $i->currency, 'proposal_id' => $i->proposal_id, 'provider_reference' => $i->provider_reference, 'created_at' => $i->created_at?->toIso8601String()];
    }

    private function refund(object $r): array
    {
        $iso = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->toIso8601String() : null;

        return [
            'id' => $r->id, 'payment_id' => $r->payment_intent_id, 'refund_number' => $r->refund_number, 'status' => $r->status,
            'amount_minor' => (int) $r->amount_minor, 'currency' => $r->currency, 'reason_code' => $r->reason_code, 'payout_method' => $r->payout_method ?? null,
            'requested_at' => $iso($r->created_at), 'approved_at' => $iso($r->approved_at ?? null), 'paid_at' => $iso($r->paid_at ?? null),
            'rejected_at' => $iso($r->rejected_at ?? null), 'rejection_reason' => $r->rejection_reason ?? null,
        ];
    }
}
