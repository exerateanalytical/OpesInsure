<?php

declare(strict_types=1);

namespace App\Application\Payments\Adapters;

use App\Application\Demo\DemoPersonas;
use App\Models\PaymentIntentRecord;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * MTN MoMo Collections API (Request to Pay). MTN's initiation call returns
 * 202 with no body — the reference we generate IS the resource identifier
 * used to poll status afterwards. MTN does not sign its callback, so the
 * callback URL carries our own callback_token plus the reference id, and
 * the callback controller always re-queries status() rather than trusting
 * whatever body MTN posts back (see MtnMomoCallbackController).
 *
 * Credentials come from MtnMomoCredentials (encrypted payment connection, .env fallback). A SANDBOX
 * connection moves no real money: it only ever prompts for a seeded demo persona's payment, and
 * status() tags its result `opes_sandbox` so MobileMoneyStatusReconciler never settles a real intent on it.
 */
final class MtnMomoAdapter implements PaymentProviderAdapter
{
    public function provider(): string
    {
        return 'mtn_momo';
    }

    public function configured(): bool
    {
        return MtnMomoCredentials::resolve()->configured();
    }

    public function requestCustomerAuthorization(PaymentIntentRecord $intent, string $requestId): ProviderInitiationResult
    {
        $creds = MtnMomoCredentials::resolve();

        if (! $creds->configured()) {
            throw new DomainException('MTN MoMo provider is not configured.');
        }
        if ($creds->isSandbox() && ! DemoPersonas::ownsPayment($intent)) {
            throw new DomainException('MTN MoMo is in test mode and is not available for this payment.');
        }

        // A plain (unsigned) route: MTN echoes this URL back verbatim, but we
        // cannot rely on a full-URL signature scheme here because the sibling
        // Orange adapter's callback URL gets extra query params appended by
        // the provider itself (see OrangeMoneyAdapter) — a signature over
        // "all current query params" would break once Orange adds its own.
        // The token param alone, compared with hash_equals(), is immune to
        // that and keeps both providers' callback verification consistent.
        $callbackUrl = route('payments.mtn_momo.callback', [
            'reference_id' => $requestId,
            'token' => $creds->get('callback_token'),
        ]);

        $response = Http::baseUrl($creds->get('base_url'))
            ->withToken($this->accessToken($creds))
            ->withHeaders([
                'X-Reference-Id' => $requestId,
                'X-Target-Environment' => $creds->get('target_environment'),
                'Ocp-Apim-Subscription-Key' => $creds->get('subscription_key'),
                'X-Callback-Url' => $callbackUrl,
                'Content-Type' => 'application/json',
            ])
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->post('/collection/v1_0/requesttopay', [
                'amount' => (string) $intent->amount_minor,
                'currency' => $creds->wireCurrency((string) $intent->currency),
                'externalId' => $intent->id,
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => ltrim($intent->payer_phone_e164, '+'),
                ],
                'payerMessage' => 'OpesInsure payment',
                'payeeNote' => 'OpesInsure payment '.$intent->id,
            ]);

        if ($response->status() !== 202) {
            throw new DomainException('MTN MoMo request-to-pay was rejected.');
        }

        return new ProviderInitiationResult($requestId, 'PENDING_CUSTOMER', [
            'http_status' => $response->status(),
            'x_reference_id' => $requestId,
            'sandbox' => $creds->isSandbox(),
        ]);
    }

    /** @return array<string, mixed> The raw MTN RequestToPayResult resource — the sole source of truth for status. */
    public function status(string $referenceId): array
    {
        $creds = MtnMomoCredentials::resolve();

        if (! $creds->configured()) {
            throw new DomainException('MTN MoMo provider is not configured.');
        }

        $response = Http::baseUrl($creds->get('base_url'))
            ->withToken($this->accessToken($creds))
            ->withHeaders([
                'X-Target-Environment' => $creds->get('target_environment'),
                'Ocp-Apim-Subscription-Key' => $creds->get('subscription_key'),
            ])
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->get("/collection/v1_0/requesttopay/{$referenceId}");

        if (! $response->successful()) {
            throw new DomainException('MTN MoMo status query failed.');
        }

        $raw = (array) $response->json();
        if ($creds->isSandbox()) {
            // Sandbox reports EUR (its only currency), not the intent's XAF: drop it rather than let the
            // currency check reject it, and tag the result so only a demo persona's intent can settle on it.
            unset($raw['currency']);
            $raw['opes_sandbox'] = true;
        }

        return $raw;
    }

    private function accessToken(MtnMomoCredentials $creds): string
    {
        $cacheKey = 'mtn_momo:access_token:'.$creds->fingerprint();

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($creds->get('base_url'))
            ->withBasicAuth($creds->get('api_user'), $creds->get('api_key'))
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $creds->get('subscription_key')])
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->post('/collection/token/');

        if (! $response->successful()) {
            \App\Application\Operations\Monitoring\MonitoringSignals::record(\App\Application\Operations\Monitoring\MonitoringSignals::PAYMENT_AUTH_FAILURE, 'mtn_momo');
            throw new DomainException('MTN MoMo authentication failed.');
        }

        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new DomainException('MTN MoMo authentication response did not include an access token.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }
}
