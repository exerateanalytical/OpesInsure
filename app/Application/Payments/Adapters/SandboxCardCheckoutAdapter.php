<?php

declare(strict_types=1);

namespace App\Application\Payments\Adapters;

use App\Models\PaymentIntentRecord;
use DomainException;

/**
 * Sandbox hosted-checkout card adapter (no real vendor). Available in local/testing, or where
 * payments.providers.card_sandbox.enabled is set. Settlement comes from a signed callback on
 * webhooks/payments/card_sandbox, exactly like a real vendor.
 */
final class SandboxCardCheckoutAdapter implements HostedCardCheckoutAdapter
{
    public function provider(): string
    {
        return 'card_sandbox';
    }

    public function requestCustomerAuthorization(PaymentIntentRecord $intent, string $requestId): ProviderInitiationResult
    {
        if (! app()->environment('local', 'testing') && ! config('payments.providers.card_sandbox.enabled')) {
            throw new DomainException('The sandbox card checkout is not enabled.');
        }
        $session = 'CARD-SBX-'.substr(hash('sha256', $intent->id.'|'.$requestId), 0, 20);

        return new ProviderInitiationResult($session, 'PENDING_CUSTOMER', [
            'request_id' => $requestId,
            'sandbox' => true,
            'checkout_url' => $this->checkoutUrl($session),
        ]);
    }

    public function checkoutUrl(string $providerReference): string
    {
        return rtrim((string) config('payments.providers.card_sandbox.checkout_base_url'), '/').'/'.rawurlencode($providerReference);
    }
}
