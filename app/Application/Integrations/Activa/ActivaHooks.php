<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Models\CarrierApiConnection;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use Throwable;

/**
 * Entry points called by the issuing / payment flows. Cheap when the carrier has no Activa connection (one indexed
 * lookup) and never throws: an Activa problem must never undo an issued policy or a confirmed payment — failures are
 * recorded by ActivaPolicySync and picked up by activa:reconcile.
 */
final class ActivaHooks
{
    public function policyIssued(Policy $policy): void
    {
        $this->dispatch($policy->carrier_id, 'policy', $policy->id);
    }

    public function paymentSucceeded(PaymentIntentRecord $payment): void
    {
        try {
            $carrierId = $payment->proposal_id ? \App\Models\Proposal::with('offer')->find($payment->proposal_id)?->offer?->carrier_id : null;
            $this->dispatch($carrierId, 'payment_intent', $payment->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function dispatch(?string $carrierId, string $type, string $id): void
    {
        try {
            if ($carrierId === null || ! CarrierApiConnection::query()->where('carrier_id', $carrierId)->where('provider', config('activa.provider'))
                ->whereIn('status', ['ACTIVE', 'PENDING_VERIFICATION'])->exists()) {
                return;
            }
            ActivaSyncJob::dispatch($type, $id)->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
