<?php

declare(strict_types=1);

namespace App\Application\Finance\Refunds;

use App\Application\Finance\Obligations\ObligationServiceRefundLink;
use App\Models\PaymentIntentRecord;
use App\Models\Refund;

/** REQ-PAY-009 ↔ REQ-OBL-001: an approved refund is a PAYABLE obligation to the payer, settled on payout. */
final class ObligationRefundLink implements RefundObligationLink
{
    public function __construct(private readonly ObligationServiceRefundLink $link) {}

    public function open(Refund $refund): ?string
    {
        $payment = PaymentIntentRecord::with('proposal')->find($refund->payment_intent_id);
        $policyId = $payment?->proposal_id ? \App\Models\Policy::where('proposal_id', $payment->proposal_id)->value('id') : null;

        return $this->link->openRefundPayable(
            (string) $refund->tenant_id, (string) $refund->id, (int) $refund->amount_minor, (string) $refund->currency,
            $payment?->proposal?->party_id, $policyId, null, $refund->approved_by,
        );
    }

    public function settle(Refund $refund): void
    {
        $this->link->settleRefund((string) $refund->id, (int) $refund->amount_minor, (string) ($refund->provider_reference ?: 'refund:'.$refund->id), $refund->paid_by);
    }
}
