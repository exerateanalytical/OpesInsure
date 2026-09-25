<?php

declare(strict_types=1);

namespace App\Application\Payments\Adapters;

/**
 * REQ-PAY-003 / WF-024 card payments. A card vendor plugs in by implementing this: initiation opens a hosted checkout
 * session (card data never touches OpesInsure) and returns status PENDING_CUSTOMER with safeResponse['checkout_url'].
 * The outcome arrives only through the vendor's signed callback to webhooks/payments/{provider}; the browser redirect
 * back from the checkout page is never treated as payment confirmation.
 */
interface HostedCardCheckoutAdapter extends PaymentProviderAdapter
{
    /** The URL the payer is sent to for the given provider session reference. */
    public function checkoutUrl(string $providerReference): string;
}
