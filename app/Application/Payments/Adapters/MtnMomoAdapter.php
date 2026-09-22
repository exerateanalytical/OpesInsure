<?php

declare(strict_types=1);

namespace App\Application\Payments\Adapters;

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
 */
final class MtnMomoAdapter implements PaymentProviderAdapter
{
    public function provider(): string
    {
        return 'mtn_momo';
    }

    public function requestCustomerAuthorization(PaymentIntentRecord $intent, string $requestId): ProviderInitiationResult
    {
        $baseUrl = $this->baseUrl();
        $subscriptionKey = $this->config('subscription_key');

        if ($baseUrl === '' || $subscriptionKey === '' || $this->config('api_user') === '' || $this->config('api_key') === '') {
            throw new DomainException('MTN MoMo provider is not configured.');
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
            'token' => $this->config('callback_token'),
        ]);

        $response = Http::baseUrl($baseUrl)
            ->withToken($this->accessToken())
            ->withHeaders([
                'X-Reference-Id' => $requestId,
                'X-Target-Environment' => $this->config('target_environment', 'sandbox'),
                'Ocp-Apim-Subscription-Key' => $subscriptionKey,
                'X-Callback-Url' => $callbackUrl,
                'Content-Type' => 'application/json',
            ])
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->post('/collection/v1_0/requesttopay', [
                'amount' => (string) $intent->amount_minor,
                'currency' => $intent->currency,
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
        ]);
    }

    /** @return array<string, mixed> The raw MTN RequestToPayResult resource — the sole source of truth for status. */
    public function status(string $referenceId): array
    {
        $response = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->withHeaders([
                'X-Target-Environment' => $this->config('target_environment', 'sandbox'),
                'Ocp-Apim-Subscription-Key' => $this->config('subscription_key'),
            ])
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->get("/collection/v1_0/requesttopay/{$referenceId}");

        if (! $response->successful()) {
            throw new DomainException('MTN MoMo status query failed.');
        }

        return (array) $response->json();
    }

    private function accessToken(): string
    {
        $environment = (string) $this->config('target_environment', 'sandbox');
        $cacheKey = "mtn_momo:access_token:{$environment}";

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->baseUrl())
            ->withBasicAuth((string) $this->config('api_user'), (string) $this->config('api_key'))
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $this->config('subscription_key')])
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->post('/collection/token/');

        if (! $response->successful()) {
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

    private function baseUrl(): string
    {
        return rtrim((string) config('payments.providers.mtn_momo.base_url'), '/');
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("payments.providers.mtn_momo.{$key}", $default);
    }
}
