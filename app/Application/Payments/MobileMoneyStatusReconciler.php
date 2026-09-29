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
        // Security review 2026-09-27 item 3: the re-queried MTN record must be for this intent, at this amount and
        // currency (when MTN reports them), before it can move money state.
        if (! $this->sameTransaction($intent, $rawStatus['externalId'] ?? null, $rawStatus['amount'] ?? null, $rawStatus['currency'] ?? null)) {
            return null;
        }
        // A SANDBOX status moves no real money: it may only settle a seeded demo persona's payment, never a real customer's.
        if (! empty($rawStatus['opes_sandbox']) && ! \App\Application\Demo\DemoPersonas::ownsPayment($intent)) {
            \Illuminate\Support\Facades\Log::warning('mobile_money.reconcile.sandbox_refused', ['payment_intent_id' => $intent->id, 'provider' => 'mtn_momo']);

            return null;
        }
        $result = $this->reconcile($intent, 'mtn_momo', $this->mapMtnStatus((string) ($rawStatus['status'] ?? '')));
        if (! empty($rawStatus['opes_sandbox']) && $result !== null) {
            $intent->refresh();
            $intent->forceFill(['provider_snapshot' => array_merge((array) ($intent->provider_snapshot ?? []), ['sandbox' => true, 'demo' => true])])->save();
        }

        return $result;
    }

    public function reconcileOrange(PaymentIntentRecord $intent, array $rawStatus): ?string
    {
        if (! $this->sameTransaction($intent, $rawStatus['order_id'] ?? null, $rawStatus['amount'] ?? null, $rawStatus['currency'] ?? null)) {
            return null;
        }

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

    /** Fields the provider did not report are not held against the intent; a reported mismatch always is. */
    private function sameTransaction(PaymentIntentRecord $intent, mixed $reference, mixed $amount, mixed $currency): bool
    {
        $ok = ($reference === null || (string) $reference === (string) $intent->id)
            && ($amount === null || (string) (int) $amount === (string) $intent->amount_minor)
            && ($currency === null || strtoupper((string) $currency) === strtoupper((string) $intent->currency));
        if (! $ok) {
            \Illuminate\Support\Facades\Log::warning('mobile_money.reconcile.mismatch', ['payment_intent_id' => $intent->id, 'provider' => $intent->provider]);
        }

        return $ok;
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
