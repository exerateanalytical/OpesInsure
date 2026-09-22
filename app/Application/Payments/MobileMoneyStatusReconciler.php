<?php

declare(strict_types=1);

namespace App\Application\Payments;

use App\Models\PaymentIntentRecord;

/**
 * Shared by both mobile-money callback controllers and the polling command,
 * so "map provider status -> generic status -> WebhookProcessingService::process()"
 * exists exactly once. Neither MTN nor Orange is trusted to declare its own
 * status here — callers must have already obtained $rawStatus from a live
 * call to the adapter's status() method, never from an unverified callback
 * body.
 *
 * A PENDING/unrecognised provider status is deliberately a no-op: there is
 * no generic status to transition to yet, so nothing is written and no
 * webhook_inbox row is created, leaving the intent free to be reconciled
 * again on the next poll or callback.
 */
final class MobileMoneyStatusReconciler
{
    public function __construct(private WebhookProcessingService $webhooks)
    {
    }

    public function reconcileMtn(PaymentIntentRecord $intent, array $rawStatus): ?string
    {
        return $this->reconcile($intent, 'mtn_momo', $this->mapMtnStatus((string) ($rawStatus['status'] ?? '')));
    }

    public function reconcileOrange(PaymentIntentRecord $intent, array $rawStatus): ?string
    {
        return $this->reconcile($intent, 'orange_money', $this->mapOrangeStatus((string) ($rawStatus['status'] ?? '')));
    }

    private function reconcile(PaymentIntentRecord $intent, string $provider, ?string $genericStatus): ?string
    {
        if ($genericStatus === null) {
            return null;
        }

        $eventId = "{$provider}:{$intent->provider_reference}:{$genericStatus}";

        $this->webhooks->process($provider, $eventId, [
            'payment_reference' => $intent->provider_reference,
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'status' => $genericStatus,
        ], 'reconciled');

        return $genericStatus;
    }

    private function mapMtnStatus(string $status): ?string
    {
        return match ($status) {
            'SUCCESSFUL' => 'SUCCEEDED',
            'FAILED' => 'FAILED',
            default => null, // PENDING, or anything MTN adds later — no transition yet
        };
    }

    private function mapOrangeStatus(string $status): ?string
    {
        return match ($status) {
            'SUCCESS' => 'SUCCEEDED',
            'FAILED' => 'FAILED',
            'EXPIRED' => 'EXPIRED',
            default => null, // INITIATED, PENDING, or anything else — no transition yet
        };
    }
}
