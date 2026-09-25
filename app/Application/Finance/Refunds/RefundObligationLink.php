<?php

declare(strict_types=1);

namespace App\Application\Finance\Refunds;

use App\Models\Refund;

/**
 * Seam to agent 9-1's financial_obligations (PAYABLE / REFUND). When a binding exists the engine opens the
 * payable on approval and settles it on payout; unbound (the default until 9-1 lands) nothing happens.
 * The bound implementation is expected to wrap App\Application\Finance\Obligations\ObligationService.
 */
interface RefundObligationLink
{
    /** Opens the payable obligation for an approved refund; returns the obligation id. */
    public function open(Refund $refund): ?string;

    public function settle(Refund $refund): void;
}
