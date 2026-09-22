<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Application\Identity\PartyResolver;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * BATCH_1_CLAUDE_HANDOFF.md: "This endpoint closes the dangerous gap
 * between successful collection and actual insurance coverage." A
 * SUCCEEDED payment does not by itself mean a policy exists — this is the
 * one place that tells the mobile app which of the two is actually true,
 * rather than letting the app infer coverage from payment status alone.
 */
final class MobilePurchaseStatusService
{
    public function __construct(private PartyResolver $parties)
    {
    }

    /** @return array{status: string, payment: array|null, policy: array|null} */
    public function status(string $proposalId, User $user, string $tenantId): array
    {
        $proposal = Proposal::where('tenant_id', $tenantId)->find($proposalId);

        if (! $proposal) {
            throw new ModelNotFoundException;
        }

        $party = $this->parties->forUser($user);

        if (! $party || $proposal->party_id !== $party->id) {
            throw new AuthorizationException;
        }

        $payment = PaymentIntentRecord::where('proposal_id', $proposal->id)->latest('created_at')->first();
        $policy = Policy::where('proposal_id', $proposal->id)->first();

        return [
            'status' => $this->aggregateStatus($payment, $policy),
            'payment' => $payment ? ['id' => $payment->id, 'proposal_id' => $payment->proposal_id, 'status' => $payment->status] : null,
            'policy' => $policy ? [
                'id' => $policy->id,
                'policy_number' => $policy->policy_number,
                'certificate_number' => $policy->certificate_number,
                'status' => $policy->status,
                'issued_at' => $policy->issued_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function aggregateStatus(?PaymentIntentRecord $payment, ?Policy $policy): string
    {
        if ($policy) {
            return 'POLICY_ISSUED';
        }

        if (! $payment) {
            return 'PAYMENT_PENDING';
        }

        return match ($payment->status) {
            'PENDING_CUSTOMER' => 'PAYMENT_PENDING',
            'PROCESSING' => 'PAYMENT_PROCESSING',
            'FAILED', 'EXPIRED' => 'PAYMENT_FAILED',
            'SUCCEEDED' => 'ISSUANCE_PENDING',
            default => 'PAYMENT_PENDING',
        };
    }
}
