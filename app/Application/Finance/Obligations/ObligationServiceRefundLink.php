<?php

declare(strict_types=1);

namespace App\Application\Finance\Obligations;

use Illuminate\Support\Facades\DB;

/**
 * REQ-PAY-009 ↔ REQ-OBL-001 adapter for the refund engine (Batch 9-6 RefundObligationLink).
 * Deliberately does not `implements` the interface (it lives on another branch); bind it at merge, e.g.
 *   if (interface_exists(RefundObligationLink::class)) $app->bind(RefundObligationLink::class, ObligationServiceRefundLink::class);
 *
 *   openRefundPayable — on refund approval: a PAYABLE / REFUND obligation to the customer (creditor), source_type 'refund'.
 *   settleRefund      — on payout: settles it (idempotent per payout reference).
 *   cancelRefund      — refund rejected / voided after approval.
 */
final class ObligationServiceRefundLink
{
    public function __construct(private readonly ObligationService $obligations) {}

    public function openRefundPayable(string $tenantId, string $refundId, int $amountMinor, string $currency, ?string $partyId = null, ?string $policyId = null, mixed $dueAt = null, ?string $actorId = null): string
    {
        return $this->obligations->create([
            'tenant_id' => $tenantId, 'kind' => 'PAYABLE', 'type' => 'REFUND', 'source_type' => 'refund', 'source_id' => $refundId,
            'currency' => $currency, 'amount_minor' => $amountMinor, 'due_at' => $dueAt ?? now(), 'policy_id' => $policyId,
            'creditor_type' => $partyId ? 'party' : null, 'creditor_id' => $partyId, 'description' => 'Refund',
        ], $actorId)->id;
    }

    public function settleRefund(string $refundId, int $amountMinor, string $payoutReference, ?string $actorId = null): ?string
    {
        $id = $this->obligationFor($refundId);

        return $id ? $this->obligations->settle($id, $amountMinor, $payoutReference, $actorId)->id : null;
    }

    public function cancelRefund(string $refundId, string $reason, ?string $actorId = null): ?string
    {
        $id = $this->obligationFor($refundId);

        return $id ? $this->obligations->cancel($id, $reason, $actorId)->id : null;
    }

    public function obligationFor(string $refundId): ?string
    {
        return DB::table('financial_obligations')->where(['source_type' => 'refund', 'source_id' => $refundId, 'type' => 'REFUND'])->value('id');
    }
}
