<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Integrations\WebhookDeliveryService;
use App\Models\{IntegrationDeliveryAttempt,IntegrationWebhookSubscription};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The worker the plan calls for explicitly: "An outbox table without a
 * supervised worker is not an integration." Two passes per run:
 *
 *  1. New outbox_messages (published_at IS NULL) — fan out to every ACTIVE
 *     subscription for that event_name and attempt delivery once, unless
 *     its circuit is open.
 *  2. Previously RETRY_SCHEDULED attempts whose backoff window has elapsed —
 *     retry them without re-scanning the whole outbox.
 *
 * Safe to run concurrently or re-run after a crash: outbox rows are claimed
 * with an atomic UPDATE...WHERE published_at IS NULL before being processed,
 * and delivery_attempts are looked up by (subscription, event) before
 * creating a new attempt, so nothing is double-delivered on overlap.
 */
final class DispatchIntegrationOutbox extends Command
{
    protected $signature = 'integration:dispatch-outbox {--limit=100}';

    protected $description = 'Deliver pending outbox events to subscribed partner webhooks, retry due attempts, and respect each subscription\'s circuit breaker.';

    public function handle(WebhookDeliveryService $delivery): int
    {
        $limit = (int) $this->option('limit');
        $delivered = 0;
        $failed = 0;
        $retryScheduled = 0;
        $circuitSkipped = 0;

        $messages = DB::table('outbox_messages')->whereNull('published_at')->orderBy('occurred_at')->limit($limit)->get();

        foreach ($messages as $message) {
            $claimed = DB::table('outbox_messages')->where('id', $message->id)->whereNull('published_at')->update(['published_at' => now(), 'attempts' => $message->attempts + 1]);

            if (! $claimed) {
                continue; // another worker already claimed this row
            }

            // REQ-AML-003 / Reg. 003-25 tipping-off: STR cases and AML signals never leave the platform via partner webhooks.
            if (\App\Application\Compliance\Aml\Str\TippingOffGuard::withholdFromIntegrations($message)) {
                continue;
            }

            $subscriptions = IntegrationWebhookSubscription::where('event_name', $message->event_name)
                ->where('status', 'ACTIVE')
                ->whereHas('client', fn ($q) => $q->where('status', 'ACTIVE'))
                ->get();

            foreach ($subscriptions as $subscription) {
                if (! $delivery->isEligibleForAttempt($subscription)) {
                    $circuitSkipped++;

                    continue;
                }

                $attemptNumber = IntegrationDeliveryAttempt::where('integration_webhook_subscription_id', $subscription->id)
                    ->where('event_id', $message->id)
                    ->count() + 1;

                $result = $delivery->attempt($subscription, $message->id, $message->event_name, json_decode($message->payload, true), $attemptNumber);

                match ($result->status) {
                    'DELIVERED' => $delivered++,
                    'DEAD_LETTERED' => $failed++,
                    default => $retryScheduled++,
                };
            }
        }

        [$retryCount, $retrySkipped] = $this->retryDueAttempts($delivery);
        $circuitSkipped += $retrySkipped;

        $this->info("New events: {$messages->count()} claimed. Delivered: {$delivered}, dead-lettered: {$failed}, scheduled for retry: {$retryScheduled}. Retried this run: {$retryCount}. Circuit-skipped: {$circuitSkipped}.");

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} [retried count, circuit-skipped count] */
    private function retryDueAttempts(WebhookDeliveryService $delivery): array
    {
        // unique() keeps the FIRST row per key — without sorting by attempt
        // DESC first, that's often the oldest attempt once a second one
        // exists, which then fails the "is this really the latest" filter
        // and gets dropped entirely, silently stopping all further retries
        // for that pair. Sorting first makes unique() keep the right row.
        $due = IntegrationDeliveryAttempt::where('status', 'RETRY_SCHEDULED')
            ->where('next_attempt_at', '<=', now())
            ->orderByDesc('attempt')
            ->get()
            ->unique(fn ($a) => $a->integration_webhook_subscription_id.'|'.$a->event_id)
            ->filter(fn ($a) => $a->id === IntegrationDeliveryAttempt::where('integration_webhook_subscription_id', $a->integration_webhook_subscription_id)->where('event_id', $a->event_id)->latest('attempt')->value('id'));

        $retried = 0;
        $circuitSkipped = 0;

        foreach ($due as $attempt) {
            $message = DB::table('outbox_messages')->find($attempt->event_id);

            if (! $message) {
                continue;
            }

            $subscription = $attempt->subscription;

            if (! $subscription || $subscription->status !== 'ACTIVE') {
                continue;
            }

            if (! $delivery->isEligibleForAttempt($subscription)) {
                $circuitSkipped++;

                continue;
            }

            $delivery->attempt($subscription, $message->id, $message->event_name, json_decode($message->payload, true), $attempt->attempt + 1);
            $retried++;
        }

        return [$retried, $circuitSkipped];
    }
}
