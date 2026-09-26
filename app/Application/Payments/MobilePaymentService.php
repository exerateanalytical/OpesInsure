<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Identity\PartyResolver;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Refund;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing payment read/retry/refund-request — distinct from the
 * existing staff-facing PaymentController, which has no ownership check at
 * all (only tenant scoping) and gates refunds behind the staff
 * `refund.request` permission. PaymentIntentRecord has no direct party_id;
 * ownership is resolved transitively via proposal.party_id.
 */
final class MobilePaymentService
{
    public function __construct(
        private PartyResolver $parties,
        private PaymentInitiationService $initiation,
        private FinancialCaseService $financialCases,
    ) {
    }

    public function list(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->orderByDesc('created_at')->paginate($perPage);
    }

    public function show(string $paymentId, User $user, string $tenantId): PaymentIntentRecord
    {
        return $this->owned($paymentId, $user, $tenantId);
    }

    public function retry(string $paymentId, User $user, string $tenantId): PaymentIntentRecord
    {
        $intent = $this->owned($paymentId, $user, $tenantId);

        if ($intent->status !== 'FAILED') {
            throw ValidationException::withMessages(['status' => __('wave12.payment_not_retryable')]);
        }

        // REQ-PAY-008: a new attempt under the same intent, with retry limits and the no-double-charge guards.
        return app(Retries\PaymentRetryService::class)->retry($intent, $user)['payment'];
    }

    /**
     * JSON receipt. receipt_number/issued_at are what receipt.tsx renders;
     * reference/confirmed_at are kept for older app builds. download_url is
     * a short-lived signed link to the PDF version.
     *
     * @return array<string, mixed>
     */
    public function receipt(string $paymentId, User $user, string $tenantId): array
    {
        return $this->receiptData($this->owned($paymentId, $user, $tenantId), true);
    }

    /** @return array<string, mixed> */
    private function receiptData(PaymentIntentRecord $intent, bool $withUrl): array
    {
        $intent->loadMissing('proposal.offer.product', 'proposal.offer.carrier.party', 'proposal.party');
        $confirmedAt = $intent->status === 'SUCCEEDED' ? ($intent->reconciled_at ?? $intent->updated_at) : null;
        $policy = $intent->proposal_id ? Policy::where('proposal_id', $intent->proposal_id)->first() : null;

        return [
            'id' => $intent->id,
            'receipt_number' => self::receiptNumber($intent),
            'issued_at' => $confirmedAt?->toIso8601String(),
            'reference' => $intent->provider_reference,
            'provider' => $intent->provider,
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'status' => $intent->status,
            'payer_phone_e164' => $intent->payer_phone_e164,
            'payer_name' => $intent->proposal?->party?->display_name,
            'proposal_id' => $intent->proposal_id,
            'product_name' => $intent->proposal?->offer?->product?->name,
            'carrier_name' => $intent->proposal?->offer?->carrier?->party?->display_name,
            'policy_id' => $policy?->id,
            'policy_number' => $policy?->policy_number,
            'requested_at' => $intent->created_at?->toIso8601String(),
            'confirmed_at' => $confirmedAt?->toIso8601String(),
            'download_url' => $withUrl ? URL::temporarySignedRoute('mobile.payments.receipt.pdf', now()->addMinutes((int) config('lifecycle.download_ttl_minutes', 30)), ['payment' => $intent->id]) : null,
        ];
    }

    public static function receiptNumber(PaymentIntentRecord $intent): string
    {
        return 'RCT-'.($intent->created_at?->format('Ymd') ?? now()->format('Ymd')).'-'.strtoupper(substr(str_replace('-', '', $intent->id), -8));
    }

    /** Rendered on demand behind a signed URL — nothing to store or leak. */
    public function receiptPdf(string $paymentId): Response
    {
        $intent = PaymentIntentRecord::findOrFail($paymentId);
        $receipt = $this->receiptData($intent, false);
        $carrier = $intent->proposal?->offer?->carrier;
        $letterhead = $carrier ? \App\Application\Documents\Letterhead\LetterheadResolver::forDocument('INSURER', (string) ($carrier->party?->display_name ?? 'Insurer'), $carrier->id, $carrier->party?->display_name, null, null) : null;
        // D3: canonical secure shell (RECEIPT master shell); same receipt number and data.
        $bytes = app(\App\Application\Documents\Engine\SecureShellRenderer::class)->render([
            'type_code' => 'PAYMENT_RECEIPT', 'shell' => 'TPL-SHELL-PREMIUM-RECEIPT-001', 'number' => (string) $receipt['receipt_number'],
            'issuer_name' => (string) ($receipt['carrier_name'] ?? 'OpesInsure'), 'letterhead' => $letterhead, 'currency' => $receipt['currency'],
            'title_en' => 'Payment receipt', 'title_fr' => 'Reçu de paiement', 'label' => 'PAYMENT '.$receipt['status'], 'issued_at' => $receipt['issued_at'] ?? now(),
            'values' => array_filter([
                'party.name' => $receipt['payer_name'], 'policy.insurer' => $receipt['carrier_name'], 'policy.product' => $receipt['product_name'],
                'payment.reference' => $receipt['reference'], 'payment.amount' => $receipt['amount_minor'], 'payment.paid_at' => $receipt['issued_at'],
                'payment.method' => strtoupper(str_replace('_', ' ', (string) $receipt['provider'])), 'payment.status' => $receipt['status'],
            ], fn ($v) => $v !== null),
            'sections' => [['heading' => 'Reçu / Receipt', 'paragraphs' => array_values(array_filter([
                'Payeur / Payer: '.trim(($receipt['payer_name'] ?? '—').' '.$receipt['payer_phone_e164']),
                $receipt['policy_number'] ? 'Police / Policy: '.$receipt['policy_number'] : null,
            ]))]],
            'status' => $receipt['status'] === 'SUCCEEDED' ? 'ISSUED' : (string) $receipt['status'], 'template_ref' => 'SYSTEM payment receipt',
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$receipt['receipt_number'].'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A7: the app sends only {reason}. Defaults: amount = refundable balance
     * (the full amount when nothing is refunded yet), reason_code =
     * CUSTOMER_REQUEST, idempotency key = supplied header/body key or a
     * deterministic per-payment/user/day key so a double tap can't open two.
     */
    public function requestRefund(string $paymentId, array $data, User $user, string $tenantId): Refund
    {
        $intent = $this->owned($paymentId, $user, $tenantId);

        $alreadyRefunding = (int) Refund::where('payment_intent_id', $intent->id)->whereIn('status', Refund::ACTIVE_STATUSES)->sum('amount_minor');
        $key = $data['idempotency_key'] ?? hash('sha256', 'mobile-refund|'.$intent->id.'|'.$user->id.'|'.now()->toDateString());
        $existing = Refund::where('tenant_id', $intent->tenant_id)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $payload = [
            'amount_minor' => (int) ($data['amount_minor'] ?? max(0, (int) $intent->amount_minor - $alreadyRefunding)),
            'reason_code' => $data['reason_code'] ?? 'CUSTOMER_REQUEST',
            'notes' => $data['notes'] ?? $data['reason'] ?? null,
            'idempotency_key' => $key,
        ];
        if ($payload['amount_minor'] < 1) {
            throw ValidationException::withMessages(['amount_minor' => __('wave4.refund_exceeds_balance')]);
        }

        return $this->financialCases->requestRefund($intent, $payload, $user);
    }

    private function owned(string $paymentId, User $user, string $tenantId): PaymentIntentRecord
    {
        $intent = $this->ownedQuery($user, $tenantId)->find($paymentId);

        if (! $intent) {
            $exists = PaymentIntentRecord::where('tenant_id', $tenantId)->where('id', $paymentId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $intent;
    }

    private function ownedQuery(User $user, string $tenantId)
    {
        $party = $this->parties->forUser($user);
        $query = PaymentIntentRecord::where('tenant_id', $tenantId);

        // No matching party at all: an empty (never-matching) query, not an
        // exception — a customer with no resolvable party simply has no
        // payments, same as one who happens to have zero real ones. A raw
        // 'never-matches' string would fail as an invalid uuid literal, so
        // short-circuit to a guaranteed-empty condition instead.
        if (! $party) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('proposal', fn ($q) => $q->where('party_id', $party->id));
    }
}
