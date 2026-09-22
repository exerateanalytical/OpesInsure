<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Payments\Adapters\MtnMomoAdapter;
use App\Application\Payments\Adapters\OrangeMoneyAdapter;
use App\Application\Payments\MobileMoneyStatusReconciler;
use App\Models\PaymentIntentRecord;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fallback reconciliation for MTN MoMo and Orange Money: neither provider's
 * callback delivery is guaranteed (MTN's callback URL can simply never be
 * called; Orange's notif_url delivery is best-effort too), so a still-open
 * intent must eventually be reconciled by asking the provider directly
 * rather than waiting forever for a push that may never arrive.
 *
 * Reuses the same MobileMoneyStatusReconciler as the two callback
 * controllers, so a poll and a callback that race each other converge on
 * the same webhook_inbox dedup key and only one of them actually transitions
 * the intent.
 */
final class PollPendingMobileMoneyPayments extends Command
{
    protected $signature = 'payments:poll-pending {--limit=100}';

    protected $description = 'Re-query MTN MoMo and Orange Money for the live status of still-open payment intents.';

    public function handle(
        MtnMomoAdapter $mtn,
        OrangeMoneyAdapter $orange,
        MobileMoneyStatusReconciler $reconciler,
    ): int {
        $limit = (int) $this->option('limit');
        $polled = 0;
        $transitioned = 0;
        $failed = 0;

        $intents = PaymentIntentRecord::whereIn('provider', ['mtn_momo', 'orange_money'])
            ->whereIn('status', ['PENDING_CUSTOMER', 'PROCESSING'])
            ->whereNotNull('provider_reference')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($intents as $intent) {
            $polled++;

            try {
                $result = match ($intent->provider) {
                    'mtn_momo' => $reconciler->reconcileMtn($intent, $mtn->status($intent->provider_reference)),
                    'orange_money' => $reconciler->reconcileOrange(
                        $intent,
                        $orange->status($intent->provider_reference, $intent->id, (int) $intent->amount_minor),
                    ),
                    default => null,
                };

                if ($result !== null) {
                    $transitioned++;
                }
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        $this->info("Polled: {$polled}. Transitioned: {$transitioned}. Failed: {$failed}.");

        return self::SUCCESS;
    }
}
