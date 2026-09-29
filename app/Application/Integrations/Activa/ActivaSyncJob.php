<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Queued Activa sync of one policy or one payment (dispatched after the issuing / paying transaction commits). */
final class ActivaSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // retries are owned by carrier_api_sync_records (backoff) and activa:reconcile

    public function __construct(public readonly string $subjectType, public readonly string $subjectId) {}

    public function handle(ActivaPolicySync $sync): void
    {
        try {
            if ($this->subjectType === 'policy' && ($p = Policy::find($this->subjectId))) {
                $sync->syncPolicy($p);
            } elseif ($this->subjectType === 'payment_intent' && ($pi = PaymentIntentRecord::find($this->subjectId))) {
                $sync->syncPayment($pi);
            }
        } catch (\Throwable $e) {
            // Never surface into the issuing / paying request (sync queue); activa:reconcile retries.
            report($e);
        }
    }
}
