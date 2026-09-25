<?php

declare(strict_types=1);

namespace App\Application\Finance\Refunds;

use Illuminate\Support\ServiceProvider;

/** Binds the reconciliation refund-candidate sink only when Batch 9-5's interface is present. */
final class RefundCandidateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (interface_exists('App\\Application\\Reconciliation\\RefundCandidateSink')) {
            $this->app->bind('App\\Application\\Reconciliation\\RefundCandidateSink', DuplicatePaymentRefundCandidateSink::class);
        }
        // refunds open/settle a PAYABLE financial obligation (Batch 9-1 ledger)
        $this->app->bind(RefundObligationLink::class, ObligationRefundLink::class);
    }
}
