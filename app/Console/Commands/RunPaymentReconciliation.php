<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Policies\PaymentIssuanceTrigger;
use App\Models\PaymentIntentRecord;
use Illuminate\Console\Command;

/**
 * Hourly catch-up for the payment -> issuance pipeline (A4/A8). Statement
 * imports remain the manual ReconciliationService flow; this command closes
 * the automated gaps:
 *  1. a SUCCEEDED payment confirmed by a provider status event
 *     (payment_events PROVIDER_STATUS -> SUCCEEDED) but not yet marked
 *     reconciled is marked reconciled;
 *  2. every reconciled SUCCEEDED payment whose proposal is still waiting
 *     (PAYMENT_PENDING, no issuance request, no policy) gets its issuance
 *     request opened — e.g. when the trigger failed transiently.
 */
final class RunPaymentReconciliation extends Command
{
    protected $signature = 'reconciliation:run {--limit=500}';

    protected $description = 'Reconcile provider-confirmed payments and open any missing issuance requests.';

    public function handle(PaymentIssuanceTrigger $trigger): int
    {
        $limit = (int) $this->option('limit');

        $reconciled = PaymentIntentRecord::where('status', 'SUCCEEDED')->whereNull('reconciled_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('payment_events as e')->whereColumn('e.payment_intent_id', 'payment_intents.id')
                ->where('e.type', 'PROVIDER_STATUS')->where('e.new_status', 'SUCCEEDED'))
            ->limit($limit)->update(['reconciled_at' => now()]);

        $opened = 0;
        PaymentIntentRecord::where('status', 'SUCCEEDED')->whereNotNull('reconciled_at')->whereNotNull('proposal_id')
            ->whereHas('proposal', fn ($q) => $q->where('status', 'PAYMENT_PENDING'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('policy_issuance_requests as r')->whereColumn('r.proposal_id', 'payment_intents.proposal_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('policies as p')->whereColumn('p.proposal_id', 'payment_intents.proposal_id'))
            ->limit($limit)->get()
            ->each(function (PaymentIntentRecord $payment) use ($trigger, &$opened): void {
                if ($trigger->afterPaymentSucceeded($payment)) {
                    $opened++;
                }
            });

        $this->info("Reconciled: {$reconciled}. Issuance requests opened: {$opened}.");

        return self::SUCCESS;
    }
}
