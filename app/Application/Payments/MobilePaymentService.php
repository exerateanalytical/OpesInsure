<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Identity\PartyResolver;
use App\Models\PaymentIntentRecord;
use App\Models\Refund;
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

        return $this->initiation->initiate($intent);
    }

    /** @return array<string, mixed> A JSON receipt — no PDF renderer exists anywhere in this app; see the batch report. */
    public function receipt(string $paymentId, User $user, string $tenantId): array
    {
        $intent = $this->owned($paymentId, $user, $tenantId);

        return [
            'id' => $intent->id,
            'reference' => $intent->provider_reference,
            'provider' => $intent->provider,
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'status' => $intent->status,
            'payer_phone_e164' => $intent->payer_phone_e164,
            'proposal_id' => $intent->proposal_id,
            'requested_at' => $intent->created_at?->toIso8601String(),
            'confirmed_at' => $intent->status === 'SUCCEEDED' ? $intent->updated_at?->toIso8601String() : null,
        ];
    }

    public function requestRefund(string $paymentId, array $data, User $user, string $tenantId): Refund
    {
        $intent = $this->owned($paymentId, $user, $tenantId);

        return $this->financialCases->requestRefund($intent, $data, $user);
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
