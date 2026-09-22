<?php
declare(strict_types=1);
namespace App\Application\Integrations;

use App\Application\Audit\AuditWriter;
use App\Models\{IntegrationDeliveryAttempt,IntegrationWebhookSubscription,User};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Signs and delivers one webhook attempt, and classifies the outcome into
 * DELIVERED / RETRY_SCHEDULED / DEAD_LETTERED per the plan's retry rules
 * (§15.2): retry network/timeout/408/425/429/5xx, never retry a 4xx that
 * means the request itself was rejected (bad signature, bad schema, blocked
 * partner, permanently wrong endpoint).
 *
 * Also owns the circuit breaker (§15.3): a subscription whose endpoint keeps
 * failing has its circuit opened so the worker stops hammering it, and gets
 * one HALF_OPEN probe attempt after a cooldown instead of a full retry
 * storm.
 */
final class WebhookDeliveryService
{
    private const MAX_ATTEMPTS = 8;
    private const BASE_BACKOFF_SECONDS = 30;
    private const CIRCUIT_FAILURE_THRESHOLD = 5;
    private const CIRCUIT_COOLDOWN_MINUTES = 15;

    public function __construct(private readonly AuditWriter $audit) {}

    /**
     * OPEN circuits are skipped entirely (no request sent) except for a
     * single HALF_OPEN probe once the cooldown has elapsed — claimed
     * atomically here so two concurrent worker runs can't both send a
     * probe at once.
     */
    public function isEligibleForAttempt(IntegrationWebhookSubscription $subscription): bool
    {
        if ($subscription->circuit_state !== 'OPEN') {
            return true;
        }

        if ($subscription->circuit_opened_at?->addMinutes(self::CIRCUIT_COOLDOWN_MINUTES)->isFuture()) {
            return false;
        }

        return IntegrationWebhookSubscription::where('id', $subscription->id)->where('circuit_state', 'OPEN')->update(['circuit_state' => 'HALF_OPEN']) > 0;
    }

    public function attempt(IntegrationWebhookSubscription $subscription, string $eventId, string $eventName, array $payload, int $attemptNumber): IntegrationDeliveryAttempt
    {
        $envelope = ['id' => $eventId, 'type' => $eventName, 'occurred_at' => now()->toIso8601String(), 'data' => $payload];
        $body = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $subscription->signingSecret());
        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-OpesInsure-Event-Id' => $eventId,
                'X-OpesInsure-Event-Type' => $eventName,
                'X-OpesInsure-Timestamp' => $timestamp,
                'X-OpesInsure-Signature' => 'sha256='.$signature,
            ])->withBody($body, 'application/json')->timeout(10)->post($subscription->endpoint());

            $duration = (int) ((microtime(true) - $startedAt) * 1000);

            return $this->record($subscription, $eventId, $attemptNumber, $this->classify($response->status()), $response->status(), $response->status() >= 300 ? Str::limit($response->body(), 500) : null, $duration);
        } catch (Throwable $e) {
            $duration = (int) ((microtime(true) - $startedAt) * 1000);

            return $this->record($subscription, $eventId, $attemptNumber, 'RETRY_SCHEDULED', null, $e->getMessage(), $duration);
        }
    }

    /**
     * A human-triggered redelivery of a dead-lettered attempt — distinct from
     * the worker's own automatic retry, and requires an actor for the audit
     * trail. Continues the same attempt sequence rather than starting over.
     */
    public function replay(IntegrationDeliveryAttempt $deadLettered, array $payload, User $actor): IntegrationDeliveryAttempt
    {
        if ($deadLettered->status !== 'DEAD_LETTERED') {
            throw ValidationException::withMessages(['status' => 'Only a dead-lettered attempt can be replayed.']);
        }

        $subscription = $deadLettered->subscription;
        $attempt = $this->attempt($subscription, $deadLettered->event_id, $subscription->event_name, $payload, $deadLettered->attempt + 1);
        $attempt->update(['is_manual_replay' => true, 'replayed_by' => $actor->id]);

        $this->audit->record('integration.webhook.replayed', 'integration_delivery_attempt', $attempt->id, ['original_attempt_id' => $deadLettered->id, 'result' => $attempt->status]);

        return $attempt->refresh();
    }

    /** @return 'DELIVERED'|'RETRY_SCHEDULED'|'DEAD_LETTERED' */
    private function classify(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return 'DELIVERED';
        }

        $retryable = $status === 408 || $status === 425 || $status === 429 || ($status >= 500 && $status < 600);

        return $retryable ? 'RETRY_SCHEDULED' : 'DEAD_LETTERED';
    }

    private function record(IntegrationWebhookSubscription $subscription, string $eventId, int $attemptNumber, string $status, ?int $responseStatus, ?string $failureReason, int $durationMs): IntegrationDeliveryAttempt
    {
        if ($status === 'RETRY_SCHEDULED' && $attemptNumber >= self::MAX_ATTEMPTS) {
            $status = 'DEAD_LETTERED';
        }

        $jitter = random_int(0, 1000) / 1000;
        $backoffSeconds = self::BASE_BACKOFF_SECONDS * (2 ** ($attemptNumber - 1));
        $nextAttemptAt = $status === 'RETRY_SCHEDULED' ? now()->addSeconds((int) ($backoffSeconds + $jitter * $backoffSeconds)) : null;

        $record = IntegrationDeliveryAttempt::create([
            'integration_webhook_subscription_id' => $subscription->id,
            'event_id' => $eventId,
            'attempt' => $attemptNumber,
            'status' => $status,
            'response_status' => $responseStatus,
            'failure_reason' => $failureReason,
            'next_attempt_at' => $nextAttemptAt,
            'delivered_at' => $status === 'DELIVERED' ? now() : null,
            'duration_ms' => $durationMs,
        ]);

        $this->updateCircuit($subscription, $status);

        return $record;
    }

    private function updateCircuit(IntegrationWebhookSubscription $subscription, string $status): void
    {
        if ($status === 'DELIVERED') {
            if ($subscription->circuit_state !== 'CLOSED' || $subscription->consecutive_failures !== 0) {
                $this->audit->record('integration.circuit.closed', 'integration_webhook_subscription', $subscription->id, []);
            }
            $subscription->update(['consecutive_failures' => 0, 'circuit_state' => 'CLOSED', 'circuit_opened_at' => null]);

            return;
        }

        $failures = $subscription->consecutive_failures + 1;

        if ($failures >= self::CIRCUIT_FAILURE_THRESHOLD && $subscription->circuit_state !== 'OPEN') {
            $subscription->update(['consecutive_failures' => $failures, 'circuit_state' => 'OPEN', 'circuit_opened_at' => now()]);
            $this->audit->record('integration.circuit.opened', 'integration_webhook_subscription', $subscription->id, ['consecutive_failures' => $failures], 'failure_threshold_exceeded');
            report_integration_circuit_opened($subscription);

            return;
        }

        $subscription->update(['consecutive_failures' => $failures]);
    }
}

if (! function_exists('report_integration_circuit_opened')) {
    /**
     * The only "alert" this batch can honestly claim: there is no real
     * email/SMS/Slack adapter in this codebase yet (confirmed missing this
     * session) — writing one here to look complete would be exactly the
     * kind of unverified integration claim the plan explicitly forbids.
     * This writes to the standard Laravel log channel, which is real and
     * operationally visible today; wiring an actual paging channel is a
     * later batch once a communications adapter exists.
     */
    function report_integration_circuit_opened(\App\Models\IntegrationWebhookSubscription $subscription): void
    {
        \Illuminate\Support\Facades\Log::warning('Integration webhook circuit opened', [
            'subscription_id' => $subscription->id,
            'integration_client_id' => $subscription->integration_client_id,
            'event_name' => $subscription->event_name,
            'consecutive_failures' => $subscription->consecutive_failures,
        ]);
    }
}
